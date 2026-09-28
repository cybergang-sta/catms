// Dependency-free PDF text extractor for Node.js
// Handles: xref tables, xref streams, object streams, FlateDecode/LZW/ASCIIHex/ASCII85,
// ToUnicode CMaps, standard-encoding fallbacks, text positioning.
import fs from 'node:fs';
import zlib from 'node:zlib';

const file = process.argv[2];
const buf = fs.readFileSync(file);
console.error(`[i] read ${buf.length} bytes`);

// ---------- tokenizer / lexer over raw bytes ----------
const WS = new Set([0x00, 0x09, 0x0a, 0x0c, 0x0d, 0x20]);
const DELIM = new Set([0x28, 0x29, 0x3c, 0x3e, 0x5b, 0x5d, 0x7b, 0x7d, 0x2f, 0x25]);
const HEX = '0123456789abcdefABCDEF';

function isWS(c) { return WS.has(c); }
function isDelim(c) { return DELIM.has(c); }
function isRegular(c) { return !isWS(c) && !isDelim(c); }

class Lexer {
  constructor(data, pos = 0) { this.d = data; this.p = pos; }
  skipWS() {
    while (this.p < this.d.length) {
      const c = this.d[this.p];
      if (isWS(c)) { this.p++; continue; }
      if (c === 0x25) { // '%' comment
        while (this.p < this.d.length && this.d[this.p] !== 0x0a && this.d[this.p] !== 0x0d) this.p++;
        continue;
      }
      break;
    }
  }
  // returns token string, or number, or null at EOF
  next() {
    this.skipWS();
    if (this.p >= this.d.length) return null;
    const c = this.d[this.p];
    if (c === 0x3c) {
      // <...> dict or <<...>>
      if (this.d[this.p + 1] === 0x3c) {
        this.p += 2; const s = this.p;
        while (this.p < this.d.length) {
          if (this.d[this.p] === 0x3e && this.d[this.p + 1] === 0x3e) break;
          this.p++;
        }
        const v = this.d.slice(s, this.p).toString('latin1'); this.p += 2;
        // rewind so the dict body is tokenized by the parser
        this.p = s;
        return { t: 'dict_open', v };
      }
      this.p++; const s = this.p;
      while (this.p < this.d.length && this.d[this.p] !== 0x3e) this.p++;
      const v = this.d.slice(s, this.p).toString('latin1'); this.p++;
      return { t: 'hexstr', v };
    }
    if (c === 0x3e) {
      if (this.d[this.p + 1] === 0x3e) { this.p += 2; return { t: 'dict_close', v: '' }; }
      this.p++; return { t: 'dict_close', v: '' };
    }
    if (c === 0x5b) { this.p++; return { t: 'arr_open', v: '[' }; }
    if (c === 0x5d) { this.p++; return { t: 'arr_close', v: ']' }; }
    if (c === 0x7b) { this.p++; return { t: 'brace_open', v: '{' }; }
    if (c === 0x7d) { this.p++; return { t: 'brace_close', v: '}' }; }
    if (c === 0x2f) { // name
      this.p++; let s = this.p;
      while (this.p < this.d.length && isRegular(this.d[this.p])) this.p++;
      return { t: 'name', v: decodeName(this.d.slice(s, this.p).toString('latin1')) };
    }
    if (c === 0x28) { // literal string ( ) with escapes, nested parens
      this.p++; let depth = 1; const out = [];
      while (this.p < this.d.length) {
        const ch = this.d[this.p];
        if (ch === 0x5c) { // backslash
          out.push(ch, this.d[this.p + 1]);
          this.p += 2; continue;
        }
        if (ch === 0x28) { depth++; out.push(ch); this.p++; continue; }
        if (ch === 0x29) { depth--; if (depth === 0) { this.p++; break; } out.push(ch); this.p++; continue; }
        if (ch === 0x0d) { this.p++; if (this.d[this.p] === 0x0a) this.p++; continue; }
        out.push(ch); this.p++;
      }
      return { t: 'str', v: out };
    }
    // number or keyword
    {
      let s = this.p;
      while (this.p < this.d.length && isRegular(this.d[this.p])) this.p++;
      const v = this.d.slice(s, this.p).toString('latin1');
      if (v === '') { this.p++; return this.next(); }
      if (/^[+-]?(\d+\.?\d*|\.\d+)$/.test(v)) return { t: 'num', v: parseFloat(v) };
      return { t: 'kw', v };
    }
  }
}

function decodeName(s) {
  if (!s.includes('#')) return s;
  let out = '';
  for (let i = 0; i < s.length; i++) {
    if (s[i] === '#' && HEX.includes(s[i + 1]) && HEX.includes(s[i + 2])) {
      out += String.fromCharCode(parseInt(s.substr(i + 1, 2), 16)); i += 2;
    } else out += s[i];
  }
  return out;
}

