# Investor presentation (PowerPoint) - sources

- `ETEL_Investor_Presentation.pptx` (English) and `ETEL_Investor_Presentation_AM.pptx` (Amharic): 14 slides, native charts, speaker notes on every slide.
- `build.js` builds both decks with pptxgenjs. `DECK_LANG=am node build.js` builds the Amharic one (uses `am.js`, an English-string to Amharic dictionary; in Amharic mode any untranslated string is printed as MISSING TRANSLATIONS).
- `am_tr.py` regenerates `am.js` from `keys.json` (the list of English strings) plus the Amharic list inside it. Keep both in the same order when you change wording.
- Setup: `npm install pptxgenjs sharp react react-dom react-icons`, run with `NODE_PATH=$PWD/node_modules`. `build.js` also needs the pptx skill's `apply_theme.js` (it looks in `/root/.claude/skills/synced/*/pptx/scripts`).
- Amharic deck font: Noto Sans Ethiopic (free, OFL). Install it before opening the deck.
- All numbers on the slides are illustrations: fare ETB 120, Etel earns 5% = ETB 6 a trip, today's costs ETB 17,667 a month, full team ETB 150,000 a month (assumed).
- The Amharic wording of new slides is a draft and needs a native review.
