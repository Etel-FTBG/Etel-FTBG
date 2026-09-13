import os

root = r'C:\Users\dagianan\Downloads\EtelG\delivery'

# Re-scan EVERY file and group by relevance to deploy
all_non_ascii = []
for dirpath, dirs, files in os.walk(root):
    for f in files:
        p = os.path.join(dirpath, f)
        try:
            with open(p, 'rb') as fh:
                data = fh.read()
        except:
            continue
        n = sum(1 for b in data if b > 127)
        if n:
            all_non_ascii.append((p, n, len(data), f))

# Group 1: Apache / PHP-served files (REAL risk on shared cPanel with ModSecurity)
print("=" * 70)
print("HIGH-RISK (Apache-served / WAF-facing):")
print("=" * 70)
for p, n, total, f in all_non_ascii:
    lname = f.lower()
    if lname in ('.htaccess', 'web.config') or lname.endswith('.php') or lname.endswith('.phtml'):
        print(f"  {n:6d}/{total:6d}  {p}")

# Group 2: Files loaded by PHP at runtime (manifest.json, .env, .sql via include)
print()
print("=" * 70)
print("MEDIUM-RISK (loaded by PHP at runtime):")
print("=" * 70)
for p, n, total, f in all_non_ascii:
    lname = f.lower()
    if lname in ('.env', 'manifest.json', 'manifest.php', 'patch.json') or lname.endswith('.sql') or lname.endswith('.json'):
        print(f"  {n:6d}/{total:6d}  {p}")

# Group 3: Browser-served (HTML/CSS/JS) — UTF-8 native, no WAF risk
print()
print("=" * 70)
print("LOW-RISK (browser-served, UTF-8 native — safe):")
print("=" * 70)
for p, n, total, f in all_non_ascii:
    lname = f.lower()
    if lname.endswith(('.html', '.css', '.js', '.md', '.txt')):
        print(f"  {n:6d}/{total:6d}  {p}")

# Inspect the suspicious api/controller (no .php extension)
print()
print("=" * 70)
print("ANOMALY: api/controller (no .php extension):")
print("=" * 70)
suspect = r'C:\Users\dagianan\Downloads\EtelG\delivery\api\controller'
with open(suspect, 'rb') as fh:
    data = fh.read()
n = sum(1 for b in data if b > 127)
print(f"Size: {len(data)} bytes, non-ASCII: {n}")
# Find where non-ASCII lives
in_block = False
block_start = None
i = 0
while i < len(data):
    if data[i] > 127:
        if not in_block:
            in_block = True
            block_start = i
    else:
        if in_block:
            in_block = False
            ctx_start = max(0, block_start - 20)
            ctx_end = min(len(data), i + 20)
            ctx = data[ctx_start:ctx_end]
            preview = ctx.decode('utf-8', errors='replace')
            print(f"  Block at offset {block_start}-{i-1}: ...{preview}...")
    i += 1

# Show first/last 300 bytes to see if this is a real PHP or just a stray copy
print()
print("First 300 bytes:")
print(data[:300].decode('utf-8', errors='replace'))
print()
print("Last 300 bytes:")
print(data[-300:].decode('utf-8', errors='replace'))