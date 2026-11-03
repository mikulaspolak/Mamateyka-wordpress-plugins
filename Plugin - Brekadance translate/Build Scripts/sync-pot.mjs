// Dopĺňa databázu prekladov o nové texty zo zdrojov Breakdance (po aktualizácii Breakdance).
// Existujúce preklady nikdy nemení ani nemaže; nové položky dostanú status 'todo'.
//
// Zdroje sa čítajú z Translations/source (POT súbory skopírované z Breakdance + elements-extra-strings.json).
//
//   node "Build Scripts/sync-pot.mjs" [--write]
//       jadro Breakdance: breakdance-builder.pot (js) + breakdance.pot (php), doména breakdance
//   node "Build Scripts/sync-pot.mjs" --elements [--write]
//       prvky (breakdance-elements): breakdance-elements-builder.pot + elements-extra-strings.json
//       - vynecháva WooCommerce prvky a kontext "Preset section label" (za behu sa neprekladá)
//       - breakdance-elements.pot sa nečíta: obsahuje len hlavičku pluginu a zastarané texty,
//         živé texty z kódu pokrýva elements-extra-strings.json (node "Build Scripts/extract-element-strings.mjs")
//
// Voliteľné: --dir <priečinok zdrojov> --js <pot> --php <pot> --domain <doména>
import { existsSync, readFileSync } from "node:fs";
import path from "node:path";
import process from "node:process";
import { DEFAULT_DOMAIN, SOURCE_DIR, entryDomain, entryKey, isWooPath, loadDb, writeDbChanges } from "./translations-lib.mjs";

const ELEMENTS_DOMAIN = "breakdance-elements";
const SKIPPED_CONTEXTS = new Set(["Preset section label"]);

const args = process.argv.slice(2);
const opt = (name, fallback) => {
  const i = args.indexOf(name);
  return i >= 0 ? args[i + 1] : fallback;
};
const write = args.includes("--write");
const elementsMode = args.includes("--elements");
const dir = opt("--dir", SOURCE_DIR);
const domain = opt("--domain", elementsMode ? ELEMENTS_DOMAIN : DEFAULT_DOMAIN);

function parsePot(file) {
  const entries = [];
  let cur = null;
  let field = null;
  const flush = () => {
    if (cur && cur.id !== undefined && cur.id !== "") entries.push(cur);
    cur = null;
    field = null;
  };
  for (const raw of readFileSync(file, "utf8").split("\n")) {
    const line = raw.replace(/\r$/, "");
    if (line.trim() === "") { flush(); continue; }
    cur ||= { refs: [], ctx: null, plural: null, notes: [] };
    if (line.startsWith("#:")) cur.refs.push(...line.slice(2).trim().split(/\s+/));
    else if (line.startsWith("#.")) cur.notes.push(line.slice(2).trim());
    else if (line.startsWith("#")) continue;
    else if (line.startsWith("msgctxt ")) { field = "ctx"; cur.ctx = JSON.parse(line.slice(8)); }
    else if (line.startsWith("msgid_plural ")) { field = "plural"; cur.plural = JSON.parse(line.slice(13)); }
    else if (line.startsWith("msgid ")) { field = "id"; cur.id = JSON.parse(line.slice(6)); }
    else if (line.startsWith("msgstr")) field = "msgstr";
    else if (line.startsWith('"') && ["ctx", "plural", "id"].includes(field)) cur[field] += JSON.parse(line);
  }
  flush();
  return entries;
}

// Element strings: skip WooCommerce-only and runtime-untranslated items, keep only non-Woo refs.
function filterElementItem(it) {
  if (SKIPPED_CONTEXTS.has(it.ctx)) return null;
  const refs = it.refs.filter((r) => !isWooPath(r));
  if (it.refs.length > 0 && refs.length === 0) return null;
  return { ...it, refs };
}

function loadExtraStrings(file) {
  const data = JSON.parse(readFileSync(file, "utf8"));
  return data.entries.map((e) => ({ id: e.id, ctx: e.ctx || null, plural: null, notes: [], refs: e.refs || [], domain: e.domain, src: e.src, fromElements: true }));
}

function areaOf(ref, src, itemDomain) {
  const p = ref.split(":")[0].split("/");
  const base = (s) => s.split(".")[0];
  if (src === "js") {
    const rest = p.slice(2);
    if (rest.length >= 2 && ["components", "subapps", "oxygen", "lib"].includes(rest[0])) return `editor/${rest[0]}/${base(rest[1])}`;
    return `editor/${base(rest[0] || p[0])}`;
  }
  const prefix = itemDomain === DEFAULT_DOMAIN ? "php" : `php/${itemDomain}`;
  if (itemDomain === ELEMENTS_DOMAIN) {
    if (p.length === 1) return `${prefix}/${base(p[0])}`;
    return `${prefix}/${["elements", "elements-manual"].includes(p[0]) ? p[1] : p[0]}`;
  }
  if (p.length === 1) return `${prefix}/${base(p[0])}`;
  return `${prefix}/${base(p[0] === "plugin" || p[0] === "src" ? p[1] : p[0])}`;
}

