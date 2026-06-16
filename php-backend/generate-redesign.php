<?php
/**
 * Arch3 — POST /api/generate-redesign.php
 * Recebe: multipart/form-data { image (arquivo), prompt (texto) }
 * Faz: monta prompt arquitetônico + panorâmico, chama a OpenAI Images API,
 *      expande o resultado para um panorama ~2:1 (Imagick) e devolve JSON.
 *
 * A chave fica em arch3-config.php FORA do diretório público.
 */

require_once __DIR__ . '/lib/auth.php';

header('Content-Type: application/json; charset=utf-8');
set_time_limit(180);
@ini_set('max_execution_time', '180');

function fail($status, $message, $extra = []) {
    http_response_code($status);
    echo json_encode(array_merge(['error' => $message], $extra));
    exit;
}

// ---- Autenticação obrigatória ----
// Nenhuma geração sem conta: toda geração é associada a um usuário logado.
$pdo = arch3_db();
$user = current_user();
if (!$user) {
    fail(401, 'Please sign in to generate.', ['code' => 'auth_required']);
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
    fail(500, 'OPENAI_API_KEY não configurada no servidor.');
}
$model   = cfg('OPENAI_IMAGE_MODEL', 'gpt-image-1.5');
$size    = cfg('OPENAI_IMAGE_SIZE', '1536x1024');
$quality = cfg('OPENAI_IMAGE_QUALITY', 'medium');

// Pro: preset de qualidade superior (Higher image quality presets).
if (($user['subscription_plan'] ?? '') === 'pro' && ($user['subscription_status'] ?? '') === 'active') {
    $quality = cfg('OPENAI_IMAGE_QUALITY_PRO', 'high');
}

// ---- Validação de entrada ----
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail(405, 'Método não permitido.');
}
$prompt = isset($_POST['prompt']) ? trim($_POST['prompt']) : '';
if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    fail(400, 'Envie uma imagem panorâmica no campo image.');
}
if ($prompt === '') {
    fail(400, 'Escreva uma frase descrevendo a transformação desejada.');
}

$file = $_FILES['image'];
if ($file['size'] > 20 * 1024 * 1024) {
    fail(413, 'A imagem é grande demais. Use um arquivo menor que 20 MB.');
}
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file['tmp_name']);
$allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
if (!isset($allowed[$mime])) {
    fail(400, 'Formato inválido. Use JPG, JPEG, PNG ou WEBP.');
}

// ---- Prompt base (arquitetônico + panorâmico) ----
$BASE_PROMPT = <<<TXT
You are an AI architectural visualization assistant for Arch3. Edit the provided panoramic interior image according to the user's request while preserving the original room's core structure, perspective, openings, wall positions, ceiling height, floor plan logic and realistic renovation constraints.

Create a redesigned version that feels like a real architectural/interior design proposal, not a fantasy scene.

Important rules:
- Preserve the same room and spatial structure.
- Keep the same general camera position and panoramic feel.
- Respect realistic renovation possibilities.
- Do not change the room so much that it becomes impossible to recognize.
- Follow the user's requested changes closely.
- You may change furniture, colors, materials, lighting, decor, rugs, curtains, plants, art, doors, windows and finishes.
- You may add or remove furniture if the user asks.
- Make the result look premium, realistic, coherent and professionally designed.
- Avoid unrealistic architecture, distorted geometry, impossible windows, warped furniture or fantasy elements.
- Maintain a high-end architectural visualization style.
- Output should work as a 360° panoramic preview if the input is panoramic.
TXT;

$PANORAMIC_PROMPT = <<<TXT
The output should preserve the original panoramic perspective and be suitable for immersive 360° viewing.

Do not crop the scene into a square composition.

Maintain a wide panoramic field of view.

Preserve room geometry and spatial continuity.

The final image should feel like a realistic architectural redesign of the same room and remain compatible with panoramic viewing.
TXT;

$finalPrompt = $BASE_PROMPT . "\n\n" . $PANORAMIC_PROMPT . "\n\nUser request:\n" . $prompt;