function unescapeLiteral(bytes) {
  const out = [];
  for (let i = 0; i < bytes.length; i++) {
    const c = bytes[i];
    if (c === 0x5c && i + 1 < bytes.length) {
      const n = bytes[++i];
      switch (n) {
        case 0x6e: out.push(0x0a); break;
        case 0x72: out.push(0x0d); break;
        case 0x74: out.push(0x09); break;
        case 0x62: out.push(0x08); break;
        case 0x66: out.push(0x0c); break;
        case 0x28: out.push(0x28); break;
        case 0x29: out.push(0x29); break;
        case 0x5c: out.push(0x5c); break;
        case 0x0d: if (bytes[i + 1] === 0x0a) i++; break;
        case 0x0a: break;
        default:
          if (n >= 0x30 && n <= 0x37) { // octal
            let oct = String.fromCharCode(n); let k = 0;
            while (k < 2 && i + 1 < bytes.length && bytes[i + 1] >= 0x30 && bytes[i + 1] <= 0x37) { oct += String.fromCharCode(++i); k++; }
            out.push(parseInt(oct, 8) & 0xff);
          } else out.push(n);
      }
    } else out.push(c);
  }
  return Buffer.from(out);
}

// ---------- object model ----------
// PdfObj: { num, gen, val }  val = {dict, stream} | primitive | array

function hexToBytes(hex) {
  const h = hex.replace(/[^0-9a-fA-F]/g, '');
  const s = h.length % 2 ? h + '0' : h;
  const out = Buffer.allocUnsafe(s.length / 2);
  for (let i = 0; i < out.length; i++) out[i] = parseInt(s.substr(i * 2, 2), 16);
  return out;
}

class Parser {
  constructor(data, pos = 0, opts = {}) { this.lex = new Lexer(data, pos); this.data = data; this.noRefs = !!opts.noRefs; }
  // After reading a numeric value, look ahead for "<gen> R" -> indirect reference.
  maybeRef(val) {
    if (this.noRefs) return val;
    if (typeof val !== 'number') return val;
    const save = this.lex.p;
    this.lex.skipWS();
    const t2 = this.lex.next();
    if (t2 && t2.t === 'num') {
      const t3 = this.lex.next();
      if (t3 && t3.t === 'kw' && t3.v === 'R') return { __ref: val };
    }
    this.lex.p = save;
    return val;
  }
  parseObject(tokenIn) {
    const tok = tokenIn ?? this.lex.next();
    if (tok === null) return undefined;
    switch (tok.t) {
      case 'num': return this.maybeRef(tok.v);
      case 'str': return { __str: unescapeLiteral(Buffer.from(tok.v, 'latin1')) };
      case 'hexstr': return { __str: hexToBytes(tok.v) };
      case 'name': return { __name: tok.v };
      case 'arr_open': {
        const arr = [];
        for (;;) {
          const t = this.lex.next();
          if (t === null) break;
          if (t.t === 'arr_close') break;
          if (t.t === 'dict_close') break;
          const v = this.parseObject(t);
          if (v === undefined) break;
          arr.push(v);
        }
        return arr;
      }
      case 'dict_open': {
        const dict = {};
        for (;;) {
          const t = this.lex.next();
          if (t === null) break;
          if (t.t === 'dict_close') break;
          if (t.t !== 'name') { this.parseObject(t); continue; }
          const val = this.parseObject();
          dict[t.v] = val === undefined ? null : val;
        }
        // check for stream
        const save = this.lex.p;
        this.lex.skipWS();
        if (this.data.slice(this.lex.p, this.lex.p + 6).toString('latin1') === 'stream') {
          this.lex.p += 6;
          if (this.data[this.lex.p] === 0x0d) this.lex.p++;
          if (this.data[this.lex.p] === 0x0a) this.lex.p++;
          const start = this.lex.p;
          let len = -1;
          if (dict && typeof dict.Length === 'number') len = dict.Length;
          let end = -1;
          if (len >= 0 && start + len <= this.data.length) {
            const tail = this.data.slice(start + len, start + len + 20).toString('latin1');
            if (/^\s*endstream/.test(tail)) end = start + len;
          }
          if (end < 0) { // scan for endstream
            const idx = this.data.indexOf('endstream', start, 'latin1');
            end = idx < 0 ? this.data.length : idx;
            if (this.data[end - 1] === 0x0a) end--;
            if (this.data[end - 1] === 0x0d) end--;
          }
          const raw = this.data.slice(start, end);
          const eidx = this.data.indexOf('endstream', end, 'latin1');
          this.lex.p = eidx < 0 ? end : eidx + 9;
          return { __dict: dict, stream: raw };
        }
        this.lex.p = save;
        return { __dict: dict };
      }
      case 'kw': {
        if (tok.v === 'true') return true;
        if (tok.v === 'false') return false;
        if (tok.v === 'null') return null;
        return { __kw: tok.v };
      }
      case 'arr_close': case 'brace_open': case 'brace_close':
        return { __kw: tok.v };
    }
    return null;
  }
}

const isDict = (o) => o && typeof o === 'object' && o.__dict !== undefined;
const dget = (d, k) => (isDict(d) ? d.__dict[k] : undefined);

