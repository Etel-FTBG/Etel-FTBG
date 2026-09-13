"""Scan all PHP files for SQL injection risk patterns."""
import os, re, sys

ROOT = r'C:\Users\dagianan\Downloads\EtelG\delivery\api'

# Patterns that suggest SQL injection or unsanitized query construction.
# Each pattern is paired with a label and a severity.
PATTERNS = [
    # Critical: direct interpolation in query
    (re.compile(r'->query\s*\(\s*["\'`].*\$[A-Za-z_]'), 'CRIT: ->query() with $var interpolation'),
    (re.compile(r'->exec\s*\(\s*["\'`].*\$[A-Za-z_]'),   'CRIT: ->exec() with $var interpolation'),
    # Critical: legacy mysql_ functions
    (re.compile(r'\bmysql_query\s*\('),    'CRIT: legacy mysql_query()'),
    (re.compile(r'\bmysqli_query\s*\('),   'CRIT: legacy mysqli_query()'),
    # High: WHERE col="$var" string concatenation
    (re.compile(r'WHERE\s+\w+\s*=\s*["\'`][^"\']*\$[A-Za-z_]'), 'HIGH: WHERE col="$var"'),
    # High: PHP string concat pattern in SQL string  "..." . $var . "..."
    (re.compile(r'["\'`][^"\']*["\'`]\s*\.\s*\$[A-Za-z_]\w*\s*\.'), 'HIGH: SQL string built with . concat'),
    # High: ORDER BY / LIMIT / OFFSET with $var (not parameterizable via ?)
    (re.compile(r'ORDER\s+BY\s+\$', re.IGNORECASE),  'HIGH: ORDER BY $var (not parameterizable)'),
    (re.compile(r'LIMIT\s+\$',    re.IGNORECASE),  'HIGH: LIMIT $var (must be cast to int)'),
    # Medium: dynamic table/column names (can't be parameterized)
    (re.compile(r'FROM\s+\$',     re.IGNORECASE),  'MED:  dynamic table name from $var'),
    (re.compile(r'INTO\s+\$',     re.IGNORECASE),  'MED:  dynamic table name from $var'),
]

# Whitelist: prepared statements are safe — we look for ->prepare() + ->execute([...]) co-occurrence
PREPARE_PATTERN = re.compile(r'->prepare\s*\(')
EXECUTE_PATTERN = re.compile(r'->execute\s*\(\s*array\s*\(')

def scan_file(path):
    findings = []
    prep_count = 0
    exec_count = 0
    try:
        with open(path, 'r', encoding='utf-8', errors='replace') as fh:
            content = fh.read()
    except Exception as e:
        return [(0, f'READ-ERR: {e}', '')], 0, 0
    for ln, line in enumerate(content.splitlines(), 1):
        if PREPARE_PATTERN.search(line):
            prep_count += 1
        if EXECUTE_PATTERN.search(line):
            exec_count += 1
        for pat, label in PATTERNS:
            m = pat.search(line)
            if m:
                findings.append((ln, label, line.strip()[:200]))
    return findings, prep_count, exec_count

def main():
    total_files = 0
    total_findings = 0
    print(f"{'SEVERITY':<8} {'FILE':<60} {'LINE':>5}  SNIPPET")
    print('-' * 110)
    for dirpath, _, files in os.walk(ROOT):
        for f in files:
            if not f.lower().endswith('.php'):
                continue
            p = os.path.join(dirpath, f)
            rel = os.path.relpath(p, ROOT)
            total_files += 1
            findings, prep, exe = scan_file(p)
            total_findings += len(findings)
            for ln, label, snippet in findings:
                print(f"{label.split(':')[0]:<8} {rel:<60} {ln:>5}  {snippet}")
    print()
    print(f"Scanned {total_files} PHP files in {ROOT}")
    print(f"Total findings: {total_findings}")

if __name__ == '__main__':
    main()