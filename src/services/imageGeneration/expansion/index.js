import { ambientFill } from './ambientFill.js';

// =============================================================================
// Panorama expansion strategies
// -----------------------------------------------------------------------------
// Turns a provider's (often non-panoramic) output into a wide ~2:1 panorama.
//
// The MVP ships a single strategy — 'ambient' (blurred ambient side fill, no AI).
// To upgrade the side expansion later, add a strategy file exporting:
//
//     export const <name> = {
//       id: '<name>',
//       async expand(buffer, options) { return { buffer, width, height, expanded }; }
//     };
//
// register it below, and set PANORAMA_EXPANSION=<name> in .env. Nothing else in
// the pipeline (route, viewer, UI) changes. Planned future strategies:
//   - 'outpaint-openai' : AI outpainting of the side margins via OpenAI
//   - 'stability'       : Stability AI outpaint
//   - 'replicate'       : Replicate / Flux outpaint model
//   - 'panorama-native' : a panorama-specific model (may skip expansion entirely)
// =============================================================================

const STRATEGIES = {
  ambient: ambientFill,
  // 'outpaint-openai': outpaintOpenAI,
  // stability: stabilityOutpaint,
  // replicate: replicateOutpaint,
  // 'panorama-native': passthrough,
};

export function getExpansionStrategy() {
  const id = process.env.PANORAMA_EXPANSION || 'ambient';
  const strategy = STRATEGIES[id];
  if (!strategy) {
    throw new Error(
      `Estratégia de expansão panorâmica desconhecida: "${id}". Opções: ${Object.keys(STRATEGIES).join(', ')}.`,
    );
  }
  return strategy;
}

export async function expandToPanorama(buffer, options) {
  const strategy = getExpansionStrategy();
  const result = await strategy.expand(buffer, options);
  return { ...result, strategy: strategy.id };
}
