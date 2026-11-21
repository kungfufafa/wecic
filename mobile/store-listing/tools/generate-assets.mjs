/**
 * Builds the store graphics for WeCIC from the app's own icon and logo:
 *
 *   node mobile/store-listing/tools/generate-assets.mjs
 *
 * Renders HTML in the installed Google Chrome (Playwright, channel "chrome")
 * at the exact pixel sizes each store asks for. Screenshots are framed from
 * raw captures in ../raw/ (see ../raw/README.md); a missing capture becomes a
 * labelled placeholder so the layout can be checked before capturing.
 */
import { chromium } from 'playwright';
import { existsSync, mkdirSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const listing = join(here, '..');
const images = join(listing, '..', 'assets', 'images');
const out = join(listing, 'assets');

const dataUri = (path) => `data:image/png;base64,${readFileSync(path).toString('base64')}`;
const icon = dataUri(join(images, 'icon.png'));
const logo = dataUri(join(images, 'wecic-logo-white.png'));

const BRAND = '#0352A1';
const BRAND_LIGHT = '#6EA8EE';
const GOLD = '#FFC300';
const INK = '#060606';

/** The six screens, in store order. `raw` is the capture in ../raw/. */
const SCREENS = [
  { raw: '01-beranda.png', id: 'Jadwal dan absensi hari ini, sekali lihat', en: "Today's classes and attendance at a glance" },
  { raw: '02-absensi.png', id: 'Absen kelas cukup pindai QR', en: 'Check in to class with a QR scan' },
  { raw: '03-tugas.png', id: 'Tenggat tugas tak lagi terlewat', en: 'Never miss an assignment deadline' },
  { raw: '04-nilai.png', id: 'IPK, IPS, dan KHS dalam genggaman', en: 'GPA and semester grades in your pocket' },
  { raw: '05-keuangan.png', id: 'Tagihan dan riwayat pembayaran', en: 'Bills and payment history' },
  { raw: '06-kuliah.png', id: 'Semua kelas dan jadwal kuliah', en: 'All your classes and schedule' },
];

/** Output sizes: [folder, width, height]. */
const SCREENSHOT_SIZES = [
  ['app-store/iphone-6.9', 1320, 2868],
  ['app-store/iphone-6.5', 1284, 2778],
  ['google-play/phone', 1080, 1920],
];

const base = `
  * { margin: 0; padding: 0; box-sizing: border-box; }
  html, body { width: 100%; height: 100%; }
  body { font-family: -apple-system, "SF Pro Display", system-ui, sans-serif; -webkit-font-smoothing: antialiased; overflow: hidden; }
`;

function iconHtml(size) {
  return `<style>${base} body { background: #111; } img { width: ${size}px; height: ${size}px; display: block; }</style><img src="${icon}">`;
}

function featureGraphicHtml() {
  return `<style>${base}
    html, body { width: 1024px; height: 500px; }
    body { background: radial-gradient(120% 140% at 85% 20%, ${BRAND} 0%, #022A55 45%, ${INK} 100%); color: #fff; display: flex; align-items: center; padding: 0 72px; gap: 56px; }
    .mark { width: 220px; height: 220px; border-radius: 48px; box-shadow: 0 24px 60px rgba(0,0,0,.45); flex: none; }
    .copy { display: flex; flex-direction: column; gap: 20px; }
    .logo { height: 64px; width: auto; align-self: flex-start; }
    h1 { font-size: 46px; line-height: 1.12; font-weight: 700; letter-spacing: -0.5px; }
    h1 em { font-style: normal; color: ${GOLD}; }
    p { font-size: 24px; color: rgba(255,255,255,.75); }
  </style>
  <img class="mark" src="${icon}">
  <div class="copy">
    <img class="logo" src="${logo}">
    <h1>Kuliah lebih rapi,<br><em>semua di satu aplikasi.</em></h1>
    <p>Jadwal · Absensi QR · Tugas · Nilai · Keuangan</p>
  </div>`;
}

function screenshotHtml(width, height, caption, rawUri, label) {
  const scale = width / 1320;
  const phoneWidth = Math.round(Math.min(width * 0.78, (height * 0.7) / (2868 / 1320)));
  const phoneHeight = Math.round(phoneWidth * (2868 / 1320));
  const radius = Math.round(phoneWidth * 0.11);
  const bezel = Math.round(14 * scale);
  const screen = rawUri
    ? `<img class="shot" src="${rawUri}">`
    : `<div class="shot placeholder"><div>Tempel tangkapan layar<br><b>${label}</b><br>di mobile/store-listing/raw/</div></div>`;

  return `<style>${base}
    html, body { width: ${width}px; height: ${height}px; }
    body { background: linear-gradient(180deg, ${BRAND} 0%, #022A55 55%, ${INK} 100%) no-repeat; color: #fff; display: flex; flex-direction: column; align-items: center; padding-top: ${Math.round(height * 0.065)}px; gap: ${Math.round(height * 0.04)}px; }
    h1 { font-size: ${Math.round(92 * scale)}px; line-height: 1.12; font-weight: 700; letter-spacing: -1px; text-align: center; padding: 0 ${Math.round(80 * scale)}px; text-wrap: balance; }
    .phone { width: ${phoneWidth}px; height: ${phoneHeight}px; border-radius: ${radius}px; background: #1c1c1e; padding: ${bezel}px; box-shadow: 0 ${Math.round(40 * scale)}px ${Math.round(120 * scale)}px rgba(0,0,0,.55), inset 0 0 0 ${Math.round(3 * scale)}px #3a3a3c; flex: none; }
    .shot { width: 100%; height: 100%; border-radius: ${radius - bezel}px; object-fit: cover; object-position: top; display: block; }
    .placeholder { background: ${INK}; color: ${BRAND_LIGHT}; display: flex; align-items: center; justify-content: center; text-align: center; font-size: ${Math.round(44 * scale)}px; line-height: 1.5; }
    .placeholder b { color: ${GOLD}; }
  </style>
  <h1>${caption}</h1>
  <div class="phone">${screen}</div>`;
}

async function render(page, html, width, height, path) {
  mkdirSync(dirname(path), { recursive: true });
  await page.setViewportSize({ width, height });
  await page.setContent(html, { waitUntil: 'load' });
  await page.screenshot({ path, omitBackground: false });
  console.log(`✓ ${path.replace(`${listing}/`, '')} (${width}×${height})`);
}

const browser = await chromium.launch({ channel: 'chrome' });
const page = await browser.newPage({ deviceScaleFactor: 1 });

await render(page, iconHtml(512), 512, 512, join(out, 'google-play', 'icon-512.png'));
await render(page, iconHtml(1024), 1024, 1024, join(out, 'app-store', 'icon-1024.png'));
await render(page, featureGraphicHtml(), 1024, 500, join(out, 'google-play', 'feature-graphic-1024x500.png'));

let missing = 0;
for (const [folder, width, height] of SCREENSHOT_SIZES) {
  for (const lang of ['id', 'en']) {
    for (const [index, screen] of SCREENS.entries()) {
      const rawPath = join(listing, 'raw', screen.raw);
      const rawUri = existsSync(rawPath) ? dataUri(rawPath) : null;
      if (!rawUri && folder === SCREENSHOT_SIZES[0][0] && lang === 'id') {
        missing++;
      }
      const name = `${String(index + 1).padStart(2, '0')}.png`;
      await render(page, screenshotHtml(width, height, screen[lang], rawUri, screen.raw), width, height, join(out, folder, lang, name));
    }
  }
}

await browser.close();

if (missing > 0) {
  console.log(`\n${missing} of ${SCREENS.length} raw captures are missing; their screenshots are placeholders. See mobile/store-listing/raw/README.md.`);
}
