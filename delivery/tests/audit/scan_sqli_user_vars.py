"""Focused SQL injection check: $_GET/$body/$REQUEST values that touch SQL."""
import os, re

ROOT = r'C:\Users\dagianan\Downloads\EtelG\delivery\api'

# User-input sources in this codebase:
#   $_GET, $_POST, $_REQUEST, $body (decoded JSON body)
# Safe patterns: ->prepare(...)->execute(array(...))
# Unsafe patterns: any of the above in a SQL string before prepare()

USER_VARS = re.compile(r'(\$_GET|\$_POST|\$_REQUEST|\$body)\b')
PREPARE   = re.compile(r'->prepare\s*\(')
EXECUTE   = re.compile(r'->execute\s*\(')

def scan_file(path):
    findings = []
    try:
        with open(path, 'r', encoding='utf-8', errors='replace') as fh:
            lines = fh.readlines()
    except:
        return findings

    # Track if we're inside a prepare() ... ->execute() pair
    # when user var appears inside SQL string
    in_query_string = False
    paren_depth = 0
    saw_prepare = False
    saw_execute = False
    last_brace_line = -10

    for ln, line in enumerate(lines, 1):
        # Detect SQL string assignment
        sql_string_match = re.search(r'=\s*["\'`].*\$', line) and re.search(r'(SELECT|INSERT|UPDATE|DELETE|FROM|WHERE|ORDER|LIMIT)', line, re.IGNORECASE)
        if sql_string_match and USER_VARS.search(line):
            # Check if ->prepare() is on the same line
            if '->prepare' in line:
                # prepare with user var inline = unsafe (PDO doesn't substitute named params in SQL string)
                # actually it's fine if the user var is just a placeholder name, not value
                # but if it's an actual value, that's injection
                findings.append((ln, 'PREPARE-WITH-USERVAR', line.strip()[:200]))
            elif '->query' in line or '->exec' in line:
                findings.append((ln, 'QUERY/EXEC-WITH-USERVAR', line.strip()[:200]))
            elif not PREPARE.search(line):
                # SQL string with $var but no prepare on this line - check next few lines
                findings.append((ln, 'SQL-STRING-WITH-USERVAR', line.strip()[:200]))

    return findings

def main():
    total = 0
    print(f"{'FILE':<55} {'LINE':>5}  {'TYPE':<26}  SNIPPET")
    print('-' * 120)
    for dirpath, _, files in os.walk(ROOT):
        for f in files:
            if not f.lower().endswith('.php'):
                continue
            p = os.path.join(dirpath, f)
            rel = os.path.relpath(p, ROOT)
            findings = scan_file(p)
            total += len(findings)
            for ln, kind, snippet in findings:
                print(f"{rel:<55} {ln:>5}  {kind:<26}  {snippet}")
    print()
    print(f"REAL findings: {total}")

if __name__ == '__main__':
    main()