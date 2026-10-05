#!/usr/bin/env python3
"""
CI check (needs the migrated test database): no column is a reserved word that the code uses unquoted.

Background: `items.portion` is a reserved word on MariaDB 10.11, so
  INSERT INTO items (..., portion) ...
is a syntax error and adding a menu item failed in production. Nothing caught it because
the database had the column and the PHP linter does not parse SQL.

Step 1: for every distinct column name in the database, try `SELECT <name> FROM <table> LIMIT 0`
        unquoted. A syntax error (1064) means the name must always be backquoted.
Step 2: scan the PHP code for those names used unquoted in SQL-looking lines.
"""
import os
import re
import sys

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), ".."))
import etel_test_db

ok, out, err = etel_test_db.query_text(
    "SELECT DISTINCT table_name, column_name FROM information_schema.columns WHERE table_schema = DATABASE();")
if not ok:
    print("FAIL: cannot read the test database: " + err)
    sys.exit(1)

seen = {}
for line in out.splitlines():
    t, c = line.split("\t")
    seen.setdefault(c, t)

script = "\n".join("SELECT %s FROM `%s` LIMIT 0;" % (c, t) for c, t in seen.items())
names = list(seen.keys())
ok, out, err = etel_test_db.query_text(script, force=True)
reserved = set()
for m in re.finditer(r"ERROR 1064 \(42000\) at line (\d+)", err):
    reserved.add(names[int(m.group(1)) - 1])

print("Checked %d distinct column names; unusable unquoted: %s" % (len(names), sorted(reserved) or "none"))
if not reserved:
    sys.exit(0)

api = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..", "api")
sql_line = re.compile(r"\b(SELECT|INSERT|UPDATE|SET|FROM|WHERE|VALUES|ORDER BY|GROUP BY|JOIN)\b|\$updates\[\]|'\s*,\s*[a-z_]+'", re.I)
bad = []
for dirpath, dirs, files in os.walk(api):
    dirs[:] = [d for d in dirs if d not in ("backups", "migrations", "seeds", "documents_storage")]
    for f in files:
        if not f.endswith(".php"):
            continue
        p = os.path.join(dirpath, f)
        for n, line in enumerate(open(p, encoding="utf-8", errors="replace"), 1):
            s = line.strip()
            if s.startswith(("//", "*", "/*", "#")):
                continue
            for w in reserved:
                if re.search(r"(?<![`$'\"\w>\[\-])" + re.escape(w) + r"(?![`'\"\w\]])", line) and (sql_line.search(line) or "quality, " + w in line):
                    bad.append("%s:%d: %s" % (os.path.relpath(p, api), n, s[:110]))
if bad:
    print("FAIL: reserved column names used unquoted in SQL (wrap them in backticks):")
    for b in bad:
        print("  " + b)
    sys.exit(1)
print("OK: every use of those names is backquoted")
