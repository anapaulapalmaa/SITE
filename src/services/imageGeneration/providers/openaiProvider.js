import fs from 'fs';
import path from 'path';
import OpenAI, { toFile } from 'openai';

const EXT_TO_MIME = {
  '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg',
  '.png': 'image/png',
  '.webp': 'image/webp',
};

// Widest size the OpenAI Images API (gpt-image) accepts for edits. The API does
// NOT support true 2:1 panoramic output, so we request the widest landscape and
// expand to a panorama afterwards (see expansion/). Allowed: 1024x1024,
// 1536x1024, 1024x1536, auto.
const DEFAULT_SIZE = '1536x1024';

let client = null;

function getClient() {
  const apiKey = process.env.OPENAI_API_KEY;
  if (!apiKey) {
    throw new Error('OPENAI_API_KEY não configurada. Copie .env.example para .env e preencha a chave.');
  }
  if (!client) {
    client = new OpenAI({ apiKey });
  }
  return client;
}

/**
 * OpenAI implementation of the ImageProvider contract.
 * @param {{ imagePath: string, prompt: string }} params
 * @returns {Promise<{ buffer: Buffer, mimeType: string }>}
 */
export async function generate({ imagePath, prompt }) {
  const model = process.env.OPENAI_IMAGE_MODEL || 'gpt-image-1.5';
  const size = process.env.OPENAI_IMAGE_SIZE || DEFAULT_SIZE;
  // low | medium | high. high ≈ $0.20/img (1536x1024); medium ≈ $0.05; low ≈ $0.013.
  const quality = process.env.OPENAI_IMAGE_QUALITY || 'high';

  const ext = path.extname(imagePath).toLowerCase();
  const type = EXT_TO_MIME[ext] || 'image/png';
  const image = await toFile(fs.createReadStream(imagePath), path.basename(imagePath), { type });

  const response = await getClient().images.edit({
    model,
    image,
    prompt,
    size,
    output_format: 'png',
    quality,
  });

  const generated = response.data?.[0];
  if (!generated) {
    throw new Error('A OpenAI não retornou uma imagem.');
  }

  if (generated.b64_json) {
    return { buffer: Buffer.from(generated.b64_json, 'base64'), mimeType: 'image/png' };
  }

  if (generated.url) {
    const res = await fetch(generated.url);
    const arrayBuffer = await res.arrayBuffer();
    return { buffer: Buffer.from(arrayBuffer), mimeType: 'image/png' };
  }

  throw new Error('A OpenAI respondeu sem URL ou base64 da imagem.');
}

export const openaiProvider = { id: 'openai', generate };