// ---------- filters ----------
function applyFilters(data, dict, resolver) {
  let out = data;
  let f = dget(dict, 'Filter');
  if (f === undefined || f === null) return out;
  if (!Array.isArray(f)) f = [f];
  let parms = dget(dict, 'DecodeParms') ?? dget(dict, 'DP');
  if (!Array.isArray(parms)) parms = [parms];
  for (let i = 0; i < f.length; i++) {
    let name = f[i] && f[i].__name;
    if (!name) continue;
    const pm = parms[i] && parms[i].__dict ? parms[i].__dict : {};
    try {
      switch (name) {
        case 'FlateDecode': case 'Fl': {
          out = inflate(out);
          const pred = pm && pm.Predictor !== undefined ? pm.Predictor : 1;
          if (pred && pred > 1) out = pngPredictor(out, pm);
          break;
        }
        case 'LZWDecode': case 'LZW': {
          const early = pm.EarlyChange !== undefined ? pm.EarlyChange : 1;
          out = lzwDecode(out, early);
          const pred = pm.Predictor !== undefined ? pm.Predictor : 1;
          if (pred && pred > 1) out = pngPredictor(out, pm);
          break;
        }
        case 'ASCIIHexDecode': case 'AHx': {
          const s = out.toString('latin1').replace(/[^0-9a-fA-F]/g, '');
          out = Buffer.from(s.match(/.{1,2}/g)?.map(h => parseInt(h, 16)) ?? []);
          break;
        }
        case 'ASCII85Decode': case 'A85': {
          out = a85Decode(out);
          break;
        }
        case 'RunLengthDecode': case 'RL': {
          out = rleDecode(out); break;
        }
        case 'Crypt': {
          break; // identity / encrypted-streams unsupported
        }
        default:
          console.error(`[warn] unknown filter ${name}`);
      }
    } catch (e) {
      console.error(`[warn] filter ${name} failed: ${e.message}`);
    }
  }
  return out;
}

function inflate(b) {
  const attempts = [
    () => zlib.inflateSync(b),
    () => zlib.inflateRawSync(b),
    () => zlib.inflateSync(b, { finishFlush: zlib.constants.Z_SYNC_FLUSH }),
    () => zlib.inflateRawSync(b, { finishFlush: zlib.constants.Z_SYNC_FLUSH }),
    () => zlib.unzipSync(b),
  ];
  for (const fn of attempts) {
    try {
      const r = fn();
      if (r && r.length) return r;
    } catch (e) { /* next */ }
  }
  // tolerate corrupt tail: inflate up to last complete deflate block
  for (let cut = b.length - 1; cut > Math.max(64, b.length - 4096); cut -= 1) {
    try {
      const r = zlib.inflateRawSync(b.slice(0, cut), { finishFlush: zlib.constants.Z_SYNC_FLUSH });
      if (r && r.length > 32) return r;
    } catch (e) { /* next */ }
  }
  return Buffer.alloc(0);
}

function pngPredictor(data, pm) {
  const predictor = pm.Predictor || 1;
  const colors = pm.Colors || 1;
  const bpc = pm.BitsPerComponent || 8;
  const columns = pm.Columns || 1;
  const bpp = Math.ceil((colors * bpc) / 8);
  const rowLen = Math.ceil((colors * bpc * columns) / 8);
  if (predictor === 2) {
    if (bpc !== 8) return data;
    const rows = Math.floor(data.length / rowLen);
    for (let r = 0; r < rows; r++) {
      const base = r * rowLen;
      for (let i = bpp; i < rowLen; i++) data[base + i] = (data[base + i] + data[base + i - bpp]) & 0xff;
    }
    return data;
  }
  // TIFF predictor 10-15
  const outChunks = [];
  let prev = Buffer.alloc(rowLen);
  for (let p = 0; p + 1 <= data.length; p += rowLen + 1) {
    const ft = data[p];
    const row = Buffer.from(data.slice(p + 1, p + 1 + rowLen));
    if (row.length === 0) break;
    switch (ft) {
      case 0: break;
      case 1:
        for (let i = bpp; i < row.length; i++) row[i] = (row[i] + row[i - bpp]) & 0xff;
        break;
      case 2:
        for (let i = 0; i < row.length; i++) row[i] = (row[i] + prev[i]) & 0xff;
        break;
      case 3:
        for (let i = 0; i < row.length; i++) {
          const left = i >= bpp ? row[i - bpp] : 0;
          row[i] = (row[i] + ((left + prev[i]) >> 1)) & 0xff;
        }
        break;
      case 4:
        for (let i = 0; i < row.length; i++) {
          const a = i >= bpp ? row[i - bpp] : 0;
          const b = prev[i];
          const c = i >= bpp ? prev[i - bpp] : 0;
          const pp = a + b - c;
          const pa = Math.abs(pp - a), pb = Math.abs(pp - b), pc = Math.abs(pp - c);
          const pr = (pa <= pb && pa <= pc) ? a : (pb <= pc ? b : c);
          row[i] = (row[i] + pr) & 0xff;
        }
        break;
      default: break;
    }
    outChunks.push(row);
    prev = row;
  }
  return Buffer.concat(outChunks);
}

