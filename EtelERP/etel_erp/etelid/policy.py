"""Pure rules for turning an EtelID member into an ERP user. No Frappe import."""
import re

# EtelID company role (role_at, lower case) -> Frappe/ERPNext roles.
# Unknown roles get "*", which is deliberately empty: the person can sign in but
# sees nothing until their role is set in EtelID (Employees panel) or mapped in
# site_config key etelid_role_map.
DEFAULT_ROLE_MAP = {
	"owner": ["System Manager", "Accounts Manager", "Sales Manager", "Purchase Manager", "Stock Manager", "HR Manager"],
	"creator": ["System Manager", "Accounts Manager", "Sales Manager", "Purchase Manager", "Stock Manager", "HR Manager"],
	"admin": ["System Manager", "Accounts Manager", "Sales Manager", "Purchase Manager", "Stock Manager", "HR Manager"],
	"manager": ["Accounts Manager", "Sales Manager", "Purchase Manager", "Stock Manager"],
	"accountant": ["Accounts User", "Accounts Manager"],
	"cashier": ["Sales User", "Accounts User"],
	"sales": ["Sales User"],
	"staff": ["Sales User", "Stock User"],
	"employee": ["Sales User", "Stock User"],
	"*": [],
}

USER_DOMAIN = "etelid.etelerp.app"
MAX_IDENTIFIER = 120


def normalize_identifier(value):
	"""Trim and sanity-check what the person typed. Returns '' if unusable."""
	if not isinstance(value, str):
		return ""
	value = value.strip()
	if not value or len(value) > MAX_IDENTIFIER:
		return ""
	if any(ord(c) < 32 or ord(c) == 127 for c in value):
		return ""
	return value


def synthetic_user(etel_id, domain=USER_DOMAIN):
	"""Frappe User name for an EtelID member. Built ONLY from the EtelID number,
	never from an email address: EtelID emails are not verified, so linking by
	email would let someone register an EtelID with an admin's email and take
	over that ERP account. Returns '' if the EtelID number is unusable."""
	local = re.sub(r"[^a-z0-9._-]", "", str(etel_id or "").strip().lower())
	if not local:
		return ""
	return local + "@" + domain


def parse_role_map(raw):
	"""Role map from site config (dict or JSON text); falls back to the default
	for anything malformed. Keys are lower-cased; values must be lists of strings."""
	import json

	if isinstance(raw, str) and raw.strip():
		try:
			raw = json.loads(raw)
		except ValueError:
			return dict(DEFAULT_ROLE_MAP)
	if not isinstance(raw, dict) or not raw:
		return dict(DEFAULT_ROLE_MAP)
	out = {}
	for k, v in raw.items():
		if not isinstance(k, str) or not isinstance(v, list) or not all(isinstance(r, str) for r in v):
			return dict(DEFAULT_ROLE_MAP)
		out[k.strip().lower()] = [r.strip() for r in v if r.strip()]
	out.setdefault("*", [])
	return out


def roles_for(role_at, role_map):
	"""Frappe roles for an EtelID company role."""
	key = str(role_at or "").strip().lower()
	roles = role_map.get(key)
	if roles is None:
		roles = role_map.get("*", [])
	seen, out = set(), []
	for r in roles:
		if r not in seen:
			seen.add(r)
			out.append(r)
	return out


def split_name(full_name):
	parts = str(full_name or "").strip().split()
	if not parts:
		return "EtelID", "Member"
	if len(parts) == 1:
		return parts[0], ""
	return parts[0], " ".join(parts[1:])


def decide_login(member, role_row, require_role):
	"""Decide whether this EtelID member may enter this ERP site.
	Returns (allowed, reason). Reasons: ok, inactive, limited, no_role."""
	if str(member.get("status") or "").lower() != "active":
		return False, "inactive"
	if member.get("profile_limited"):
		return False, "limited"
	if require_role and not role_row:
		return False, "no_role"
	return True, "ok"
