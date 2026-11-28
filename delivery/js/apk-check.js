/* ETEL - APK checker. Pick an .apk on the phone or computer; it is read HERE in the browser and never uploaded.
   It answers: is the file complete, is it a real installable APK (not an App Bundle .aab, not a web page), is it signed, which phone types (ABI) does it carry.
   These are the usual reasons for "There was a problem parsing the package". */
(function () {
  'use strict';
  var out = document.getElementById('out'), pick = document.getElementById('pick');
  function u16(d, o) { return d.getUint16(o, true); }
  function u32(d, o) { return d.getUint32(o, true); }
  function ascii(buf, a, b) { var s = ''; for (var i = a; i < b; i++) s += String.fromCharCode(buf[i]); return s; }
  function row(kind, title, text) {
    var d = document.createElement('div'); d.className = 'r ' + kind;
    var h = document.createElement('b'); h.textContent = (kind === 'ok' ? 'OK  ' : kind === 'bad' ? 'PROBLEM  ' : 'NOTE  ') + title;
    d.appendChild(h); if (text) { var p = document.createElement('p'); p.textContent = text; d.appendChild(p); }
    out.appendChild(d);
  }
  function read(file, a, b) { return file.slice(a, b).arrayBuffer(); }
  function mb(n) { return (n / 1048576).toFixed(2) + ' MB'; }

  /* names of every file inside the zip, or throws a short reason */
  async function zipNames(file) {
    var size = file.size, tail = Math.min(size, 66000);
    var t = new Uint8Array(await read(file, size - tail, size)), i = -1;
    for (var k = t.length - 22; k >= 0; k--) { if (t[k] === 0x50 && t[k + 1] === 0x4b && t[k + 2] === 5 && t[k + 3] === 6) { i = k; break; } }
    if (i < 0) throw { code: 'noend' };
    var d = new DataView(t.buffer), cdSize = u32(d, i + 12), cdOff = u32(d, i + 16);
    if (cdOff === 0xFFFFFFFF || cdSize === 0xFFFFFFFF) throw { code: 'zip64' };
    if (cdOff + cdSize > size) throw { code: 'short' };
    var cd = new Uint8Array(await read(file, cdOff, cdOff + cdSize)), cv = new DataView(cd.buffer), p = 0, names = [];
    while (p + 46 <= cd.length && u32(cv, p) === 0x02014b50) {
      var nl = u16(cv, p + 28), el = u16(cv, p + 30), cl = u16(cv, p + 32);
      names.push(ascii(cd, p + 46, p + 46 + nl)); p += 46 + nl + el + cl;
    }
    var magic = cdOff >= 16 ? ascii(new Uint8Array(await read(file, cdOff - 16, cdOff)), 0, 16) : '';
    return { names: names, sigBlock: magic === 'APK Sig Block 42' };
  }

  async function check(file) {
    out.textContent = '';
    row('note', file.name + '  -  ' + mb(file.size) + ' (' + file.size + ' bytes)', 'Compare these bytes with the file you built. They must be exactly the same.');
    var head = new Uint8Array(await read(file, 0, 4));
    if (!(head[0] === 0x50 && head[1] === 0x4b)) {
      var txt = ascii(new Uint8Array(await read(file, 0, 200)), 0, 200);
      row('bad', 'This is not an APK file', /<html|<!doctype/i.test(txt) ? 'It is a web page (an error page was saved instead of the app). Download the file again from the build page.' : 'An APK is a zip file and must start with "PK". This file does not. It was damaged or is another kind of file.');
      return;
    }
    var z;
    try { z = await zipNames(file); } catch (e) {
      var c = e && e.code;
      row('bad', c === 'short' ? 'The file is cut off (incomplete)' : 'The file is damaged', c === 'short' ? 'The end of the file is missing. The upload or the download stopped early. Upload it again and wait until it finishes.' : 'The zip table at the end of the file cannot be read. Download the file again from the build page and upload it again.');
      return;
    }
    var n = z.names, has = function (x) { return n.indexOf(x) >= 0; };
    var isAab = has('BundleConfig.pb') || n.some(function (x) { return x.indexOf('base/manifest/') === 0; });
    if (isAab) { row('bad', 'This is an App Bundle (.aab), not an installable APK', 'An .aab is only for the Google Play Store. A phone cannot install it, and renaming it to .apk gives "problem parsing the package". Build with the PREVIEW profile (APK) instead of the production profile.'); return; }
    if (!has('AndroidManifest.xml')) { row('bad', 'No AndroidManifest.xml inside', 'This zip is not an Android app.'); return; }
    row('ok', 'It is an APK (AndroidManifest.xml is inside)');
    var dex = n.filter(function (x) { return /^classes\d*\.dex$/.test(x); });
    row(dex.length ? 'ok' : 'bad', dex.length ? 'App code found (' + dex.length + ' dex file' + (dex.length > 1 ? 's' : '') + ')' : 'No app code (classes.dex) inside');
    var v1 = n.some(function (x) { return /^META-INF\/[^/]+\.(RSA|DSA|EC)$/i.test(x); });
    if (z.sigBlock || v1) row('ok', 'It is signed', (z.sigBlock ? 'Modern signature (v2 or newer) found. ' : '') + (v1 ? 'Old-style signature (v1) found.' : ''));
    else row('bad', 'It is NOT signed', 'Android refuses an unsigned APK ("problem parsing the package"). Build it again with the PREVIEW profile, which signs it with the ETEL key.');
    var abis = {}; n.forEach(function (x) { var m = /^lib\/([^/]+)\//.exec(x); if (m) abis[m[1]] = 1; });
    var list = Object.keys(abis);
    if (!list.length) row('note', 'No native libraries', 'No phone-type (ABI) list. This is fine only if the app has no native code.');
    else {
      var arm64 = abis['arm64-v8a'], arm32 = abis['armeabi-v7a'];
      row(arm64 ? 'ok' : 'bad', 'Phone types inside: ' + list.join(', '), arm64 ? (arm32 ? 'New 64-bit phones and older 32-bit phones are both covered. Almost every real Android phone is one of these.' : 'Only 64-bit phones. Older 32-bit phones will not install it.') : 'No 64-bit (arm64-v8a) code. Most new phones need it.');
    }
    row('note', 'Not checked here', 'The lowest Android version the app needs (set by the build, normally Android 7.0) and whether the signature is valid. A damaged signature still fails on the phone.');
  }

  pick.addEventListener('change', function () {
    var f = pick.files && pick.files[0]; if (!f) return;
    out.textContent = 'Reading...';
    check(f).catch(function (e) { out.textContent = ''; row('bad', 'Could not read the file', String(e && e.message || e)); });
  });
})();