function lzwDecode(data, early) {
  const out = [];
  let dict = [], dictLen = 0;
  const reset = () => { dict = []; for (let i = 0; i < 256; i++) dict[i] = [i]; dictLen = 258; };
  reset();
  let codeBits = 9;
  let prev = null;
  let bitPos = 0;
  const totalBits = data.length * 8;
  while (bitPos + codeBits <= totalBits) {
    let code = 0;
    for (let i = 0; i < codeBits; i++) {
      const b = bitPos + i;
      const bit = (data[b >> 3] >> (7 - (b & 7))) & 1;
      code = (code << 1) | bit;
    }
    bitPos += codeBits;
    if (code === 256) { reset(); codeBits = 9; prev = null; continue; }
    if (code === 257) break;
    let entry;
    if (code < dictLen && dict[code]) entry = dict[code];
    else if (prev) entry = prev.concat([prev[0]]);
    else break;
    out.push(...entry);
    if (prev) { dict[dictLen++] = prev.concat([entry[0]]); }
    prev = entry;
    const limit = dictLen + (early ? 1 : 0);
    if (limit >= 512 && codeBits === 9) codeBits = 10;
    else if (limit >= 1024 && codeBits === 10) codeBits = 11;
    else if (limit >= 2048 && codeBits === 11) codeBits = 12;
  }
  return Buffer.from(out);
}

function a85Decode(data) {
  const s = data.toString('latin1').replace(/\s/g, '').replace(/^<~/, '').replace(/~>$/, '');
  const out = [];
  let tuple = [], i = 0;
  while (i < s.length) {
    const ch = s[i];
    if (ch === 'z' && tuple.length === 0) { out.push(0, 0, 0, 0); i++; continue; }
    const v = ch.charCodeAt(0) - 33;
    if (v < 0 || v > 84) { i++; continue; }
    tuple.push(v); i++;
    if (tuple.length === 5) {
      let n = 0;
      for (const d of tuple) n = n * 85 + d;
      out.push((n >>> 24) & 0xff, (n >>> 16) & 0xff, (n >>> 8) & 0xff, n & 0xff);
      tuple = [];
    }
  }
  if (tuple.length > 0) {
    const n = tuple.length;
    for (let j = n; j < 5; j++) tuple.push(84);
    let val = 0;
    for (const d of tuple) val = val * 85 + d;
    const bytes = [(val >>> 24) & 0xff, (val >>> 16) & 0xff, (val >>> 8) & 0xff, val & 0xff];
    out.push(...bytes.slice(0, n - 1));
  }
  return Buffer.from(out);
}

function rleDecode(data) {
  const out = [];
  let i = 0;
  while (i < data.length) {
    const l = data[i++];
    if (l === 128) break;
    if (l < 128) { for (let j = 0; j <= l; j++) out.push(data[i++]); }
    else { const b = data[i++]; for (let j = 0; j < 257 - l; j++) out.push(b); }
  }
  return Buffer.from(out);
}

// ---------- document: scan all "N G obj" ----------
const doc = new Map(); // num -> parsed value
const trailerInfo = { root: null, info: null, encrypt: null };

function scanObjects() {
  const s = buf.toString('latin1');
  const re = /(\d+)\s+(\d+)\s+obj\b/g;
  let m;
  const found = [];
  while ((m = re.exec(s)) !== null) {
    found.push({ num: parseInt(m[1], 10), gen: parseInt(m[2], 10), start: m.index + m[0].length });
  }
  for (const o of found) {
    if (doc.has(o.num)) continue; // first (highest-priority per spec after xref) wins
    try {
      const p = new Parser(buf, o.start);
      const v = p.parseObject();
      if (v !== undefined) doc.set(o.num, v);
    } catch (e) { /* ignore malformed */ }
  }
  // trailer info
  const tre = /trailer/g; let t;
  while ((t = tre.exec(s)) !== null) {
    try {
      const p = new Parser(buf, t.index + 7);
      const v = p.parseObject();
      if (isDict(v)) {
        const dd = v.__dict;
        if (dd.Root && !trailerInfo.root) trailerInfo.root = dd.Root;
        if (dd.Encrypt) trailerInfo.encrypt = dd.Encrypt;
        if (dd.Info) trailerInfo.info = dd.Info;
        if (Array.isArray(dd.XRefStm) && !trailerInfo.root) { /* handled by scan */ }
      }
    } catch (e) { /* ignore */ }
  }
  // Also handle xref-stream-only PDFs: find any object with /Type /Catalog
  for (const [num, v] of doc) {
    if (isDict(v) && dget(v, 'Type') && dget(v, 'Type').__name === 'Catalog' && !trailerInfo.root) trailerInfo.root = { __ref: num };
    if (isDict(v) && dget(v, 'Type') && dget(v, 'Type').__name === 'XRef' && dget(v, 'Root') && !trailerInfo.root) trailerInfo.root = dget(v, 'Root');
  }
}
scanObjects();
console.error(`[i] objects found: ${doc.size}`);

