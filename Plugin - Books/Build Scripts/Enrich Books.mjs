import { promises as fs } from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const SCRIPT_DIR = path.dirname(fileURLToPath(import.meta.url));
const DEFAULT_INPUT = "../Databases/Kniznica DB.json";
const USER_AGENT = "MamateykaBookMetadataEnricher/1.0";
const OPEN_LIBRARY_FIELDS = [
  "key",
  "title",
  "author_name",
  "first_publish_year",
  "edition_key",
  "isbn",
  "publisher",
  "language",
  "number_of_pages_median",
  "cover_i",
  "edition_count",
  "editions",
  "editions.key",
  "editions.isbn",
  "editions.language",
  "editions.publisher",
  "editions.publish_year",
  "editions.number_of_pages_median",
  "editions.cover_i",
].join(",");

const MAX_ATTEMPTS = 5;
const MAX_GOOGLE_QUERIES = 3;
const MAX_KNIHOVNY_QUERIES = 4;
const MAX_SNK_QUERIES = 2;
const MAX_SNK_RECORDS = 3;
const KNIHOVNY_FAILURE_LIMIT = 5;
const SNK_FAILURE_LIMIT = 5;
const DESCRIPTION_LIMIT = 600;
const KNIHOVNY_FIELDS = [
  "id",
  "title",
  "authors",
  "publicationDates",
  "isbns",
  "publishers",
  "physicalDescriptions",
  "languages",
  "summary",
];
const EDITION_FIELDS = [
  "publisher",
  "published_year",
  "isbn",
  "page_count",
  "image",
];
const authorAliases = new Map();
const MAX_INCONCLUSIVE_RUNS = 3;
const GOOGLE_FAILURE_STREAK_LIMIT = 5;
const GOOGLE_COOLDOWN_MS = 60000;
const GOOGLE_MAX_COOLDOWNS = 3;
const MIN_IMAGE_BYTES = 2000;
const CHECK = {
  completed: "details_completed",
  alreadyComplete: "details_already_complete",
  partial: "details_partial_needs_verification",
  ambiguous: "ambiguous_needs_ai_check",
  notFound: "no_book_details_found",
  sourceError: "source_error_retry",
};
const CHECK_STATUS = {
  [CHECK.completed]: "matched",
  [CHECK.alreadyComplete]: "matched",
  [CHECK.partial]: "matched",
  [CHECK.ambiguous]: "ai-check",
  [CHECK.notFound]: "no-match",
  [CHECK.sourceError]: "no-match",
};
// Polia mimo schémy knihy, ktoré smieme pridať, ak ich skript nájde.
const EXTRA_BOOK_FIELDS = ["publisher"];
const SCRIPT_KEY_PREFIXES = ["script_check_", "script_checked_"];

const LANGUAGE_CODES = {
  slo: "sk",
  slk: "sk",
  cze: "cs",
  ces: "cs",
  ita: "it",
  eng: "en",
  lat: "la",
  ger: "de",
  deu: "de",
  fre: "fr",
  fra: "fr",
  spa: "es",
  pol: "pl",
  hun: "hu",
  por: "pt",
  rus: "ru",
  ukr: "uk",
};

function printHelp() {
  console.log(`Enrich a library JSON export using Google Books and Open Library.

Usage:
  node "Enrich Books.mjs" [options]

Options:
  --test-apis                    Test Google Books and Open Library using a sample book
  --snk                          Backward compatibility; SNK is also used automatically
  --input <path>                 Input JSON (default: ${DEFAULT_INPUT})
  --output <path>                Output JSON (default: update the input file in place)
  --limit <count>                Process at most this many pending books from the start
  --sample <count>               Process evenly spaced pending books from the catalogue
  --retry-no-match               Retry no_book_details_found and source_error_retry results
  --retry-all                    Retry all books, including those with script_check_result
  --save-every <count>           Save after this many books (default: 20)
  --google-delay-ms <ms>         Delay between Google requests (default: 1000)
  --openlibrary-delay-ms <ms>    Delay between Open Library requests
  --knihovny-delay-ms <ms>       Delay between knihovny.cz requests (default: 1000)
  --snk-delay-ms <ms>            Delay between SNK catalogue requests (default: 1000)
  --no-knihovny                  Do not use knihovny.cz
  --google-supplement             Query Google when knihovny.cz has only a cover or description
  --dry-run                      Count records without writing files or making API requests
  --help                         Show this help

Optional environment variables:
  GOOGLE_BOOKS_API_KEY           Google Books API key (never stored in this file)
  CONTACT_EMAIL                  Contact email for User-Agent headers; overrides OPEN_LIBRARY_CONTACT
  OPEN_LIBRARY_CONTACT           Open Library contact email; identified clients have a higher request limit`);
}

function parseArguments(args) {
  const options = {
    input: path.resolve(SCRIPT_DIR, DEFAULT_INPUT),
    output: null,
    testApis: false,
    limit: null,
    sample: null,
    retryNoMatch: false,
    retryAll: false,
    saveEvery: 20,
    googleDelayMs: 1000,
    openLibraryDelayMs: null,
    knihovnyDelayMs: 1000,
    googleSupplement: false,
    noKnihovny: false,
    snk: true,
    snkDelayMs: 1000,
    dryRun: false,
  };

  for (let i = 0; i < args.length; i += 1) {
    const arg = args[i];
    if (arg === "--help" || arg === "-h") {
      options.help = true;
      continue;
    }
    if (arg === "--test-apis") {
      options.testApis = true;
      continue;
    }
    if (arg === "--dry-run") {
      options.dryRun = true;
      continue;
    }
    if (arg === "--retry-no-match") {
      options.retryNoMatch = true;
      continue;
    }
    if (arg === "--retry-all") {
      options.retryAll = true;
      continue;
    }
    if (arg === "--google-supplement") {
      options.googleSupplement = true;
      continue;
    }
    if (arg === "--no-knihovny") {
      options.noKnihovny = true;
      continue;
    }
    if (arg === "--snk") {
      options.snk = true;
      continue;
    }

    const value = args[i + 1];
    if (!value || value.startsWith("--")) {
      throw new Error(`Možnosť ${arg} vyžaduje hodnotu.`);
    }
    i += 1;

    if (arg === "--input") options.input = path.resolve(value);
    else if (arg === "--output") options.output = path.resolve(value);
    else if (arg === "--limit")
      options.limit = parsePositiveInteger(value, "--limit");
    else if (arg === "--sample")
      options.sample = parsePositiveInteger(value, "--sample");
    else if (arg === "--save-every")
      options.saveEvery = parsePositiveInteger(value, "--save-every");
    else if (arg === "--google-delay-ms")
      options.googleDelayMs = parseNonNegativeInteger(value, arg);
    else if (arg === "--openlibrary-delay-ms")
      options.openLibraryDelayMs = parseNonNegativeInteger(value, arg);
    else if (arg === "--knihovny-delay-ms")
      options.knihovnyDelayMs = parseNonNegativeInteger(value, arg);
    else if (arg === "--snk-delay-ms")
      options.snkDelayMs = parseNonNegativeInteger(value, arg);
    else throw new Error(`Neznáma možnosť: ${arg}`);
  }

  if (options.limit !== null && options.sample !== null) {
    throw new Error("Možnosti --limit a --sample nemožno kombinovať.");
  }
  options.openLibraryDelayMs ??= process.env.OPEN_LIBRARY_CONTACT ? 350 : 1100;
  return options;
}

function parsePositiveInteger(value, name) {
  const number = Number(value);
  if (!Number.isSafeInteger(number) || number < 1) {
    throw new Error(`${name} musí byť kladné celé číslo.`);
  }
  return number;
}

function parseNonNegativeInteger(value, name) {
  const number = Number(value);
  if (!Number.isSafeInteger(number) || number < 0) {
    throw new Error(`${name} musí byť celé číslo 0 alebo väčšie.`);
  }
  return number;
}

function idFor(book, index) {
  if (
    book.id === null ||
    book.id === undefined ||
    String(book.id).trim() === ""
  ) {
    throw new Error(
      `Kniha na indexe ${index} nemá id; bezpečné priebežné ukladanie nie je možné.`,
    );
  }
  return String(book.id);
}

function missingValue(book, field) {
  const value = book[field];
  if (
    value === null ||
    value === undefined ||
    (typeof value === "string" && value.trim() === "")
  ) {
    return true;
  }
  if (field === "author" && typeof value === "string" && value.trim() === "-")
    return true;
  return (
    field === "image" &&
    typeof value === "string" &&
    /empty_book_cover\./i.test(value)
  );
}

function normalizeText(value) {
  return String(value ?? "")
    .normalize("NFD")
    .replace(/\p{Diacritic}/gu, "")
    .replace(/[łŁ]/g, "l")
    .replace(/[đĐ]/g, "d")
    .replace(/[øØ]/g, "o")
    .replace(/ß/g, "ss")
    .replace(/[æÆ]/g, "ae")
    .toLowerCase()
    .replace(/&/g, " and ")
    .replace(/[^a-z0-9]+/g, " ")
    .trim()
    .replace(/\s+/g, " ");
}

function tokenSimilarity(a, b) {
  const left = new Set(
    normalizeText(a).split(" ").filter(Boolean).map(stemSurname),
  );
  const right = new Set(
    normalizeText(b).split(" ").filter(Boolean).map(stemSurname),
  );
  if (left.size === 0 || right.size === 0) return 0;

  // Slová s drobným preklepom alebo inou koncovkou sa počítajú ako zhodné.
  const unused = new Set(right);
  let intersection = 0;
  for (const token of left) {
    let match = unused.has(token) ? token : null;
    if (!match && token.length >= 4) {
      match =
        [...unused].find(
          (other) => other.length >= 4 && editSimilarity(token, other) >= 0.8,
        ) ?? null;
    }
    if (match) {
      unused.delete(match);
      intersection += 1;
    }
  }
  const jaccard = intersection / (left.size + right.size - intersection);
  const containment = intersection / Math.min(left.size, right.size);
  return jaccard * 0.65 + containment * 0.35;
}

function editSimilarity(a, b) {
  const left = normalizeText(a).replace(/\s/g, "");
  const right = normalizeText(b).replace(/\s/g, "");
  if (!left || !right) return 0;
  if (left === right) return 1;

  let previous = Array.from({ length: right.length + 1 }, (_, index) => index);
  for (let i = 1; i <= left.length; i += 1) {
    const current = [i];
    for (let j = 1; j <= right.length; j += 1) {
      const substitutionCost = left[i - 1] === right[j - 1] ? 0 : 1;
      current[j] = Math.min(
        current[j - 1] + 1,
        previous[j] + 1,
        previous[j - 1] + substitutionCost,
      );
    }
    previous = current;
  }
  return 1 - previous[right.length] / Math.max(left.length, right.length);
}

