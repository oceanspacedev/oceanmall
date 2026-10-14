// Generates the two store listing graphics that are not screenshots:
//   store/play-icon-512.png             Google Play hi-res icon, 512x512 PNG
//   store/feature-graphic-1024x500.png  Google Play feature graphic, 1024x500 PNG (no alpha)
// Run from mobile/: node scripts/store-assets.js [outDir]
// Re-run after scripts/brand-assets.js changes the icon or the white lockup.
// Uses jimp-compact (a devDependency), no native deps.
const Jimp = require('jimp-compact');
const fs = require('fs');
const path = require('path');
const zlib = require('zlib');

const ROOT = path.resolve(__dirname, '..');
const IMAGES = path.join(ROOT, 'assets', 'images');
const OUT = path.resolve(process.argv[2] || path.join(ROOT, 'store'));

const NAVY = Jimp.rgbaToInt(10, 42, 107, 255); // #0a2a6b (om-navy)
const FEATURE_WIDTH = 1024;
const FEATURE_HEIGHT = 500;
// Share of the feature graphic height the lockup may use. Play crops the edges on some surfaces,
// so keep generous margins around it.
const LOCKUP_MAX_HEIGHT = 340;

// jimp-compact can only write RGBA PNGs, and Play rejects a feature graphic with an alpha
// channel, so encode the (fully opaque) canvas as a plain 24-bit RGB PNG ourselves.
const CRC_TABLE = Array.from({ length: 256 }, (_, n) => {
  let c = n;
  for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
  return c >>> 0;
});

function crc32(buffer) {
  let c = 0xffffffff;
  for (const byte of buffer) c = CRC_TABLE[(c ^ byte) & 0xff] ^ (c >>> 8);
  return (c ^ 0xffffffff) >>> 0;
}

function pngChunk(type, data) {
  const length = Buffer.alloc(4);
  length.writeUInt32BE(data.length);
  const body = Buffer.concat([Buffer.from(type, 'ascii'), data]);
  const crc = Buffer.alloc(4);
  crc.writeUInt32BE(crc32(body));
  return Buffer.concat([length, body, crc]);
}

function encodeRgbPng({ width, height, data }) {
  const stride = width * 3 + 1;
  const raw = Buffer.alloc(stride * height); // each row starts with filter byte 0 (none)
  for (let y = 0; y < height; y++) {
    for (let x = 0; x < width; x++) {
      const from = (y * width + x) * 4;
      const to = y * stride + 1 + x * 3;
      raw[to] = data[from];
      raw[to + 1] = data[from + 1];
      raw[to + 2] = data[from + 2];
    }
  }
  const header = Buffer.alloc(13);
  header.writeUInt32BE(width, 0);
  header.writeUInt32BE(height, 4);
  header[8] = 8; // bit depth
  header[9] = 2; // colour type 2 = RGB, no alpha
  return Buffer.concat([
    Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
    pngChunk('IHDR', header),
    pngChunk('IDAT', zlib.deflateSync(raw, { level: 9 })),
    pngChunk('IEND', Buffer.alloc(0)),
  ]);
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });

  // 1. Play icon: the full-bleed app icon (Play applies its own mask), 32-bit PNG under 1 MB.
  const icon = await Jimp.read(path.join(IMAGES, 'icon.png'));
  await icon
    .clone()
    .resize(512, 512, Jimp.RESIZE_BICUBIC)
    .writeAsync(path.join(OUT, 'play-icon-512.png'));

  // 2. Feature graphic: brand navy with the white mark + wordmark lockup centred.
  const lockup = await Jimp.read(path.join(IMAGES, 'logo-lockup-white.png'));
  lockup.scaleToFit(560, LOCKUP_MAX_HEIGHT, Jimp.RESIZE_BICUBIC);

  const canvas = new Jimp(FEATURE_WIDTH, FEATURE_HEIGHT, NAVY);
  canvas.composite(
    lockup,
    Math.round((FEATURE_WIDTH - lockup.bitmap.width) / 2),
    Math.round((FEATURE_HEIGHT - lockup.bitmap.height) / 2)
  );
  fs.writeFileSync(path.join(OUT, 'feature-graphic-1024x500.png'), encodeRgbPng(canvas.bitmap));

  console.log(
    `wrote ${path.relative(ROOT, OUT) || '.'}/play-icon-512.png and feature-graphic-1024x500.png`
  );
})().catch((error) => {
  console.error(error);
  process.exit(1);
});
