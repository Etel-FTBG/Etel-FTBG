# NTAG 424 DNA signed cards (EtelID NFC upgrade)

A normal NFC card holds a fixed link, so a copied link is a cloned card. An
**NTAG 424 DNA** chip rewrites the link on every tap with an encrypted
counter and a signature. A copy of an old link stops working, and a link
without a chip cannot be forged.

Nothing changes until you turn it on: the default mode is `off`, and the
cards you already issued keep working.

## 1. What you need
- NTAG 424 DNA tags/cards (NXP). Order a few extra for testing.
- An Android phone with the free **NXP TagWriter** app (it can set up SDM / "Secure Dynamic Messaging").

## 2. Put one secret key in wp-config.php (not in the database)
Make 32 hex characters (16 random bytes), for example with
`openssl rand -hex 16`, and add above the "stop editing" line:

    define('ETELID_SUN_META_KEY', 'PUT-YOUR-32-HEX-CHARS-HERE');
    define('ETELID_SUN_MODE', 'log');   // off | log | enforce

Keep a copy in a password manager. Anyone with this key can make valid cards.
It is a different secret from the service key.

## 3. Program a tag (TagWriter)
1. Write a URL record: `https://ethelmarket.com/EtelId/m/<CARD TOKEN>?e=00000000000000000000000000000000&c=0000000000000000`
   (`<CARD TOKEN>` comes from the existing admin "Write NFC card" tool; the zeros are placeholders).
2. Enable **SDM** with: UID mirror on, read-counter mirror on (PICC data, encrypted),
   mirror position `e` = the 32 zeros, CMAC position `c` = the 16 zeros,
   CMAC input offset = the start of `c` (so nothing extra is signed).
3. Set the SDM meta-read key and file-read key to your key from step 2 and change the tag's master key.

## 4. Roll out in two steps
1. **log mode** (default after step 2): every tap is checked and anything unsigned or
   replayed is written to the audit log as `nfc_sun`. Nothing is blocked. Tap a test card
   several times and confirm there are no `nfc_sun` problems for it.
2. **enforce mode**: set `ETELID_SUN_MODE` to `'enforce'` **only after every card in use has been replaced**.
   Then a card link only opens, and its pay/confirm actions only work, after a genuine fresh tap
   (valid for 15 minutes). Old static cards stop working.

## What it checks
- The signature must be valid (wrong key or edited link = refused).
- The chip UID is saved on the first good tap; a different chip on the same card is refused.
- The tap counter must go up; replaying an old link is refused (a page reload within 15 minutes is fine).

To go back at any time set `ETELID_SUN_MODE` to `'off'`.