// expand object streams (/Type /ObjStm)
function expandObjStm() {
  const toExpand = [];
  for (const [num, v] of doc) {
    if (isDict(v) && v.stream && dget(v, 'Type') && dget(v, 'Type').__name === 'ObjStm') toExpand.push([num, v]);
  }
  for (const [num, v] of toExpand) {
    let data;
    try { data = applyFilters(v.stream, v, resolve); } catch (e) { continue; }
    const n = dget(v, 'N') | 0;
    const first = dget(v, 'First') | 0;
    const lex = new Lexer(data, 0);
    const pairs = [];
    for (let i = 0; i < n; i++) {
      const a = lex.next(), b = lex.next();
      if (!a || !b || a.t !== 'num' || b.t !== 'num') break;
      pairs.push([a.v, b.v]);
    }
    for (const [onum, ooff] of pairs) {
      if (doc.has(onum)) continue;
      try {
        const p = new Parser(data, first + ooff);
        const ov = p.parseObject();
        if (ov !== undefined) doc.set(onum, ov);
      } catch (e) { /* ignore */ }
    }
  }
  if (toExpand.length) console.error(`[i] expanded ${toExpand.length} object streams`);
}

function deref(v) {
  let guard = 0;
  while (v && typeof v === 'object' && v.__ref !== undefined && guard++ < 32) {
    v = doc.get(v.__ref);
  }
  return v;
}

// Wire parser + filters now that doc exists
function resolve(ref) { return deref(ref); }
expandObjStm();

// ---------- content stream text extraction ----------
// Build ToUnicode maps
function parseToUnicode(data) {
  const s = data.toString('latin1');
  const map = new Map();
  const sections = s.match(/beginbfchar[\s\S]*?endbfchar/g) || [];
  for (const sec of sections) {
    const re = /<([0-9a-fA-F]+)>\s*<([0-9a-fA-F]*)>/g;
    let m;
    while ((m = re.exec(sec)) !== null) {
      const src = parseInt(m[1], 16);
      const dstHex = m[2];
      map.set(src, utf16beToStr(dstHex));
    }
  }
  const rsections = s.match(/beginbfrange[\s\S]*?endbfrange/g) || [];
  for (const sec of rsections) {
    // <lo> <hi> <dststart>
    let re = /<([0-9a-fA-F]+)>\s*<([0-9a-fA-F]+)>\s*<([0-9a-fA-F]+)>/g;
    let m;
    while ((m = re.exec(sec)) !== null) {
      const lo = parseInt(m[1], 16), hi = parseInt(m[2], 16);
      const dstHex = m[3];
      const startCode = dstHex.length >= 4 ? parseInt(dstHex.slice(-4), 16) : parseInt(dstHex, 16);
      const startStr = dstHex;
      const baseLen = startStr.length;
      for (let c = lo; c <= hi && c - lo < 65536; c++) {
        const inc = c - lo;
        let hexTail = (startCode + inc).toString(16);
        const keep = Math.max(1, baseLen - (baseLen % 4) || 4);
        let newHex = startStr.slice(0, startStr.length - Math.min(baseLen, 4)) + hexTail.padStart(Math.min(4, baseLen), '0');
        map.set(c, utf16beToStr(newHex));
      }
    }
    // [ <lo> <hi> ] <dstarray>
    re = /\[\s*<([0-9a-fA-F]+)>\s*<([0-9a-fA-F]+)>\s*\]\s*<([0-9a-fA-F]+)>/g;
    while ((m = re.exec(sec)) !== null) {
      const lo = parseInt(m[1], 16), hi = parseInt(m[2], 16);
      const start = parseInt(m[3], 16);
      for (let c = lo; c <= hi && c - lo < 65536; c++) map.set(c, String.fromCodePoint(start + (c - lo)));
    }
  }
  return map;
}

function utf16beToStr(hex) {
  let s = '';
  for (let i = 0; i + 3 < hex.length + 1; i += 4) {
    const h = hex.substr(i, 4);
    if (h.length < 4) break;
    const cp = parseInt(h, 16);
    if (cp >= 0xd800 && cp <= 0xdbff && i + 8 <= hex.length) {
      const lo = parseInt(hex.substr(i + 4, 4), 16);
      s += String.fromCharCode(cp, lo);
      i += 4;
    } else s += String.fromCharCode(cp);
  }
  return s;
}

