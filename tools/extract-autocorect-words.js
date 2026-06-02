const fs = require('fs');
const path = require('path');
const { TextDecoder } = require('util');

const projectRoot = path.resolve(__dirname, '..');
const autoRoot = path.resolve(process.argv[2] || path.join(projectRoot, 'AutoCorect'));
const outPath = path.resolve(process.argv[3] || path.join(autoRoot, 'autocorect-words.txt'));
const dec1250 = new TextDecoder('windows-1250');
const decUtf16 = new TextDecoder('utf-16le');
const words = new Set();
const stats = [];
const includeDex = process.env.AUTOCORECT_INCLUDE_DEX === '1';
const includeOcr = process.env.AUTOCORECT_INCLUDE_OCR === '1';

const WORD_RE = /\p{L}+/gu;
const WORD_ONLY_RE = /^[a-z\u0103\u00e2\u00ee\u0219\u021b]+$/u;

function normalizeWord(value) {
  return String(value || '')
    .replace(/[\u015e\u015f]/g, ch => ch === '\u015e' ? '\u0218' : '\u0219')
    .replace(/[\u0162\u0163]/g, ch => ch === '\u0162' ? '\u021a' : '\u021b')
    .replace(/[\u00c1\u00e1]/g, ch => ch === '\u00c1' ? 'A' : 'a')
    .replace(/[\u00c9\u00e9]/g, ch => ch === '\u00c9' ? 'E' : 'e')
    .replace(/[\u00cd\u00ed]/g, ch => ch === '\u00cd' ? 'I' : 'i')
    .replace(/[\u00d3\u00f3]/g, ch => ch === '\u00d3' ? 'O' : 'o')
    .replace(/[\u00da\u00fa]/g, ch => ch === '\u00da' ? 'U' : 'u')
    .normalize('NFC')
    .toLowerCase();
}

function addWord(raw) {
  const w = normalizeWord(raw);
  if (w.length < 2 || w.length > 40) return;
  if (!WORD_ONLY_RE.test(w)) return;
  words.add(w);
}

function addWordsFromText(text) {
  if (!text) return;
  for (const match of String(text).matchAll(WORD_RE)) addWord(match[0]);
}

function allFiles(dir, exts) {
  const out = [];
  if (!fs.existsSync(dir)) return out;
  for (const name of fs.readdirSync(dir)) {
    const full = path.join(dir, name);
    const st = fs.statSync(full);
    if (st.isDirectory()) out.push(...allFiles(full, exts));
    else if (exts.has(path.extname(name).toLowerCase())) out.push(full);
  }
  return out.sort((a, b) => a.localeCompare(b));
}

function findLenFile(dicPath) {
  const dir = path.dirname(dicPath);
  const base = path.basename(dicPath, '.dic');
  const candidates = [base + '.len'];
  if (/[bi]$/i.test(base)) candidates.push(base.slice(0, -1) + '.len');
  for (const name of candidates) {
    const candidate = path.join(dir, name);
    if (fs.existsSync(candidate)) return candidate;
  }
  return null;
}

function sumBytes(buf) {
  let sum = 0;
  for (const b of buf) sum += b;
  return sum;
}

function extractLenDic(dicPath, lenPath) {
  const data = fs.readFileSync(dicPath);
  const lens = fs.readFileSync(lenPath);
  if (sumBytes(lens) !== data.length) return null;
  let pos = 0;
  let pieces = 0;
  for (const len of lens) {
    if (!len || pos + len > data.length) break;
    addWordsFromText(dec1250.decode(data.subarray(pos, pos + len)));
    pos += len;
    pieces++;
  }
  return pieces;
}

function extractDictionare() {
  const dir = path.join(autoRoot, 'Dictionare');
  for (const dicPath of allFiles(dir, new Set(['.dic']))) {
    const before = words.size;
    const lenPath = findLenFile(dicPath);
    let mode = 'text';
    let pieces = 0;
    if (lenPath) {
      const extracted = extractLenDic(dicPath, lenPath);
      if (extracted !== null) {
        mode = 'len';
        pieces = extracted;
      }
    }
    if (mode === 'text') addWordsFromText(dec1250.decode(fs.readFileSync(dicPath)));
    stats.push({ source: path.relative(autoRoot, dicPath).replace(/\\/g, '/'), mode, pieces, added: words.size - before });
  }
}

function extractDex() {
  const dir = path.join(autoRoot, 'Dex');
  for (const dicPath of allFiles(dir, new Set(['.dic']))) {
    const before = words.size;
    const text = dec1250.decode(fs.readFileSync(dicPath));
    let heads = 0;
    for (const match of text.matchAll(/\x01([^\x02]{1,160})\x02/g)) {
      addWordsFromText(match[1]);
      heads++;
    }
    if (!heads) addWordsFromText(text);
    stats.push({ source: path.relative(autoRoot, dicPath).replace(/\\/g, '/'), mode: heads ? 'dex-headwords' : 'text', pieces: heads, added: words.size - before });
  }
}

function looksUtf16Le(buf) {
  const limit = Math.min(200, buf.length - 1);
  if (limit < 20) return false;
  let zeros = 0;
  for (let i = 1; i < limit; i += 2) if (buf[i] === 0) zeros++;
  return zeros > limit / 4;
}

function extractOcr() {
  const dir = path.join(autoRoot, 'OCR_DIC');
  for (const imdPath of allFiles(dir, new Set(['.imd']))) {
    const before = words.size;
    const buf = fs.readFileSync(imdPath);
    const text = looksUtf16Le(buf) ? decUtf16.decode(buf) : dec1250.decode(buf);
    const lines = text.split(/\r?\n/).map(s => s.trim()).filter(Boolean);
    let records = 0;
    for (let i = 0; i + 2 < lines.length; i++) {
      if (/^[01]$/.test(lines[i])) {
        if (lines[i] === '1') addWordsFromText(lines[i + 2]);
        records++;
        i += 3;
      }
    }
    if (!records) addWordsFromText(text);
    stats.push({ source: path.relative(autoRoot, imdPath).replace(/\\/g, '/'), mode: records ? 'ocr-corrections' : 'text', pieces: records, added: words.size - before });
  }
}

extractDictionare();
// DEX/OCR contain explanation text, OCR variants, and correction artifacts.
// Keep them opt-in so spellcheck does not accept broken words like "acass".
if (includeDex) extractDex();
if (includeOcr) extractOcr();

const sorted = Array.from(words).sort((a, b) => a.localeCompare(b, 'ro'));
fs.writeFileSync(outPath, sorted.join('\n') + '\n', 'utf8');
fs.writeFileSync(outPath + '.meta.json', JSON.stringify({
  generatedAt: new Date().toISOString(),
  autoRoot,
  includeDex,
  includeOcr,
  wordCount: sorted.length,
  bytes: fs.statSync(outPath).size,
  stats
}, null, 2), 'utf8');

const probes = ['buniica', 'bunica', 'dore\u0219te', 'doresti', 'm\u00e2ncare', 'mancare', 'cafea'];
console.log(JSON.stringify({
  outPath,
  includeDex,
  includeOcr,
  wordCount: sorted.length,
  bytes: fs.statSync(outPath).size,
  probes: Object.fromEntries(probes.map(p => [p, words.has(normalizeWord(p))]))
}, null, 2));