function textSimilarity(source, candidate) {
  const left = normalizeText(source);
  const right = normalizeText(candidate);
  if (!left || !right) return 0;
  if (left === right) return 1;

  const tokenScore = tokenSimilarity(left, right);
  const editScore = editSimilarity(left, right);
  const leftTokens = left.split(" ");
  const rightTokens = right.split(" ");
  const containsAllShorter =
    leftTokens.every((token) => rightTokens.includes(token)) ||
    rightTokens.every((token) => leftTokens.includes(token));
  // Krátky všeobecný názov ("Don Bosco") obsiahnutý v dlhšom nie je dôkaz zhody.
  const shorterLength = Math.min(leftTokens.length, rightTokens.length);
  const longerLength = Math.max(leftTokens.length, rightTokens.length);
  const containmentScore =
    containsAllShorter &&
    shorterLength >= 3 &&
    shorterLength / longerLength >= 0.6
      ? 0.94
      : 0;

  return Math.max(tokenScore * 0.65 + editScore * 0.35, containmentScore);
}

// Slovenské ženské priezviská (Beccalossiová) sa v zdrojoch uvádzajú v mužskom tvare (Beccalossi).
function stemSurname(token) {
  return token.length > 5 && token.endsWith("ova") ? token.slice(0, -3) : token;
}

function catalogAuthorScore(inputAuthors, candidateAuthors) {
  const candidates = candidateAuthors.map((name) =>
    normalizeText(name).split(" ").filter(Boolean).map(stemSurname),
  );
  let best = 0;
  for (const { surname, initials } of inputAuthors) {
    for (const tokens of candidates) {
      let exact = true;
      let surnameIndex = tokens.indexOf(surname);
      if (surnameIndex === -1 && surname.length >= 4) {
        surnameIndex = tokens.findIndex(
          (token) =>
            token.length >= 4 && editSimilarity(token, surname) >= 0.85,
        );
        exact = false;
      }
      if (surnameIndex === -1) continue;
      const others = tokens.filter((_, index) => index !== surnameIndex);
      const initialsMatch =
        initials.length > 0 &&
        initials.every((initial) =>
          others.some((token) => token[0] === initial),
        );
      // Zdroje často uvádzajú iba krstné meno bez druhého, preto stačí zhoda prvej iniciály.
      const firstInitialMatch =
        initials.length > 0 && others.some((token) => token[0] === initials[0]);
      const score = initialsMatch
        ? 1
        : initials.length === 0
          ? 0.8
          : firstInitialMatch
            ? 0.85
            : 0.3;
      best = Math.max(best, exact ? score : score * 0.9);
    }
  }
  return best;
}

function scoreCandidate(book, candidate) {
  // Katalóg často uvádza názov bez podtitulu, ktorý Google vedie osobitne.
  let titleScore = Math.max(
    textSimilarity(book.title, candidate.title),
    candidate.mainTitle
      ? Math.min(0.98, textSimilarity(book.title, candidate.mainTitle))
      : 0,
  );
  const inputAuthor = String(book.author ?? "").trim();
  const inputAuthors = parseCatalogAuthors(inputAuthor);
  const candidateAuthors = candidate.authors ?? [];
  const authorScore =
    inputAuthors.length > 0
      ? Math.max(
          catalogAuthorScore(inputAuthors, candidateAuthors),
          textSimilarity(inputAuthor, candidateAuthors.join(" ")),
        )
      : null;
  // Zdroj môže pred názov katalógu pridať slovo (Blahoslavený ...); pri istom autorovi to stačí.
  if (authorScore !== null && authorScore >= 0.9) {
    const sourceTokens = normalizeText(candidate.title).split(" ");
    const catalogTokens = normalizeText(book.title).split(" ");
    if (
      catalogTokens.length >= 2 &&
      catalogTokens.every((token) => sourceTokens.includes(token))
    ) {
      titleScore = Math.max(titleScore, 0.92);
    }
  }
  const totalScore =
    authorScore === null ? titleScore : titleScore * 0.75 + authorScore * 0.25;
  return { titleScore, authorScore, totalScore };
}

function candidateIsStrong(book, score) {
  if (score.titleScore < 0.82) return false;
  if (score.authorScore !== null) {
    return score.authorScore >= 0.48 && score.totalScore >= 0.79;
  }
  return score.titleScore >= 0.97;
}

function normalizeGoogleCandidate(item) {
  const volume = item.volumeInfo ?? {};
  return {
    provider: "Google Books",
    id: item.id ?? null,
    title: [volume.title, volume.subtitle].filter(Boolean).join(": "),
    mainTitle: volume.title ?? null,
    authors: Array.isArray(volume.authors) ? volume.authors : [],
    publisher: volume.publisher ?? null,
    publishedDate: volume.publishedDate ?? null,
    description: volume.description ?? null,
    identifiers: (volume.industryIdentifiers ?? [])
      .map((entry) => entry.identifier)
      .filter(Boolean),
    pageCount: volume.pageCount ?? null,
    language: volume.language ?? null,
    imageUrl:
      volume.imageLinks?.thumbnail ?? volume.imageLinks?.smallThumbnail ?? null,
    sourceUrl: item.id
      ? `https://books.google.com/books?id=${encodeURIComponent(item.id)}`
      : null,
  };
}

function normalizeOpenLibraryCandidate(item) {
  // Údaje o vydaní sú spoľahlivé iba vtedy, keď dielo má práve jedno vydanie.
  const editions = item.editions?.docs ?? [];
  const edition =
    item.editions?.numFound === 1 && editions.length === 1 ? editions[0] : null;
  const list = (value) => (Array.isArray(value) ? value : []);
  const coverId = edition ? (edition.cover_i ?? item.cover_i) : null;
  return {
    provider: "Open Library",
    id: item.key ?? null,
    title: item.title ?? "",
    authors: list(item.author_name),
    publisher: edition ? list(edition.publisher) : list(item.publisher),
    publishedDate:
      edition?.publish_year?.[0] ?? item.first_publish_year ?? null,
    description: null,
    identifiers: edition ? list(edition.isbn) : list(item.isbn),
    pageCount: edition
      ? (edition.number_of_pages_median ?? null)
      : (item.number_of_pages_median ?? null),
    language: edition ? list(edition.language) : list(item.language),
    imageUrl: coverId
      ? `https://covers.openlibrary.org/b/id/${coverId}-L.jpg?default=false`
      : null,
    sourceUrl: edition?.key
      ? `https://openlibrary.org${edition.key}`
      : item.key
        ? `https://openlibrary.org${item.key}`
        : null,
    editionCount: item.edition_count ?? null,
    editionLevel: edition !== null,
  };
}

// Rôzne vydania tej istej knihy (rovnaký názov a aspoň jeden spoločný autor) nepovažujeme za konkurenčné kandidáty.
function sameWork(a, b) {
  if (textSimilarity(a.candidate.title, b.candidate.title) < 0.97) return false;
  const tokens = (entry) =>
    new Set(
      entry.candidate.authors
        .flatMap((name) => normalizeText(name).split(" "))
        .filter((token) => token.length > 2),
    );
  const left = tokens(a);
  const right = tokens(b);
  if (left.size === 0 || right.size === 0) return left.size === right.size;
  return [...left].some((token) => right.has(token));
}

function assessCandidates(book, candidates) {
  const ranked = candidates
    .filter((candidate) => normalizeText(candidate.title))
    .map((candidate) => ({ candidate, score: scoreCandidate(book, candidate) }))
    .sort((a, b) => b.score.totalScore - a.score.totalScore);

  if (ranked.length === 0) return { match: null, ranked };
  const best = ranked[0];
  if (!candidateIsStrong(book, best.score)) return { match: null, ranked };

  const rivals = ranked.slice(1).filter((entry) => !sameWork(best, entry));
  const margin =
    rivals.length > 0 ? best.score.totalScore - rivals[0].score.totalScore : 1;
  // Bez autora sa celé skóre opiera iba o názov, preto je podmienka pri vysokom skóre názvu užšia.
  const minMargin = best.score.authorScore === null ? 0.03 : 0.08;
  if (margin < minMargin) return { match: null, ranked };
  return { match: best, ranked };
}

function validIsbn(value) {
  const isbn = String(value ?? "")
    .replace(/[^0-9Xx]/g, "")
    .toUpperCase();
  if (/^\d{13}$/.test(isbn)) {
    let sum = 0;
    for (let i = 0; i < 13; i += 1)
      sum += Number(isbn[i]) * (i % 2 === 0 ? 1 : 3);
    return sum % 10 === 0 ? isbn : null;
  }
  if (/^\d{9}[\dX]$/.test(isbn)) {
    let sum = 0;
    for (let i = 0; i < 10; i += 1) {
      const digit = isbn[i] === "X" ? 10 : Number(isbn[i]);
      sum += digit * (10 - i);
    }
    return sum % 11 === 0 ? isbn : null;
  }
  return null;
}

function uniqueValue(value) {
  const values = (Array.isArray(value) ? value : [value])
    .map((entry) => String(entry ?? "").trim())
    .filter(
      (entry) => entry && !/^\[?(unknown|n\/a|s\.?\s?n\.?)\]?$/i.test(entry),
    );
  const distinct = [...new Set(values)];
  return distinct.length === 1 ? distinct[0] : null;
}

function yearFrom(value) {
  const match = String(value ?? "").match(
    /(?:^|[^\d])((?:1[5-9]|20|21)\d{2})(?:[^\d]|$)/,
  );
  if (!match) return null;
  const year = Number(match[1]);
  return year >= 1450 && year <= new Date().getFullYear() + 1 ? year : null;
}

function positiveInteger(value) {
  const number = Number(value);
  return Number.isSafeInteger(number) && number > 0 ? number : null;
}

function secureImageUrl(value) {
  if (!value) return null;
  try {
    const url = new URL(String(value).replace(/^http:/i, "https:"));
    if (url.protocol !== "https:") return null;
    if (url.hostname.endsWith("google.com")) url.searchParams.delete("edge");
    return url.toString();
  } catch {
    return null;
  }
}

function languageCode(value) {
  const code =
    String(value ?? "")
      .split("/")
      .filter(Boolean)
      .pop()
      ?.trim()
      .toLowerCase() ?? "";
  return code ? (LANGUAGE_CODES[code] ?? code) : null;
}

function preferredIsbn(values) {
  const isbns = [...new Set(values.map(validIsbn).filter(Boolean))];
  return isbns.find((isbn) => isbn.length === 13) ?? isbns[0] ?? null;
}

function dataFromGoogle(candidate) {
  const fields = {};
  const sources = {};
  const put = (field, value) => {
    if (value !== null && value !== undefined && value !== "") {
      fields[field] = value;
      sources[field] = {
        provider: candidate.provider,
        url: candidate.sourceUrl,
      };
    }
  };

  put("author", candidate.authors[0] ?? null);
  if (candidate.authors.length > 1)
    put("co_authors", candidate.authors.slice(1).join("; "));
  put("publisher", uniqueValue(candidate.publisher));
  put("published_year", yearFrom(candidate.publishedDate));
  put("isbn", preferredIsbn(candidate.identifiers));
  put("page_count", positiveInteger(candidate.pageCount));
  put("language", languageCode(candidate.language));
  put("description", shortDescription(candidate.description));
  put("image", secureImageUrl(candidate.imageUrl));
  return { fields, sources, warnings: [] };
}

