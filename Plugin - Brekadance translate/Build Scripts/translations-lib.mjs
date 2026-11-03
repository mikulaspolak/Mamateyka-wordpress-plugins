import { readFileSync, writeFileSync } from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { execFileSync } from "node:child_process";

export const SCRIPT_DIR = path.dirname(fileURLToPath(import.meta.url));
export const PROJECT_ROOT = path.resolve(SCRIPT_DIR, "..");
export const DB_DIR = path.join(PROJECT_ROOT, "Translations");
export const DB_PATH = path.join(DB_DIR, "breakdance-sk_SK.json");
export const SOURCE_DIR = path.join(DB_DIR, "source");
export const PLUGIN_LANG_DIR = path.join(PROJECT_ROOT, "breakdance-slovak-ui", "languages");
export const DEFAULT_DOMAIN = "breakdance";
export const STATUSES = ["todo", "ai", "reviewed"];
const IDENTITY_FIELDS = ["id", "ctx", "plural", "domain"];

export function loadDb(file = DB_PATH) {
  return JSON.parse(readFileSync(file, "utf8"));
}

// The committed file has hand-edited formatting, so it is never re-serialized: new entries are
// appended as text and 'src' of touched entries is patched in place. Everything else stays byte-identical.
export function writeDbChanges({ added = [], srcChanges = [] }, file = DB_PATH) {
  let text = readFileSync(file, "utf8");

  for (const { n, src } of srcChanges) {
    const start = text.indexOf(`\n      "n": ${n},`);
    const at = start < 0 ? -1 : text.indexOf('"src": "', start);
    if (at < 0) throw new Error(`Položku n=${n} sa nepodarilo nájsť na úpravu 'src'.`);
    const end = text.indexOf('"', at + 8);
    text = text.slice(0, at + 8) + src + text.slice(end);
  }

  if (added.length > 0) {
    const tail = "\n    }\n  ]\n}\n";
    if (!text.endsWith(tail)) throw new Error("Neočakávaný koniec súboru s prekladmi; nové položky sa nedajú pridať.");
    const body = added.map((e) => JSON.stringify(e, null, 2).replace(/^/gm, "    ")).join(",\n");
    text = text.slice(0, -"\n  ]\n}\n".length) + ",\n" + body + "\n  ]\n}\n";
  }

  writeFileSync(file, text, "utf8");
}

// Elements that only exist for WooCommerce (not used on this site) are never imported.
const WOO_SEGMENT_RE = /^(?:Woo|Product(?:Builder|_Reviews)?$|Related_Products$|Upsell_Products$|MiniCart$|MenuCart$|CheckoutBuilder$|woocommerce$)/;
export function isWooPath(ref) {
  const parts = ref.split(":")[0].split("/");
  return parts.some((p, i) => i > 0 && WOO_SEGMENT_RE.test(p));
}

export const entryDomain = (e) => e.domain || DEFAULT_DOMAIN;
export const entryKey = (e) => `${entryDomain(e)}\u0000${e.ctx || ""}\u0000${e.id}`;
export const hasSrc = (e, src) => e.src.split("+").includes(src);
export const isTranslated = (e) => (Array.isArray(e.sk) ? e.sk.length > 0 : typeof e.sk === "string" && e.sk !== "");

