#!/usr/bin/env python3
"""Builds the language dictionaries of the Delivery apps (28 Nov 2026) from ONE readable table.

    python3 tools/i18n/build_langs.py          writes delivery/i18n/<code>.js for am, om, ti, so, aa   (check: --check = only compare, change nothing)

tools/i18n/strings.tsv   columns: English (the exact on-screen text of the app), am (Amharic: only used by pages that have no Amharic of their own; the apps keep theirs), om (Afaan Oromoo), ti (Tigrinya), so (Somali), aa (Afar)
  - A native reader fixes a translation by editing one cell of this table (any spreadsheet app opens it: tab separated, UTF-8), then runs this script.
  - An empty cell means "not translated yet": that text simply stays English in that language.
  - The first column must match the English text on screen (the apps' own English). Texts that do not match anything on screen are harmless.
Each output file is plain ASCII (non-English letters as \\u escapes) and calls EtelLangs.add('<code>', {...}) (js/etel-langs.js)."""
import json
import os
import sys

root = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
tsv = os.path.join(root, 'tools', 'i18n', 'strings.tsv')
out_dir = os.path.join(root, 'delivery', 'i18n')
CODES = ['am', 'om', 'ti', 'so', 'aa']

def esc(s):
    out = []
    for c in s:
        o = ord(c)
        if 32 <= o < 127 and c not in '\\"':
            out.append(c)
        elif c == '"':
            out.append('\\"')
        elif c == '\\':
            out.append('\\\\')
        elif o > 0xFFFF:                                   # emoji: a JavaScript \u escape holds 4 digits, so write the surrogate pair
            o -= 0x10000
            out.append('\\u%04x\\u%04x' % (0xD800 + (o >> 10), 0xDC00 + (o & 0x3FF)))
        else:
            out.append('\\u%04x' % o)
    return ''.join(out)

def build():
    rows = [l.rstrip('\n').split('\t') for l in open(tsv, encoding='utf-8').read().split('\n') if l.strip()]
    head = rows[0]
    assert head[0] == 'English' and head[1:] == CODES, 'unexpected header: %r' % head
    res = {}
    for code in CODES:
        idx = head.index(code)
        d = {}
        for r in rows[1:]:
            r = r + [''] * (len(head) - len(r))
            if r[idx].strip():
                d[r[0].strip()] = r[idx].strip()
        body = ',\n'.join('"%s":"%s"' % (esc(k), esc(v)) for k, v in sorted(d.items()))
        res[code] = ('/* ETEL language dictionary %s (om, ti, so: BETA drafts): English text -> translated text. Made by tools/i18n/build_langs.py from tools/i18n/strings.tsv: edit the table, not this file. */\n'
                     'EtelLangs.add("%s", {\n%s\n});\n') % (code, code, body)
    return res

if __name__ == '__main__':
    check = '--check' in sys.argv
    res = build()
    bad = 0
    for code, text in res.items():
        p = os.path.join(out_dir, code + '.js')
        cur = open(p, encoding='ascii').read() if os.path.exists(p) else None
        if check:
            if cur != text:
                print('OUT OF DATE: delivery/i18n/%s.js (run tools/i18n/build_langs.py)' % code); bad = 1
        else:
            os.makedirs(out_dir, exist_ok=True)
            open(p, 'w', encoding='ascii', newline='\n').write(text)
            print('wrote delivery/i18n/%s.js (%d texts)' % (code, text.count('\n"') + (1 if '\n"' in text else 0)))
    sys.exit(bad)
