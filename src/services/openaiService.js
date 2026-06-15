import fs from 'fs';
import path from 'path';
import OpenAI, { toFile } from 'openai';

const EXT_TO_MIME = {
  '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg',
  '.png': 'image/png',
  '.webp': 'image/webp',
};

// Read env lazily (inside the call) so the key is resolved AFTER dotenv has
// loaded — ES module imports are evaluated before the server body runs
// dotenv.config(), so reading at module top-level would see an empty key.
let client = null;

function getClient() {
  const apiKey = process.env.OPENAI_API_KEY;

  if (!apiKey) {
    throw new Error(
      'OPENAI_API_KEY não configurada. Copie .env.example para .env e preencha a chave.',
    );
  }

  if (!client) {
    client = new OpenAI({ apiKey });
  }

  return client;
}

export async function generateImageFromPrompt(imagePath, prompt) {
  const model = process.env.OPENAI_IMAGE_MODEL || 'gpt-image-1.5';
  const ext = path.extname(imagePath).toLowerCase();
  const type = EXT_TO_MIME[ext] || 'image/png';
  // Wrap the file so the SDK sends a proper image mimetype instead of
  // application/octet-stream (which the Images API rejects).
  const image = await toFile(fs.createReadStream(imagePath), path.basename(imagePath), { type });

  const response = await getClient().images.edit({
    model,
    image,
    prompt,
    output_format: 'png',
    quality: 'high',
    size: '1024x1024',
  });

  const generatedImage = response.data?.[0];

  if (!generatedImage) {
    throw new Error('A OpenAI não retornou uma imagem.');
  }

  if (generatedImage.b64_json) {
    return {
      imageUrl: `data:image/png;base64,${generatedImage.b64_json}`,
      mimeType: 'image/png',
      fileName: 'arch3-generated.png',
      imageBase64: generatedImage.b64_json,
    };
  }

  if (generatedImage.url) {
    return {
      imageUrl: generatedImage.url,
      mimeType: 'image/png',
      fileName: 'arch3-generated.png',
    };
  }

  throw new Error('A OpenAI respondeu sem URL ou base64 da imagem.');
}