"""Tests for etel_erp.etelid.login using a small fake of the parts of Frappe it touches."""
import os
import sys
import types
import unittest

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))


class Throw(Exception):
	pass


class AuthError(Exception):
	pass


class FakeDoc:
	def __init__(self, store, data):
		self.store = store
		self.data = dict(data)
		self.roles = []
		self.name = self.data.get("name") or self.data.get("email")

	def __setattr__(self, k, v):
		if k in ("store", "data", "roles", "name"):
			object.__setattr__(self, k, v)
		else:
			self.data[k] = v

	def __getattr__(self, k):
		try:
			return object.__getattribute__(self, "data")[k]
		except KeyError:
			raise AttributeError(k)

	def append(self, table, row):
		assert table == "roles"
		self.roles.append(row["role"])

	def set(self, table, rows):
		assert table == "roles"
		self.roles = list(rows)

	def insert(self, ignore_permissions=False):
		self.store.users[self.name] = self
		return self

	def save(self, ignore_permissions=False):
		self.store.users[self.name] = self


class FakeFrappe(types.ModuleType):
	AuthenticationError = AuthError

	def __init__(self):
		types.ModuleType.__init__(self, "frappe")
		self.users = {}
		self.existing_roles = {"System Manager", "Sales User", "Stock User", "Accounts User", "Accounts Manager", "Sales Manager", "Purchase Manager", "Stock Manager", "HR Manager"}
		self.conf = {}
		self.logged = []
		self.commits = 0
		self.login_as_calls = []
		self.local = types.SimpleNamespace(login_manager=types.SimpleNamespace(login_as=lambda n: self.login_as_calls.append(n)))
		self.db = types.SimpleNamespace(get_value=self._get_value, exists=self._exists, set_value=self._set_value, commit=self._commit)

	def whitelist(self, **kw):
		return lambda f: f

	def throw(self, msg, exc=None):
		raise (exc or Throw)(msg)

	def log_error(self, title=None, message=None):
		self.logged.append((title, message))

	def get_doc(self, arg, name=None):
		if isinstance(arg, dict):
			return FakeDoc(self, arg)
		return self.users[name]

	def _get_value(self, doctype, filters, field):
		for n, u in self.users.items():
			if u.data.get("etel_id") == filters.get("etel_id"):
				return n
		return None

	def _exists(self, doctype, name):
		return name in self.existing_roles

	def _set_value(self, doctype, name, field, value):
		self.users[name].data[field] = value

	def _commit(self):
		self.commits += 1


fake = FakeFrappe()
fake._ = lambda s: s
sys.modules["frappe"] = fake
rl = types.ModuleType("frappe.rate_limiter")
rl.rate_limit = lambda **kw: (lambda f: f)
sys.modules["frappe.rate_limiter"] = rl

from etel_erp.etelid import login as L  # noqa: E402
from etel_erp.etelid.client import EtelIDError  # noqa: E402

KEY = "k" * 32


class FakeClient:
	def __init__(self, member=None, role=None, auth_error=None, role_error=None):
		self.member, self.role, self.auth_error, self.role_error = member, role, auth_error, role_error
		self.calls = []

	def authenticate(self, identifier, password, totp):
		self.calls.append(("authenticate", identifier, totp))
		if self.auth_error:
			raise self.auth_error
		return self.member

	def check_role(self, slug, phone):
		self.calls.append(("check_role", slug, phone))
		if self.role_error:
			raise self.role_error
		return self.role


def member(**kw):
	m = {"etel_id": "ETL-7", "full_name": "Abebe Kebede", "phone": "0911223344", "status": "active", "email": "a@b.com"}
	m.update(kw)
	return m


