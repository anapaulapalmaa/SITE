import { buildRedesignPrompt } from './prompt.js';
import { expandToPanorama } from './expansion/index.js';
import { openaiProvider } from './providers/openaiProvider.js';

// =============================================================================
// Image generation abstraction
// -----------------------------------------------------------------------------
// The rest of the app talks to generatePanoramicRedesign() and never to a
// specific vendor. To add a new provider (Stability AI, Flux, Replicate, or a
// panorama-specific model):
//
//   1. Create providers/<name>Provider.js exporting:
//        export const <name>Provider = {
//          id: '<name>',
//          async generate({ imagePath, prompt }) { return { buffer, mimeType }; }
//        };
//   2. Register it in PROVIDERS below.
//   3. Set IMAGE_PROVIDER=<name> in .env.
//
// The prompt rules (prompt.js) and the panorama expansion (expansion/) are
// shared across every provider, so the user-facing behavior stays identical.
// =============================================================================

const PROVIDERS = {
  openai: openaiProvider,
  // stability: stabilityProvider,
  // flux: fluxProvider,
  // replicate: replicateProvider,
};

function getProvider() {
  const id = process.env.IMAGE_PROVIDER || 'openai';
  const provider = PROVIDERS[id];
  if (!provider) {
    throw new Error(
      `Provider de imagem desconhecido: "${id}". Opções: ${Object.keys(PROVIDERS).join(', ')}.`,
    );
  }
  return provider;
}

/**
 * Generate a panoramic architectural redesign of a room.
 * @param {{ imagePath: string, userPrompt: string }} params
 * @returns {Promise<{ imageUrl: string, mimeType: string, width: number, height: number, provider: string, expanded: boolean, expansionStrategy: string }>}
 */
export async function generatePanoramicRedesign({ imagePath, userPrompt }) {
  const provider = getProvider();
  const prompt = buildRedesignPrompt(userPrompt);

  const { buffer } = await provider.generate({ imagePath, prompt });

  // Always normalize/expand into a wide ~2:1 panorama before it reaches the
  // viewer — Arch3 never serves a plain square image. The expansion strategy
  // (ambient fill today; outpainting/panorama model later) is swappable.
  const { buffer: panoBuffer, width, height, expanded, strategy } = await expandToPanorama(buffer);
  const base64 = panoBuffer.toString('base64');

  return {
    imageUrl: `data:image/png;base64,${base64}`,
    mimeType: 'image/png',
    fileName: 'arch3-panorama.png',
    width,
    height,
    expanded,
    expansionStrategy: strategy,
    provider: provider.id,
  };
}
