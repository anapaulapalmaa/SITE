import { useEffect, useMemo, useState } from 'react';
import UploadArea from '../components/UploadArea.jsx';
import PromptForm from '../components/PromptForm.jsx';
import PanoramaViewer from '../components/PanoramaViewer.jsx';

const loadingMessages = [
  'Analyzing room...',
  'Understanding layout...',
  'Generating architectural concept...',
  'Rendering spatial preview...',
];

export default function Generate360() {
  const [selectedFile, setSelectedFile] = useState(null);
  const [previewUrl, setPreviewUrl] = useState('');
  const [prompt, setPrompt] = useState('');
  const [generatedImageUrl, setGeneratedImageUrl] = useState('');
  const [error, setError] = useState('');
  const [isLoading, setIsLoading] = useState(false);
  const [loadingIndex, setLoadingIndex] = useState(0);

  useEffect(() => {
    if (!selectedFile) {
      setPreviewUrl('');
      return undefined;
    }

    const objectUrl = URL.createObjectURL(selectedFile);
    setPreviewUrl(objectUrl);

    return () => URL.revokeObjectURL(objectUrl);
  }, [selectedFile]);

  useEffect(() => {
    if (!isLoading) return undefined;

    const interval = window.setInterval(() => {
      setLoadingIndex((current) => (current + 1) % loadingMessages.length);
    }, 1600);

    return () => window.clearInterval(interval);
  }, [isLoading]);

  const canGenerate = useMemo(() => Boolean(selectedFile && prompt.trim()), [selectedFile, prompt]);

  const handleSubmit = async (event) => {
    event.preventDefault();

    if (!selectedFile) {
      setError('Choose a panorama image first.');
      return;
    }

    if (!prompt.trim()) {
      setError('Write a prompt before generating.');
      return;
    }

    setError('');
    setIsLoading(true);
    setGeneratedImageUrl('');

    try {
      const formData = new FormData();
      formData.append('image', selectedFile);
      formData.append('prompt', prompt.trim());

      const response = await fetch('/api/generate', {
        method: 'POST',
        body: formData,
      });

      const data = await response.json();

      if (!response.ok) {
        throw new Error(data.error || 'Generation failed.');
      }

      setGeneratedImageUrl(data.imageUrl);
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Generation failed.');
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <main className="generate360-page">
      <section className="generate360-hero">
        <div className="hero-copy">
          <p className="eyebrow">Arch3 playground</p>
          <h1>Upload a room, describe the change, and explore the result in 360°.</h1>
          <p>
            This isolated page is the MVP sandbox for panorama upload, prompt-based generation,
            and immersive viewing on mobile and desktop.
          </p>
        </div>

        <div className="hero-status-card">
          <span className="status-pill">Ready for testing</span>
          <p>Backend: Express + Multer + OpenAI</p>
          <p>Viewer: Photo Sphere Viewer + gyroscope</p>
        </div>
      </section>

      <section className="generate360-layout">
        <div className="generate360-panel">
          <UploadArea
            onFileSelected={setSelectedFile}
            previewUrl={previewUrl}
            error={error}
          />

          <PromptForm
            prompt={prompt}
            onPromptChange={setPrompt}
            onSubmit={handleSubmit}
            disabled={!canGenerate || isLoading}
          />

          {isLoading && (
            <div className="loading-card">
              <span className="loading-spinner" />
              <p>{loadingMessages[loadingIndex]}</p>
            </div>
          )}
        </div>

        <div className="generate360-result">
          {generatedImageUrl ? (
            <PanoramaViewer imageUrl={generatedImageUrl} />
          ) : (
            <div className="empty-state">
              <p className="eyebrow">Result</p>
              <h2>Your generated panorama will appear here.</h2>
              <p>After generation, the viewer opens with mouse drag, zoom, and mobile gyroscope support.</p>
            </div>
          )}
        </div>
      </section>
    </main>
  );
}