// Collect font info: name -> {toUnicode map, is2byte}
function collectFonts(resources, depth = 0) {
  const fonts = new Map();
  if (!resources || depth > 8) return fonts;
  const res = deref(resources);
  if (!isDict(res)) {
    if (process.env.PDFDEBUG) console.error(`[dbg] collectFonts: res not dict (${JSON.stringify(resources)})`);
    return fonts;
  }
  const fdict = dget(res, 'Font');
  if (!fdict) { if (process.env.PDFDEBUG) console.error(`[dbg] collectFonts: no /Font, keys=${Object.keys(res.__dict)}`); return fonts; }
  const fdictR = deref(fdict);
  if (!isDict(fdictR)) {
    if (process.env.PDFDEBUG) console.error(`[dbg] collectFonts: /Font not dict: ${JSON.stringify(fdict)} -> ${JSON.stringify(fdictR).slice(0,120)}`);
    return fonts;
  }
  for (const key of Object.keys(fdictR.__dict)) {
    const fref = deref(fdictR.__dict[key]);
    if (!isDict(fref)) { if (process.env.PDFDEBUG) console.error(`[dbg]  font ${key} not dict: ${JSON.stringify(fdictR.__dict[key])} -> ${JSON.stringify(fref).slice(0,120)}`); continue; }
    const info = { twoByte: false, tounicode: null };
    const sub = dget(fref, 'Subtype');
    const st = sub && sub.__name;
    if (st === 'Type0') info.twoByte = true;
    const tuRef = dget(fref, 'ToUnicode');
    const tu = deref(tuRef);
    if (tu && tu.stream) {
      try { info.tounicode = parseToUnicode(applyFilters(tu.stream, tu, resolve)); } catch (e) { /* */ }
    }
    if (st === 'Type0') {
      const df = dget(fref, 'DescendantFonts');
      if (Array.isArray(df) && df.length) {
        const d0 = deref(df[0]);
        if (isDict(d0)) {
          const dsub = dget(d0, 'Subtype');
          if (dsub && dsub.__name === 'CIDFontType2') info.twoByte = true;
        }
      }
    }
    if (process.env.PDFDEBUG) {
      console.error(`[dbg]  font ${key}: subtype=${st} twoByte=${info.twoByte} toUni=${info.tounicode ? info.tounicode.size : 0} keys=${Object.keys(fref.__dict).join(',')}`);
      const df = dget(fref, 'DescendantFonts');
      if (Array.isArray(df) && df.length) {
        const d0 = deref(df[0]);
        if (isDict(d0)) console.error(`[dbg]    descendant keys=${Object.keys(d0.__dict).join(',')}`);
      }
    }
    fonts.set(key, info);
  }
  return fonts;
}

function decodeBytes(bytes, font) {
  if (font && font.tounicode && font.tounicode.size) {
    let s = '';
    const n = font.twoByte ? 2 : 1;
    let i = 0;
    while (i < bytes.length) {
      let code;
      if (n === 2 && i + 1 < bytes.length) code = (bytes[i] << 8) | bytes[i + 1];
      else code = bytes[i];
      const mapped = font.tounicode.get(code);
      if (mapped !== undefined) s += mapped;
      else if (n === 2 && code === 0) s += '';
      else s += n === 2 && code < 256 ? String.fromCharCode(code) : '';
      i += n;
    }
    return s;
  }
  if (font && font.twoByte) {
    let s = '';
    for (let i = 0; i + 1 < bytes.length; i += 2) {
      const c = (bytes[i] << 8) | bytes[i + 1];
      if (c) s += String.fromCharCode(c);
    }
    return s;
  }
  return bytes.toString('latin1');
}

function unescapePDFTextBytes(bytes) {
  const out = [];
  for (let i = 0; i < bytes.length; i++) {
    const c = bytes[i];
    if (c === 0x5c && i + 1 < bytes.length) {
      const n = bytes[++i];
      switch (n) {
        case 0x6e: out.push(0x0a); break;
        case 0x72: out.push(0x0d); break;
        case 0x74: out.push(0x09); break;
        case 0x62: out.push(8); break;
        case 0x66: out.push(12); break;
        case 0x28: out.push(0x28); break;
        case 0x29: out.push(0x29); break;
        case 0x5c: out.push(0x5c); break;
        case 0x0d: if (bytes[i + 1] === 0x0a) i++; break;
        case 0x0a: break;
        default:
          if (n >= 0x30 && n <= 0x37) { let oct = String.fromCharCode(n), k = 0; while (k < 2 && i + 1 < bytes.length && bytes[i + 1] >= 0x30 && bytes[i + 1] <= 0x37) { oct += String.fromCharCode(++i); k++; } out.push(parseInt(oct, 8) & 0xff); }
          else out.push(n);
      }
    } else out.push(c);
  }
  return Buffer.from(out);
}