const PLACEHOLDER_RE = /%%[A-Za-z_]+%%|%[A-Za-z_]+%|%(?:\d+\$)?[sdfu]|\{\{?[A-Za-z_.]+\}?\}/g;
const HTML_TAG_RE = /<\/?[A-Za-z][^>]*>/g;
const BACKTICK_RE = /`[^`]+`/g;

const sortedMatches = (text, re) => (text.match(re) || []).slice().sort();
const sameList = (a, b) => a.length === b.length && a.every((v, i) => v === b[i]);
const edgeSpace = (s) => [s.match(/^\s*/)[0], s.match(/\s*$/)[0]];

function readHeadDb() {
  try {
    const out = execFileSync("git", ["show", "HEAD:./breakdance-sk_SK.json"], {
      cwd: DB_DIR,
      encoding: "utf8",
      stdio: ["ignore", "pipe", "ignore"],
      maxBuffer: 256 * 1024 * 1024,
    });
    return JSON.parse(out);
  } catch {
    return null;
  }
}

export function validateDb(db) {
  const errors = [];
  const warnings = [];
  const err = (e, msg) => errors.push(`n=${e?.n ?? "?"}: ${msg}`);
  const warn = (e, msg) => warnings.push(`n=${e.n}: ${msg}`);

  if (!db || !Array.isArray(db.entries) || !db.meta) {
    return { errors: ["Súbor musí obsahovať 'meta' a pole 'entries'."], warnings };
  }
  const protectedTerms = Array.isArray(db.meta.protected_terms) ? db.meta.protected_terms : [];
  const seenN = new Set();
  const seenKey = new Set();

  for (const e of db.entries) {
    if (!Number.isInteger(e.n) || seenN.has(e.n)) err(e, "chýbajúce alebo duplicitné 'n'");
    seenN.add(e.n);
    if (typeof e.id !== "string" || e.id === "") { err(e, "chýba 'id'"); continue; }
    const key = entryKey(e);
    if (seenKey.has(key)) err(e, `duplicitná položka (ctx+id): ${JSON.stringify(e.id)}`);
    seenKey.add(key);
    if (!STATUSES.includes(e.status)) { err(e, `neplatný status '${e.status}'`); continue; }
    if (typeof e.src !== "string" || !/^(js|php)(\+(js|php))?$/.test(e.src)) err(e, `neplatné 'src' '${e.src}'`);

    const isPlural = typeof e.plural === "string";

    if (isPlural ? !Array.isArray(e.sk) : typeof e.sk !== "string") {
      err(e, isPlural ? "'sk' musí byť pole 3 reťazcov" : "'sk' musí byť reťazec");
      continue;
    }
    if (!isTranslated(e)) {
      if (e.status !== "todo") err(e, `prázdny preklad musí mať status 'todo' (je '${e.status}')`);
      continue;
    }
    if (e.status === "todo") err(e, "preložená položka nesmie mať status 'todo' (nastav 'ai')");

    const forms = isPlural ? e.sk : [e.sk];
    if (isPlural && (forms.length !== 3 || forms.some((f) => typeof f !== "string" || f.trim() === ""))) {
      err(e, "plurál musí mať presne 3 neprázdne tvary [1, 2-4, 5+]");
      continue;
    }

    const sources = isPlural ? [e.id, e.plural] : [e.id];
    const ph = sources.map((s) => sortedMatches(s, PLACEHOLDER_RE));
    const tags = sources.map((s) => sortedMatches(s, HTML_TAG_RE));
    const ticks = sortedMatches(e.id, BACKTICK_RE);
    const [lead, trail] = edgeSpace(e.id);

    for (const f of forms) {
      if (f.trim() === "") { err(e, "prázdny preklad"); continue; }
      const fPh = sortedMatches(f, PLACEHOLDER_RE);
      if (!ph.some((p) => sameList(p, fPh))) {
        err(e, `placeholdery sa nezhodujú: zdroj ${JSON.stringify(ph[0])}, preklad ${JSON.stringify(fPh)} | ${JSON.stringify(f)}`);
      }
      if (!tags.some((t) => sameList(t, sortedMatches(f, HTML_TAG_RE)))) {
        err(e, `HTML značky sa nezhodujú | ${JSON.stringify(f)}`);
      }
      if (!sameList(ticks, sortedMatches(f, BACKTICK_RE))) err(e, `text v backtickoch sa nesmie meniť | ${JSON.stringify(f)}`);
      for (const term of protectedTerms) {
        if (sources.some((s) => s.includes(term)) && !f.includes(term)) {
          err(e, `chýba nepreložiteľný výraz '${term}' | ${JSON.stringify(f)}`);
        }
      }
      if (f.includes("\uFFFD")) err(e, "obsahuje neplatný znak U+FFFD");
      const [fl, ft] = edgeSpace(f);
      if (fl !== lead || ft !== trail) err(e, `medzery na začiatku/konci sa musia zachovať | ${JSON.stringify(f)}`);
      if (e.id.length >= 15 && f.length > e.id.length * 1.6) warn(e, `preklad je >1,6× dlhší ako zdroj | ${JSON.stringify(f)}`);
    }
  }

  const head = readHeadDb();
  if (head && Array.isArray(head.entries)) {
    const byN = new Map(db.entries.map((e) => [e.n, e]));
    for (const old of head.entries) {
      const cur = byN.get(old.n);
      if (!cur) { err(old, "položka bola odstránená (nesmie sa mazať)"); continue; }
      for (const f of IDENTITY_FIELDS) {
        if (JSON.stringify(old[f]) !== JSON.stringify(cur[f])) err(old, `pole '${f}' sa nesmie meniť (bolo ${JSON.stringify(old[f])})`);
      }
      if (old.status === "reviewed" && (JSON.stringify(old.sk) !== JSON.stringify(cur.sk) || cur.status !== "reviewed")) {
        warn(cur, "skontrolovaný (reviewed) preklad bol zmenený");
      }
    }
  }
  return { errors, warnings };
}

export function summarize(db) {
  const byStatus = { todo: 0, ai: 0, reviewed: 0 };
  const areas = new Map();
  for (const e of db.entries) {
    byStatus[e.status] = (byStatus[e.status] || 0) + 1;
    const a = areas.get(e.area) || { total: 0, done: 0 };
    a.total++;
    if (isTranslated(e)) a.done++;
    areas.set(e.area, a);
  }
  return { total: db.entries.length, byStatus, areas };
}
