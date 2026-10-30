# Weekly website screenshots

GitHub Actions reads every page URL from the sitemap and captures a full-page
PNG for each page. The current sitemap is `https://mamateyka.sk/page-sitemap.xml`;
the workflow picks up new or removed pages automatically. The capture script
only follows URLs on the sitemap's host.

## Where to find the screenshots

Each run saves one artifact for 90 days (the maximum for a public repository).
Open **Actions → Weekly website screenshots → a run → Artifacts** and download
`YYYY.MM.DD Mamateyka screenshots.zip`. It contains one PNG per page, named
after the page title without the ` - Mamateyka` suffix (for example
`Kontakt.png`). If any page could not be captured, the ZIP also contains
`failed_screenshots.txt` listing those URLs. Artifacts are not committed to the
repository. Download any screenshots you need to keep longer than 90 days.

## Schedule and local run

The workflow runs every Monday at 03:17 UTC (04:17 in Slovakia during standard
time, 05:17 during daylight-saving time). GitHub may start scheduled workflows a
few minutes later than requested. It can also be started manually from the
Actions tab, and it runs when this tool or its workflow changes on `main`. To
run it daily, change the cron expression to `17 3 * * *`.

To run the capture locally, install the dependencies and Chromium from this
directory:

```powershell
npm install
npx playwright install chromium
npm run capture
```

By default, local screenshots are written to `screenshots/`.
Set `SITEMAP_URL`, `OUTPUT_DIR`, or `SCREENSHOT_TIME_ZONE` to override the
defaults.

Each page is given time to finish its animations: the script waits for the page
to load, waits 5 seconds, scrolls through the page to trigger scroll-based
animations, returns to the top, and waits another 5 seconds. It then enlarges
the browser window to the full page height (up to 16,000 px) so that every
section stays in view, waits once more, and takes the screenshot. Set
`PAGE_SETTLE_MS` (milliseconds, default `5000`) to lengthen or shorten these
waits if some animations are still captured mid-way.

GitHub stores the automation code; screenshot files are kept as workflow
artifacts and are not committed to the repository.
