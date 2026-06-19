<?php
/**
 * Arch3 — POST /api/generate-redesign.php
 * Recebe: multipart/form-data { image (arquivo), prompt (texto) }
 *
 * Fluxo:
 *   1. Aceita QUALQUER foto de ambiente (não rejeita por resolução, proporção,
 *      pixels ou por não ser panorâmica).
 *   2. Pré-processa a imagem (auto-orienta via EXIF, converte para JPG, reduz
 *      para um tamanho seguro mantendo a proporção) — isso evita timeout/502.
 *   3. Monta o prompt arquitetônico + de expansão panorâmica.
 *   4. Chama a OpenAI Images Edit e devolve o resultado como JPEG (leve).
 *   5. Em qualquer falha, devolve SEMPRE JSON com o motivo real e registra log.
 *
 * A chave fica em arch3-config.php FORA do diretório público.
 */

require_once __DIR__ . '/lib/auth.php';

header('Content-Type: application/json; charset=utf-8');
set_time_limit(150);
@ini_set('max_execution_time', '150');

// --- Nunca deixar resposta vazia / não-JSON vinda do PHP -------------------
// Se um erro fatal (ou estouro de memória/tempo no nosso lado) interromper o
// script antes de qualquer saída, este shutdown emite um JSON claro.
$GLOBALS['arch3_responded'] = false;
register_shutdown_function(function () {
    if ($GLOBALS['arch3_responded']) {
        return;
    }
    $err = error_get_last();
    $msg = 'The generator hit an unexpected error. Please try again in a moment.';
    $code = 'server_error';
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log('[arch3] fatal in generate-redesign: ' . $err['message'] . ' @ ' . $err['file'] . ':' . $err['line']);
        if (stripos($err['message'], 'memory') !== false) {
            $msg = 'The image was too heavy to process. Try a slightly smaller photo.';
            $code = 'image_too_large';
        }
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['error' => $msg, 'code' => $code]);
});

function fail($status, $message, $extra = []) {
    $GLOBALS['arch3_responded'] = true;
    if (!headers_sent()) {
        http_response_code($status);
    }
    if (!empty($extra['log'])) {
        error_log('[arch3] generate fail (' . $status . '): ' . $extra['log']);
        unset($extra['log']);
    }
    echo json_encode(array_merge(['error' => $message], $extra));
    exit;
}

function ok_json(array $payload) {
    $GLOBALS['arch3_responded'] = true;
    echo json_encode($payload);
    exit;
}

// ---- Autenticação obrigatória ----
$pdo = arch3_db();
$user = current_user();
if (!$user) {
    fail(401, 'Please sign in to generate.', ['code' => 'auth_required']);
}

// ---- E-mail precisa estar verificado (crédito grátis só após verificação) ----
// Controlado por flag: só passa a bloquear quando a verificação de e-mail
// estiver totalmente publicada (front + back).
if (cfg('REQUIRE_EMAIL_VERIFICATION', false)
    && (int) ($user['email_verified'] ?? 0) !== 1
    && !is_admin_email($user['email'])) {
    fail(403, 'Please verify your email before generating.', ['code' => 'email_unverified']);
}

// ---- Verificação de créditos ----
if ((int) $user['credits_remaining'] <= 0) {
    fail(402, "You've used your free generation.\n\nChoose a plan to continue transforming spaces with Arch3.", [
        'code' => 'no_credits',
        'credits_remaining' => 0,
        'plan' => $user['subscription_plan'] ?: 'free',
    ]);
}

// ---- Config / chave (fora do webroot) ----
$apiKey = (string) cfg('OPENAI_API_KEY', '');
if ($apiKey === '') {
    fail(500, 'Image generation is not configured on the server yet.', ['code' => 'no_api_key', 'log' => 'OPENAI_API_KEY missing']);
}
$model   = cfg('OPENAI_IMAGE_MODEL', 'gpt-image-1.5');
$quality = cfg('OPENAI_IMAGE_QUALITY', 'medium');

