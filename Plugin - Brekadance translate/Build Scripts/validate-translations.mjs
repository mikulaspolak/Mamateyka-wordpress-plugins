import process from "node:process";
import { DB_PATH, loadDb, summarize, validateDb } from "./translations-lib.mjs";

const MAX_LINES = 60;
const verbose = process.argv.includes("--all");

const db = loadDb();
const { errors, warnings } = validateDb(db);
const { total, byStatus, areas } = summarize(db);

const print = (title, lines) => {
  if (lines.length === 0) return;
  console.log(`\n${title} (${lines.length}):`);
  for (const l of verbose ? lines : lines.slice(0, MAX_LINES)) console.log(`  ${l}`);
  if (!verbose && lines.length > MAX_LINES) console.log(`  … a ďalších ${lines.length - MAX_LINES} (spusti s --all)`);
};

print("CHYBY", errors);
print("Upozornenia", warnings);

console.log(`\nPoložiek: ${total} | todo: ${byStatus.todo} | ai: ${byStatus.ai} | reviewed: ${byStatus.reviewed}`);
if (process.argv.includes("--areas")) {
  for (const [area, a] of areas) console.log(`  ${String(a.done).padStart(4)}/${String(a.total).padEnd(4)} ${area}`);
}
console.log(errors.length === 0 ? `Validácia v poriadku (${DB_PATH}).` : `Validácia zlyhala: ${errors.length} chýb.`);
process.exit(errors.length === 0 ? 0 : 1);