// Extract text from a content stream, tracking Td/TD/Tm/TJ for line breaks
function extractTextFromContent(content) {
  const P = new Parser(content, 0, { noRefs: true });   // content streams have no indirect refs
  const stack = [];
  let text = '';
  let lastX = null, lastY = null;
  let tm = [1, 0, 0, 1, 0, 0];
  let tlm = [1, 0, 0, 1, 0, 0];
  let leading = 0;
  let font = null;
  const gs = [];
  const emit = (s) => { text += s; };
  const mul = (a, b) => [
    a[0] * b[0] + a[1] * b[2], a[0] * b[1] + a[1] * b[3],
    a[2] * b[0] + a[3] * b[2], a[2] * b[1] + a[3] * b[3],
    a[4] * b[0] + a[5] * b[2] + b[4], a[4] * b[1] + a[5] * b[3] + b[5],
  ];
  const newlineIfMoved = (x, y) => {
    if (lastY === null) { lastX = x; lastY = y; return; }
    const dy = y - lastY, dx = x - lastX;
    if (Math.abs(dy) > 0.8) emit('\n');
    else if (dx > 3) emit(' ');
    lastX = x; lastY = y;
  };
  for (;;) {
    const t = P.lex.next();
    if (t === null) break;
    if (t.t === 'str' || t.t === 'hexstr' || t.t === 'num' || t.t === 'name' || t.t === 'arr_open' || t.t === 'dict_open') {
      let v;
      try { v = P.parseObject(t); } catch (e) { v = null; }
      stack.push(v);
      if (stack.length > 512) stack.shift();
      continue;
    }
    if (t.t !== 'kw') continue;
    const op = t.v;
    const n = (k) => stack[stack.length - 1 - k];
    switch (op) {
      case 'BT': tm = [1, 0, 0, 1, 0, 0]; tlm = tm.slice(); lastX = lastY = null; break;
      case 'ET': emit('\n'); break;
      case 'Tf': {
        const fname = n(1);
        if (fname && fname.__name !== undefined) font = currentFonts.get(fname.__name) || font;
        break;
      }
      case 'Td': {
        const ty = n(0) || 0, tx = n(1) || 0;
        tlm = mul([1, 0, 0, 1, tx, ty], tlm);
        tm = tlm.slice();
        newlineIfMoved(tm[4], tm[5]);
        break;
      }
      case 'TD': {
        const ty = n(0) || 0, tx = n(1) || 0;
        leading = -ty;
        tlm = mul([1, 0, 0, 1, tx, ty], tlm);
        tm = tlm.slice();
        emit('\n');
        break;
      }
      case 'Tm': {
        const a = n(5), b = n(4), c = n(3), d = n(2), e = n(1), f = n(0);
        tlm = [a ?? 1, b ?? 0, c ?? 0, d ?? 1, e ?? 0, f ?? 0];
        tm = tlm.slice();
        newlineIfMoved(tm[4], tm[5]);
        break;
      }
      case 'T*': { tlm = mul([1, 0, 0, 1, 0, -leading], tlm); tm = tlm.slice(); emit('\n'); break; }
      case 'TL': leading = n(0) || 0; break;
      case 'cm': {
        const a = n(5), b = n(4), c = n(3), d = n(2), e = n(1), f = n(0);
        const m = [a ?? 1, b ?? 0, c ?? 0, d ?? 1, e ?? 0, f ?? 0];
        tlm = mul(m, tlm); tm = tlm.slice();
        break;
      }
      case 'q': gs.push(tlm.slice()); if (gs.length > 64) gs.shift(); break;
      case 'Q': { const p = gs.pop(); if (p) tlm = p.slice(); break; }
      case 'Tj': case "'": case '"': {
        if (op !== 'Tj') { tlm = mul([1, 0, 0, 1, 0, -leading], tlm); tm = tlm.slice(); emit('\n'); }
        const s = n(0);
        if (s && s.__str) emit(decodeBytes(s.__str, font));
        break;
      }
      case 'TJ': {
        const arr = n(0);
        if (Array.isArray(arr)) {
          let buf = '';
          for (const el of arr) {
            if (el && el.__str) buf += decodeBytes(el.__str, font);
            else if (typeof el === 'number' && el < -170) buf += ' ';
          }
          emit(buf);
        }
        break;
      }
    }
    stack.length = 0;
  }
  return text;
}

// ---------- page tree walk ----------
let currentFonts = new Map();
const pageTexts = [];

function processPage(page, pageNum) {
  const contents = dget(page, 'Contents');
  const res = dget(page, 'Resources');
  currentFonts = collectFonts(res);
  // also try inheriting from parent
  if (currentFonts.size === 0 && page.__inheritedRes) currentFonts = collectFonts(page.__inheritedRes);
  let contentBuf = Buffer.alloc(0);
  const parts = Array.isArray(contents) ? contents : [contents];
  for (const c of parts) {
    const cr = deref(c);
    if (cr && cr.stream) {
      try {
        let d = applyFilters(cr.stream, cr, resolve);
        contentBuf = Buffer.concat([contentBuf, d, Buffer.from('\n')]);
      } catch (e) { /* */ }
    } else if (cr && cr.__dict) {
      // array of streams mistakenly deref'd
      for (const e2 of cr) { const er = deref(e2); if (er && er.stream) { try { contentBuf = Buffer.concat([contentBuf, applyFilters(er.stream, er, resolve)]); } catch (_) { } } }
    }
  }
  if (contentBuf.length === 0) {
    if (process.env.PDFDEBUG) console.error(`[dbg] page ${pageNum}: NO CONTENT (contents=${JSON.stringify(contents)})`);
    return '';
  }
  let t = '';
  try { t = extractTextFromContent(contentBuf); } catch (e) {
    if (process.env.PDFDEBUG) console.error(`[dbg] page ${pageNum}: extract threw ${e.message}\n${e.stack}`);
    return '';
  }
  if (process.env.PDFDEBUG) {
    console.error(`[dbg] page ${pageNum}: contentBytes=${contentBuf.length} fonts=${currentFonts.size} textChars=${t.length}`);
    console.error(`[dbg]   raw: ${JSON.stringify(contentBuf.slice(0, 400).toString('latin1'))}`);
  }
  return t;
}