// Pro: preset de qualidade superior.
if (($user['subscription_plan'] ?? '') === 'pro' && ($user['subscription_status'] ?? '') === 'active') {
    $quality = cfg('OPENAI_IMAGE_QUALITY_PRO', 'high');
}

// ---- Validação de entrada (permissiva: aceitar qualquer foto) ----
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail(405, 'Method not allowed.');
}
$prompt = isset($_POST['prompt']) ? trim($_POST['prompt']) : '';
if (!isset($_FILES['image'])) {
    fail(400, 'Please attach a room photo.', ['code' => 'no_file']);
}
if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    $up = (int) $_FILES['image']['error'];
    // Upload maior que os limites do servidor: orienta a reduzir, sem bloquear o conceito.
    if (in_array($up, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
        fail(413, 'That photo is extremely large. Please try one under ~40 MB.', ['code' => 'image_too_large']);
    }
    fail(400, 'The upload did not complete. Please try again.', ['code' => 'upload_failed', 'log' => 'UPLOAD_ERR ' . $up]);
}
if ($prompt === '') {
    fail(400, 'Describe the transformation you want.', ['code' => 'no_prompt']);
}

$file = $_FILES['image'];

// ---- Pré-processamento: aceitar qualquer imagem e torná-la "segura" --------
// Converte para JPG, auto-orienta (EXIF), reduz para um tamanho seguro mantendo
// a proporção original. Isso reduz o tempo de geração e o tráfego, evitando o
// timeout do gateway (causa do antigo 502).
list($jpegPath, $srcW, $srcH, $cleanup) = preprocess_upload($file['tmp_name']);

// ---- Prompt base (arquitetônico + expansão panorâmica) ----
$BASE_PROMPT = <<<TXT
You are an AI architectural visualization assistant for Arch3.

Treat the uploaded image as the base environment. Preserve the original room structure, proportions and architecture. If the image is not panoramic, intelligently expand and reconstruct the surrounding environment so it can be displayed in an immersive panoramic viewer. Maintain realism and continuity between walls, floor, ceiling, windows and furniture.

Create a redesigned version that feels like a real architectural / interior design proposal, not a fantasy scene.

Rules:
- Preserve the same room identity and spatial logic; keep the general camera position.
- Respect realistic renovation possibilities; avoid distorted geometry, impossible windows, warped furniture or fantasy elements.
- You may change furniture, colors, materials, lighting, decor, rugs, curtains, plants, art, doors, windows and finishes, and add or remove furniture if asked.
- Follow the user's requested changes closely.
- Produce a wide, coherent interior scene suitable for an immersive panoramic preview.
- Fill the entire frame edge to edge with real, continuous architectural content. Do NOT add blurred side filling, vignettes, dark borders, stretched edges or duplicated pixels.
- Keep a premium, realistic, professionally designed look.

Return a wide panoramic interior image suitable for immersive 360° preview. Preserve the original room, but compose the output as a seamless wide panorama. Avoid black borders, empty areas, cropped corners, warped framing or missing visual information.
TXT;

$finalPrompt = $BASE_PROMPT . "\n\nUser request:\n" . $prompt;

// Empurra o resultado para o formato panorâmico mais largo disponível na API.
$size = '1536x1024';

