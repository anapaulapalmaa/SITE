import sharp from 'sharp';

// MVP panorama expansion strategy: "ambient fill".
//
// This is intentionally NOT AI outpainting. The real redesign stays sharp and
// centered; the side margins are filled with a blurred, darkened copy of the
// same scene. That gives horizontal continuity for the 360° wrap and reads as
// an immersive panorama rather than a letterboxed square — fast, free, no extra
// API calls.
//
// It implements the ExpansionStrategy contract (see ./index.js): a future
// strategy (OpenAI outpaint, Stability, Replicate/Flux, native panorama model)
// just exports the same `expand(buffer, options)` shape and the rest of the
// pipeline is untouched.

const TARGET_ASPECT = 2; // 2:1 equirectangular-friendly ratio.

async function expand(inputBuffer, { minAspect = 1.9, sideBlur = 42, sideDim = 0.5 } = {}) {
  const meta = await sharp(inputBuffer).metadata();
  const width = meta.width || 0;
  const height = meta.height || 0;

  if (!width || !height) {
    return { buffer: await sharp(inputBuffer).png().toBuffer(), width, height, expanded: false };
  }

  const aspect = width / height;

  // Already panoramic enough: just normalize to PNG, no expansion needed.
  if (aspect >= minAspect) {
    return { buffer: await sharp(inputBuffer).png().toBuffer(), width, height, expanded: false };
  }

  // Build a 2:1 canvas keyed off the source height.
  const targetH = height;
  const targetW = Math.round(height * TARGET_ASPECT);

  // Ambient side fill: cover the full canvas with a heavily blurred + dimmed
  // copy of the scene so the periphery feels like a continuation of the room.
  const background = await sharp(inputBuffer)
    .resize(targetW, targetH, { fit: 'cover', position: 'centre' })
    .blur(sideBlur)
    .modulate({ brightness: sideDim })
    .toBuffer();

  // Foreground: the real redesign, full height, centered and crisp.
  const fgWidth = Math.round(targetH * aspect);
  const foreground = await sharp(inputBuffer)
    .resize(fgWidth, targetH, { fit: 'fill' })
    .toBuffer();

  const buffer = await sharp(background)
    .composite([{ input: foreground, gravity: 'centre' }])
    .png()
    .toBuffer();

  return { buffer, width: targetW, height: targetH, expanded: true };
}

export const ambientFill = { id: 'ambient', expand };
