# Database Migrations

Versioned schema migrations for ETEL Delivery. Each file is idempotent
and tracked in the `schema_migrations` table.

## Structure

```
api/
  migrate.php              # runner (CLI + web)
  migrations/
    001_login_attempts.sql
    002_items.sql
    003_order_confirmations.sql
    004_payments_wallet.sql
    005_password_resets.sql
    README.md              # this file
  seeds/                   # one-time data scripts (NOT migrations)
    README.md
    admin_demo_accounts.sql
    sample_data.sql
    complete_migration.sql # full schema dump for installs without the runner
    fresh_import.sql
    nuclear_reset.sql      # DESTRUCTIVE — drops everything
```

## Naming convention

Migrations are named `<version>_<name>.sql` where `<version>` is at least
three digits and `<name>` is lowercase snake_case:

```
001_initial_users.sql
002_add_orders_table.sql
...
042_add_courier_ratings.sql
```

The runner discovers, parses, and applies files in numeric-prefix order.

## Usage

### CLI (preferred — run from the API root)

```bash
# Show status (applied vs pending)
php api/migrate.php

# Apply pending migrations
php api/migrate.php --apply

# Validate integrity (duplicate versions, gaps, bad names, destructive patterns)
php api/migrate.php --validate

# Reset applied state (DANGEROUS — does not touch schema, just re-runs all)
php api/migrate.php --reset
```

### Web

```
# Read-only status (no auth required)
GET /api/migrate.php

# Validate integrity (no auth required)
GET /api/migrate.php?validate=1

# Apply pending (admin bearer token required)
POST /api/migrate.php?apply=1
Authorization: Bearer <admin-token>

# Reset (admin bearer token required, dangerous)
POST /api/migrate.php?reset=1
Authorization: Bearer <admin-token>
```

## How it works

1. On startup, the runner ensures a `schema_migrations` table exists
   (`version`, `name`, `applied_at`, `duration_ms`).
2. It scans `api/migrations/` for `NNN_*.sql` files, sorts by version.
3. For each migration not yet in `schema_migrations`, it executes the
   SQL statements inside a transaction. On success it inserts a row into
   `schema_migrations`. On failure it rolls back and stops.

## Writing a new migration

1. Pick the next version number (`00N`). Three-digit minimum, zero-padded.
2. Create `api/migrations/00N_short_name.sql`.
3. Use `CREATE TABLE IF NOT EXISTS`, `ADD COLUMN IF NOT EXISTS`, etc.
   wherever possible — migrations must be safe to re-apply if interrupted.
4. Keep it idempotent. Never include `INSERT` statements unless guarded
   with `INSERT IGNORE` or `ON DUPLICATE KEY UPDATE`.
5. Test locally: `php api/migrate.php --apply`.
6. Run `php api/migrate.php --validate` to confirm no integrity issues.
7. Update the file table at the top of this README.

## Validation rules

`--validate` checks for:

| Severity | Code | What |
|---|---|---|
| ERROR   | `duplicate_version` | Two files share the same `NNN` prefix |
| WARNING | `version_gap`       | Sequential numbers skip (e.g. 001 → 003) |
| ERROR   | `bad_name`          | Migration name isn't lowercase snake_case |
| ERROR   | `empty_file`        | Migration file is zero bytes |
| ERROR   | `drop_table`        | Unconditional `DROP TABLE` — should use `DROP TABLE IF EXISTS` or move to `seeds/` |
| ERROR   | `truncate`          | `TRUNCATE` belongs in `seeds/`, not migrations/ |
| WARNING | `orphan_applied`    | `schema_migrations` has a version with no `.sql` file on disk |

Run `--validate` in CI to catch issues before they reach production.

## Seeds vs migrations

- **Migrations** (`api/migrations/`): idempotent schema. Tracked in DB.
- **Seeds** (`api/seeds/`): one-time data loads, demo accounts, fixture
  data. NOT auto-applied. See `api/seeds/README.md`.

## Smoke tests

`tests/test_migrate.php` verifies discovery, ordering, SQL parsing, and
CLI loading without needing MySQL:

```bash
php tests/test_migrate.php
```