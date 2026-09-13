# Audit Scripts

One-off Python scripts used to scan the ETEL Delivery codebase during the
June 2026 audit review. Kept here so the same checks can be re-run later.

## Scripts

| Script | What it checks |
|---|---|
| `scan_non_ascii.py` | Find files containing non-ASCII bytes (WAF risk on shared cPanel) |
| `scan_sqli.py` | Generic SQL injection pattern scan (concatenation, `->query()` calls, dynamic identifiers) |
| `scan_sqli_user_vars.py` | Focused: `$_GET`/`$_POST`/`$body` values that touch SQL strings |
| `find_orphans.py` | Find PHP files that no other file requires/includes |
| `verify_dead.py` | Verify a list of candidate dead files has zero live references |

## Usage

Run from the project root:

```bash
python tests/audit/scan_non_ascii.py
python tests/audit/scan_sqli.py
python tests/audit/scan_sqli_user_vars.py
python tests/audit/find_orphans.py
python tests/audit/verify_dead.py
```

The scripts print findings to stdout; no flags or options. They're meant
as quick re-runnable checks, not as a CI gate.

## History

These scripts surfaced the following issues during the June 2026 review:

- **scan_non_ascii**: false alarm — found 309 non-ASCII bytes in `.htaccess`
  (decorative comment dividers) and an orphan 42KB `api/controller` file.
  PHP files themselves were already clean.
- **scan_sqli**: audit's claim of SQL injection in `AuthController.php:45`
  was wrong; line 45 is a `$vehicle = ...` variable assignment, not SQL.
  Codebase uses prepared statements correctly throughout.
- **find_orphans**: found 6 dead PHP files (≈80KB) in `api/` that boot.php
  never loads — all duplicates of canonical `api/controllers/*.php` or
  orphaned files from a half-finished refactor.
- **verify_dead**: confirmed zero live references before trashing the
  orphan files.