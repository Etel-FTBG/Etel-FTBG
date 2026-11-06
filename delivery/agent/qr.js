/* ETEL Agent - tiny offline QR code encoder.
   Byte mode, error correction level M, versions 1-10 (up to 213 bytes). No network, no library: agents work in hotels with poor data.
   QR.encode(text) -> { size, version, modules: [[bool]] }       QR.path(qr, border) -> SVG path "d" for the dark squares
   Verified against the segno library (same matrices) and decoded with OpenCV in the test suite. */
(function (root) {
'use strict';
// [total codewords, EC codewords per block, group-1 blocks, group-1 data codewords, group-2 blocks, group-2 data codewords]  (level M)
var EC = [null, [26, 10, 1, 16, 0, 0], [44, 16, 1, 28, 0, 0], [70, 26, 1, 44, 0, 0], [100, 18, 2, 32, 0, 0], [134, 24, 2, 43, 0, 0],
  [172, 16, 4, 27, 0, 0], [196, 18, 4, 31, 0, 0], [242, 22, 2, 38, 2, 39], [292, 22, 3, 36, 2, 37], [346, 26, 4, 43, 1, 44]];
var ALIGN = [null, [], [6, 18], [6, 22], [6, 26], [6, 30], [6, 34], [6, 22, 38], [6, 24, 42], [6, 26, 46], [6, 28, 50]];
var EXP = [], LOG = [];
(function () { var x = 1; for (var i = 0; i < 255; i++) { EXP[i] = x; LOG[x] = i; x <<= 1; if (x & 256) x ^= 0x11D; } for (i = 255; i < 512; i++) EXP[i] = EXP[i - 255]; })();
function mul(a, b) { return (a === 0 || b === 0) ? 0 : EXP[LOG[a] + LOG[b]]; }
function generator(n) { var p = [1], i, j; for (i = 0; i < n; i++) { var q = new Array(p.length + 1); for (j = 0; j < q.length; j++) q[j] = 0; for (j = 0; j < p.length; j++) { q[j] ^= p[j]; q[j + 1] ^= mul(p[j], EXP[i]); } p = q; } return p; }
function remainder(data, n) {
  var g = generator(n), r = [], i, j; for (i = 0; i < n; i++) r.push(0);
  for (i = 0; i < data.length; i++) { var f = data[i] ^ r.shift(); r.push(0); if (f) for (j = 0; j < n; j++) r[j] ^= mul(g[j + 1], f); }
  return r;
}
function utf8(s) { var o = [], i, c; s = unescape(encodeURIComponent(s)); for (i = 0; i < s.length; i++) { c = s.charCodeAt(i); o.push(c); } return o; }
function dataCapacity(v) { var e = EC[v]; return e[2] * e[3] + e[4] * e[5]; }

function codewords(bytes, v) {
  var e = EC[v], cap = dataCapacity(v), bits = [], i;
  var put = function (val, len) { for (var k = len - 1; k >= 0; k--) bits.push((val >>> k) & 1); };
  put(4, 4); put(bytes.length, v <= 9 ? 8 : 16); for (i = 0; i < bytes.length; i++) put(bytes[i], 8);
  var maxBits = cap * 8; for (i = 0; i < 4 && bits.length < maxBits; i++) bits.push(0);
  while (bits.length % 8) bits.push(0);
  var data = []; for (i = 0; i < bits.length; i += 8) { var b = 0; for (var k = 0; k < 8; k++) b = (b << 1) | bits[i + k]; data.push(b); }
  for (i = 0; data.length < cap; i++) data.push(i % 2 ? 0x11 : 0xEC);
  var blocks = [], ecs = [], pos = 0, nb = e[2] + e[4];
  for (i = 0; i < nb; i++) { var len = i < e[2] ? e[3] : e[5]; var blk = data.slice(pos, pos + len); pos += len; blocks.push(blk); ecs.push(remainder(blk, e[1])); }
  var out = [], maxLen = Math.max(e[3], e[5]);
  for (i = 0; i < maxLen; i++) for (var j = 0; j < nb; j++) if (i < blocks[j].length) out.push(blocks[j][i]);
  for (i = 0; i < e[1]; i++) for (j = 0; j < nb; j++) out.push(ecs[j][i]);
  return out;
}

function encode(text) {
  var bytes = utf8(String(text)), v, i, x, y;
  for (v = 1; v <= 10; v++) { var need = 4 + (v <= 9 ? 8 : 16) + bytes.length * 8; if (need <= dataCapacity(v) * 8) break; }
  if (v > 10) throw new Error('QR: text too long');
  var size = 17 + 4 * v, cw = codewords(bytes, v);
  var mod = [], fn = [];
  for (y = 0; y < size; y++) { mod.push([]); fn.push([]); for (x = 0; x < size; x++) { mod[y].push(false); fn[y].push(false); } }
  var setFn = function (px, py, dark) { mod[py][px] = !!dark; fn[py][px] = true; };
  for (i = 0; i < size; i++) { setFn(6, i, i % 2 === 0); setFn(i, 6, i % 2 === 0); }
  var finder = function (cx, cy) { for (var dy = -4; dy <= 4; dy++) for (var dx = -4; dx <= 4; dx++) { var d = Math.max(Math.abs(dx), Math.abs(dy)), xx = cx + dx, yy = cy + dy; if (xx >= 0 && xx < size && yy >= 0 && yy < size) setFn(xx, yy, d !== 2 && d !== 4); } };
  finder(3, 3); finder(size - 4, 3); finder(3, size - 4);
  var al = ALIGN[v], n = al.length, a, b;
  for (a = 0; a < n; a++) for (b = 0; b < n; b++) { if ((a === 0 && b === 0) || (a === 0 && b === n - 1) || (a === n - 1 && b === 0)) continue; for (var dy = -2; dy <= 2; dy++) for (var dx = -2; dx <= 2; dx++) setFn(al[a] + dx, al[b] + dy, Math.max(Math.abs(dx), Math.abs(dy)) !== 1); }
  var formatBits = function (mask) {
    var data = (0 << 3) | mask, rem = data, k;                      // level M = 00
    for (k = 0; k < 10; k++) rem = (rem << 1) ^ ((rem >>> 9) * 0x537);
    var bits = ((data << 10) | rem) ^ 0x5412, bit = function (j) { return ((bits >>> j) & 1) !== 0; };
    for (k = 0; k <= 5; k++) setFn(8, k, bit(k)); setFn(8, 7, bit(6)); setFn(8, 8, bit(7)); setFn(7, 8, bit(8));
    for (k = 9; k < 15; k++) setFn(14 - k, 8, bit(k));
    for (k = 0; k < 8; k++) setFn(size - 1 - k, 8, bit(k));
    for (k = 8; k < 15; k++) setFn(8, size - 15 + k, bit(k));
    setFn(8, size - 8, true);
  };
  formatBits(0);                                                    // reserve the format area before placing data
  if (v >= 7) { var rem = v, k; for (k = 0; k < 12; k++) rem = (rem << 1) ^ ((rem >>> 11) * 0x1F25); var vb = (v << 12) | rem; for (k = 0; k < 18; k++) { var bt = ((vb >>> k) & 1) !== 0, aa = size - 11 + (k % 3), bb = Math.floor(k / 3); setFn(aa, bb, bt); setFn(bb, aa, bt); } }
  var idx = 0, right, vert, j;
  for (right = size - 1; right >= 1; right -= 2) {
    if (right === 6) right = 5;
    for (vert = 0; vert < size; vert++) for (j = 0; j < 2; j++) {
      x = right - j; var up = ((right + 1) & 2) === 0; y = up ? size - 1 - vert : vert;
      if (!fn[y][x] && idx < cw.length * 8) { mod[y][x] = ((cw[idx >>> 3] >>> (7 - (idx & 7))) & 1) !== 0; idx++; }
    }
  }
  var applyMask = function (m) { for (y = 0; y < size; y++) for (x = 0; x < size; x++) { var inv; switch (m) { case 0: inv = (x + y) % 2 === 0; break; case 1: inv = y % 2 === 0; break; case 2: inv = x % 3 === 0; break; case 3: inv = (x + y) % 3 === 0; break; case 4: inv = (Math.floor(x / 3) + Math.floor(y / 2)) % 2 === 0; break; case 5: inv = x * y % 2 + x * y % 3 === 0; break; case 6: inv = (x * y % 2 + x * y % 3) % 2 === 0; break; default: inv = ((x + y) % 2 + x * y % 3) % 2 === 0; } if (!fn[y][x] && inv) mod[y][x] = !mod[y][x]; } };
  var penalty = function () {
    var p = 0, r, c, run, k, dark = 0;
    for (r = 0; r < size; r++) { run = 1; for (c = 1; c < size; c++) { if (mod[r][c] === mod[r][c - 1]) { run++; if (run === 5) p += 3; else if (run > 5) p++; } else run = 1; } }
    for (c = 0; c < size; c++) { run = 1; for (r = 1; r < size; r++) { if (mod[r][c] === mod[r - 1][c]) { run++; if (run === 5) p += 3; else if (run > 5) p++; } else run = 1; } }
    for (r = 0; r < size - 1; r++) for (c = 0; c < size - 1; c++) if (mod[r][c] === mod[r][c + 1] && mod[r][c] === mod[r + 1][c] && mod[r][c] === mod[r + 1][c + 1]) p += 3;
    var pa = [1, 0, 1, 1, 1, 0, 1, 0, 0, 0, 0], pb = [0, 0, 0, 0, 1, 0, 1, 1, 1, 0, 1];
    for (r = 0; r < size; r++) for (c = 0; c <= size - 11; c++) { var ma = true, mb = true, ma2 = true, mb2 = true; for (k = 0; k < 11; k++) { var h = mod[r][c + k] ? 1 : 0, w = mod[c + k][r] ? 1 : 0; if (h !== pa[k]) ma = false; if (h !== pb[k]) mb = false; if (w !== pa[k]) ma2 = false; if (w !== pb[k]) mb2 = false; } if (ma) p += 40; if (mb) p += 40; if (ma2) p += 40; if (mb2) p += 40; }
    for (r = 0; r < size; r++) for (c = 0; c < size; c++) if (mod[r][c]) dark++;
    p += (Math.ceil(Math.abs(dark * 20 - size * size * 10) / (size * size)) - 1) * 10;
    return p;
  };
  var best = 0, bestP = 1e9, m;
  for (m = 0; m < 8; m++) { applyMask(m); formatBits(m); var pe = penalty(); if (pe < bestP) { bestP = pe; best = m; } applyMask(m); }
  applyMask(best); formatBits(best);
  return { size: size, version: v, mask: best, modules: mod };
}
function path(qr, border) {
  var d = '', b = border == null ? 4 : border, y, x, run;
  for (y = 0; y < qr.size; y++) { x = 0; while (x < qr.size) { if (qr.modules[y][x]) { run = 0; while (x + run < qr.size && qr.modules[y][x + run]) run++; d += 'M' + (x + b) + ' ' + (y + b) + 'h' + run + 'v1h-' + run + 'z'; x += run; } else x++; } }
  return d;
}
root.QR = { encode: encode, path: path };
})(typeof window !== 'undefined' ? window : this);
