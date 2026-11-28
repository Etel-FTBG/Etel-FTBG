# ETEL languages: how to fix or add a translation

The Delivery apps (Customer, Ride, Driver, Courier, Service, login) offer six languages: English, Amharic, Afaan Oromoo, Tigrinya, Somali and Afar.
English and Amharic come from each app's own files. The four newer languages come from ONE table: `tools/i18n/strings.tsv`.

## Fix a translation (a native reader does this)
1. Open `tools/i18n/strings.tsv` in any spreadsheet app (tab separated, UTF-8). Columns: `English`, `om`, `ti`, `so`, `aa`.
2. Change the cell of the language (the English column is the exact text on screen: do not change it).
3. Run `python3 tools/i18n/build_langs.py`. It rewrites `delivery/i18n/om.js`, `ti.js`, `so.js`, `aa.js`.
4. Upload the changed `delivery/i18n/*.js` files. Press Ctrl+F5.
An empty cell means "not translated yet": that text stays English in that language. CI refuses a pull request where the table and the .js files differ.

## Afar (aa) is not translated yet
The picker lists "Qafar af" with COMING SOON and it cannot be chosen. When a native Afar translator has filled the `aa` column, run the build script and delete `soon: 1` from the `aa` line in `delivery/js/etel-langs.js`.

## Add a new text
Add a row with the English text exactly as the app shows it, then the translations. Texts that contain a changing number or name (for example "5 trips") are not matched yet: they stay English.

## Add another language
Add it to `LANGS` in `delivery/js/etel-langs.js`, add a column to the table and the code to `CODES` in `build_langs.py`, and allow the two-letter code in `set_user_language` in `delivery/api/boot.php`.
