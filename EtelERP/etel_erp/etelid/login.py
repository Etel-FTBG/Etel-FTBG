"""EtelID sign-in for EtelERP.

The login page posts the typed EtelID number / phone and password here (see
public/js/etel_login.js). We check them with EtelID, then create or update the
matching ERP user and start a normal Frappe session. The ERP never stores an
EtelID password, and EtelID users get no usable ERP password at all.

Safety rules (do not weaken):
 - Users are keyed by EtelID number only (custom field User.etel_id), never by
   email, and this endpoint can never sign in to a user that is not linked to an
   EtelID: Administrator and any hand-made user stay on the normal Frappe login.
 - Fails closed: missing key/host, an unreachable EtelID, a suspended, limited or
   role-less member all refuse the sign-in.
 - Roles come from EtelID (company role -> ERP roles) and are re-applied on every
   sign-in, so removing someone in the EtelID Employees panel removes their access
   at their next sign-in and disables the account.
Configuration lives in site_config.json (never in the database):
   etelid_service_key       per-app key from EtelID (ETELID_SERVICE_KEYS, app "etelerp")
   etelid_host              default ethelmarket.com
   etelid_company_slug      this site's company slug in EtelID (required unless the next is 0)
   etelid_require_company_role   1 (default) = only people with an active role at that company
   etelid_role_map          optional JSON: EtelID role -> list of ERP roles
"""
import frappe
from frappe import _
from frappe.rate_limiter import rate_limit

from etel_erp.etelid.client import EtelIDClient, EtelIDError
from etel_erp.etelid.policy import (
	decide_login,
	normalize_identifier,
	parse_role_map,
	roles_for,
	split_name,
	synthetic_user,
)

GENERIC_FAIL = "Invalid login credentials"


def _config():
	conf = frappe.conf
	return {
		"host": conf.get("etelid_host") or "ethelmarket.com",
		"key": conf.get("etelid_service_key") or "",
		"slug": (conf.get("etelid_company_slug") or "").strip(),
		"require_role": str(conf.get("etelid_require_company_role", 1)) not in ("0", "False", "false", ""),
		"role_map": parse_role_map(conf.get("etelid_role_map")),
	}


def _refuse(message):
	frappe.throw(_(message), frappe.AuthenticationError)


def get_client(cfg):
	return EtelIDClient(cfg["host"], cfg["key"], app="etelerp")


def sync_user(member, roles):
	"""Create or update the ERP user for this EtelID member and return its name."""
	etel_id = str(member["etel_id"])
	name = frappe.db.get_value("User", {"etel_id": etel_id}, "name")
	first, last = split_name(member.get("full_name"))
	phone = str(member.get("phone") or "")
	wanted = [r for r in roles if frappe.db.exists("Role", r)]

	if not name:
		email = synthetic_user(etel_id)
		if not email:
			_refuse(GENERIC_FAIL)
		user = frappe.get_doc(
			{
				"doctype": "User",
				"email": email,
				"first_name": first,
				"last_name": last,
				"mobile_no": phone,
				"enabled": 1,
				"user_type": "System User",
				"send_welcome_email": 0,
				"etel_id": etel_id,
			}
		)
		for r in wanted:
			user.append("roles", {"role": r})
		user.insert(ignore_permissions=True)
		return user.name

	user = frappe.get_doc("User", name)
	user.enabled = 1
	user.first_name = first
	user.last_name = last
	if phone:
		user.mobile_no = phone
	user.set("roles", [])
	for r in wanted:
		user.append("roles", {"role": r})
	user.save(ignore_permissions=True)
	return user.name


def disable_if_linked(etel_id):
	"""Switch off an ERP user whose EtelID access was withdrawn (best effort)."""
	name = frappe.db.get_value("User", {"etel_id": str(etel_id)}, "name")
	if name:
		frappe.db.set_value("User", name, "enabled", 0)
		frappe.db.commit()  # the refusal that follows rolls the request back, so save this first


@frappe.whitelist(allow_guest=True, methods=["POST"])
@rate_limit(limit=20, seconds=60)
def login(identifier=None, password=None, totp_code=None):
	cfg = _config()
	identifier = normalize_identifier(identifier)
	if not identifier or not isinstance(password, str) or not password:
		_refuse("Both login and password required")
	if len(password) > 200:
		_refuse(GENERIC_FAIL)

	try:
		client = get_client(cfg)
		member = client.authenticate(identifier, password, (totp_code or "").strip() or None)
	except EtelIDError as e:
		if e.code in ("requires_2fa", "bad_2fa", "twofa_locked", "rate_limited", "suspended", "revoked", "profile_incomplete", "no_password"):
			_refuse(e.message)  # EtelID's own wording is safe to show and tells the person what to do
		if e.code in ("not_configured", "unreachable", "bad_response"):
			frappe.log_error(title="EtelERP: EtelID sign-in unavailable", message=e.code + ": " + e.message)
			_refuse("EtelID sign-in is not available right now. Please try again later.")
		_refuse(GENERIC_FAIL)  # wrong password / unknown account: do not reveal which

	role_row = None
	if cfg["slug"]:
		try:
			role_row = client.check_role(cfg["slug"], str(member.get("phone") or ""))
		except EtelIDError as e:
			frappe.log_error(title="EtelERP: EtelID role check failed", message=e.code + ": " + e.message)
			_refuse("EtelID sign-in is not available right now. Please try again later.")
	elif cfg["require_role"]:
		frappe.log_error(title="EtelERP: etelid_company_slug is not set", message="Set it with bench set-config.")
		_refuse("This site is not set up for EtelID sign-in yet. Ask your administrator.")

	allowed, reason = decide_login(member, role_row, cfg["require_role"])
	if not allowed:
		if reason == "no_role":
			disable_if_linked(member["etel_id"])
			_refuse("Your EtelID has no role at this company. Ask the owner to add you in the EtelID Employees panel.")
		if reason == "limited":
			_refuse("Complete your EtelID profile (photo and Fayda ID) to use EtelERP.")
		_refuse(GENERIC_FAIL)

	roles = roles_for(role_row["role_at"] if role_row else "", cfg["role_map"])
	user_name = sync_user(member, roles)
	frappe.local.login_manager.login_as(user_name)
	frappe.db.commit()
	# Frappe fills the response (message "Logged In", home page, full name) exactly as for a normal login.
