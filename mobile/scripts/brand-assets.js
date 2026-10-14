// Generates the app icon, Android adaptive icon layers, splash lockup and in-app
// logos (mark, lockup, wordmark) under assets/images from the web brand files in ../public/images.
// Run from mobile/: node scripts/brand-assets.js [outDir] [previewDir]
// Uses jimp-compact (a devDependency), no native deps.
const Jimp = require('jimp-compact');
const path = require('path');
const ROOT = path.resolve(__dirname, '..');
const SRC = path.resolve(ROOT, '..', 'public', 'images');
const OUT = process.argv[2] || path.join(ROOT, 'assets', 'images');

const NAVY = { r: 10, g: 42, b: 107 }; // #0a2a6b (om-navy)
const GRAD_A = { r: 20, g: 140, b: 255 }; // top-left
const GRAD_B = { r: 9, g: 58, b: 170 }; // bottom-right

function trim(img, threshold = 20) {
  const { width, height, data } = img.bitmap;
  let minX = width, minY = height, maxX = -1, maxY = -1;
  img.scan(0, 0, width, height, (x, y, idx) => {
    if (data[idx + 3] > threshold) {
      if (x < minX) minX = x;
      if (x > maxX) maxX = x;
      if (y < minY) minY = y;
      if (y > maxY) maxY = y;
    }
  });
  return img.clone().crop(minX, minY, maxX - minX + 1, maxY - minY + 1);
}

function tint(img, { r, g, b }) {
  const out = img.clone();
  out.scan(0, 0, out.bitmap.width, out.bitmap.height, (x, y, idx) => {
    out.bitmap.data[idx] = r;
    out.bitmap.data[idx + 1] = g;
    out.bitmap.data[idx + 2] = b;
  });
  return out;
}

function gradient(size, a, b) {
  const img = new Jimp(size, size, 0xffffffff);
  img.scan(0, 0, size, size, (x, y, idx) => {
    const t = (x + y) / (2 * (size - 1));
    img.bitmap.data[idx] = Math.round(a.r + (b.r - a.r) * t);
    img.bitmap.data[idx + 1] = Math.round(a.g + (b.g - a.g) * t);
    img.bitmap.data[idx + 2] = Math.round(a.b + (b.b - a.b) * t);
    img.bitmap.data[idx + 3] = 255;
  });
  return img;
}

function solid(size, { r, g, b }, alpha = 255) {
  return new Jimp(size, size, Jimp.rgbaToInt(r, g, b, alpha));
}

function fit(img, w, h) {
  const out = img.clone();
  out.scaleToFit(w, h, Jimp.RESIZE_BICUBIC);
  return out;
}

function center(canvas, layer, dy = 0) {
  const x = Math.round((canvas.bitmap.width - layer.bitmap.width) / 2);
  const y = Math.round((canvas.bitmap.height - layer.bitmap.height) / 2) + dy;
  return canvas.composite(layer, x, y);
}

(async () => {
  const markSrc = trim(await Jimp.read(`${SRC}/logo-icon-transparent.png`));
  const lockupFull = await Jimp.read(`${SRC}/logo-transparent.png`);
  // Drop the tagline row band (y >= 790) so the lockup is mark + wordmark only.
  const lockupSrc = trim(lockupFull.clone().crop(0, 0, lockupFull.bitmap.width, 790));

  const markWhite = tint(markSrc, { r: 255, g: 255, b: 255 });
  const lockupWhite = tint(lockupSrc, { r: 255, g: 255, b: 255 });

  // 1. iOS / generic app icon: gradient background + white glyph (~70% of canvas).
  await center(gradient(1024, GRAD_A, GRAD_B), fit(markWhite, 720, 720)).writeAsync(`${OUT}/icon.png`);

  // 2. Android adaptive icon layers: glyph inside the 66% safe zone, gradient background, monochrome.
  await center(solid(1024, { r: 0, g: 0, b: 0 }, 0), fit(markWhite, 600, 600)).writeAsync(`${OUT}/adaptive-icon.png`);
  await gradient(1024, GRAD_A, GRAD_B).writeAsync(`${OUT}/adaptive-icon-bg.png`);
  await center(solid(1024, { r: 0, g: 0, b: 0 }, 0), fit(tint(markSrc, { r: 0, g: 0, b: 0 }), 600, 600)).writeAsync(`${OUT}/adaptive-icon-mono.png`);

  // 3. Splash: white lockup on transparent (background colour comes from app.json).
  await lockupWhite.writeAsync(`${OUT}/splash-icon.png`);

  // 4. Web favicon.
  await center(gradient(196, GRAD_A, GRAD_B), fit(markWhite, 140, 140)).writeAsync(`${OUT}/favicon.png`);

  // 5. In-app logos.
  await lockupWhite.writeAsync(`${OUT}/logo-lockup-white.png`);
  await lockupSrc.writeAsync(`${OUT}/logo-lockup.png`);
  await fit(markSrc, 512, 512).writeAsync(`${OUT}/logo-mark.png`);
  await fit(markWhite, 512, 512).writeAsync(`${OUT}/logo-mark-white.png`);

  // Wordmark only (the OCEANMALL band, rows 684-783 of the 1024px source; the tagline starts at
  // row 805). Used next to the mark in BrandLogo. trim() drops the transparent padding.
  const wordmark = trim(lockupFull.clone().crop(0, 684, lockupFull.bitmap.width, 100));
  await wordmark.writeAsync(`${OUT}/logo-wordmark.png`);
  await tint(wordmark, { r: 255, g: 255, b: 255 }).writeAsync(`${OUT}/logo-wordmark-white.png`);

  // Preview sheet for eyeballing: icon, adaptive composite, splash on navy.
  const sheet = new Jimp(1024 * 3, 1024, 0xeeeeeeff);
  sheet.composite(await Jimp.read(`${OUT}/icon.png`), 0, 0);
  const adaptive = (await Jimp.read(`${OUT}/adaptive-icon-bg.png`)).composite(await Jimp.read(`${OUT}/adaptive-icon.png`), 0, 0);
  // Android shows roughly the centre 72/108 of the adaptive layers; mask to a circle for preview.
  const r = 1024 * 0.37;
  adaptive.scan(0, 0, 1024, 1024, (x, y, idx) => {
    const dx = x - 512, dy = y - 512;
    if (dx * dx + dy * dy > r * r) adaptive.bitmap.data[idx + 3] = 0;
  });
  sheet.composite(adaptive, 1024, 0);
  sheet.composite(center(solid(1024, NAVY), fit(lockupWhite, 420, 420)), 2048, 0);
  if (process.argv[3]) await sheet.writeAsync(`${process.argv[3]}/_preview.png`);
  console.log('done');
})().catch((e) => { console.error(e); process.exit(1); });
