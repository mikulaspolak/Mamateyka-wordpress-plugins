# Weekly website screenshots

GitHub Actions reads every page URL from the sitemap and captures a full-page
PNG for each page. The current sitemap is `https://mamateyka.sk/page-sitemap.xml`;
the workflow picks up new or removed pages automatically. The capture script
only follows URLs on the sitemap's host.

## Where to find the screenshots

Each run saves one artifact named `website-screenshots-<run number>` for 90
days (the maximum for a public repository). Open **Actions → Weekly website
screenshots → a run → Artifacts** and download the ZIP. It contains the dated
folder with one PNG per page and a `manifest.json`. Artifacts are not committed
to the repository. Download any screenshots you need to keep longer than 90
days.

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

By default, local screenshots are written under `screenshots/YYYY-MM-DD/`.
Set `SITEMAP_URL`, `OUTPUT_DIR`, or `SCREENSHOT_TIME_ZONE` to override the
defaults.

GitHub stores the automation code; screenshot files are kept as workflow
artifacts and are not committed to the repository.