// ---- Chamada à OpenAI Images Edit ----
$ch = curl_init('https://api.openai.com/v1/images/edits');
$post = [
    'model' => $model,
    'prompt' => $finalPrompt,
    'size' => $size,
    'quality' => $quality,
    'output_format' => 'png',
    'image' => new CURLFile($file['tmp_name'], $mime, $file['name'] ?: 'panorama.' . $allowed[$mime]),
];
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $post,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey],
    CURLOPT_TIMEOUT => 170,
]);
$resp = curl_exec($ch);
if ($resp === false) {
    fail(502, 'Falha ao contatar a OpenAI: ' . curl_error($ch));
}
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data = json_decode($resp, true);
if ($httpCode !== 200 || !is_array($data)) {
    $msg = $data['error']['message'] ?? ('OpenAI retornou HTTP ' . $httpCode);
    fail(502, $msg);
}
$b64 = $data['data'][0]['b64_json'] ?? null;
if (!$b64) {
    // alguns modelos podem devolver url
    $url = $data['data'][0]['url'] ?? null;
    if ($url) {
        $b64 = base64_encode(file_get_contents($url));
    }
}
if (!$b64) {
    fail(502, 'A OpenAI não retornou uma imagem.');
}
$imageBinary = base64_decode($b64);

// ---- Expansão para panorama ~2:1 (ambient fill via Imagick) ----
list($panoBinary, $w, $h, $expanded) = toPanorama($imageBinary);

// ---- Consome 1 crédito (somente após geração bem-sucedida) ----
// Guard atômico: o UPDATE só decrementa se ainda houver crédito, evitando
// saldo negativo em requisições concorrentes.
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

echo json_encode([
    'imageUrl' => 'data:image/png;base64,' . base64_encode($panoBinary),
    'mimeType' => 'image/png',
    'fileName' => 'arch3-panorama.png',
    'width' => $w,
    'height' => $h,
    'expanded' => $expanded,
    'expansionStrategy' => 'ambient-php',
    'provider' => 'openai',
    'credits_remaining' => $creditsLeft,
]);

/**
 * Replica a estratégia "ambient fill": centro nítido (redesign real) +
 * laterais ambiente desfocadas/escurecidas, num canvas 2:1.
 * Se Imagick não existir, devolve a imagem original (degrada com elegância).
 */
function toPanorama($pngBinary) {
    if (!extension_loaded('imagick')) {
        $size = @getimagesizefromstring($pngBinary);
        return [$pngBinary, $size[0] ?? 0, $size[1] ?? 0, false];
    }

    $im = new Imagick();
    $im->readImageBlob($pngBinary);
    $w = $im->getImageWidth();
    $h = $im->getImageHeight();
    $aspect = $h > 0 ? $w / $h : 1;

    // Já panorâmico o suficiente: normaliza para PNG e retorna.
    if ($aspect >= 1.9) {
        $im->setImageFormat('png');
        $out = $im->getImageBlob();
        $im->clear();
        return [$out, $w, $h, false];
    }

    $targetH = $h;
    $targetW = (int) round($h * 2);

    // Fundo ambiente: cobre o canvas (cover + crop central), desfoca e escurece.
    $bg = clone $im;
    $bg->cropThumbnailImage($targetW, $targetH);      // escala "cover" + corta centralizado
    $bg->gaussianBlurImage(0, 22);                    // desfoque forte
    $bg->modulateImage(52, 100, 100);                 // brilho ~52% (escurece)

    // Primeiro plano: o redesign real, altura cheia, centralizado e nítido.
    $fgW = (int) round($targetH * $aspect);
    $fg = clone $im;
    $fg->resizeImage($fgW, $targetH, Imagick::FILTER_LANCZOS, 1);
    $x = (int) round(($targetW - $fgW) / 2);
    $bg->compositeImage($fg, Imagick::COMPOSITE_OVER, $x, 0);

    $bg->setImageFormat('png');
    $out = $bg->getImageBlob();
    $im->clear(); $fg->clear(); $bg->clear();
    return [$out, $targetW, $targetH, true];
}