// ---- Chamada à OpenAI Images Edit ----
$ch = curl_init('https://api.openai.com/v1/images/edits');
$post = [
    'model'         => $model,
    'prompt'        => $finalPrompt,
    'size'          => $size,
    'quality'       => $quality,
    'output_format' => 'jpeg',
    'image'         => new CURLFile($jpegPath, 'image/jpeg', 'room.jpg'),
];
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $post,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $apiKey],
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_TIMEOUT        => 115, // dentro da janela do gateway; falha limpa em JSON antes do 502
]);
$resp = curl_exec($ch);
$errno = curl_errno($ch);
$curlErr = curl_error($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($cleanup && is_file($jpegPath)) {
    @unlink($jpegPath);
}

// Erros de transporte (timeout, conexão) — motivo real, nunca 502 genérico.
if ($resp === false || $errno !== 0) {
    if ($errno === CURLE_OPERATION_TIMEDOUT) {
        fail(504, 'The generation took too long this time. Please try again in a moment — a smaller photo also helps.', [
            'code' => 'timeout',
            'log'  => 'curl timeout: ' . $curlErr,
        ]);
    }
    fail(502, 'Could not reach the image service right now. Please try again shortly.', [
        'code' => 'api_unreachable',
        'log'  => 'curl errno ' . $errno . ': ' . $curlErr,
    ]);
}

$data = json_decode($resp, true);

// Erros lógicos da OpenAI — mapear para motivo claro.
if ($httpCode !== 200 || !is_array($data) || isset($data['error'])) {
    $apiMsg  = $data['error']['message'] ?? ('Image service returned HTTP ' . $httpCode);
    $apiType = $data['error']['type'] ?? '';
    $apiCode = $data['error']['code'] ?? '';

    if ($httpCode === 401) {
        fail(502, 'Image generation is temporarily unavailable (authentication). The team has been notified.', [
            'code' => 'invalid_key', 'log' => 'OpenAI 401: ' . $apiMsg,
        ]);
    }
    if ($httpCode === 429 || $apiType === 'insufficient_quota' || $apiCode === 'insufficient_quota') {
        $isBilling = ($apiType === 'insufficient_quota' || $apiCode === 'insufficient_quota');
        fail(502, $isBilling
            ? 'Image generation is temporarily unavailable (account quota/billing). The team has been notified.'
            : 'The image service is busy right now. Please wait a few seconds and try again.', [
            'code' => $isBilling ? 'quota' : 'rate_limited',
            'log'  => 'OpenAI ' . $httpCode . ' ' . $apiType . '/' . $apiCode . ': ' . $apiMsg,
        ]);
    }
    if ($httpCode === 400) {
        // Tipicamente conteúdo/parametro/imagem — repassa motivo real.
        fail(422, 'The image service could not process this request: ' . $apiMsg, [
            'code' => 'api_rejected', 'log' => 'OpenAI 400: ' . $apiMsg,
        ]);
    }
    fail(502, 'The image service returned an error: ' . $apiMsg, [
        'code' => 'api_failure', 'log' => 'OpenAI ' . $httpCode . ': ' . $apiMsg,
    ]);
}

$b64 = $data['data'][0]['b64_json'] ?? null;
if (!$b64 && !empty($data['data'][0]['url'])) {
    $b64 = base64_encode((string) @file_get_contents($data['data'][0]['url']));
}
if (!$b64) {
    fail(502, 'The image service did not return an image. Please try again.', [
        'code' => 'empty_result', 'log' => 'no b64/url in OpenAI response',
    ]);
}
$imageBinary = base64_decode($b64);
unset($b64, $data, $resp); // libera memória

// ---- Normaliza para entrega leve (JPEG) + detecta proporção panorâmica ----
list($outBinary, $w, $h, $isPanoramic, $outMime) = finalize_result($imageBinary);
unset($imageBinary);

// ---- Consome 1 crédito (somente após geração bem-sucedida) ----
$consume = $pdo->prepare(
    'UPDATE users
        SET credits_remaining = credits_remaining - 1,
            generations_used = generations_used + 1
      WHERE id = :id AND credits_remaining > 0'
);
$consume->execute([':id' => $user['id']]);

$pdo->prepare('INSERT INTO generations (user_id, created_at, prompt) VALUES (:uid, :t, :p)')
    ->execute([':uid' => $user['id'], ':t' => date('Y-m-d H:i:s'), ':p' => mb_substr($prompt, 0, 1000)]);

$creditsLeft = max(0, (int) $user['credits_remaining'] - 1);
$aspect = $h > 0 ? round($w / $h, 3) : 0;

ok_json([
    'imageUrl' => 'data:' . $outMime . ';base64,' . base64_encode($outBinary),
    'mimeType' => $outMime,
    'fileName' => 'arch3-result.jpg',
    'width'    => $w,
    'height'   => $h,
    'aspect'   => $aspect,
    'panoramic' => $isPanoramic,
    'notice'   => $isPanoramic
        ? null
        : 'For the most immersive experience, upload a full panorama. Standard photos are also supported.',
    'provider' => 'openai',
    'credits_remaining' => $creditsLeft,
]);

// ===========================================================================
// Helpers
// ===========================================================================

/**
 * Aceita qualquer imagem e a prepara para a OpenAI:
 *   - auto-orienta (EXIF),
 *   - remove metadados,
 *   - reduz para um lado máximo seguro mantendo a proporção,
 *   - converte para JPEG comprimido.
 * NUNCA rejeita por resolução, pixels ou proporção. Sem Imagick, usa o arquivo
 * original como fallback.
 *
 * @return array{0:string,1:int,2:int,3:bool} [jpegPath, width, height, isTemp]
 */
function preprocess_upload($srcPath) {
    $MAX_SIDE = 1536; // lado máximo enviado à OpenAI (rápido e suficiente)

    if (!extension_loaded('imagick')) {
        return [$srcPath, 0, 0, false];
    }

    try {
        $im = new Imagick();
        $im->readImage($srcPath);
        $im->setFirstIterator();

        // Auto-orientação por EXIF, depois remove metadados.
        if (method_exists($im, 'autoOrient')) {
            @$im->autoOrient();
        }
        $im->stripImage();

        $w = $im->getImageWidth();
        $h = $im->getImageHeight();
        $maxDim = max($w, $h);
        if ($maxDim > $MAX_SIDE) {
            $scale = $MAX_SIDE / $maxDim;
            $im->resizeImage((int) round($w * $scale), (int) round($h * $scale), Imagick::FILTER_LANCZOS, 1);
            $w = $im->getImageWidth();
            $h = $im->getImageHeight();
        }

        $im->setImageBackgroundColor(new ImagickPixel('white'));
        if (method_exists($im, 'mergeImageLayers')) {
            $im = $im->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
        } else {
            $im->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
        }
        $im->setImageFormat('jpeg');
        $im->setImageCompression(Imagick::COMPRESSION_JPEG);
        $im->setImageCompressionQuality(88);

        $tmp = tempnam(sys_get_temp_dir(), 'arch3_') . '.jpg';
        $im->writeImage($tmp);
        $im->clear();
        $im->destroy();
        return [$tmp, $w, $h, true];
    } catch (Throwable $e) {
        error_log('[arch3] preprocess fallback: ' . $e->getMessage());
        return [$srcPath, 0, 0, false];
    }
}

/**
 * Normaliza o resultado da OpenAI para entrega leve (JPEG de qualidade alta) e
 * detecta se a proporção é panorâmica (>= ~1.9:1) para abrir o viewer 360.
 *
 * @return array{0:string,1:int,2:int,3:bool,4:string} [binary,w,h,isPanoramic,mime]
 */
function finalize_result($binary) {
    $PANO_OK = 1.9;

    if (!extension_loaded('imagick')) {
        $size = @getimagesizefromstring($binary);
        $w = $size[0] ?? 0;
        $h = $size[1] ?? 0;
        $aspect = $h > 0 ? $w / $h : 0;
        $mime = $size['mime'] ?? 'image/jpeg';
        return [$binary, $w, $h, $aspect >= $PANO_OK, $mime];
    }

    try {
        $im = new Imagick();
        $im->readImageBlob($binary);
        $w = $im->getImageWidth();
        $h = $im->getImageHeight();
        $aspect = $h > 0 ? $w / $h : 0;

        $im->setImageFormat('jpeg');
        $im->setImageCompression(Imagick::COMPRESSION_JPEG);
        $im->setImageCompressionQuality(90);
        $out = $im->getImageBlob();
        $im->clear();
        $im->destroy();
        return [$out, $w, $h, $aspect >= $PANO_OK, 'image/jpeg'];
    } catch (Throwable $e) {
        error_log('[arch3] finalize fallback: ' . $e->getMessage());
        $size = @getimagesizefromstring($binary);
        $w = $size[0] ?? 0;
        $h = $size[1] ?? 0;
        $aspect = $h > 0 ? $w / $h : 0;
        return [$binary, $w, $h, $aspect >= $PANO_OK, 'image/png'];
    }
}
