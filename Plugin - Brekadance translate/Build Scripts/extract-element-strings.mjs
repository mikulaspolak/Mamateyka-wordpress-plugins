// Vyťahuje z kódu prvkov Breakdance (PHP a Twig) texty, ktoré nie sú v žiadnom POT súbore, napr. texty
// zobrazované návštevníkom webu ("Read more", "Search for:", ...). Výsledok sa uloží do
// Translations/source/elements-extra-strings.json a číta ho sync-pot.mjs --elements.
//
// node "Build Scripts/extract-element-strings.mjs" [--elements-dir <breakdance-elements>] [--out <súbor>]
//
// Rozpoznáva __(), _e(), esc_html__(), esc_attr__(), esc_html_e(), esc_attr_e() s literálom a doménou
// 'breakdance' alebo 'breakdance-elements'. WooCommerce súbory sa vynechávajú.
import { readdirSync, readFileSync, writeFileSync } from "node:fs";
import path from "node:path";
import process from "node:process";
import { PROJECT_ROOT, SOURCE_DIR, isWooPath } from "./translations-lib.mjs";

const args = process.argv.slice(2);
const opt = (name, fallback) => {
  const i = args.indexOf(name);
  return i >= 0 ? args[i + 1] : fallback;
};
const root = opt("--elements-dir", path.join(PROJECT_ROOT, "breakdance", "breakdance-elements"));
const out = opt("--out", path.join(SOURCE_DIR, "elements-extra-strings.json"));

const CALL_RE =
  /(?<![\w$>])(?:__|_e|esc_html__|esc_attr__|esc_html_e|esc_attr_e)\(\s*(['"])((?:\\.|(?!\1)[^\\])*)\1\s*,\s*['"](breakdance|breakdance-elements)['"]\s*\)/g;

function* walk(dir) {
  for (const e of readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) yield* walk(p);
    else if (/\.(php|twig)$/.test(e.name)) yield p;
  }
}

const unescape = (s, quote) => s.replace(quote === "'" ? /\\(['\\])/g : /\\(["\\])/g, "$1");

const found = new Map();
for (const file of walk(root)) {
  const rel = path.relative(root, file).split(path.sep).join("/");
  if (isWooPath(rel)) continue;
  const text = readFileSync(file, "utf8");
  for (const m of text.matchAll(CALL_RE)) {
    const id = unescape(m[2], m[1]);
    if (id.trim() === "") continue;
    const domain = m[3];
    const line = text.slice(0, m.index).split("\n").length;
    const key = `${domain}\u0000${id}`;
    const item = found.get(key) || { domain, id, parts: new Set(), refs: [] };
    // Twig is rendered in the editor through wp.i18n, which only has the 'breakdance' domain loaded.
    item.parts.add(file.endsWith(".twig") && domain === "breakdance" ? "js+php" : "php");
    item.refs.push(`${rel}:${line}`);
    found.set(key, item);
  }
}

const entries = [...found.values()]
  .map((i) => {
    const parts = new Set([...i.parts].flatMap((p) => p.split("+")));
    return { domain: i.domain, id: i.id, src: parts.has("js") ? "js+php" : "php", refs: i.refs.slice(0, 3) };
  })
  .sort((a, b) => a.domain.localeCompare(b.domain) || a.id.localeCompare(b.id));

const meta = {
  file: "Texty z kódu prvkov Breakdance (PHP/Twig), ktoré nie sú v POT súboroch. Generuje extract-element-strings.mjs; ručne neupravuj.",
  source: "Breakdance 2.8.3: breakdance-elements/{elements,macros,lib,presets}",
};
writeFileSync(out, JSON.stringify({ meta, entries }, null, 2) + "\n", "utf8");
console.log(`Extrahovaných textov: ${entries.length} -> ${path.relative(process.cwd(), out)}`);
for (const e of entries) console.log(`  [${e.domain}] ${JSON.stringify(e.id)} (${e.src})`);
