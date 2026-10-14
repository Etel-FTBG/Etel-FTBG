/* ETEL photo matching: decide which menu item a photo file belongs to from its file name.
   "fasika_shiro (2).jpg" -> item "Shiro" of "Fasika Restaurant". Used by the Admin bulk photo upload, which always shows
   the result for the admin to confirm before anything is uploaded, so a wrong guess costs one click, not a wrong picture.
   Works in the browser and in Node (tests/photo_match_test.js). */
(function (root, factory) {
  if (typeof module === 'object' && module.exports) module.exports = factory(); else root.EtelPhotoMatch = factory();
})(typeof self !== 'undefined' ? self : this, function () {
  'use strict';

  var NOISE = { img: 1, image: 1, photo: 1, pic: 1, picture: 1, copy: 1, jpg: 1, jpeg: 1, png: 1, webp: 1, whatsapp: 1, screenshot: 1, final: 1, new: 1, dsc: 1, pxl: 1 };

  // lower case, letters and digits only (Amharic included), single spaces
  function words(s) {
    return String(s == null ? '' : s).toLowerCase().replace(/[^\p{L}\p{N}]+/gu, ' ').trim().split(' ').filter(Boolean);
  }
  // file name -> meaningful words: drop the extension, camera noise ("IMG", "copy") and bare numbers like "(2)" or "20241014"
  function fileWords(name) {
    var base = String(name == null ? '' : name).replace(/\.[A-Za-z0-9]{2,5}$/, '');
    return words(base).filter(function (w) { return !NOISE[w] && !/^\d+$/.test(w); });
  }
  function overlap(a, b) {
    if (!a.length || !b.length) return 0;
    var set = {}, i, n = 0; for (i = 0; i < b.length; i++) set[b[i]] = 1;
    for (i = 0; i < a.length; i++) if (set[a[i]]) n++;
    return n / Math.max(a.length, b.length);
  }

  // How well does this file name fit this item? 0 = not at all, up to 100.
  function score(fileName, item) {
    var f = fileWords(fileName), n = words(item.name);
    if (!f.length || !n.length) return 0;
    var fs = f.join(' '), ns = n.join(' ');
    if (fs === ns) return 100;
    var inFile = n.every(function (w) { return f.indexOf(w) >= 0; });          // every word of the item name is in the file name
    var inName = f.every(function (w) { return n.indexOf(w) >= 0; });          // every word of the file name is in the item name
    var s = 0;
    if (inFile) s = 85; else if (inName) s = 70; else s = Math.round(60 * overlap(f, n));
    if (s < 40) return 0;
    // the shop name in the file name ("fasika shiro") breaks ties between two shops that both sell "Shiro"
    var shop = words(item.business_name || item.shop || '');
    if (shop.length && shop.some(function (w) { return f.indexOf(w) >= 0; })) s += 10;
    return Math.min(s, 99);
  }

  // files: [{name}], items: [{id, name, business_name}] -> one entry per file: { file, item|null, score, ambiguous }
  // An item is used by one file only, the best fit first. A file that fits two items equally well is left unmatched
  // (ambiguous) for the admin to choose.
  function matchFiles(files, items) {
    var out = files.map(function (f, i) { return { index: i, file: f, item: null, score: 0, ambiguous: false }; });
    var cands = [];
    out.forEach(function (o) {
      var scored = items.map(function (it) { return { it: it, s: score(o.file.name, it) }; }).filter(function (x) { return x.s > 0; })
                        .sort(function (a, b) { return b.s - a.s; });
      if (!scored.length) return;
      if (scored.length > 1 && scored[0].s === scored[1].s) { o.ambiguous = true; return; }
      cands.push({ o: o, it: scored[0].it, s: scored[0].s });
    });
    cands.sort(function (a, b) { return b.s - a.s; });
    var used = {};
    cands.forEach(function (c) {
      if (used[c.it.id]) return;
      used[c.it.id] = 1; c.o.item = c.it; c.o.score = c.s;
    });
    return out;
  }

  return { words: words, fileWords: fileWords, score: score, matchFiles: matchFiles };
});
