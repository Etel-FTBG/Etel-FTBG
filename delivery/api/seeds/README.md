# Seeds

One-time data scripts for ETEL Delivery. **Not** auto-applied. Run manually
when you need them.

## Contents

| File | Purpose | When to run |
|---|---|---|
| `admin_demo_accounts.sql` | Inserts/updates the default admin + demo customer/courier accounts | Fresh install, after migrations |
| `sample_data.sql`         | Sample catalogue data (Hawassa delivery zones, pricing rules) | Fresh install |
| `complete_migration.sql`  | Full schema dump for fresh installs — covers everything migrations/ does plus more | Only if installing without the migration runner |
| `fresh_import.sql`        | Fresh data import (used after `complete_migration.sql`) | Fresh install |
| `nuclear_reset.sql`       | **DESTRUCTIVE** — drops all tables and recreates from `complete_migration.sql` | Only when you want to wipe everything |

## Usage

```bash
# Fresh install (one-time)
mysql etel_delivery < api/seeds/complete_migration.sql
mysql etel_delivery < api/seeds/fresh_import.sql
mysql etel_delivery < api/seeds/admin_demo_accounts.sql
mysql etel_delivery < api/seeds/sample_data.sql

# Or use the migration runner instead (preferred)
php api/migrate.php --apply
mysql etel_delivery < api/seeds/admin_demo_accounts.sql
mysql etel_delivery < api/seeds/sample_data.sql
```

## Don't

- Don't run `nuclear_reset.sql` on production. It drops everything.
- Don't run `complete_migration.sql` after migrations are applied — it
  duplicates work and may conflict with `IF NOT EXISTS` clauses that
  behave differently on re-run.