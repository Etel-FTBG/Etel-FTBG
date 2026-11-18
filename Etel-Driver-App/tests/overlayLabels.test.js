// Run: node tests/overlayLabels.test.js (needs `npm ci` for typescript). Checks the words on the golden light's pills with the real file.
const ts = require('typescript'); const fs = require('fs'); const path = require('path'); const Module = require('module');
const src = fs.readFileSync(path.join(__dirname, '../src/online/overlayLabels.ts'), 'utf8');
const js = ts.transpileModule(src, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2019 } }).outputText;
const m = new Module('x.js'); m._compile(js, path.join(__dirname, 'x.js')); const { labelsFor } = m.exports;
let pass = 0; const fails = []; const t = (n, c, x) => { if (c) pass++; else fails.push(n + (x ? ' -> ' + x : '')); };
t('driver: Trip / New request', labelsFor('driver')[0] === 'Trip' && labelsFor('driver')[1] === 'New request');
t('courier: New order and Delivery', labelsFor('courier')[2] === 'New order' && labelsFor('courier')[3] === 'Delivery');
t('service giver: New order, In progress', labelsFor('service_giver')[2] === 'New order' && labelsFor('service_giver')[3] === 'In progress');
t('customer: Your trip / Your order', labelsFor('customer')[0] === 'Your trip' && labelsFor('customer')[3] === 'Your order');
t('admin and unknown roles use the customer words', labelsFor('admin')[0] === 'Your trip' && labelsFor(undefined)[3] === 'Your order');
t('always four labels', ['driver', 'courier', 'service_giver', 'customer', 'admin', undefined].every((r) => labelsFor(r).length === 4));
console.log('PASSED: ' + pass + ' FAILED: ' + fails.length); fails.forEach((f) => console.log(' - ' + f)); process.exit(fails.length ? 1 : 0);
