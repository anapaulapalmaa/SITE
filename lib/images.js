/**
 * Arch3 — geração de imagem via OpenAI Images (edits) + preparação de panorama
 * SEM preenchimento artificial (nada de blur/stretch nas laterais).
 *
 * A imagem da IA volta no aspecto nativo (~1.5:1). Apenas detectamos se é
 * panorâmica de verdade (>= 1.9) para o frontend decidir entre 360 direto ou
 * versão limpa + 360 opcional. A expansão real (outpainting) fica para depois.
 */
import OpenAI, { toFile } from 'openai';
import sharp from 'sharp';

const PANO_OK = 1.9;

let client = null;
function getClient() {
  const apiKey = process.env.OPENAI_API_KEY;
  if (!apiKey) throw new Error('OPENAI_API_KEY não configurada no servidor.');
  if (!client) client = new OpenAI({ apiKey });
  return client;
}

const BASE_PROMPT = `You are an AI architectural visualization assistant for Arch3. Edit the provided panoramic interior image according to the user's request while preserving the original room's core structure, perspective, openings, wall positions, ceiling height, floor plan logic and realistic renovation constraints.

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
- Output should work as a 360° panoramic preview if the input is panoramic.`;

const PANORAMIC_PROMPT = `The output should preserve the original panoramic perspective and be suitable for immersive 360° viewing.

Do not crop the scene into a square composition.

Strict framing rules:
- The output must be a wide panoramic image.
- Preserve the panoramic field of view of the input; do not zoom into a narrow part of the room.
- Produce a single coherent wide interior scene suitable for 360° viewing.
- Fill the entire frame with real, continuous architectural content edge to edge.
- Do NOT add blurred side filling, vignettes or dark borders.
- Do NOT stretch, smear or duplicate pixels at the edges to widen the image.
- Avoid artificial stretched borders or out-of-focus padding of any kind.

Preserve room geometry and spatial continuity.

The final image should feel like a realistic architectural redesign of the same room and remain compatible with panoramic viewing.`;

export async function generateRedesign({ buffer, mimeType, filename, prompt, highQuality = false }) {
  const model = process.env.OPENAI_IMAGE_MODEL || 'gpt-image-1.5';
  const size = process.env.OPENAI_IMAGE_SIZE || '1536x1024';
  let quality = process.env.OPENAI_IMAGE_QUALITY || 'medium';
  if (highQuality) quality = process.env.OPENAI_IMAGE_QUALITY_PRO || 'high';

  const finalPrompt = `${BASE_PROMPT}\n\n${PANORAMIC_PROMPT}\n\nUser request:\n${prompt}`;

  const image = await toFile(buffer, filename || 'panorama.png', { type: mimeType || 'image/png' });
  const response = await getClient().images.edit({
    model,
    image,
    prompt: finalPrompt,
    size,
    output_format: 'png',
    quality,
  });

  const generated = response.data?.[0];
  let outBuf;
  if (generated?.b64_json) {
    outBuf = Buffer.from(generated.b64_json, 'base64');
  } else if (generated?.url) {
    const r = await fetch(generated.url);
    outBuf = Buffer.from(await r.arrayBuffer());
  } else {
    throw new Error('A OpenAI não retornou uma imagem.');
  }

  // Preparação SEM blur/stretch: só medimos o aspecto.
  let width = 0;
  let height = 0;
  try {
    const meta = await sharp(outBuf).metadata();
    width = meta.width || 0;
    height = meta.height || 0;
  } catch {
    /* metadados indisponíveis -> aspecto 0 */
  }
  const aspect = height > 0 ? width / height : 0;

  return {
    buffer: outBuf,
    width,
    height,
    aspect: Math.round(aspect * 1000) / 1000,
    panoramic: aspect >= PANO_OK,
  };
}
