import express from 'express';
import cors from 'cors';
import dotenv from 'dotenv';
import path from 'path';
import { fileURLToPath } from 'url';
import { generateRouter } from './routes/generate.js';
import { generateRedesignRouter } from './routes/generateRedesign.js';

dotenv.config();

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const projectRoot = path.resolve(__dirname, '..');
const app = express();
const port = process.env.PORT || 3000;

app.use(cors());
app.use(express.json({ limit: '2mb' }));
app.use(express.urlencoded({ extended: true }));

app.use('/assets', express.static(path.join(projectRoot, 'assets')));
app.use('/uploads', express.static(path.join(__dirname, 'uploads')));

app.get('/', (_req, res) => {
  res.sendFile(path.join(projectRoot, 'index.html'));
});

app.get(['/generate360', '/playground'], (_req, res) => {
  res.sendFile(path.join(projectRoot, 'generate360.html'));
});

app.get(['/try-it', '/try-it.html'], (_req, res) => {
  res.sendFile(path.join(projectRoot, 'try-it.html'));
});

// Lightweight health check so the frontend can confirm it's actually talking to
// the Express backend (and not Live Server / file://).
app.get('/api/health', (_req, res) => {
  res.json({ ok: true, hasOpenAiKey: Boolean(process.env.OPENAI_API_KEY) });
});

app.use('/api', generateRouter);
app.use('/api', generateRedesignRouter);
app.use(express.static(projectRoot));

app.use((error, _req, res, _next) => {
  console.error(error);
  res.status(500).json({
    error: 'Não foi possível processar a solicitação.',
  });
});

app.listen(port, () => {
  console.log(`Arch3 backend running on http://localhost:${port}`);
});