import express from 'express';
import multer from 'multer';
import fs from 'fs/promises';
import path from 'path';
import { fileURLToPath } from 'url';
import { generatePanoramicRedesign } from '../../src/services/imageGeneration/index.js';

const router = express.Router();
const upload = multer({
  storage: multer.memoryStorage(),
  limits: {
    fileSize: 20 * 1024 * 1024,
  },
  fileFilter: (_req, file, callback) => {
    const allowedMimeTypes = new Set(['image/jpeg', 'image/png', 'image/webp']);
    if (!allowedMimeTypes.has(file.mimetype)) {
      callback(new Error('Formato inválido. Use JPG, JPEG, PNG ou WEBP.'));
      return;
    }

    callback(null, true);
  },
});

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const uploadsDir = path.resolve(__dirname, '..', 'uploads');

const sanitizeFilename = (name) =>
  name
    .replace(/[^a-z0-9._-]+/gi, '-')
    .replace(/-+/g, '-')
    .replace(/^-|-$/g, '')
    .slice(0, 80) || 'panorama';

router.post('/generate-redesign', upload.single('image'), async (req, res) => {
  const prompt = typeof req.body?.prompt === 'string' ? req.body.prompt.trim() : '';

  if (!req.file) {
    return res.status(400).json({
      error: 'Envie uma imagem panorâmica no campo image.',
    });
  }

  if (!prompt) {
    return res.status(400).json({
      error: 'Escreva uma frase descrevendo a transformação desejada.',
    });
  }

  const tempFileName = `${Date.now()}-${sanitizeFilename(req.file.originalname || 'panorama')}`;
  const tempFilePath = path.join(uploadsDir, tempFileName);

  await fs.mkdir(uploadsDir, { recursive: true });
  await fs.writeFile(tempFilePath, req.file.buffer);

  try {
    const result = await generatePanoramicRedesign({ imagePath: tempFilePath, userPrompt: prompt });

    return res.json({
      ...result,
      prompt,
      sourceFileName: req.file.originalname,
    });
  } catch (error) {
    console.error('OpenAI redesign generation failed:', error);
    return res.status(500).json({
      error:
        error instanceof Error
          ? error.message
          : 'Falha ao gerar a nova imagem.',
    });
  } finally {
    await fs.unlink(tempFilePath).catch(() => {});
  }
});

router.use((error, _req, res, _next) => {
  const message = error instanceof Error ? error.message : 'Falha no upload da imagem.';
  const status = message.includes('File too large') ? 413 : 400;

  res.status(status).json({
    error:
      status === 413
        ? 'A imagem é grande demais. Use um arquivo menor que 20 MB.'
        : message,
  });
});

export { router as generateRedesignRouter };
