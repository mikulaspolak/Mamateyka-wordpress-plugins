import { createHash } from 'node:crypto';
import { mkdir, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { XMLParser } from 'fast-xml-parser';
import { chromium } from 'playwright';

const sitemapUrl = process.env.SITEMAP_URL ?? 'https://mamateyka.sk/page-sitemap.xml';
const outputRoot = path.resolve(process.env.OUTPUT_DIR ?? './screenshots');
const timeZone = process.env.SCREENSHOT_TIME_ZONE ?? 'Europe/Bratislava';

function getCaptureDate(date = new Date()) {
  const parts = new Intl.DateTimeFormat('en-US', {
    timeZone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).formatToParts(date);
  const values = Object.fromEntries(parts.map(({ type, value }) => [type, value]));
  return `${values.year}-${values.month}-${values.day}`;
}

async function getPageUrls() {
  const response = await fetch(sitemapUrl, { signal: AbortSignal.timeout(30_000) });
  if (!response.ok) {
    throw new Error(`Could not fetch sitemap (${response.status} ${response.statusText}): ${sitemapUrl}`);
  }

  const sitemap = new XMLParser({ ignoreAttributes: true, trimValues: true }).parse(await response.text());
  const rawEntries = sitemap.urlset?.url;
  const entries = Array.isArray(rawEntries) ? rawEntries : rawEntries ? [rawEntries] : [];
  const origin = new URL(sitemapUrl).origin;
  const urls = entries.map(({ loc }) => {
    if (typeof loc !== 'string') {
      throw new Error('Sitemap contains an entry without a URL.');
    }

    const url = new URL(loc);
    if (url.origin !== origin || !['http:', 'https:'].includes(url.protocol)) {
      throw new Error(`Sitemap URL is outside the sitemap host: ${loc}`);
    }
    return url.href;
  });

  const uniqueUrls = [...new Set(urls)];
  if (uniqueUrls.length === 0) {
    throw new Error(`No page URLs found in sitemap: ${sitemapUrl}`);
  }
  return uniqueUrls;
}

function getFileName(url, index) {
  const pathname = decodeURIComponent(new URL(url).pathname);
  const slug = pathname
    .split('/')
    .filter(Boolean)
    .join('--')
    .normalize('NFKD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/[^a-zA-Z0-9._-]+/g, '-')
    .replace(/-+/g, '-')
    .toLowerCase() || 'home';
  const suffix = createHash('sha256').update(url).digest('hex').slice(0, 8);
  return `${String(index + 1).padStart(2, '0')}-${slug}-${suffix}.png`;
}

async function prepareFullPage(page) {
  await page.evaluate(async () => {
    await document.fonts.ready;
    const pause = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));
    const step = Math.max(window.innerHeight * 0.8, 400);
    for (let y = 0; y < document.documentElement.scrollHeight; y += step) {
      window.scrollTo(0, y);
      await pause(120);
    }
    window.scrollTo(0, 0);
    await pause(300);
  });
}

const captureDate = getCaptureDate();
const outputDirectory = path.join(outputRoot, captureDate);
const urls = await getPageUrls();
await mkdir(outputDirectory, { recursive: true });

const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({
  viewport: { width: 1440, height: 1000 },
  deviceScaleFactor: 1,
  locale: 'sk-SK',
  timezoneId: timeZone,
  reducedMotion: 'reduce',
});
const failures = [];
const captured = [];

try {
  for (const [index, url] of urls.entries()) {
    const page = await context.newPage();
    try {
      const response = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60_000 });
      if (!response || response.status() >= 400) {
        throw new Error(`Page returned ${response?.status() ?? 'no response'}`);
      }

      await page.locator('body').waitFor({ state: 'visible', timeout: 15_000 });
      await prepareFullPage(page);

      const fileName = getFileName(url, index);
      await page.screenshot({
        path: path.join(outputDirectory, fileName),
        fullPage: true,
        animations: 'disabled',
        caret: 'hide',
      });
      captured.push({ url, file: fileName, status: response.status() });
      console.log(`Captured ${index + 1}/${urls.length}: ${url}`);
    } catch (error) {
      failures.push({ url, message: error instanceof Error ? error.message : String(error) });
      console.error(`Failed to capture ${url}: ${failures.at(-1).message}`);
    } finally {
      await page.close();
    }
  }
} finally {
  await context.close();
  await browser.close();
}

await writeFile(
  path.join(outputDirectory, 'manifest.json'),
  `${JSON.stringify({ capturedAt: new Date().toISOString(), timeZone, captured, failures }, null, 2)}\n`,
);

if (failures.length > 0) {
  throw new Error(`Captured ${captured.length}/${urls.length} pages; ${failures.length} page(s) failed.`);
}

console.log(`Saved ${captured.length} full-page screenshots to ${outputDirectory}`);