class LoginTests(unittest.TestCase):
	def setUp(self):
		fake.users.clear()
		fake.logged[:] = []
		fake.login_as_calls[:] = []
		fake.commits = 0
		fake.conf.clear()
		fake.conf.update({"etelid_service_key": KEY, "etelid_company_slug": "my-co"})
		self.client = FakeClient(member=member(), role={"role_at": "Manager", "etel_id": "ETL-7"})
		L.get_client = lambda cfg: self.client

	def test_new_user_created_with_mapped_roles_and_session_started(self):
		L.login("ETL-7", "pw")
		self.assertEqual(fake.login_as_calls, ["etl-7@etelid.etelerp.app"])
		u = fake.users["etl-7@etelid.etelerp.app"]
		self.assertEqual(u.data["etel_id"], "ETL-7")
		self.assertEqual(u.data["first_name"], "Abebe")
		self.assertEqual(u.data["last_name"], "Kebede")
		self.assertEqual(u.data["mobile_no"], "0911223344")
		self.assertEqual(u.data["send_welcome_email"], 0)
		self.assertEqual(sorted(u.roles), ["Accounts Manager", "Purchase Manager", "Sales Manager", "Stock Manager"])
		self.assertNotIn("password", u.data)
		self.assertNotIn("new_password", u.data)

	def test_second_login_reuses_user_and_resets_roles(self):
		L.login("ETL-7", "pw")
		self.client.role = {"role_at": "cashier", "etel_id": "ETL-7"}
		L.login("ETL-7", "pw")
		self.assertEqual(len(fake.users), 1)
		self.assertEqual(sorted(fake.users["etl-7@etelid.etelerp.app"].roles), ["Accounts User", "Sales User"])
		self.assertEqual(len(fake.login_as_calls), 2)

	def test_roles_that_do_not_exist_on_the_site_are_skipped(self):
		fake.existing_roles.discard("HR Manager")
		self.client.role = {"role_at": "owner", "etel_id": "ETL-7"}
		L.login("ETL-7", "pw")
		self.assertNotIn("HR Manager", fake.users["etl-7@etelid.etelerp.app"].roles)
		self.assertIn("System Manager", fake.users["etl-7@etelid.etelerp.app"].roles)

	def test_wrong_password_is_generic_and_starts_no_session(self):
		self.client.auth_error = EtelIDError("wrong_password", "Incorrect EtelID password.", 401)
		with self.assertRaises(AuthError) as cm:
			L.login("ETL-7", "bad")
		self.assertEqual(str(cm.exception), "Invalid login credentials")
		self.assertEqual(fake.login_as_calls, [])
		self.assertEqual(fake.users, {})

	def test_unknown_account_looks_the_same_as_wrong_password(self):
		self.client.auth_error = EtelIDError("not_found", "No EtelID account found for that identifier.", 404)
		with self.assertRaises(AuthError) as cm:
			L.login("nobody", "pw")
		self.assertEqual(str(cm.exception), "Invalid login credentials")

	def test_two_step_message_passes_through(self):
		self.client.auth_error = EtelIDError("requires_2fa", "Enter the 6-digit code from your authenticator app.", 401)
		with self.assertRaises(AuthError) as cm:
			L.login("ETL-7", "pw")
		self.assertIn("6-digit", str(cm.exception))

	def test_totp_is_forwarded_and_trimmed(self):
		L.login("ETL-7", "pw", " 123456 ")
		self.assertEqual(self.client.calls[0], ("authenticate", "ETL-7", "123456"))
		self.client.calls[:] = []
		L.login("ETL-7", "pw", "   ")
		self.assertEqual(self.client.calls[0], ("authenticate", "ETL-7", None))

	def test_etelid_unreachable_is_refused_and_logged_without_the_password(self):
		self.client.auth_error = EtelIDError("unreachable", "Could not reach EtelID. Please try again.")
		with self.assertRaises(AuthError) as cm:
			L.login("ETL-7", "SecretPw123")
		self.assertIn("not available", str(cm.exception))
		self.assertTrue(fake.logged)
		self.assertNotIn("SecretPw123", repr(fake.logged))
		self.assertEqual(fake.login_as_calls, [])

	def test_missing_key_fails_closed(self):
		del fake.conf["etelid_service_key"]
		L.get_client = lambda cfg: __import__("etel_erp.etelid.client", fromlist=["x"]).EtelIDClient(cfg["host"], cfg["key"])
		with self.assertRaises(AuthError):
			L.login("ETL-7", "pw")
		self.assertEqual(fake.login_as_calls, [])

	def test_no_company_role_is_refused_and_disables_a_linked_user(self):
		L.login("ETL-7", "pw")
		self.client.role = None
		with self.assertRaises(AuthError) as cm:
			L.login("ETL-7", "pw")
		self.assertIn("no role", str(cm.exception))
		self.assertEqual(fake.users["etl-7@etelid.etelerp.app"].data["enabled"], 0)
		self.assertGreaterEqual(fake.commits, 1)
		self.assertEqual(len(fake.login_as_calls), 1)  # only the first, successful one

	def test_suspended_member_is_refused(self):
		self.client.member = member(status="suspended")
		with self.assertRaises(AuthError):
			L.login("ETL-7", "pw")
		self.assertEqual(fake.login_as_calls, [])

	def test_limited_profile_is_refused(self):
		self.client.member = member(profile_limited=True)
		with self.assertRaises(AuthError) as cm:
			L.login("ETL-7", "pw")
		self.assertIn("profile", str(cm.exception))

	def test_company_slug_missing_fails_closed_unless_role_not_required(self):
		del fake.conf["etelid_company_slug"]
		with self.assertRaises(AuthError):
			L.login("ETL-7", "pw")
		fake.conf["etelid_require_company_role"] = 0
		L.login("ETL-7", "pw")
		self.assertEqual(fake.users["etl-7@etelid.etelerp.app"].roles, [])  # signed in, but no roles until mapped

	def test_role_check_failure_is_refused(self):
		self.client.role_error = EtelIDError("unreachable", "Could not reach EtelID.")
		with self.assertRaises(AuthError):
			L.login("ETL-7", "pw")
		self.assertEqual(fake.login_as_calls, [])

	def test_cannot_sign_in_as_administrator_or_an_email_match(self):
		# a hand-made user with the same email as the EtelID member must stay untouched
		victim = FakeDoc(fake, {"email": "a@b.com", "name": "a@b.com", "roles_note": "admin"})
		victim.roles = ["System Manager"]
		fake.users["a@b.com"] = victim
		admin = FakeDoc(fake, {"email": "Administrator", "name": "Administrator"})
		fake.users["Administrator"] = admin
		L.login("Administrator", "pw")  # EtelID answers with its own member for these credentials
		self.assertEqual(fake.login_as_calls, ["etl-7@etelid.etelerp.app"])
		self.assertEqual(victim.roles, ["System Manager"])
		self.assertNotIn("etel_id", victim.data)

	def test_input_checks(self):
		for ident, pw in (("", "pw"), (None, "pw"), ("ETL-7", ""), ("ETL-7", None), ("a" * 200, "pw")):
			with self.assertRaises(AuthError):
				L.login(ident, pw)
		with self.assertRaises(AuthError):
			L.login("ETL-7", "x" * 201)
		self.assertEqual(self.client.calls, [])


if __name__ == "__main__":
	unittest.main()
