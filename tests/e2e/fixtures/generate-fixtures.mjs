/**
 * Generate E2E photo fixtures (run via `npm run e2e:fixtures`).
 * Creates a ~12 MP progressive JPEG (3024×4032) plus small/wrong helpers.
 *
 * The 12 MP fixture must be >900 KB so the client-side downscale path runs
 * (see resources/js/app.js SKIP_BELOW_BYTES) and ideally <2.5 MB for CI.
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import sharp from 'sharp';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const outDir = __dirname;

async function writeProgressiveJpeg(name, width, height, { quality = 82, noisy = false } = {}) {
  let pipeline;

  if (noisy) {
    // Mild chroma noise — large enough after JPEG for client-downscale, not multi-MB.
    const raw = Buffer.alloc(width * height * 3);
    for (let y = 0; y < height; y++) {
      for (let x = 0; x < width; x++) {
        const i = (y * width + x) * 3;
        const n = ((x * 73 + y * 149) ^ (x * y)) & 0xff;
        raw[i] = 110 + (n % 40);
        raw[i + 1] = 120 + ((n >> 2) % 40);
        raw[i + 2] = 100 + ((n >> 4) % 40);
      }
    }
    pipeline = sharp(raw, { raw: { width, height, channels: 3 } });
  } else {
    const svg = Buffer.from(`
      <svg width="${width}" height="${height}" xmlns="http://www.w3.org/2000/svg">
        <defs>
          <linearGradient id="g" x1="0" y1="0" x2="1" y2="1">
            <stop offset="0%" stop-color="#c8d2cc"/>
            <stop offset="100%" stop-color="#6f7f74"/>
          </linearGradient>
        </defs>
        <rect width="100%" height="100%" fill="url(#g)"/>
        <text x="6%" y="10%" font-size="${Math.round(width / 18)}" fill="#18201d" font-family="sans-serif">${name}</text>
      </svg>
    `);
    pipeline = sharp(svg);
  }

  const buf = await pipeline.jpeg({ quality, progressive: true, mozjpeg: true }).toBuffer();
  const target = path.join(outDir, name);
  fs.writeFileSync(target, buf);
  const mb = (buf.length / (1024 * 1024)).toFixed(2);
  console.log(`wrote ${name} (${width}x${height}, ${mb} MB, progressive)`);
  return buf.length;
}

async function writeSized12mp() {
  // Binary-ish search on quality to land in [0.95 MB, 2.4 MB].
  let lo = 40;
  let hi = 90;
  let best = null;
  for (let i = 0; i < 8; i++) {
    const q = Math.round((lo + hi) / 2);
    const bytes = await writeProgressiveJpeg('fusebox-12mp-progressive.jpg', 3024, 4032, {
      quality: q,
      noisy: true,
    });
    best = bytes;
    if (bytes < 950 * 1024) {
      lo = q + 1;
    } else if (bytes > 2.4 * 1024 * 1024) {
      hi = q - 1;
    } else {
      return bytes;
    }
  }
  return best;
}

async function main() {
  const bytes = await writeSized12mp();
  if (bytes < 900 * 1024) {
    throw new Error(`12 MP fixture too small (${bytes} bytes); client downscale would skip it`);
  }
  if (bytes > 2.5 * 1024 * 1024) {
    console.warn(`warning: 12 MP fixture is ${(bytes / 1024 / 1024).toFixed(2)} MB (>2.5)`);
  }

  await writeProgressiveJpeg('room-overview-good.jpg', 1600, 1200, { quality: 85 });
  await writeProgressiveJpeg('wrong-subject-outdoor.jpg', 1600, 1200, { quality: 85 });
  await writeProgressiveJpeg('too-small-400.jpg', 400, 300, { quality: 70 });
  await writeProgressiveJpeg('facade-around-house.jpg', 1800, 1200, { quality: 85 });
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
