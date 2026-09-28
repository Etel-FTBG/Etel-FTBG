# Etel Group MCP Server

A single unified MCP (Model Context Protocol) server exposing read-only
tools across every Etel Group system: EtelID, EtelGroup, Etel.Pay,
Delivery & Ride, EtelTaxi, EtelGebeya, EtelMadeya. Talks to each system's
own MySQL database directly (SELECT-only, capped at 500 rows) and calls
EtelID's existing Service API for cross-company role lookups - it does not
introduce any new auth mechanism, it reuses `ETELID_SERVICE_KEY`.

## Tools exposed

- `<system>_query` (one per system: `etelid_query`, `etelgroup_query`,
  `etelpay_query`, `delivery_query`, `eteltaxi_query`, `etelgebeya_query`,
  `etelmadeya_query`) - run a single read-only `SELECT` against that
  system's DB. Rejects anything that isn't a lone SELECT (no writes, no
  stacked statements, no `INTO OUTFILE`/`LOAD_FILE`).
- `etelid_check_role` / `etelid_list_company_roles` - wrap EtelID's Service
  API (`etelid/v1/service/check_role`, `.../list_company_roles`). Use the
  canonical company slug (`etel-group`, `etel-pay`, `etel-delivery`,
  `etel-transport` = EtelTaxi, `etel-market` = EtelGebeya, `etel-madeya`,
  `etel-media`, `luxline`), not the marketing name.
- `system_health` - HTTP reachability + latency check, one system or all.

This first version is intentionally read-only. Deploy/write tools (pushing
files via each system's Updater, running migrations) are a deliberate v2 -
see "Extending" below - because they need per-system review of what's safe
to automate versus what should stay a human-reviewed Updater upload.

## Setup

1. Copy `.env.example` to `.env` and fill in:
   - `MCP_SHARED_KEY` - a long random secret. This is what every MCP
     client must send back to this server.
   - `ETELID_SERVICE_KEY` - the same value already set as a
     `wp-config.php` constant on the live EtelID site. Do not invent a new
     one; if it was ever rotated, use the *current* value (see the ops
     notes' "service-key cross-system calls" section - downstream copies
     do not auto-update).
   - `DB_HOST` / `DB_USER` / `DB_PASS` - shared MySQL credentials (this
     cPanel account typically uses one MySQL user across DBs), plus each
     system's `*_DB_NAME` (and `ETELID_DB_PREFIX` / `ETELPAY_DB_PREFIX` if
     they differ from the defaults `Etelid_` / `Etelpay_`).
   - Only fill in the systems you actually want exposed; a system left
     without a `*_DB_NAME` will error clearly on its `_query` tool rather
     than silently connecting to the wrong DB.

2. **Deploy the whole `mcp-server/` folder to the site root**, e.g.
   `ethelmarket.com/mcp/` (not nested under any single system's folder -
   this server has no dependency on any one system's codebase). Use
   whichever Updater is most convenient (Delivery's Updater "Upload" tab
   with an explicit Target Path is the most reliable per the ops notes,
   since it doesn't rely on path-guessing). Deploy `.env` itself separately
   and directly (never through git, never through a path that resolves to
   inside webroot without the `.htaccess` protecting it - confirm the
   `.htaccess` in this folder actually made it to the same directory as
   `.env`).

3. Confirm access is locked down: `curl https://ethelmarket.com/mcp/.env`
   and `curl https://ethelmarket.com/mcp/config.php` must both fail (403
   or similar), not return file contents. `curl -X POST
   https://ethelmarket.com/mcp/index.php` with no key must return 401.

## Connecting an MCP client

This server speaks MCP's Streamable HTTP transport over a single POST
endpoint (`index.php`). Example Claude Code config
(`~/.claude.json` or project `.mcp.json`):

```json
{
  "mcpServers": {
    "etel-group": {
      "type": "http",
      "url": "https://ethelmarket.com/mcp/index.php",
      "headers": {
        "X-Mcp-Key": "<the MCP_SHARED_KEY value from .env>"
      }
    }
  }
}
```

If your MCP client only supports stdio servers, bridge it with
[`mcp-remote`](https://www.npmjs.com/package/mcp-remote):

```json
{
  "mcpServers": {
    "etel-group": {
      "command": "npx",
      "args": ["-y", "mcp-remote", "https://ethelmarket.com/mcp/index.php",
        "--header", "X-Mcp-Key:<the MCP_SHARED_KEY value>"]
    }
  }
}
```

## Example queries

```
etelid_query: SELECT etel_id, full_name, phone FROM Etelid_etelid_members
  WHERE phone LIKE ? LIMIT 5   params: ["%912345%"]

delivery_query: SELECT id, status, created_at FROM rides
  ORDER BY created_at DESC LIMIT 10

etelid_check_role: member_id="ET-00123", company_slug="etel-transport"

system_health: system="all"
```

## Security notes

- `_query` tools are hard-capped to `SELECT` only - see `lib/db.php`. They
  are still a real read path into production data; keep `MCP_SHARED_KEY`
  as tightly held as any other service key in this codebase, and treat
  giving someone this key as equivalent to giving them read access to
  every system's DB.
- The DB user configured in `.env` should ideally be a **separate,
  SELECT-only MySQL user** per the principle of least privilege, not the
  same superuser other systems' `config.php` files use - ask your host if
  cPanel's MySQL manager allows creating one. If not available, the
  code-level SELECT-only enforcement in `lib/db.php` is the only guard, so
  treat the key with equivalent care to full DB credentials.
- Follows this codebase's existing hard constraints: pure ASCII PHP, no
  file named literally `api.php`, every file kept well under the ~30KB
  WAF-silent-delete risk size (largest file here is `lib/tools.php` at
  ~6.6KB).

## Extending

- **Write/deploy tools (v2)**: if you want the MCP server to push files
  via a system's Updater or trigger a migration, add them as new named
  tools in `lib/tools.php` with an explicit allowlist of what they can do
  - never a generic "run arbitrary code" tool. Consider requiring a second,
  separate confirmation secret for anything destructive.
- **New system**: add an entry to `mcp_db_registry()` in `config.php`, add
  its key to `mcp_public_systems()` in `lib/tools.php`, and add its `.env`
  variables to `.env.example`.
- **Monitoring/alerting**: `system_health` currently just does a reachability
  check; wiring it to each system's own diagnostic endpoints (e.g.
  Delivery's built-in diagnostic panel) would give real error-rate data
  instead of just up/down.
