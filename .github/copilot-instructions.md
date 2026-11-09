# Copilot instructions

Read this file and `README.md` before working in this repository.

## Context

- Repository of WordPress plugins and helper scripts for **mamateyka.sk** (production site, built with **Breakdance**).
- Each `Plugin - ...` folder is a project: installable plugin in a slug-named subfolder, `build-release.ps1` at the project root, ZIPs in `Releases/`.
- Target stack: WordPress 7.1.x, Breakdance 2.8.3, PHP 8.2. Windows; use PowerShell 7 (`pwsh`) for build scripts.
- Write code, comments, and docs in English. Never commit secrets or Breakdance's own (licensed) files.

## Breakdance Elements by Miki (`Plugin - Breakdance Elements by Miki/`)

Custom Breakdance elements, shown in the editor under the **Elements by Miki** section (category `miki-elements`). More elements will be added over time.

### Rules for every new element

1. **Independent:** one folder `elements/<Name>/` with `element.php`, `html.twig`, `css.twig`, `default.css`. No dependency on other elements or shared element code. Start from `Templates/Element Skeleton/` (outside the ZIP).
2. **Own naming:** unique class in namespace `BreakdanceElementsByMiki`, `className()` `bde-miki-<name>`, CSS prefix `miki-<name>__`, `category()` returns `'miki-elements'`.
3. **element.php must** call `registerElementForEditing(<slug>, getDirectoryPathRelativeToPluginFolder(__DIR__))`, extend `\Breakdance\Elements\Element`, and implement `template()`, `defaultCss()`, `cssTemplate()` (reading the files; the base class returns empty values otherwise), `uiIcon()` (inline SVG), `name()`, `slug()` (`__CLASS__`), `defaultProperties()` with sample content.
4. **Controls:** use `c(slug, label, children, options, enableMediaQueries, enableHover)`; control slugs are `[a-z0-9_]` (no `-`). Dropdown/button-bar `items` go directly in `options` (no `dropdownOptions`). Repeaters need `repeaterOptions` (`titleTemplate`, `defaultTitle`, `buttonName`). `unit` values expose `.style`; `wpmedia` exposes `url`, `alt`, `sizes.<size>.{url,width,height}`. Breakdance has no max-items option for repeaters: add an info `alert_box` and enforce limits in Twig (`slice`).
5. **Responsive controls** (`enableMediaQueries = true`) store `['breakpoint_base' => value]` in defaults. `css.twig` is rendered per breakpoint, so read `design.x` directly; never hardcode selectors, always use `%%SELECTOR%%`.
6. **CSS:** `default.css` is scoped `.breakdance .bde-miki-<name> ...` and must not declare custom properties that `css.twig` sets on `%%SELECTOR%%` (specificity wins); use `var(--x, fallback)` instead. Keep hover effects pure CSS and honour `prefers-reduced-motion`.
7. **Twig:** the editor renders Twig in the browser (Twig.js), the front end in PHP. Use simple constructs: full ternary (`a ? b : c`), `|e` on all user text, `|e('html_attr')` in attributes, `trim` without side argument.
8. **i18n:** editor strings are written in English via `t('...')` (`includes/i18n.php`); Slovak translations go to `languages/sk_SK.php` (key = exact English text). Element names are English (e.g. `Memories` -> `Spomienky`).
9. **Stable data contract:** the element slug and every property path are saved in page content. **Never rename or remove them**; only add new optional fields with safe defaults. Display names, labels, and CSS may change.
10. Do not store elements in Breakdance's own folder (updates overwrite it). Element Studio's Delete removes files; commit before using it.

### Verification (before a release)

- `php -l` on all PHP files; check every `t()` key exists in `sk_SK.php`; render `html.twig`/`css.twig` with sample data (including empty and malicious input).
- Real runtime test is required and is **not** replaced by the above: load the plugin in WordPress with Breakdance 2.8.3 (copied from the production server; it is a paid plugin and not on wordpress.org) and test the editor and front end: insert, content fields, image/alt, breakpoints, hover, empty state, Element Studio. Do not rely on third-party copies of Breakdance (older versions); state clearly what was not tested.

### Release

- `build-release.ps1` reads the version from the plugin header (it does not auto-bump) and refuses to overwrite an existing ZIP. Bump `Version` in `breakdance-elements-by-miki.php` and `Stable tag` + changelog in `readme.txt`, then run `pwsh -File .\build-release.ps1`.
- Release ZIP root must be `breakdance-elements-by-miki/`. Update the required-entries check in the script if you add top-level files.
- After deploying: regenerate Breakdance CSS cache and any page/CDN cache.