function dataFromOpenLibrary(candidate) {
  const fields = {};
  const sources = {};
  const warnings = [];
  const put = (field, value, detail = null) => {
    if (value !== null && value !== undefined && value !== "") {
      fields[field] = value;
      sources[field] = {
        provider: candidate.provider,
        url: candidate.sourceUrl,
        ...(detail ? { detail } : {}),
      };
    }
  };

  put("author", candidate.authors[0] ?? null);
  if (candidate.authors.length > 1)
    put("co_authors", candidate.authors.slice(1).join("; "));
  put("publisher", uniqueValue(candidate.publisher));
  put(
    "published_year",
    yearFrom(candidate.publishedDate),
    candidate.editionLevel
      ? "publication year of the only known edition"
      : "first publication year of the work",
  );
  if (candidate.editionLevel) {
    put("isbn", preferredIsbn(candidate.identifiers));
    put("page_count", positiveInteger(candidate.pageCount));
    put("image", secureImageUrl(candidate.imageUrl));
  } else {
    const identifiers = new Set(
      candidate.identifiers.map(validIsbn).filter(Boolean),
    );
    if (identifiers.size === 1) put("isbn", [...identifiers][0]);
    else if (identifiers.size > 1)
      warnings.push(
        "Open Library uvádza viac ISBN pre rôzne vydania; ISBN nebolo automaticky vybrané.",
      );
    if (candidate.imageUrl)
      warnings.push(
        "Open Library uvádza obálku diela, nie konkrétneho vydania; obálka nebola použitá.",
      );
    if (candidate.pageCount) {
      warnings.push(
        "Open Library uvádza medián počtu strán naprieč vydaniami; počet strán nebol automaticky doplnený.",
      );
    }
  }
  put("language", languageCode(uniqueValue(candidate.language)));
  return { fields, sources, warnings };
}

function candidateForReport(candidate, score) {
  return {
    provider: candidate.provider,
    title: candidate.title,
    authors: candidate.authors,
    publisher: Array.isArray(candidate.publisher)
      ? candidate.publisher
      : candidate.publisher,
    published_year: yearFrom(candidate.publishedDate),
    isbn_candidates: candidate.identifiers,
    page_count_or_median: positiveInteger(candidate.pageCount),
    language: candidate.language,
    source_url: candidate.sourceUrl,
    confidence_scores: {
      title: Number(score.titleScore.toFixed(3)),
      author:
        score.authorScore === null
          ? null
          : Number(score.authorScore.toFixed(3)),
      overall: Number(score.totalScore.toFixed(3)),
    },
    ...(candidate.description
      ? { description_excerpt: String(candidate.description).slice(0, 1200) }
      : {}),
  };
}

const AUTHOR_NOISE_TOKENS = new Set([
  "sdb",
  "sj",
  "op",
  "ofm",
  "osb",
  "ssp",
  "cssr",
  "fsc",
  "csc",
  "ocd",
  "ofmcap",
  "mons",
  "thdr",
  "phdr",
  "judr",
  "mudr",
  "ing",
  "mgr",
  "doc",
  "prof",
  "ml",
  "st",
  "jr",
  "sr",
  "sv",
]);

// Autori v katalógu majú tvar "Priezvisko I." a viacerí sú oddelení čiarkou, bodkočiarkou alebo ".-".
function parseCatalogAuthors(value) {
  return String(value ?? "")
    .split(/[;,]|\.\s*-\s*|\s-\s/)
    .map((part) => part.trim())
    .filter((part) => /\p{L}/u.test(part))
    .map((part) => {
      const tokens = normalizeText(part)
        .split(" ")
        .filter((token) => token && !AUTHOR_NOISE_TOKENS.has(token));
      return {
        surname: tokens[0] ? stemSurname(tokens[0]) : null,
        initials: tokens.slice(1).map((token) => token[0]),
      };
    })
    .filter((entry) => entry.surname);
}

function coreTitle(title) {
  const core = title
    .split(/\s*[:\u2013\u2014]\s*|\s+-\s+/)[0]
    .replace(/\s*\([^)]*\)\s*$/, "")
    .trim();
  return normalizeText(core).length >= 3 ? core : title;
}

function queryParts(book) {
  const title = String(book.title ?? "").trim();
  const authors = parseCatalogAuthors(book.author);
  const author = authors.length > 0 ? String(book.author).trim() : "";
  const surname = authors[0]
    ? (authorAliases.get(authors[0].surname) ?? authors[0].surname)
    : null;
  const core = coreTitle(title);

  const googleQueries = [];
  const addGoogle = (...parts) => {
    const query = parts.filter(Boolean).join(" ").trim();
    if (query && !googleQueries.includes(query)) googleQueries.push(query);
  };
  // Voľný text funguje lepšie ako `intitle:` (ten vracia časté 503 a prázdne odpovede).
  // Dotazy sú od najpresnejšieho po najvoľnejší a každý míňa dennú kvótu Google.
  const isbn = validIsbn(book.isbn);
  if (isbn) addGoogle(`isbn:${isbn}`);
  const withoutVolume = core.replace(/\s+(?:[IVX]+|\d+)\.?$/, "").trim();
  const words = core.split(/\s+/);
  addGoogle(title, surname);
  if (surname) addGoogle(title);
  if (core !== title) addGoogle(core, surname);
  if (withoutVolume !== core && normalizeText(withoutVolume).length >= 3)
    addGoogle(withoutVolume, surname);
  if (words.length >= 5) addGoogle(words.slice(0, 3).join(" "), surname);
  googleQueries.splice(MAX_GOOGLE_QUERIES);

  const openLibraryQueries = [];
  if (isbn) openLibraryQueries.push({ isbn });
  if (surname) openLibraryQueries.push({ title, author: surname });
  openLibraryQueries.push({ title, author: null });

  const snkQueries = [...new Set([title, core].filter(Boolean))].slice(
    0,
    MAX_SNK_QUERIES,
  );

  // knihovny.cz nemá kvótu; typ Title vyžaduje všetky slová, preto ide od celého názvu po skrátený.
  const knihovnyQueries = [];
  const addKnihovny = (type, text) => {
    const clean = text.replace(/[^\p{L}\p{N}]+/gu, " ").trim();
    if (
      clean &&
      !knihovnyQueries.some(
        (query) => query.type === type && query.text === clean,
      )
    ) {
      knihovnyQueries.push({ type, text: clean });
    }
  };
  addKnihovny("Title", title);
  if (core !== title) addKnihovny("Title", core);
  if (withoutVolume !== core) addKnihovny("Title", withoutVolume);
  if (surname) addKnihovny("AllFields", `${core} ${surname}`);
  if (words.length >= 5) addKnihovny("Title", words.slice(0, 3).join(" "));
  knihovnyQueries.splice(MAX_KNIHOVNY_QUERIES);

  return {
    title,
    author,
    authors,
    googleQueries,
    openLibraryQueries,
    knihovnyQueries,
    snkQueries,
  };
}

async function writeAtomic(file, data) {
  await fs.mkdir(path.dirname(file), { recursive: true });
  const temporary = `${file}.tmp-${process.pid}`;
  await fs.writeFile(temporary, data, "utf8");
  await fs.rename(temporary, file);
}

function retryAfterMs(response) {
  const value = response.headers.get("retry-after");
  if (!value) return null;
  const seconds = Number(value);
  if (Number.isFinite(seconds)) return Math.max(0, seconds * 1000);
  const date = Date.parse(value);
  return Number.isNaN(date) ? null : Math.max(0, date - Date.now());
}

const lastRequestAt = new Map();

async function waitForProvider(provider, delayMs) {
  const last = lastRequestAt.get(provider);
  if (last !== undefined) {
    const remaining = delayMs - (Date.now() - last);
    if (remaining > 0)
      await new Promise((resolve) => setTimeout(resolve, remaining));
  }
  lastRequestAt.set(provider, Date.now());
}

