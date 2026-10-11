// tools/build-hero.mjs — Responsive variants for the homepage brand-board image.
//
// The Vercel PHP runtime has no GD, so anything uploaded is resized in the
// browser (assets/js/upload-optimize.js). Images that ship with the repository
// have no such step, so the hero is pre-flattened here, once, by CI/maintainer:
//
//   npm run build:hero                                   # defaults below
//   node tools/build-hero.mjs path/to/original.jpg out/dir
//
// Default source: assets/img/hero/hero-hawza.jpg (keep a ≥1280px original there;
// it is also the largest rendition, so the folder stays self-contained).
// Writes WebP + JPEG at 640 / 960 / 1280 px wide next to it. jhd_picture()
// (includes/cards.php) builds `<base>-<width>.webp|jpg` URLs relative to
// `assets/`, so the public base is `img/hero/hero-hawza` — keep that naming
// convention if this script changes.
import fs from 'node:fs';
import path from 'node:path';
import sharp from 'sharp';

const source = process.argv[2] ?? 'assets/img/hero/hero-hawza.jpg';
const outDir = process.argv[3] ?? path.dirname(source);
const widths = [640, 960, 1280];
const base = path.basename(source, path.extname(source));

if (!fs.existsSync(source)) {
    console.error(`✗ منبع پیدا نشد: ${source}\n  یک نسخهٔ دست‌کم ${Math.max(...widths)}px آن‌جا بگذارید.`);
    process.exit(1);
}
fs.mkdirSync(outDir, { recursive: true });

const report = [];
for (const width of widths) {
    for (const [ext, opts] of [['webp', { quality: 68, effort: 6 }], ['jpg', { quality: 72, mozjpeg: true }]]) {
        const target = path.join(outDir, `${base}-${width}.${ext}`);
        const info = await sharp(source, { failOn: 'none' })
            .resize({ width, withoutEnlargement: true })
            .toFormat(ext === 'jpg' ? 'jpeg' : 'webp', opts)
            .toFile(target);
        report.push({ file: path.relative(process.cwd(), target), kb: Math.round(info.size / 1024) });
    }
}
console.table(report);
