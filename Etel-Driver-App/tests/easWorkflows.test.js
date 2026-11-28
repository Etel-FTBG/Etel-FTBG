// Run: node tests/easWorkflows.test.js. The Expo workflows (.eas/workflows, one copy at the repo root and one in this folder):
// the over-the-air update runs by itself on a merge, but the Android builds (APK and Play bundle) must NOT, or the free plan's few
// builds run out and every merge shows a red failure ("used its Android builds from the Free plan this month").
const fs = require('fs'); const path = require('path');
const dirs = [path.join(__dirname, '..', '.eas', 'workflows'), path.join(__dirname, '..', '..', '.eas', 'workflows')];
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
const hasTrigger = (s) => /^on:/m.test(s.replace(/^\s*#.*$/gm, ''));   // a real "on:" line, not one inside a comment
for (const d of dirs) {
  const tag = path.relative(path.join(__dirname, '..', '..'), d);
  const read = (f) => (fs.existsSync(path.join(d, f)) ? fs.readFileSync(path.join(d, f), 'utf8') : null);
  const apk = read('build-android-preview.yml'), aab = read('build-android-production.yml'), ota = read('publish-update.yml');
  t(tag + ': the APK workflow exists', apk !== null);
  t(tag + ': the APK build does NOT start by itself on a merge', apk !== null && !hasTrigger(apk));
  t(tag + ': the APK build can still be run by hand (it is still a build job)', apk !== null && /type:\s*build/.test(apk) && /profile:\s*preview/.test(apk));
  t(tag + ': the Play bundle build does NOT start by itself', aab !== null && !hasTrigger(aab));
  t(tag + ': the over-the-air update STILL runs by itself on main', ota !== null && hasTrigger(ota) && /branches:\s*\['main'\]/.test(ota) && /Etel-Driver-App\/\*\*/.test(ota));
}
for (const f of ['build-android-preview.yml', 'build-android-production.yml', 'publish-update.yml']) {
  const a = fs.existsSync(path.join(dirs[0], f)) ? fs.readFileSync(path.join(dirs[0], f), 'utf8') : 'A';
  const b = fs.existsSync(path.join(dirs[1], f)) ? fs.readFileSync(path.join(dirs[1], f), 'utf8') : 'B';
  t(f + ': both copies are the same', a === b);
}
console.log(fails.length ? 'FAILED:\n' + fails.join('\n') : 'ok'); console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); process.exit(fails.length ? 1 : 0);
