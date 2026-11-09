# Mamateyka Plugins

This repository contains WordPress plugins and website helper scripts created
for the Mamateyka website.

## Projects

- **Farske oznamy** — parish announcements and Mass-intention displays.
- **Knizica Mamateyka** — a small library catalogue and reservation plugin.
- **Hero Carousel Mini Plugin** — a carousel for selected website content.
- **Breakdance Elements by Miki** — custom Breakdance elements (section
  *Elements by Miki* in the editor). First element: Memories (sk: Spomienky).
- **Breakdance Slovak UI** — Slovak translation of the Breakdance editor.
- **Website scripts** — calendar, search, gallery, navigation, and other
  custom website components.

The website (production: mamateyka.sk) is built with Breakdance. AI assistants
and contributors should also read
[`.github/copilot-instructions.md`](./.github/copilot-instructions.md).

## Repository layout

Each plugin project has its installable WordPress plugin in a subdirectory named
with its existing plugin slug. Its `build-release.ps1` script is kept at the
project root, and generated release ZIPs are stored in `Releases/`.

The Books project also contains `Build Scripts/` for the catalogue-enrichment
tool and `Databases/` for its JSON files. Standalone website integrations are
kept in their own `Script - ...` project folders.

## Building plugin releases

Run `build-release.ps1` from the relevant plugin project folder using Windows
PowerShell. Each script increments that plugin's minor version, updates its
version metadata, builds a ZIP with the existing WordPress slug as its root,
and validates the package before saving it under `Releases/`.

The three scripts share the same name and workflow, while retaining the
plugin-specific version fields and release notes. Exception: the Breakdance
Elements by Miki script does not bump the version; it reads it from the plugin
header, so bump it manually first.

## Adding Breakdance elements

New elements go into `Plugin - Breakdance Elements by Miki/breakdance-elements-by-miki/elements/<Name>/`,
one independent folder per element, started from `Templates/Element Skeleton/`.
In short:

1. Copy the skeleton, rename the class, slug, `className()` and CSS prefix; keep `category()` as `miki-elements`.
2. Write editor texts in English with `t()` and add Slovak translations to `languages/sk_SK.php`.
3. Never rename an element slug or saved property path; only add optional fields.
4. Verify with `php -l`, then test in a real Breakdance 2.8.3 editor and on the front end.
5. Bump the version (plugin header, `readme.txt`), run `build-release.ps1`, deploy, and regenerate the Breakdance CSS cache.

Full rules and pitfalls are in [`.github/copilot-instructions.md`](./.github/copilot-instructions.md).

## Weekly website screenshots

The GitHub Actions workflow captures full-page screenshots of every URL in the
Mamateyka page sitemap and keeps them as downloadable workflow artifacts for 90
days. See
[the screenshot tool guide](<./Tool - Screenshots/README.md>) for details.

## Copyright and permitted use

Copyright (c) 2026 Mikulas Polak. All rights reserved.

This repository is public for inspection only. No license is granted to use,
copy, modify, distribute, or create derivative works from its contents, except
for actions GitHub expressly permits as part of displaying and operating public
repositories. See [LICENSE](./LICENSE).

Third-party content, dependencies, data, images, fonts, and other materials
remain subject to their respective owners' terms and licenses. They are not
relicensed by this repository's notice.

## Contributions

Issues and pull requests may be submitted as suggestions. Submitting a pull
request does not grant permission to incorporate or distribute its code.
Contributions will only be used after separate permission has been agreed with
the contributor.
