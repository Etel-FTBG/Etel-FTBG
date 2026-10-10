# EtelERP

Etel Group's branded ERP: a custom Frappe app (`etel_erp`) that sits on top of stock
ERPNext. It is **not** a fork, so ERPNext upgrades stay clean. It does two things:

1. **Brand** - Etel name, logo, gold-on-carbon colours, no links to ERPNext/Frappe docs, no self sign-up.
2. **EtelID sign-in** - people sign in with their EtelID (number, phone or email) and password.
   EtelID checks the password; the ERP never stores it. The person's company role in EtelID
   (Employees panel) decides their ERP roles.

Built for Frappe/ERPNext **version-15**. Subscription plans, the MCP link and the VPS
install come in later steps.

## How sign-in works

```
/login page  --(etel_login.js)-->  POST /api/method/etel_erp.etelid.login.login
   -> EtelID  POST /EtelId/wp-json/etelid/v1/service/authenticate   (password + optional 2-step code)
   -> EtelID  POST .../service/check_role   (does this person have an active role at THIS company?)
   -> create/update ERP user keyed by EtelID number, set roles from the role map, start a normal Frappe session
```

Rules that are enforced and tested (`tests/`):

* ERP users are keyed **only** by EtelID number (`User.etel_id`), never by email. EtelID emails are
  not verified, so linking by email would let anyone take over an ERP account by registering the
  same email in EtelID.
* The endpoint can only ever sign in an EtelID-linked user. `Administrator` and hand-made users keep
  the normal Frappe login (this is your break-glass access if EtelID is down).
* Fails closed: no key, unreachable EtelID, suspended / limited member, or no role at the company = no sign-in.
* Wrong password and unknown account give the same message.
* Roles are re-applied from EtelID at every sign-in; removing someone in the EtelID Employees panel
  removes their access at their next sign-in (and disables the account). ERP-side role edits for
  EtelID users are overwritten.
* EtelID users have no ERP password at all, so the normal password form cannot be used to enter their account.

## Configuration (per site, in `site_config.json` - never in the database)

| key | meaning |
|---|---|
| `etelid_service_key` | **required.** Per-app key from EtelID (see below). |
| `etelid_company_slug` | **required** unless `etelid_require_company_role` is 0. This site's company slug in EtelID. |
| `etelid_host` | default `ethelmarket.com` |
| `etelid_require_company_role` | default `1`: only people with an active role at that company may sign in |
| `etelid_role_map` | optional JSON, EtelID role -> list of ERP roles. Default in `etelid/policy.py`. |

```bash
bench --site erp.example.com set-config etelid_service_key "<key>"
bench --site erp.example.com set-config etelid_company_slug "my-company-slug"
```

### One-time EtelID change (wp-config.php on the EtelID host)

Add a key for this app, limited to the routes it needs (generate 40+ random characters):

```php
define('ETELID_SERVICE_KEYS', '[
  ... existing entries ...,
  {"app":"etelerp","key":"<random 40+ chars>","scopes":["authenticate","check_role"]}
]');
```

The key must be at least 24 characters. Optionally add `"ips":["<VPS ip>"]` to lock it to the server.

## Install on a bench (Frappe v15 with ERPNext)

```bash
bench get-app etel_erp /path/to/Etel-FTBG/EtelERP      # or the git URL with --subdirectory-less checkout
bench --site <site> install-app etel_erp
bench --site <site> migrate
bench build --app etel_erp && bench restart
```

`install-app` and every `migrate` add the `User.etel_id` field and apply the branding
(`etel_erp/setup/install.py`). After install, open `/login`: the EtelID form is the normal form
with an extra "Authenticator code" box.

## Tests

```bash
python3 -I -m unittest discover -s EtelERP/tests -v      # no Frappe or database needed
```

The tests cover the EtelID client, the role/user rules and the whole sign-in flow against a small
fake of Frappe. They do **not** run inside a real bench: the install step, the branding settings,
the login page script and the real session cookie need a bench (done on the VPS in step 5, see
`docs` checklist below).

### Check on a real bench (first install)

1. `/login` shows the Etel logo and the gold theme; the field says "EtelID number, phone or email".
2. Sign in as a person who has a role at the company: you land on `/app`; **User** list shows a new
   user `<etel-id>@etelid.etelerp.app` with their roles.
3. Sign in with a wrong password: "Invalid login credentials".
4. Remove that person's role in EtelID, sign in again: refused, and the user is disabled.
5. `Administrator` + the bench password still signs in.
6. Help menu has no ERPNext/Frappe links.
