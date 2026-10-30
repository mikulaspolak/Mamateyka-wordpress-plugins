import { mkdir, rm, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { XMLParser } from 'fast-xml-parser';
import { chromium } from 'playwright';

const sitemapUrl = process.env.SITEMAP_URL ?? 'https://mamateyka.sk/page-sitemap.xml';
const outputDirectory = path.resolve(process.env.OUTPUT_DIR ?? './screenshots');
const timeZone = process.env.SCREENSHOT_TIME_ZONE ?? 'Europe/Bratislava';
const settleMs = Number(process.env.PAGE_SETTLE_MS ?? 5000);
if (!Number.isFinite(settleMs) || settleMs < 0) {
  throw new Error('PAGE_SETTLE_MS must be a non-negative number of milliseconds.');
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

const imageFileNames = new Set();

function getFileName(title, url) {
  const pageName = title
    .replace(/\s+[-–—]\s+Mamateyka$/i, '')
    .replace(/[<>:"/\\|?*\u0000-\u001f]+/g, '-')
    .replace(/\s+/g, ' ')
    .trim()
    .replace(/[. ]+$/, '')
    .slice(0, 120);
  const baseName = pageName || decodeURIComponent(new URL(url).pathname).split('/').filter(Boolean).join('-') || 'Domov';

  let fileName = `${baseName}.png`;
  for (let copy = 2; imageFileNames.has(fileName.toLowerCase()); copy += 1) {
    fileName = `${baseName} (${copy}).png`;
  }
  imageFileNames.add(fileName.toLowerCase());
  return fileName;
}

async function prepareFullPage(page) {
  // Let entrance animations finish, trigger scroll-based ones, then settle at the top again.
  await page.waitForTimeout(settleMs);
  await page.evaluate(async () => {
    await document.fonts.ready;
    const pause = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));
    const step = Math.max(window.innerHeight * 0.6, 400);
    for (let y = 0; y < document.documentElement.scrollHeight; y += step) {
      window.scrollTo(0, y);
      await pause(400);
    }
    await pause(1000);
    window.scrollTo(0, 0);
  });
  await page.waitForTimeout(settleMs);

  // A full-page capture resizes the viewport, which resets scroll animations that are not
  // in view. A viewport as tall as the page keeps every section in view and fully animated.
  const pageHeight = await page.evaluate(() => document.documentElement.scrollHeight);
  await page.setViewportSize({ width: viewport.width, height: Math.min(pageHeight, maxViewportHeight) });
  await page.waitForTimeout(settleMs);
}

const urls = await getPageUrls();
await mkdir(outputDirectory, { recursive: true });

const viewport = { width: 1440, height: 1000 };
const maxViewportHeight = 16_000;
const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({
  viewport,
  deviceScaleFactor: 1,
  locale: 'sk-SK',
  timezoneId: timeZone,
});
const failures = [];
const captured = [];

try {
  for (const [index, url] of urls.entries()) {
    const page = await context.newPage();
    try {
      const response = await page.goto(url, { waitUntil: 'load', timeout: 60_000 });
      if (!response || response.status() >= 400) {
        throw new Error(`Page returned ${response?.status() ?? 'no response'}`);
      }

      await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {});
      await page.locator('body').waitFor({ state: 'visible', timeout: 15_000 });
      await prepareFullPage(page);

      const fileName = getFileName(await page.title(), url);
      await page.screenshot({
        path: path.join(outputDirectory, fileName),
        fullPage: true,
        animations: 'disabled',
        caret: 'hide',
      });
      captured.push(url);
      console.log(`Captured ${index + 1}/${urls.length}: ${url} -> ${fileName}`);
    } catch (error) {
      failures.push(url);
      console.error(`Failed to capture ${url}: ${error instanceof Error ? error.message : String(error)}`);
    } finally {
      await page.close();
    }
  }
} finally {
  await context.close();
  await browser.close();
}

const failureListPath = path.join(outputDirectory, 'failed_screenshots.txt');
if (failures.length > 0) {
  await writeFile(failureListPath, `${failures.join('\n')}\n`);
  throw new Error(`Captured ${captured.length}/${urls.length} pages; ${failures.length} page(s) failed.`);
}
await rm(failureListPath, { force: true });

console.log(`Saved ${captured.length} full-page screenshots to ${outputDirectory}`);
