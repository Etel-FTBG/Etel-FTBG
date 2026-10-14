// Run: node tests/photo_match_test.js   (no dependencies)
'use strict';
var M = require('../js/photo-match.js');
var fails = 0;
function check(name, cond) { console.log((cond ? '  PASS  ' : '  FAIL  ') + name); if (!cond) fails++; }

var items = [
  { id: 1, name: 'Shiro', business_name: 'Fasika Restaurant' },
  { id: 2, name: 'Shiro', business_name: 'Habesha Kitchen' },
  { id: 3, name: 'Doro Wat', business_name: 'Fasika Restaurant' },
  { id: 4, name: 'Macchiato', business_name: 'Sidama Coffee House' },
  { id: 5, name: 'Teff 1kg', business_name: 'Habesha Supermarket' },
  { id: 6, name: 'ሽሮ', business_name: 'Fasika Restaurant' }
];
function one(name, list) { return M.matchFiles([{ name: name }], list || items)[0]; }

console.log('photo file name matching');
check('exact name: macchiato.jpg -> Macchiato', one('macchiato.jpg').item && one('macchiato.jpg').item.id === 4);
check('capitals, dashes and numbers: IMG_Doro-Wat (2).JPG -> Doro Wat', one('IMG_Doro-Wat (2).JPG').item && one('IMG_Doro-Wat (2).JPG').item.id === 3);
check('shop name breaks the tie: fasika_shiro.jpg -> Shiro of Fasika', one('fasika_shiro.jpg').item && one('fasika_shiro.jpg').item.id === 1);
check('shop name breaks the tie: habesha shiro.png -> Shiro of Habesha Kitchen', one('habesha shiro.png').item && one('habesha shiro.png').item.id === 2);
check('two shops sell Shiro and the file says only "shiro": left for the admin (ambiguous)', one('shiro.jpg').item === null && one('shiro.jpg').ambiguous === true);
check('Amharic file name matches the Amharic item', one('ሽሮ.jpg').item && one('ሽሮ.jpg').item.id === 6);
check('a camera file name matches nothing', one('IMG_20241014_093011.jpg').item === null);
check('an unrelated name matches nothing', one('sunset beach.jpg').item === null);
check('part of the item name: teff.jpg -> Teff 1kg', one('teff.jpg').item && one('teff.jpg').item.id === 5);

var r = M.matchFiles([{ name: 'macchiato.jpg' }, { name: 'macchiato (copy).jpg' }], items);
check('one item gets one photo only', (r[0].item ? 1 : 0) + (r[1].item ? 1 : 0) === 1);
check('empty input is safe', M.matchFiles([], items).length === 0 && M.matchFiles([{ name: 'x.jpg' }], []).length === 1);
check('words() keeps letters from any language', M.words('Doro-Wat 2!').join(',') === 'doro,wat,2');

console.log(fails ? 'FAILED: ' + fails : 'ALL PASSED');
process.exit(fails ? 1 : 0);
