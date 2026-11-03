// Dopĺňa databázu prekladov o nové texty z POT súborov Breakdance (po aktualizácii Breakdance
// alebo po doplnení subplugins). Existujúce preklady nikdy nemení ani nemaže.
//
// node "Build Scripts/sync-pot.mjs" [--js <builder.pot>] [--php <plugin.pot>] [--domain breakdance] [--write]
import { readFileSync } from "node:fs";
import path from "node:path";
import process from "node:process";
import { DEFAULT_DOMAIN, PROJECT_ROOT, entryDomain, entryKey, loadDb, saveDb } from "./translations-lib.mjs";

const args = process.argv.slice(2);
const opt = (name, fallback) => {
  const i = args.indexOf(name);
  return i >= 0 ? args[i + 1] : fallback;
};
const langDir = path.join(PROJECT_ROOT, "breakdance", "languages");
const sources = [
  { src: "js", file: opt("--js", path.join(langDir, "breakdance-builder.pot")) },
  { src: "php", file: opt("--php", path.join(langDir, "breakdance.pot")) },
];
const domain = opt("--domain", DEFAULT_DOMAIN);
const write = args.includes("--write");

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

function areaOf(ref, src) {
  const p = ref.split(":")[0].split("/");
  const base = (s) => s.split(".")[0];
  if (src === "js") {
    const rest = p.slice(2);
    if (rest.length >= 2 && ["components", "subapps", "oxygen", "lib"].includes(rest[0])) return `editor/${rest[0]}/${base(rest[1])}`;
    return `editor/${base(rest[0] || p[0])}`;
  }
  const prefix = domain === DEFAULT_DOMAIN ? "php" : `php/${domain}`;
  if (p.length === 1) return `${prefix}/${base(p[0])}`;
  return `${prefix}/${base(p[0] === "plugin" || p[0] === "src" ? p[1] : p[0])}`;
}

const db = loadDb();
const known = new Map(db.entries.map((e) => [entryKey(e), e]));
const seen = new Set();
const added = [];
const touched = [];
let nextN = db.entries.reduce((m, e) => Math.max(m, e.n), 0) + 1;

for (const { src, file } of sources) {
  let items;
  try {
    items = parsePot(file);
  } catch (err) {
    console.warn(`Preskakujem ${src}: ${err.message}`);
    continue;
  }
  for (const it of items) {
    const probe = { domain, ctx: it.ctx || undefined, id: it.id };
    const key = entryKey(probe);
    seen.add(key);
    let e = known.get(key) || added.find((a) => entryKey(a) === key);
    if (!e) {
      e = { n: nextN++, id: it.id };
      if (domain !== DEFAULT_DOMAIN) e.domain = domain;
      if (it.ctx) e.ctx = it.ctx;
      if (it.plural) e.plural = it.plural;
      Object.assign(e, { sk: it.plural ? [] : "", status: "todo", src, area: areaOf(it.refs[0] || src, src), refs: it.refs.slice(0, 3) });
      if (it.notes.length) e.note = it.notes.join(" | ");
      added.push(e);
      known.set(key, e);
      continue;
    }
    if (!e.src.split("+").includes(src)) {
      e.src += `+${src}`;
      touched.push(e);
    }
    if ((it.plural || null) !== (e.plural || null)) console.warn(`Zmenený plurál pri n=${e.n}: ${JSON.stringify(e.id)} (skontroluj ručne)`);
  }
}

const missing = db.entries.filter((e) => entryDomain(e) === domain && !seen.has(entryKey(e)));
console.log(`Nové texty: ${added.length}, rozšírené 'src': ${touched.length}, v databáze, ale už nie v POT: ${missing.length}.`);
for (const e of added.slice(0, 15)) console.log(`  + n=${e.n} [${e.area}] ${JSON.stringify(e.id)}`);
if (added.length > 15) console.log(`  … a ďalších ${added.length - 15}`);

if (!write) {
  console.log("Dry-run: nič sa nezapísalo. Pridaj --write.");
  process.exit(0);
}
db.entries.push(...added.filter((a) => !db.entries.includes(a)));
saveDb(db);
console.log("Databáza aktualizovaná. Nové položky majú status 'todo'.");