const sortSrc = (parts) => [...new Set(parts)].sort((a, b) => (a === "js" ? -1 : b === "js" ? 1 : 0)).join("+");

// Each source: items = parsed strings; full = the file lists every string of its domain,
// so catalog entries missing from it are reported (strings from any source count as present).
function buildSources() {
  const file = (name, override) => override ?? path.join(dir, name);
  if (elementsMode) {
    return [
      {
        src: "php",
        domain,
        full: true,
        file: file("breakdance-elements-builder.pot", opt("--php")),
        load: (f) => parsePot(f).map(filterElementItem).filter(Boolean),
      },
      { full: false, file: path.join(dir, "elements-extra-strings.json"), load: loadExtraStrings },
    ];
  }
  return [
    { src: "js", domain, full: true, file: file("breakdance-builder.pot", opt("--js")), load: parsePot },
    { src: "php", domain, full: true, file: file("breakdance.pot", opt("--php")), load: parsePot },
    // Only marks the strings as known; they are imported by --elements.
    { full: false, markOnly: true, file: path.join(dir, "elements-extra-strings.json"), load: loadExtraStrings },
  ];
}

const db = loadDb();
const known = new Map(db.entries.map((e) => [entryKey(e), e]));
const seen = new Set();
const fullDomains = new Set();
const added = [];
const touched = new Map();
let nextN = db.entries.reduce((m, e) => Math.max(m, e.n), 0) + 1;

for (const source of buildSources()) {
  if (!existsSync(source.file)) {
    console.warn(`Preskakujem ${path.basename(source.file)}: súbor neexistuje.`);
    continue;
  }
  const items = source.load(source.file);
  if (source.full) fullDomains.add(source.domain);

  for (const it of items) {
    const itemDomain = it.domain || source.domain;
    const itemSrc = it.src || source.src;
    const key = entryKey({ domain: itemDomain, ctx: it.ctx || undefined, id: it.id });
    seen.add(key);
    if (source.markOnly) continue;

    let e = known.get(key);
    if (!e) {
      e = { n: nextN++, id: it.id };
      if (itemDomain !== DEFAULT_DOMAIN) e.domain = itemDomain;
      if (it.ctx) e.ctx = it.ctx;
      if (it.plural) e.plural = it.plural;
      Object.assign(e, {
        sk: it.plural ? [] : "",
        status: "todo",
        src: sortSrc(itemSrc.split("+")),
        area: it.fromElements
          ? areaOf(it.refs[0] || "elements", "php", ELEMENTS_DOMAIN)
          : areaOf(it.refs[0] || itemSrc.split("+")[0], itemSrc.split("+")[0], itemDomain),
        refs: it.refs.slice(0, 3),
      });
      if (it.notes.length) e.note = it.notes.join(" | ");
      added.push(e);
      known.set(key, e);
      continue;
    }

    const merged = sortSrc([...e.src.split("+"), ...itemSrc.split("+")]);
    if (merged !== e.src) {
      e.src = merged;
      if (!added.includes(e)) touched.set(e.n, e);
    }
    if ((it.plural || null) !== (e.plural || null)) console.warn(`Zmenený plurál pri n=${e.n}: ${JSON.stringify(e.id)} (skontroluj ručne)`);
  }
}

const missing = db.entries.filter((e) => fullDomains.has(entryDomain(e)) && !seen.has(entryKey(e)));
console.log(
  `Nové texty: ${added.length}, rozšírené 'src': ${touched.size}, v databáze, ale už nie v zdroji: ${missing.length} (doména ${[...fullDomains].join(", ")}).`,
);
for (const e of added.slice(0, 15)) console.log(`  + n=${e.n} [${e.area}] ${JSON.stringify(e.id)}`);
if (added.length > 15) console.log(`  … a ďalších ${added.length - 15}`);

if (!write) {
  console.log("Dry-run: nič sa nezapísalo. Pridaj --write.");
  process.exit(0);
}
writeDbChanges({ added, srcChanges: [...touched.values()].map((e) => ({ n: e.n, src: e.src })) });
console.log("Databáza aktualizovaná. Nové položky majú status 'todo'.");
