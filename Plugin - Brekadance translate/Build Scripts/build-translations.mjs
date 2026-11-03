import { mkdirSync, rmSync, writeFileSync } from "node:fs";
import path from "node:path";
import process from "node:process";
import {
  DEFAULT_DOMAIN,
  PLUGIN_LANG_DIR,
  entryDomain,
  hasSrc,
  isTranslated,
  loadDb,
  summarize,
  validateDb,
} from "./translations-lib.mjs";

const LOCALE = "sk_SK";

const db = loadDb();
const { errors, warnings } = validateDb(db);
if (errors.length > 0) {
  for (const line of errors.slice(0, 60)) console.error(`  ${line}`);
  console.error(`Preklady nie sú platné: ${errors.length} chýb. Oprav ich (node "Build Scripts/validate-translations.mjs").`);
  process.exit(1);
}
if (warnings.length > 0) console.warn(`Upozornení: ${warnings.length} (node "Build Scripts/validate-translations.mjs" ich vypíše).`);

const translated = db.entries.filter(isTranslated);

// Editor: JED locale data for wp.i18n; only the 'breakdance' domain is loaded by the editor.
const jed = {
  "": { domain: DEFAULT_DOMAIN, lang: LOCALE, plural_forms: db.meta.plural_forms },
};
for (const e of translated) {
  if (entryDomain(e) !== DEFAULT_DOMAIN || !hasSrc(e, "js")) continue;
  const key = e.ctx ? `${e.ctx}\u0004${e.id}` : e.id;
  jed[key] = Array.isArray(e.sk) ? e.sk : [e.sk];
}

// PHP: { context: { source text: translation } } per text domain, applied through gettext filters.
const phpMaps = new Map();
for (const e of translated) {
  if (!hasSrc(e, "php")) continue;
  const domain = entryDomain(e);
  const map = phpMaps.get(domain) || {};
  const ctx = e.ctx || "";
  (map[ctx] ||= {})[e.id] = e.sk;
  phpMaps.set(domain, map);
}

const jsDir = path.join(PLUGIN_LANG_DIR, "js");
const phpDir = path.join(PLUGIN_LANG_DIR, "php");
rmSync(jsDir, { recursive: true, force: true });
rmSync(phpDir, { recursive: true, force: true });
mkdirSync(jsDir, { recursive: true });
mkdirSync(phpDir, { recursive: true });

const jsFile = path.join(jsDir, `${DEFAULT_DOMAIN}-${LOCALE}.json`);
writeFileSync(jsFile, JSON.stringify({ domain: DEFAULT_DOMAIN, locale_data: { [DEFAULT_DOMAIN]: jed } }), "utf8");
for (const [domain, map] of phpMaps) {
  writeFileSync(path.join(phpDir, `${domain}-${LOCALE}.json`), JSON.stringify(map), "utf8");
}

const { total, byStatus } = summarize(db);
console.log(
  `Preklady: ${translated.length}/${total} (todo ${byStatus.todo}, ai ${byStatus.ai}, reviewed ${byStatus.reviewed}).`,
);
console.log(`Editor (JS): ${Object.keys(jed).length - 1} textov -> ${path.relative(process.cwd(), jsFile)}`);
for (const [domain, map] of phpMaps) {
  const count = Object.values(map).reduce((n, m) => n + Object.keys(m).length, 0);
  console.log(`PHP (${domain}): ${count} textov`);
}