function walkPages() {
  const root = deref(trailerInfo.root);
  const pages = [];
  const seen = new Set();
  const inherited = [];
  function visit(nodeRef, inheritedRes, depth) {
    if (depth > 60) return;
    const node = deref(nodeRef);
    if (!isDict(node) || seen.has(nodeRef && nodeRef.__ref)) return;
    if (nodeRef && nodeRef.__ref !== undefined) seen.add(nodeRef.__ref);
    const type = dget(node, 'Type');
    const kids = dget(node, 'Kids');
    const res = dget(node, 'Resources') || inheritedRes;
    if (type && type.__name === 'Page') {
      node.__inheritedRes = res;
      pages.push(node);
      return;
    }
    if (Array.isArray(kids)) for (const k of kids) visit(k, res, depth + 1);
  }
  if (isDict(root)) {
    const p = dget(root, 'Pages');
    visit(p, null, 0);
    // fallback: if tree walk failed, collect all /Type /Page objects
    if (pages.length === 0) {
      for (const [num, v] of doc) {
        if (isDict(v) && dget(v, 'Type') && dget(v, 'Type').__name === 'Page') pages.push(v);
      }
    }
  }
  if (pages.length === 0) {
    for (const [num, v] of doc) {
      if (isDict(v) && dget(v, 'Type') && dget(v, 'Type').__name === 'Page') pages.push(v);
    }
  }
  return pages;
}

const pages = walkPages();
console.error(`[i] pages: ${pages.length}`);
if (process.env.PDFDEBUG) {
  console.error('[dbg] trailer root ref:', JSON.stringify(trailerInfo.root));
  const rt = deref(trailerInfo.root);
  console.error('[dbg] root isDict:', isDict(rt), 'keys:', isDict(rt) ? Object.keys(rt.__dict).join(',') : 'n/a');
  const pgs = isDict(rt) ? dget(rt, 'Pages') : null;
  console.error('[dbg] Pages entry:', JSON.stringify(pgs && pgs.__ref !== undefined ? pgs.__ref : pgs));
  const pnode = deref(pgs);
  console.error('[dbg] Pages node isDict:', isDict(pnode), 'keys:', isDict(pnode) ? Object.keys(pnode.__dict).join(',') : 'n/a');
  const kids = isDict(pnode) ? dget(pnode, 'Kids') : null;
  console.error('[dbg] Kids isArray:', Array.isArray(kids), 'len:', Array.isArray(kids) ? kids.length : 'n/a');
  let objstm = 0, streamObjs = 0, pageObjs = 0;
  for (const [n, v] of doc) {
    if (isDict(v) && v.stream) streamObjs++;
    if (isDict(v) && dget(v, 'Type') && dget(v, 'Type').__name === 'ObjStm') objstm++;
    if (isDict(v) && dget(v, 'Type') && dget(v, 'Type').__name === 'Page') pageObjs++;
  }
  console.error(`[dbg] doc size=${doc.size} streamObjs=${streamObjs} objStm=${objstm} pageObjs=${pageObjs}`);
  for (const n of [345, 1, 2, 360, 636, 699, 701]) {
    const v = doc.get(n);
    console.error(`[dbg] doc[${n}] = ${v === undefined ? 'UNDEFINED' : (isDict(v) ? 'dict{' + Object.keys(v.__dict).join(',') + '}' + (v.stream ? ` +stream(${v.stream.length})` : '') : Array.isArray(v) ? 'array' + JSON.stringify(v).slice(0,80) : JSON.stringify(v).slice(0,80))}`);
  }
}

let out = '';
pages.forEach((pg, i) => {
  const t = processPage(pg, i);
  out += `\n\n===== PAGE ${i + 1} =====\n` + t;
});

// Also dump any remaining text found in streams (annotations, outlines, metadata)
function extractFromInfoDict() {
  let s = '';
  const info = deref(trailerInfo.info);
  if (isDict(info)) {
    for (const k of ['Title', 'Author', 'Subject', 'Keywords', 'Creator', 'Producer']) {
      const v = dget(info, k);
      if (v && v.__str) s += `${k}: ${v.__str.toString('utf8')}\n`;
    }
  }
  return s;
}

const meta = extractFromInfoDict();
if (meta) out = `===== METADATA =====\n${meta}\n` + out;

// write
fs.writeFileSync(process.argv[3] || 'out.txt', out, 'utf8');
console.error(`[i] wrote ${out.length} chars`);