function logDetail(message) {
  console.log(`      ${message}`);
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function backoffMs(attempt, ceilingMs = 30000) {
  const base = Math.min(2000 * 2 ** (attempt - 1), ceilingMs);
  return Math.round(base * (0.75 + Math.random() * 0.5));
}

function errorMessageFromBody(body) {
  try {
    const message = JSON.parse(body)?.error?.message;
    if (typeof message === "string") return message.slice(0, 160);
  } catch {
    // telo nie je JSON
  }
  return body.slice(0, 160);
}

// Kvóta Google sa obnovuje o polnoci tichomorského času.
const usage = { day: null, google: 0 };

function quotaDay() {
  return new Date().toLocaleDateString("en-CA", {
    timeZone: "America/Los_Angeles",
  });
}

function restoreUsage(saved) {
  usage.day = quotaDay();
  usage.google = saved?.day === usage.day ? Number(saved.google) || 0 : 0;
}

// meta.degraded sa nastaví, ak počas požiadavky zlyhal aspoň jeden pokus (5xx, 429, sieť, neplatný JSON).
async function requestPayload(
  url,
  provider,
  delayMs,
  maxAttempts,
  meta,
  responseType,
) {
  if (provider === "snk") {
    if (process.env.NODE_USE_SYSTEM_CA === "0") {
      throw new Error(
        "SNK vyžaduje systémové certifikáty; nastavte NODE_USE_SYSTEM_CA=1.",
      );
    }
    process.env.NODE_USE_SYSTEM_CA ??= "1";
  }
  let lastError;
  for (let attempt = 1; attempt <= maxAttempts; attempt += 1) {
    if (provider === "google") {
      if (usage.day !== quotaDay()) restoreUsage(null);
      usage.google += 1;
    }
    await waitForProvider(provider, delayMs);
    let response;
    try {
      const accept =
        provider === "snk"
          ? responseType === "buffer"
            ? "application/MARC"
            : "text/html,application/xhtml+xml"
          : "application/json";
      const headers = { Accept: accept, "User-Agent": USER_AGENT };
      const contact =
        process.env.CONTACT_EMAIL ?? process.env.OPEN_LIBRARY_CONTACT;
      if (provider !== "google" && contact)
        headers["User-Agent"] = `${USER_AGENT} (contact: ${contact})`;
      response = await fetch(url, {
        headers,
        signal: AbortSignal.timeout(30000),
      });
    } catch (error) {
      meta.degraded = true;
      lastError = new Error(
        `${provider}: požiadavka zlyhala (${error.message})`,
      );
      logDetail(
        `[${provider}] pokus ${attempt}/${maxAttempts}: sieťová chyba — ${error.message}`,
      );
      if (attempt < maxAttempts) {
        await sleep(backoffMs(attempt, 15000));
        continue;
      }
      break;
    }

    if (!response.ok) {
      const retryable = response.status === 429 || response.status >= 500;
      if (retryable) meta.degraded = true;
      lastError = new Error(
        `${provider}: HTTP ${response.status} ${response.statusText}`,
      );
      const body = (await response.text().catch(() => "")).replace(/\s+/g, " ");
      const detail = response.status >= 500 ? "" : errorMessageFromBody(body);
      logDetail(
        `[${provider}] pokus ${attempt}/${maxAttempts}: HTTP ${response.status} ${response.statusText}${detail ? ` — ${detail}` : ""}`,
      );
      const dailyQuotaExhausted = /Queries per day|per day/i.test(body);
      if (dailyQuotaExhausted) lastError.dailyQuota = true;
      if (retryable && !dailyQuotaExhausted && attempt < maxAttempts) {
        await sleep(retryAfterMs(response) ?? backoffMs(attempt));
        continue;
      }
      break;
    }

    try {
      if (responseType === "json") return await response.json();
      if (responseType === "text") return await response.text();
      return Buffer.from(await response.arrayBuffer());
    } catch (error) {
      meta.degraded = true;
      const message =
        responseType === "json"
          ? `odpoveď nie je platný JSON (${error.message})`
          : `odpoveď sa nepodarilo načítať (${error.message})`;
      lastError = new Error(`${provider}: ${message}`);
      logDetail(`[${provider}] pokus ${attempt}/${maxAttempts}: ${message}`);
      if (attempt < maxAttempts) await sleep(backoffMs(attempt, 15000));
    }
  }
  throw (
    lastError ?? new Error(`${provider}: požiadavka zlyhala bez podrobností.`)
  );
}

function requestJson(
  url,
  provider,
  delayMs,
  maxAttempts = MAX_ATTEMPTS,
  meta = {},
) {
  return requestPayload(url, provider, delayMs, maxAttempts, meta, "json");
}

function requestText(
  url,
  provider,
  delayMs,
  maxAttempts = MAX_ATTEMPTS,
  meta = {},
) {
  return requestPayload(url, provider, delayMs, maxAttempts, meta, "text");
}

function requestBuffer(
  url,
  provider,
  delayMs,
  maxAttempts = MAX_ATTEMPTS,
  meta = {},
) {
  return requestPayload(url, provider, delayMs, maxAttempts, meta, "buffer");
}

// Skúša dotazy postupne, až kým nenájde jednoznačnú zhodu. Prázdny výsledok po chybe služby nie je konečný.
async function searchWithFallbacks(book, provider, queries, fetchQuery) {
  const collected = [];
  const seen = new Set();
  let lastError = null;
  let cleanQueries = 0;

  for (const query of queries) {
    const meta = { degraded: false };
    let candidates;
    try {
      candidates = await fetchQuery(query, meta);
    } catch (error) {
      if (/\bHTTP 429\b/.test(error.message) || error.dailyQuota) throw error;
      lastError = error;
      continue;
    }
    // Dotaz, ktorý vrátil 0 výsledkov po chybe služby (napr. 503), nie je spoľahlivý.
    if (candidates.length > 0 || !meta.degraded) cleanQueries += 1;
    for (const candidate of candidates) {
      if (candidate.id) {
        if (seen.has(candidate.id)) continue;
        seen.add(candidate.id);
      }
      collected.push(candidate);
    }
    if (assessCandidates(book, collected).match) break;
  }

  if (collected.length === 0 && cleanQueries === 0) {
    throw (
      lastError ??
      new Error(
        `${provider}: prázdna odpoveď po chybe služby; výsledok nie je konečný`,
      )
    );
  }
  return collected;
}

async function getGoogleCandidates(book, options, maxAttempts = MAX_ATTEMPTS) {
  const { googleQueries } = queryParts(book);
  return searchWithFallbacks(
    book,
    "google",
    googleQueries,
    async (query, meta) => {
      const params = new URLSearchParams({
        q: query,
        maxResults: "10",
        printType: "books",
        fields:
          "items(id,volumeInfo(title,subtitle,authors,publisher,publishedDate,description,industryIdentifiers,pageCount,language,imageLinks))",
      });
      if (process.env.GOOGLE_BOOKS_API_KEY)
        params.set("key", process.env.GOOGLE_BOOKS_API_KEY);
      const response = await requestJson(
        `https://www.googleapis.com/books/v1/volumes?${params.toString()}`,
        "google",
        options.googleDelayMs,
        maxAttempts,
        meta,
      );
      const items = Array.isArray(response.items) ? response.items : [];
      logDetail(`[Google] dotaz ${query}: ${items.length} výsledkov`);
      return items.map(normalizeGoogleCandidate);
    },
  );
}

async function getOpenLibraryCandidates(
  book,
  options,
  maxAttempts = MAX_ATTEMPTS,
) {
  const { openLibraryQueries } = queryParts(book);
  return searchWithFallbacks(
    book,
    "openlibrary",
    openLibraryQueries,
    async (query, meta) => {
      const params = new URLSearchParams({
        limit: "25",
        fields: OPEN_LIBRARY_FIELDS,
      });
      if (query.title) params.set("title", query.title);
      if (query.isbn) params.set("isbn", query.isbn);
      if (query.author) params.set("author", query.author);
      const response = await requestJson(
        `https://openlibrary.org/search.json?${params.toString()}`,
        "openlibrary",
        options.openLibraryDelayMs,
        maxAttempts,
        meta,
      );
      const docs = Array.isArray(response.docs) ? response.docs : [];
      logDetail(
        `[Open Library] ${query.isbn ? `isbn="${query.isbn}"` : `title="${query.title}"`}` +
          `${query.author ? ` author="${query.author}"` : ""}: ${docs.length} výsledkov (numFound ${response.numFound ?? "n/a"})`,
      );
      return docs.map(normalizeOpenLibraryCandidate);
    },
  );
}
// Popis má mať pár viet: odstránime HTML, zjednotíme medzery a skrátime na hranici vety.
function shortDescription(value, limit = DESCRIPTION_LIMIT) {
  const text = String(value ?? "")
    .replace(/<[^>]*>/g, " ")
    .replace(/&nbsp;/gi, " ")
    .replace(/&quot;/gi, '"')
    .replace(/&#39;|&apos;/gi, "'")
    .replace(/&lt;/gi, "<")
    .replace(/&gt;/gi, ">")
    .replace(/&amp;/gi, "&")
    .replace(/\s+/g, " ")
    .trim();
  if (text.length < 20) return null;
  if (text.length <= limit) return text;
  const cut = text.slice(0, limit);
  const sentenceEnd = Math.max(
    cut.lastIndexOf(". "),
    cut.lastIndexOf("! "),
    cut.lastIndexOf("? "),
  );
  if (sentenceEnd >= 200) return cut.slice(0, sentenceEnd + 1);
  const wordEnd = cut.lastIndexOf(" ");
  return `${wordEnd > 0 ? cut.slice(0, wordEnd) : cut}…`;
}

const PUBLISHER_LIKE =
  /nakladatelstv|vydavate[ľl]stv|s\.\s?r\.\s?o|a\.\s?s\.|spole[čc]nost|spolok|ústav|institut/i;

function cleanPersonName(value) {
  const name = String(value ?? "")
    .replace(/[,\s]*\(?\d{3,4}\??\s*-\s*(?:\d{3,4})?\)?\.?\s*$/, "")
    .replace(/[,\s]+$/, "")
    .trim();
  if (!name || PUBLISHER_LIKE.test(name)) return null;
  const inverted = name.match(/^([^,]+),\s*([^,]+)$/);
  return inverted ? `${inverted[2].trim()} ${inverted[1].trim()}` : name;
}

function pageCountFromDescription(values) {
  for (const text of Array.isArray(values) ? values : []) {
    const match = String(text).match(
      /(\d{1,4})\s*(?:s\.|s\b|stran|strán|str\.|p\.|pages)/i,
    );
    if (match) return positiveInteger(match[1]);
  }
  return null;
}

function normalizeKnihovnyCandidate(record) {
  const names = (group) =>
    (group && !Array.isArray(group) ? Object.keys(group) : [])
      .map(cleanPersonName)
      .filter(Boolean);
  const primary = names(record.authors?.primary);
  const secondary = names(record.authors?.secondary);
  const rawTitle = String(record.title ?? "")
    .replace(/\s*[/:;]\s*$/, "")
    .trim();
  const summaries = Array.isArray(record.summary)
    ? record.summary
    : [record.summary];
  return {
    provider: "knihovny.cz",
    id: record.id ?? null,
    title: rawTitle.replace(/\s+:\s+/, ": "),
    mainTitle: rawTitle.split(/\s+:\s+/)[0],
    authors: [...new Set([...primary, ...secondary])],
    primaryAuthors: primary,
    publisher: (record.publishers ?? [])
      .map((value) =>
        String(value)
          .replace(/[\s,:;]+$/, "")
          .trim(),
      )
      .filter(Boolean),
    publishedDate: record.publicationDates?.[0] ?? null,
    description:
      summaries.find((text) => typeof text === "string" && text.trim()) ?? null,
    identifiers: (record.isbns ?? []).map(
      (value) => String(value).split(/[\s(]/)[0],
    ),
    pageCount: pageCountFromDescription(record.physicalDescriptions),
    language: (record.languages ?? [])[0] ?? null,
    imageUrl: null,
    sourceUrl: record.id
      ? `https://www.knihovny.cz/Record/${encodeURIComponent(record.id)}`
      : null,
    editionLevel: true,
  };
}

async function getKnihovnyCandidates(
  book,
  options,
  maxAttempts = MAX_ATTEMPTS,
) {
  const { knihovnyQueries } = queryParts(book);
  return searchWithFallbacks(
    book,
    "knihovny",
    knihovnyQueries,
    async (query, meta) => {
      const params = new URLSearchParams({
        lookfor: query.text,
        type: query.type,
        limit: "20",
      });
      for (const field of KNIHOVNY_FIELDS) params.append("field[]", field);
      const response = await requestJson(
        `https://www.knihovny.cz/api/v1/search?${params.toString()}`,
        "knihovny",
        options.knihovnyDelayMs,
        maxAttempts,
        meta,
      );
      const records = Array.isArray(response.records) ? response.records : [];
      logDetail(
        `[knihovny.cz] ${query.type} "${query.text}": ${records.length} výsledkov (resultCount ${response.resultCount ?? "n/a"})`,
      );
      return records.map(normalizeKnihovnyCandidate);
    },
  );
}

function parseMarcRecord(buffer) {
  if (!Buffer.isBuffer(buffer) || buffer.length < 25) {
    throw new Error("SNK vrátilo neúplný MARC záznam.");
  }
  const recordLength = Number(buffer.subarray(0, 5).toString("ascii"));
  const baseAddress = Number(buffer.subarray(12, 17).toString("ascii"));
  if (
    !Number.isSafeInteger(recordLength) ||
    recordLength > buffer.length ||
    recordLength < 25 ||
    !Number.isSafeInteger(baseAddress) ||
    baseAddress < 25 ||
    baseAddress > recordLength ||
    buffer.toString("ascii", 9, 10) !== "a"
  ) {
    throw new Error("SNK vrátilo nepodporovaný alebo poškodený MARC záznam.");
  }

  const directoryEnd = buffer.indexOf(0x1e, 24);
  if (directoryEnd !== baseAddress - 1 || (directoryEnd - 24) % 12 !== 0) {
    throw new Error("SNK MARC záznam má neplatný adresár polí.");
  }

  const fields = new Map();
  for (let offset = 24; offset < directoryEnd; offset += 12) {
    const tag = buffer.toString("ascii", offset, offset + 3);
    const length = Number(buffer.toString("ascii", offset + 3, offset + 7));
    const start = Number(buffer.toString("ascii", offset + 7, offset + 12));
    const fieldStart = baseAddress + start;
    const fieldEnd = fieldStart + length;
    if (
      !/^\d{3}$/.test(tag) ||
      !Number.isSafeInteger(length) ||
      length < 1 ||
      !Number.isSafeInteger(start) ||
      fieldStart < baseAddress ||
      fieldEnd > recordLength ||
      buffer[fieldEnd - 1] !== 0x1e
    ) {
      throw new Error(`SNK MARC záznam obsahuje poškodené pole ${tag}.`);
    }
    const value = new TextDecoder("utf-8", { fatal: true }).decode(
      buffer.subarray(fieldStart, fieldEnd - 1),
    );
    const values = fields.get(tag) ?? [];
    values.push(value);
    fields.set(tag, values);
  }
  return fields;
}

function marcSubfields(field) {
  const values = {};
  for (const part of field.slice(2).split("\u001f").slice(1)) {
    const code = part[0];
    if (!code) continue;
    (values[code] ??= []).push(part.slice(1).trim());
  }
  return values;
}

function normalizeSnkCandidate(recordId, buffer) {
  const record = parseMarcRecord(buffer);
  const subfields = (tag) => (record.get(tag) ?? []).map(marcSubfields);
  const values = (tag, code) =>
    subfields(tag).flatMap((field) => field[code] ?? []);
  const titleParts = (codes) =>
    codes
      .flatMap((code) => values("245", code).slice(0, 1))
      .filter(Boolean)
      .join(" ")
      .replace(/\s+([,.;:])/g, "$1")
      .replace(/\s*[/;:]\s*$/, "")
      .trim();
  const mainTitle = titleParts(["a", "p"]);
  const title = titleParts(["a", "b", "n", "p"]);
  const mainAuthor = cleanPersonName(values("100", "a")[0]);
  const authorContributors = subfields("700")
    .filter((field) =>
      [...(field["4"] ?? []), ...(field.e ?? [])].some((role) =>
        /\baut\b|author|auteur/i.test(role),
      ),
    )
    .map((field) => cleanPersonName(field.a?.[0]))
    .filter(Boolean);
  const dates = [...values("264", "c"), ...values("260", "c")];
  const identifiers = [...values("020", "a"), ...values("020", "z")];
  const language =
    values("041", "a")[0] ?? (record.get("008")?.[0]?.slice(35, 38) || null);
  return {
    provider: "SNK",
    id: recordId,
    title,
    mainTitle,
    authors: [...new Set([mainAuthor, ...authorContributors].filter(Boolean))],
    publisher: uniqueValue([...values("264", "b"), ...values("260", "b")]),
    publishedDate: dates[0] ?? null,
    description: values("520", "a").join(" ").trim() || null,
    identifiers,
    pageCount: pageCountFromDescription(values("300", "a")),
    language,
    imageUrl: null,
    sourceUrl: `https://katalog.snk.sk/vufind/Record/${encodeURIComponent(recordId)}`,
    editionLevel: true,
  };
}

async function getSnkCandidates(book, options, maxAttempts = MAX_ATTEMPTS) {
  const { snkQueries } = queryParts(book);
  return searchWithFallbacks(book, "snk", snkQueries, async (query, meta) => {
    const params = new URLSearchParams({
      lookfor: query,
      type: "AllFields",
      limit: "10",
    });
    const html = await requestText(
      `https://katalog.snk.sk/vufind/Search/Results?${params.toString()}`,
      "snk",
      options.snkDelayMs,
      maxAttempts,
      meta,
    );
    const recordIds = [
      ...new Set(
        [
          ...html.matchAll(
            /href=["'](?:https:\/\/katalog\.snk\.sk)?\/vufind\/Record\/(\d+)(?:[?#][^"']*)?["']/gi,
          ),
        ].map((match) => match[1]),
      ),
    ];
    logDetail(`[SNK] Title "${query}": ${recordIds.length} výsledkov`);

    const candidates = [];
    for (const recordId of recordIds.slice(0, MAX_SNK_RECORDS)) {
      const record = await requestBuffer(
        `https://katalog.snk.sk/vufind/Record/${encodeURIComponent(recordId)}/Export?style=MARC`,
        "snk",
        options.snkDelayMs,
        maxAttempts,
        meta,
      );
      candidates.push(normalizeSnkCandidate(recordId, record));
    }
    return candidates;
  });
}

// Ďalšie vydania tej istej knihy, ktoré sa skórom blížia k najlepšiemu záznamu.
function sameWorkEditions(assessment) {
  const best = assessment.match;
  return assessment.ranked
    .filter(
      (entry) =>
        entry !== best &&
        sameWork(best, entry) &&
        entry.score.totalScore >= best.score.totalScore - 0.03,
    )
    .slice(0, 15);
}

function dataFromKnihovny(match, group) {
  const editions = [match, ...group].map((entry) => entry.candidate);
  const first = editions[0];
  const fields = {};
  const sources = {};
  const warnings = [];
  const put = (field, value) => {
    if (value !== null && value !== undefined && value !== "") {
      fields[field] = value;
      sources[field] = { provider: first.provider, url: first.sourceUrl };
    }
  };

  put("author", first.primaryAuthors[0] ?? null);
  if (first.primaryAuthors.length > 1)
    put("co_authors", first.primaryAuthors.slice(1).join("; "));
  put(
    "description",
    shortDescription(
      editions.map((edition) => edition.description).find(Boolean),
    ),
  );
  put(
    "language",
    languageCode(
      uniqueValue(editions.map((edition) => edition.language).filter(Boolean)),
    ),
  );

  const editionValues = {
    publisher: editions.map((edition) => uniqueValue(edition.publisher)),
    published_year: editions.map((edition) => yearFrom(edition.publishedDate)),
    isbn: editions.map((edition) => preferredIsbn(edition.identifiers)),
    page_count: editions.map((edition) => positiveInteger(edition.pageCount)),
  };
  let ambiguous = false;
  for (const [field, values] of Object.entries(editionValues)) {
    if (values.every((value) => value !== null && value === values[0]))
      put(field, values[0]);
    else if (editions.length > 1 && values.some((value) => value !== null))
      ambiguous = true;
  }
  if (ambiguous) {
    warnings.push(
      `knihovny.cz: ${editions.length} vydaní tejto knihy; nejednoznačné údaje o vydaní neboli zapísané.`,
    );
  }
  return {
    fields,
    sources,
    warnings,
    years: new Set(editionValues.published_year.filter(Boolean)),
    editionOptions: ambiguous
      ? editions.map((edition) => ({
          year: yearFrom(edition.publishedDate),
          publisher: uniqueValue(edition.publisher),
          isbn: preferredIsbn(edition.identifiers),
          pages: positiveInteger(edition.pageCount),
          source_url: edition.sourceUrl,
        }))
      : undefined,
  };
}

function dataFromSnk(match, group) {
  const editions = [match, ...group].map((entry) => entry.candidate);
  const first = editions[0];
  const fields = {};
  const sources = {};
  const warnings = [];
  const put = (field, value) => {
    if (value !== null && value !== undefined && value !== "") {
      fields[field] = value;
      sources[field] = { provider: first.provider, url: first.sourceUrl };
    }
  };

  put("author", first.authors[0] ?? null);
  if (first.authors.length > 1)
    put("co_authors", first.authors.slice(1).join("; "));
  put(
    "description",
    shortDescription(
      editions.map((edition) => edition.description).find(Boolean),
    ),
  );

  const editionValues = {
    publisher: editions.map((edition) => uniqueValue(edition.publisher)),
    published_year: editions.map((edition) => yearFrom(edition.publishedDate)),
    isbn: editions.map((edition) => preferredIsbn(edition.identifiers)),
    page_count: editions.map((edition) => positiveInteger(edition.pageCount)),
    language: editions.map((edition) => languageCode(edition.language)),
  };
  let ambiguous = false;
  for (const [field, values] of Object.entries(editionValues)) {
    if (values.every((value) => value !== null && value === values[0]))
      put(field, values[0]);
    else if (editions.length > 1 && values.some((value) => value !== null))
      ambiguous = true;
  }
  if (ambiguous) {
    warnings.push(
      `SNK: ${editions.length} blízkych vydaní tejto knihy; nejednoznačné údaje o vydaní neboli zapísané.`,
    );
  }
  return {
    fields,
    sources,
    warnings,
    years: new Set(editionValues.published_year.filter(Boolean)),
  };
}

function candidateYears(candidate) {
  return new Set([yearFrom(candidate.publishedDate)].filter(Boolean));
}

// Si zapamätá, ako zdroj píše priezvisko, ktoré je v katalógu s preklepom (Pellisier → Pellissier).
function learnAuthorAlias(book, candidate) {
  const input = parseCatalogAuthors(book.author)[0];
  if (!input || input.surname.length < 4) return;
  for (const name of candidate.authors ?? []) {
    for (const token of normalizeText(name).split(" ").map(stemSurname)) {
      if (
        token !== input.surname &&
        token.length >= 4 &&
        editSimilarity(token, input.surname) >= 0.85
      ) {
        authorAliases.set(input.surname, token);
        return;
      }
    }
  }
}

async function testApis(options) {
  const english = {
    id: "api-smoke-test",
    title: "The Lord of the Rings",
    author: "Tolkien J. R. R.",
  };
  const slovak = {
    id: "api-smoke-test-sk",
    title: "Dej mi duše",
    author: "Bosco T.",
  };
  const checks = [
    ["Google Books", getGoogleCandidates, english],
    ["Open Library", getOpenLibraryCandidates, english],
    ["knihovny.cz", getKnihovnyCandidates, slovak],
  ];
  let failures = 0;

  for (const [provider, search, sample] of checks) {
    console.log(
      `${provider}: testovacia kniha "${sample.title}" — ${sample.author}`,
    );
    try {
      const candidates = await search(sample, options, 1);
      const matching = candidates
        .map((candidate) => ({
          candidate,
          score: scoreCandidate(sample, candidate),
        }))
        .filter(
          ({ score }) =>
            score.titleScore >= 0.82 &&
            score.authorScore >= 0.48 &&
            score.totalScore >= 0.79,
        )
        .sort((a, b) => b.score.totalScore - a.score.totalScore);
      if (matching.length === 0) {
        failures += 1;
        console.error(
          `${provider}: odpoveď prišla, ale neobsahovala presvedčivú zhodu pre testovaciu knihu (kandidátov: ${candidates.length}).`,
        );
        continue;
      }
      console.log(
        `${provider}: OK; kandidátov ${candidates.length}; zhoda "${matching[0].candidate.title}" — ${matching[0].candidate.authors.join(", ")}.`,
      );
    } catch (error) {
      failures += 1;
      console.error(`${provider}: CHYBA — ${error.message}`);
    }
  }

  if (failures > 0) {
    console.error(
      "Test API neprešiel pre všetky zdroje. Google Books môže vyžadovať platný GOOGLE_BOOKS_API_KEY alebo obnovenú kvótu.",
    );
    console.error("Ak knihovny.cz zlyháva, skript funguje aj s --no-knihovny.");
    process.exitCode = 1;
    return;
  }
  console.log(
    "Všetky zdroje vrátili a správne identifikovali testovaciu knihu.",
  );
}

// Do databázy sa zapisujú iba polia, ktoré záznam knihy už obsahuje (napr. `publisher` schéma nemá); ostatné idú do stavu.
function dataFieldsToFill(book, data) {
  const fields = {};
  const sources = {};
  const extra = {};
  for (const [field, value] of Object.entries(data.fields)) {
    if (!(field in book)) {
      extra[field] = value;
    } else if (missingValue(book, field)) {
      fields[field] = value;
      sources[field] = data.sources[field];
    }
  }
  return { fields, sources, extra };
}

function reviewResult(book, reason, rankedGroups) {
  const candidates = rankedGroups
    .flatMap((group) =>
      group.ranked.map(({ candidate, score }) =>
        candidateForReport(candidate, score),
      ),
    )
    .sort((a, b) => b.confidence_scores.overall - a.confidence_scores.overall)
    .filter((entry) => entry.confidence_scores.overall >= 0.3)
    .slice(0, 5);
  return {
    status: "ai-check",
    tag: "[AI-should-check]",
    book_id: book.id,
    title: book.title ?? null,
    author: book.author ?? null,
    reason,
    instruction:
      "Urči, ktorý kandidát zodpovedá tejto knihe podľa názvu a autora. Nepoužívaj iba podobný názov; ak údaje nestačia, označ zhodu ako nerozhodnuteľnú. Neodhaduj chýbajúce bibliografické údaje.",
    candidates,
  };
}

function logAssessment(provider, assessment, error) {
  if (error) logDetail(`[${provider}] CHYBA: ${error}`);
  const best = assessment.ranked[0];
  if (!best) {
    logDetail(`[${provider}] žiadni kandidáti`);
    return;
  }
  const { titleScore, authorScore, totalScore } = best.score;
  const verdict = assessment.match ? "ZHODA" : "zamietnuté";
  logDetail(
    `[${provider}] najlepší: "${best.candidate.title}" — ${best.candidate.authors.join(", ") || "bez autora"} ` +
      `(názov ${titleScore.toFixed(2)}, autor ${authorScore === null ? "n/a" : authorScore.toFixed(2)}, spolu ${totalScore.toFixed(2)}) → ${verdict}`,
  );
}

// Vráti počet bajtov, ak URL vracia použiteľný obrázok (nie zástupný 1x1 pixel ani chybovú stránku), inak 0.
async function probeImage(url) {
  try {
    await waitForProvider(new URL(url).hostname, 300);
    const response = await fetch(url, {
      headers: { "User-Agent": USER_AGENT },
      signal: AbortSignal.timeout(30000),
    });
    if (
      !response.ok ||
      !/^image\//i.test(response.headers.get("content-type") ?? "")
    )
      return 0;
    const bytes = (await response.arrayBuffer()).byteLength;
    return bytes >= MIN_IMAGE_BYTES ? bytes : 0;
  } catch {
    return 0;
  }
}

async function usableImageUrl(url) {
  const original = await probeImage(url);
  if (original === 0) return null;
  let larger = null;
  try {
    const variant = new URL(url);
    if (
      variant.hostname.endsWith("google.com") &&
      variant.searchParams.has("zoom")
    ) {
      variant.searchParams.set("zoom", "2");
      larger = variant.toString();
    }
  } catch {
    return original > 0 ? url : null;
  }
  if (larger) {
    const largerBytes = await probeImage(larger);
    if (largerBytes > original) return larger;
  }
  return original > 0 ? url : null;
}

// Obálku zapisujeme iba po overení, že URL naozaj vracia obrázok; záložný zdroj je obálka podľa ISBN konkrétneho vydania.
async function finalizeImage(book, merged, warnings) {
  if (!missingValue(book, "image")) {
    delete merged.fields.image;
    delete merged.sources.image;
    return;
  }
  const candidates = [];
  if (merged.fields.image)
    candidates.push({ url: merged.fields.image, source: merged.sources.image });
  const isbn = validIsbn(merged.fields.isbn ?? book.isbn);
  if (isbn) {
    const url = `https://covers.openlibrary.org/b/isbn/${isbn}-L.jpg?default=false`;
    candidates.push({
      url,
      source: {
        provider: "Open Library",
        url,
        detail: "cover of the edition with the same ISBN",
      },
    });
  }

  delete merged.fields.image;
  delete merged.sources.image;
  for (const candidate of candidates) {
    const url = await usableImageUrl(candidate.url);
    if (url) {
      merged.fields.image = url.replace(/\?default=false$/, "");
      merged.sources.image = candidate.source;
      return;
    }
  }
  if (candidates.length > 0)
    warnings.push(
      "Obálku sa nepodarilo overiť (URL nevrátila použiteľný obrázok); obálka nebola doplnená.",
    );
}

async function enrichBook(book, options, runtime) {
  const { title } = queryParts(book);
  runtime.googleOutcome = null;
  if (!title) {
    return {
      result: reviewResult(
        book,
        "Záznam nemá názov; podľa dostupných údajov nemožno bezpečne vyhľadávať.",
        [],
      ),
      rateLimited: false,
    };
  }

  const warnings = [];
  let pendingStop = null;

  // 1. knihovny.cz: bez kvóty, údaje o konkrétnych vydaniach aj anotácie.
  let knihovnyCandidates = [];
  let knihovnyError = null;
  const knihovnyActive = !options.noKnihovny && !runtime.knihovnyDisabled;
  if (knihovnyActive) {
    try {
      knihovnyCandidates = await getKnihovnyCandidates(book, options);
      runtime.knihovnyFailureStreak = 0;
    } catch (error) {
      knihovnyError = error.message;
      runtime.knihovnyFailureStreak += 1;
      if (
        /\bHTTP 429\b/.test(knihovnyError) ||
        runtime.knihovnyFailureStreak >= KNIHOVNY_FAILURE_LIMIT
      ) {
        runtime.knihovnyDisabled = true;
        console.error(
          "knihovny.cz zlyháva alebo vracia limit; zvyšok behu sa bez neho dokončí.",
        );
      }
    }
  }
  const knihovny = assessCandidates(book, knihovnyCandidates);
  if (knihovnyActive) logAssessment("knihovny.cz", knihovny, knihovnyError);
  else if (!options.noKnihovny)
    logDetail("[knihovny.cz] preskočené (zdroj je v tomto behu vypnutý)");
  const knihovnyData = knihovny.match
    ? dataFromKnihovny(knihovny.match, sameWorkEditions(knihovny))
    : null;

  const snkActive = options.snk && !runtime.snkDisabled;
  let snkCandidates = [];
  let snkError = null;
  if (snkActive) {
    try {
      snkCandidates = await getSnkCandidates(book, options);
      runtime.snkFailureStreak = 0;
    } catch (error) {
      snkError = error.message;
      runtime.snkHadError = true;
      runtime.snkFailureStreak += 1;
      if (
        /\bHTTP 429\b/.test(snkError) ||
        runtime.snkFailureStreak >= SNK_FAILURE_LIMIT
      ) {
        runtime.snkDisabled = true;
        console.error(
          "Katalóg SNK zlyháva alebo vracia limit; zvyšok behu pokračuje bez neho.",
        );
      }
    }
  }
  const snk = assessCandidates(book, snkCandidates);
  if (snkActive) logAssessment("SNK", snk, snkError);

  // 2. Google sa volá, iba ak knihovny.cz nenašlo zhodu alebo chýbajú údaje o vydaní (šetrí dennú kvótu).
  const lacking = (fields) =>
    fields.some(
      (field) => missingValue(book, field) && !knihovnyData?.fields[field],
    );
  const needsGoogle =
    !knihovny.match ||
    lacking(["isbn", "published_year", "page_count"]) ||
    (options.googleSupplement && lacking(["image", "description"]));
  let googleCandidates = [];
  let googleError = null;
  if (needsGoogle) {
    try {
      googleCandidates = await getGoogleCandidates(book, options);
      runtime.googleOutcome = "ok";
    } catch (error) {
      googleError = error.message;
      runtime.googleOutcome = "failed";
      let stopReason = null;
      if (error.dailyQuota) {
        runtime.googleQuotaExhausted = "google";
        runtime.googleBooksRateLimited = true;
        stopReason =
          "Google Books potvrdil vyčerpanie dennej kvóty. Beh sa zastavil; pri ďalšom spustení sa kvóta znova overí požiadavkou na Google.";
      } else if (/\bHTTP 429\b/.test(googleError)) {
        runtime.googleBooksRateLimited = true;
        stopReason =
          "Google Books vrátil HTTP 429 (limit alebo denná kvóta). Beh sa zastavil; po obnovení limitu spustite skript znova.";
      } else if (/\bHTTP (401|403)\b/.test(googleError)) {
        stopReason =
          "Google Books odmietol požiadavku (HTTP 401/403). Skontrolujte GOOGLE_BOOKS_API_KEY a jeho obmedzenia.";
      }
      if (stopReason && !knihovny.match)
        return { error: googleError, stopReason };
      if (stopReason) pendingStop = stopReason;
    }
  } else {
    logDetail(
      "[Google] preskočené (knihovny.cz zhoda obsahuje potrebné údaje o vydaní)",
    );
  }
  const google = assessCandidates(book, googleCandidates);
  if (needsGoogle) logAssessment("Google", google, googleError);

  // 3. Open Library iba ako posledná možnosť (pre slovenské knihy zvyčajne nič nenájde).
  const needsOpenLibrary = !knihovny.match && !google.match;
  let openLibraryCandidates = [];
  let openLibraryError = null;
  if (needsOpenLibrary) {
    try {
      openLibraryCandidates = await getOpenLibraryCandidates(book, options);
    } catch (error) {
      openLibraryError = error.message;
    }
  }
  const openLibrary = assessCandidates(book, openLibraryCandidates);
  if (needsOpenLibrary)
    logAssessment("Open Library", openLibrary, openLibraryError);
  const rateLimited = /\bHTTP 429\b/.test(openLibraryError ?? "");
  if (rateLimited) runtime.openLibraryRateLimited = true;

  const allRanked = [
    ...(knihovnyActive ? [{ provider: "knihovny.cz", ...knihovny }] : []),
    ...(snkActive ? [{ provider: "SNK", ...snk }] : []),
    ...(needsGoogle ? [{ provider: "Google Books", ...google }] : []),
    ...(needsOpenLibrary ? [{ provider: "Open Library", ...openLibrary }] : []),
  ];
  const entries = [];
  if (knihovny.match) {
    entries.push({
      provider: "knihovny.cz",
      match: knihovny.match,
      data: knihovnyData,
      years: knihovnyData.years,
    });
  }
  if (google.match) {
    entries.push({
      provider: "Google Books",
      match: google.match,
      data: dataFromGoogle(google.match.candidate),
      years: candidateYears(google.match.candidate),
    });
  }
  if (openLibrary.match) {
    entries.push({
      provider: "Open Library",
      match: openLibrary.match,
      data: dataFromOpenLibrary(openLibrary.match.candidate),
      years: candidateYears(openLibrary.match.candidate),
    });
  }
  const snkWriteMatch =
    snk.match &&
    snk.match.score.authorScore !== null &&
    snk.match.score.authorScore >= 0.8
      ? snk.match
      : null;
  if (entries.length === 0 && snkWriteMatch) {
    const snkData = dataFromSnk(snkWriteMatch, sameWorkEditions(snk));
    entries.push({
      provider: "SNK",
      match: snkWriteMatch,
      data: snkData,
      years: snkData.years,
    });
  }

  for (const error of [
    knihovnyError,
    snkError,
    googleError,
    openLibraryError,
  ]) {
    if (error) warnings.push(error);
  }

  if (entries.length === 0) {
    const anyCandidates = allRanked.some((group) => group.ranked.length > 0);
    const apiError =
      openLibraryError ?? googleError ?? snkError ?? knihovnyError;
    if (!anyCandidates && apiError) {
      return { error: apiError, inconclusive: true, rateLimited };
    }
    if (!anyCandidates) {
      return {
        result: {
          status: "no-match",
          book_id: book.id,
          title: book.title ?? null,
          author: book.author ?? null,
          reason: `V knihovny.cz${snkActive ? ", SNK" : ""}, Google Books ani Open Library sa nenašla použiteľná zhoda.`,
          warnings,
        },
        rateLimited,
      };
    }
    // Čiastočný výpadok jedného zdroja znamená, že zoznam kandidátov nemusí byť úplný.
    if (apiError) return { error: apiError, inconclusive: true, rateLimited };
    const review = reviewResult(
      book,
      "Nenašla sa jedna dostatočne presvedčivá a jednoznačná zhoda.",
      allRanked,
    );
    if (review.candidates.length === 0) {
      return {
        result: {
          status: "no-match",
          book_id: book.id,
          title: book.title ?? null,
          author: book.author ?? null,
          reason:
            "Zdroje vrátili iba kandidátov s príliš nízkou zhodou na AI kontrolu.",
          warnings,
        },
        rateLimited,
      };
    }
    return {
      result: {
        ...review,
        warnings,
      },
      rateLimited,
    };
  }

  // Údaje o vydaní (rok, ISBN, strany, vydavateľ, obálka) preberáme iba od zdroja, ktorý sa zhoduje s hlavným vydaním.
  const [primary, ...others] = entries;
  warnings.push(...primary.data.warnings);
  const merged = {
    fields: { ...primary.data.fields },
    sources: { ...primary.data.sources },
  };
  for (const other of others) {
    warnings.push(...other.data.warnings);
    const compatible =
      primary.years.size === 0 ||
      [...other.years].some((year) => primary.years.has(year));
    let skipped = false;
    for (const [field, value] of Object.entries(other.data.fields)) {
      if (merged.fields[field] !== undefined) continue;
      if (EDITION_FIELDS.includes(field) && !compatible) {
        skipped = true;
        continue;
      }
      merged.fields[field] = value;
      merged.sources[field] = other.data.sources[field];
    }
    if (skipped) {
      warnings.push(
        `${other.provider}: údaje o vydaní sa nezhodujú s ${primary.provider} (iný rok); nepoužité.`,
      );
    }
  }
  for (const group of allRanked) {
    if (group.ranked.length > 0 && !group.match) {
      warnings.push(
        `${group.provider} nenašlo jednoznačnú zhodu; jeho údaje neboli použité.`,
      );
    }
  }
  if (snkActive && snk.match && primary.provider !== "SNK") {
    warnings.push(
      "SNK našlo zhodný záznam; jeho údaje sú uložené iba na kontrolu a neboli automaticky použité.",
    );
  } else if (snkActive && !snkError && snk.ranked.length === 0) {
    warnings.push("SNK nenašlo záznam na nezávislé potvrdenie tejto zhody.");
  }
  learnAuthorAlias(book, primary.match.candidate);

  await finalizeImage(book, merged, warnings);
  const accepted = dataFieldsToFill(book, merged);
  return {
    result: {
      status: "matched",
      book_id: book.id,
      title: book.title ?? null,
      author: book.author ?? null,
      matched_title: primary.match.candidate.title,
      matched_authors: primary.match.candidate.authors,
      provider: primary.provider,
      providers: entries.map((entry) => entry.provider),
      source_url: primary.match.candidate.sourceUrl,
      confidence: Number(primary.match.score.totalScore.toFixed(3)),
      fields: accepted.fields,
      field_sources: accepted.sources,
      ...(Object.keys(accepted.extra).length > 0
        ? { extra_fields: accepted.extra }
        : {}),
      ...(primary.data.editionOptions
        ? { edition_options: primary.data.editionOptions }
        : {}),
      ...(snkActive
        ? {
            snk_candidates: snk.ranked
              .slice(0, 5)
              .map(({ candidate, score }) =>
                candidateForReport(candidate, score),
              ),
          }
        : {}),
      warnings: [...new Set(warnings)],
    },
    rateLimited,
    stopReason: pendingStop ?? undefined,
  };
}
function percentage(numerator, denominator) {
  return denominator === 0
    ? null
    : Number(((numerator / denominator) * 100).toFixed(1));
}

function summarize(totalBooks, results, errors) {
  const entries = Object.values(results);
  const matched = entries.filter((entry) => entry.status === "matched").length;
  const needsAiCheck = entries.filter(
    (entry) => entry.status === "ai-check",
  ).length;
  const noMatch = entries.filter((entry) => entry.status === "no-match").length;
  const processed = matched + needsAiCheck + noMatch;
  const apiErrors = Object.keys(errors).length;
  const pending = Math.max(0, totalBooks - processed - apiErrors);

  return {
    total_books: totalBooks,
    processed,
    matched,
    no_match: noMatch,
    needs_ai_check: needsAiCheck,
    api_errors: apiErrors,
    pending,
    remaining_to_process: pending + apiErrors,
    success_rate_percent: percentage(matched, processed),
    completion_percent: percentage(processed, totalBooks),
    catalog_coverage_percent: percentage(matched, totalBooks),
    ai_review_percent_of_total: percentage(needsAiCheck, totalBooks),
  };
}

function printStatistics(summary, heading) {
  const formatRate = (value) => (value === null ? "n/a" : `${value}%`);
  console.log(heading);
  console.log(`  Nájdené knihy: ${summary.matched}`);
  console.log(`  Bez zhody: ${summary.no_match}`);
  console.log(
    `  Neisté zhody na AI kontrolu: ${summary.needs_ai_check} ` +
      `(${formatRate(summary.ai_review_percent_of_total)} z celého katalógu)`,
  );
  console.log(`  Chyby API: ${summary.api_errors}`);
  console.log(`  Ešte nespracované: ${summary.pending}`);
  console.log(
    `  Zostáva automaticky spracovať alebo opakovať: ${summary.remaining_to_process}`,
  );
  console.log(
    `  Úspešnosť zhôd: ${formatRate(summary.success_rate_percent)} ` +
      `(${summary.matched}/${summary.processed} dokončených záznamov)`,
  );
  console.log(
    `  Pokrytie celého katalógu: ${formatRate(summary.catalog_coverage_percent)} ` +
      `(${summary.matched}/${summary.total_books})`,
  );
  console.log(
    `  Dokončená časť katalógu: ${formatRate(summary.completion_percent)}`,
  );
}

function makeReport(root, books, results, errors, runtime) {
  const entries = Object.values(results);
  const matched = entries.filter((entry) => entry.status === "matched");
  const review = entries.filter((entry) => entry.status === "ai-check");
  const noMatch = entries.filter((entry) => entry.status === "no-match");
  const errorEntries = Object.entries(errors).map(([bookId, message]) => {
    const book = books.find((item, index) => idFor(item, index) === bookId);
    return {
      book_id: bookId,
      title: book?.title ?? null,
      author: book?.author ?? null,
      error: message,
    };
  });
  return {
    generated_at: new Date().toISOString(),
    input_format: root.format ?? null,
    total_books: books.length,
    provider_status: {
      google_books: runtime.googleQuotaExhausted
        ? "denná kvóta vyčerpaná"
        : runtime.googleBooksRateLimited
          ? "HTTP 429; zvyšok behu používa iba Open Library"
          : "bez zaznamenaného limitu",
      open_library: runtime.openLibraryRateLimited
        ? "HTTP 429; beh zastavený pre dodržanie limitu"
        : "bez zaznamenaného limitu",
      snk_catalog: !runtime.snkEnabled
        ? "nepoužitý"
        : runtime.snkHadError
          ? "chyba pri vyhľadávaní"
          : "bez zaznamenanej chyby",
    },
    summary: summarize(books.length, results, errors),
    matches: matched,
    needs_ai_check: review,
    no_match: noMatch,
    errors: errorEntries,
  };
}

function classifyResult(result) {
  if (result.status === "ai-check") return CHECK.ambiguous;
  if (result.status === "no-match")
    return result.sourceError ? CHECK.sourceError : CHECK.notFound;
  if ((result.warnings ?? []).length > 0) return CHECK.partial;
  return filledFieldCount(result) > 0 ? CHECK.completed : CHECK.alreadyComplete;
}

function filledFieldCount(result) {
  const extra = EXTRA_BOOK_FIELDS.filter(
    (field) => result.extra_fields?.[field] !== undefined,
  ).length;
  return Object.keys(result.fields ?? {}).length + extra;
}

// Priebeh sa obnovuje priamo z polí script_check_* v knihách; žiadny samostatný súbor so stavom neexistuje.
function loadProgress(books) {
  const results = {};
  const errorCounts = {};
  books.forEach((book, index) => {
    const id = idFor(book, index);
    const status = CHECK_STATUS[book.script_check_result];
    if (status)
      results[id] = {
        status,
        check_result: book.script_check_result,
        loaded: true,
      };
    else if (Number(book.script_check_attempts) > 0)
      errorCounts[id] = Number(book.script_check_attempts);
  });
  return { results, errors: {}, errorCounts };
}

function withoutScriptFields(book) {
  return Object.fromEntries(
    Object.entries(book).filter(
      ([key]) =>
        !SCRIPT_KEY_PREFIXES.some((prefix) => key.startsWith(prefix)) &&
        key !== "script_check_attempts",
    ),
  );
}

function withPublisher(book, value) {
  const entries = Object.entries(book);
  const anchor = entries.findLastIndex(([key]) =>
    ["edition", "series"].includes(key),
  );
  if (anchor < 0) return { ...book, publisher: value };
  entries.splice(anchor + 1, 0, ["publisher", value]);
  return Object.fromEntries(entries);
}

function applyResult(original, result) {
  let book = withoutScriptFields(original);
  if (result.status === "matched") {
    for (const [field, value] of Object.entries(result.fields ?? {})) {
      if (field in book && missingValue(book, field)) book[field] = value;
    }
    for (const field of EXTRA_BOOK_FIELDS) {
      const value = result.extra_fields?.[field];
      if (value !== undefined && value !== null && !(field in book))
        book = withPublisher(book, value);
    }
  }
  book.script_check_result = result.check_result;
  book.script_checked_at = result.checked_at;
  if (result.source_url || result.provider) {
    book.script_check_source = {
      provider: result.provider ?? null,
      url: result.source_url ?? null,
      confidence: result.confidence ?? null,
    };
  }
  if ((result.warnings ?? []).length > 0)
    book.script_check_warnings = result.warnings;
  if (result.status === "ai-check" && (result.candidates ?? []).length > 0) {
    book.script_check_candidates = result.candidates;
  }
  return book;
}

function makeOutput(root, books, progress) {
  const output = JSON.parse(JSON.stringify(root));
  output.script_usage = { day: usage.day, google: usage.google };
  output.books = books.map((original, index) => {
    const id = idFor(original, index);
    const result = progress.results[id];
    if (result && !result.loaded) return applyResult(original, result);
    if (!result && progress.errorCounts[id] > 0) {
      return { ...original, script_check_attempts: progress.errorCounts[id] };
    }
    return original;
  });
  return output;
}

async function writeOutput(options, root, books, progress) {
  const output = makeOutput(root, books, progress);
  warnOnBrokenEncoding(output.books);
  await writeAtomic(options.output, `${JSON.stringify(output, null, 4)}\n`);
}

// Diakritika zmenená na '?' alebo riadiaci znak vzniká pri zápise cez zlé kódovanie (napr. PowerShell) a nedá sa doplniť späť.
function warnOnBrokenEncoding(books) {
  const broken = books.filter((book) =>
    [book.title, book.author, book.publisher, book.description].some(
      (text) =>
        typeof text === "string" &&
        /\p{L}\?\p{L}|[\u0000-\u0008\u000B\u000C\u000E-\u001F\uFFFD]/u.test(
          text,
        ),
    ),
  );
  if (broken.length)
    console.warn(
      `Pozor: ${broken.length} kníh má poškodenú diakritiku (id ${broken
        .slice(0, 5)
        .map((b) => b.id)
        .join(", ")}...).`,
    );
}

function evenlySpaced(list, count) {
  if (count >= list.length) return list;
  const step = list.length / count;
  return Array.from(
    { length: count },
    (_, index) => list[Math.floor(index * step)],
  );
}

async function main() {
  const options = parseArguments(process.argv.slice(2));
  if (options.help) {
    printHelp();
    return;
  }
  if (options.testApis) {
    await testApis(options);
    return;
  }

  const inputBytes = await fs.readFile(options.input);
  const inputText = inputBytes.toString("utf8").replace(/^\uFEFF/, "");
  let root;
  try {
    root = JSON.parse(inputText);
  } catch (error) {
    throw new Error(`Vstupný súbor nie je platný JSON: ${error.message}`);
  }
  if (!root || typeof root !== "object" || !Array.isArray(root.books)) {
    throw new Error(
      "Vstupný JSON musí obsahovať koreňový objekt s poľom books.",
    );
  }
  const books = root.books;
  const ids = books.map(idFor);
  if (new Set(ids).size !== ids.length) {
    throw new Error(
      "Pole books obsahuje duplicitné id; bezpečné priebežné ukladanie nie je možné.",
    );
  }

  options.output ??= options.input;
  const progress = loadProgress(books);
  options.openLibraryDelayMs ??= process.env.OPEN_LIBRARY_CONTACT ? 350 : 1100;
  restoreUsage(root.script_usage);
  const runtime = {
    googleBooksRateLimited: false,
    openLibraryRateLimited: false,
    googleOutcome: null,
    googleQuotaExhausted: null,
    googleFailureStreak: 0,
    googleCooldowns: 0,
    knihovnyDisabled: false,
    knihovnyFailureStreak: 0,
    snkEnabled: options.snk,
    snkDisabled: false,
    snkFailureStreak: 0,
    snkHadError: false,
  };

  const isDone = (bookId) => {
    const result = progress.results[bookId];
    if (!result || options.retryAll) return false;
    return !(options.retryNoMatch && result.status === "no-match");
  };
  const pendingBooks = books.filter(
    (book, index) => !isDone(idFor(book, index)),
  );
  let selectedBooks = pendingBooks;
  if (options.sample !== null)
    selectedBooks = evenlySpaced(pendingBooks, options.sample);
  else if (options.limit !== null)
    selectedBooks = pendingBooks.slice(0, options.limit);

  if (options.dryRun) {
    console.log(`Vstup: ${options.input}`);
    console.log(
      `  Už hotové podľa script_check_result (preskočené): ${books.length - pendingBooks.length}`,
    );
    console.log(
      `  Naplánované v tomto behu: ${selectedBooks.length} z ${pendingBooks.length} nespracovaných`,
    );
    console.log("Dry-run nezmenil žiadne súbory a neposlal API požiadavky.");
    return;
  }

  console.log(
    `Google Books API kľúč: ${process.env.GOOGLE_BOOKS_API_KEY ? "nastavený" : "NIE JE nastavený (anonymná kvóta)"}`,
  );
  console.log(
    `Spracujem ${selectedBooks.length} z ${books.length} kníh (už hotových: ${books.length - pendingBooks.length}); upravuje sa súbor ${options.output}.`,
  );
  console.log(
    `Google požiadavky dnes (lokálne počítadlo): ${usage.google}. Open Library: ${process.env.OPEN_LIBRARY_CONTACT ? "identifikovaný klient (3 požiadavky/s)" : "anonymný (1 požiadavka/s); nastavte OPEN_LIBRARY_CONTACT"}.`,
  );
  console.log(
    `SNK: zapnuté predvolene; jednoznačné zhody názvu a autora môžu doplniť chýbajúce údaje.`,
  );

  let interrupted = false;
  process.on("SIGINT", () => {
    if (interrupted) process.exit(130);
    interrupted = true;
    console.error(
      "\nPrerušenie: dokončím aktuálnu knihu, uložím stav a skončím (druhé Ctrl+C ukončí okamžite).",
    );
  });

  let stopReason = null;
  let unsaved = 0;
  try {
    for (let i = 0; i < selectedBooks.length && !interrupted; i += 1) {
      const book = selectedBooks[i];
      const bookId = idFor(book, books.indexOf(book));
      console.log(
        `[${i + 1}/${selectedBooks.length}] ID ${bookId}: "${book.title}"${book.author ? ` / ${book.author}` : ""}`,
      );
      try {
        const outcome = await enrichBook(book, options, runtime);
        if (outcome.error) {
          progress.errorCounts[bookId] =
            (progress.errorCounts[bookId] ?? 0) + 1;
          if (
            outcome.inconclusive &&
            progress.errorCounts[bookId] >= MAX_INCONCLUSIVE_RUNS
          ) {
            outcome.result = {
              status: "no-match",
              book_id: book.id,
              title: book.title ?? null,
              author: book.author ?? null,
              reason: `Zdroje ${progress.errorCounts[bookId]}× po sebe zlyhali bez použiteľného výsledku; záznam sa nepodarilo overiť.`,
              sourceError: true,
              warnings: [outcome.error],
            };
            delete outcome.error;
          }
        }
        stopReason =
          outcome.stopReason ??
          (outcome.rateLimited
            ? "Open Library vrátil HTTP 429. Beh sa zastavil; po obnovení limitu spustite skript znova."
            : null);
        if (outcome.error) {
          progress.errors[bookId] = outcome.error;
          console.error(
            `    → CHYBA (záznam sa zopakuje pri ďalšom behu): ${outcome.error}`,
          );
        } else {
          outcome.result.check_result = classifyResult(outcome.result);
          outcome.result.checked_at = new Date().toISOString();
          progress.results[bookId] = outcome.result;
          delete progress.errors[bookId];
          delete progress.errorCounts[bookId];
          const filled = filledFieldCount(outcome.result);
          console.log(
            `    → ${outcome.result.check_result}` +
              (filled ? `, doplnených polí ${filled}` : ""),
          );
          if (outcome.result.reason)
            logDetail(`dôvod: ${outcome.result.reason}`);
          for (const warning of outcome.result.warnings ?? [])
            logDetail(`upozornenie: ${warning}`);
        }
      } catch (error) {
        progress.errors[bookId] = error.message;
        console.error(`ID ${bookId}: neočakávaná chyba: ${error.message}`);
        if (/\bHTTP 429\b/.test(error.message))
          stopReason =
            "Zdroj vrátil HTTP 429. Beh sa zastavil; po obnovení limitu spustite skript znova.";
      }

      unsaved += 1;
      if (stopReason || unsaved >= options.saveEvery) {
        await writeOutput(options, root, books, progress);
        unsaved = 0;
      }
      if (stopReason) break;

      if (runtime.googleOutcome === "failed") runtime.googleFailureStreak += 1;
      else if (runtime.googleOutcome === "ok") {
        runtime.googleFailureStreak = 0;
        runtime.googleCooldowns = 0;
      }
      if (runtime.googleFailureStreak >= GOOGLE_FAILURE_STREAK_LIMIT) {
        if (runtime.googleCooldowns >= GOOGLE_MAX_COOLDOWNS) {
          stopReason =
            "Google Books je dlhodobo nedostupný (opakované chyby služby). Beh sa zastavil; spustite skript znova neskôr.";
          break;
        }
        const waitMs = GOOGLE_COOLDOWN_MS * (runtime.googleCooldowns + 1);
        console.error(
          `Google Books zlyhal ${runtime.googleFailureStreak}× za sebou; pauza ${waitMs / 1000} s.`,
        );
        await sleep(waitMs);
        runtime.googleCooldowns += 1;
        runtime.googleFailureStreak = 0;
      }
    }
  } finally {
    await writeOutput(options, root, books, progress);
  }

  const report = makeReport(
    root,
    books,
    progress.results,
    progress.errors,
    runtime,
  );
  if (stopReason) console.error(stopReason);
  console.log(`Google požiadavky dnes (lokálne počítadlo): ${usage.google}.`);
  console.log(
    stopReason
      ? "Beh prerušený."
      : interrupted
        ? "Beh prerušený používateľom."
        : "Beh ukončený.",
  );
  console.log(`Výstup: ${options.output}`);
  if (runtime.googleQuotaExhausted) {
    console.error("!!! GOOGLE BOOKS: DENNÁ KVÓTA JE VYČERPANÁ !!!");
    console.error(
      `    Google Books potvrdil vyčerpanie kvóty. Požiadavky zaznamenané týmto skriptom dnes: ${usage.google}.`,
    );
    console.error(
      "    Pri ďalšom spustení sa skript opýta Google znova; ak kvóta ešte nie je obnovená, zastaví sa znovu.",
    );
  }
  printStatistics(
    report.summary,
    "Záverečná štatistika (celý katalóg vrátane predchádzajúcich behov):",
  );
  if (report.summary.api_errors > 0) {
    console.error(
      "Niektoré záznamy majú chyby API; pri ďalšom behu sa zopakujú automaticky.",
    );
  }
  if (report.summary.needs_ai_check > 0) {
    console.log(
      `Záznamy na AI kontrolu majú v ${options.output} script_check_result = "${CHECK.ambiguous}" a kandidátov v script_check_candidates.`,
    );
  }
}
main().catch((error) => {
  console.error(`Chyba: ${error.message}`);
  process.exitCode = 1;
});
