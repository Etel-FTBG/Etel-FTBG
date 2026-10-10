import os
import sys
import unittest

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from etel_erp.etelid import policy
from etel_erp.etelid.client import EtelIDClient, EtelIDError

KEY = "k" * 32


def fake_transport(status, body, seen=None):
	def t(url, headers, payload, timeout):
		if seen is not None:
			seen.append((url, headers, payload, timeout))
		if isinstance(body, Exception):
			raise body
		return status, body

	return t


class PolicyTests(unittest.TestCase):
	def test_normalize_identifier(self):
		self.assertEqual(policy.normalize_identifier("  ETL-001 "), "ETL-001")
		self.assertEqual(policy.normalize_identifier(""), "")
		self.assertEqual(policy.normalize_identifier(None), "")
		self.assertEqual(policy.normalize_identifier("a" * 121), "")
		self.assertEqual(policy.normalize_identifier("bad\x00id"), "")
		self.assertEqual(policy.normalize_identifier("line\nbreak"), "")

	def test_synthetic_user_is_built_from_etel_id_only(self):
		self.assertEqual(policy.synthetic_user("ETL-2026-0007"), "etl-2026-0007@etelid.etelerp.app")
		self.assertEqual(policy.synthetic_user("  Etl 7!! "), "etl7@etelid.etelerp.app")
		self.assertEqual(policy.synthetic_user("###"), "")
		self.assertEqual(policy.synthetic_user(None), "")
		# an email-looking EtelID can never produce an address at someone else's domain
		self.assertTrue(policy.synthetic_user("admin@victim.com").endswith("@etelid.etelerp.app"))
		self.assertNotIn("victim", policy.synthetic_user("admin@victim.com").split("@")[1])

	def test_roles_for_known_unknown_and_case(self):
		rm = policy.DEFAULT_ROLE_MAP
		self.assertIn("System Manager", policy.roles_for("Owner", rm))
		self.assertEqual(policy.roles_for("cashier", rm), ["Sales User", "Accounts User"])
		self.assertEqual(policy.roles_for("something-new", rm), [])
		self.assertEqual(policy.roles_for("", rm), [])
		self.assertEqual(policy.roles_for(None, rm), [])
		self.assertNotIn("System Manager", policy.roles_for("manager", rm))

	def test_roles_for_removes_duplicates(self):
		self.assertEqual(policy.roles_for("x", {"x": ["A", "A", "B"], "*": []}), ["A", "B"])

	def test_parse_role_map(self):
		self.assertEqual(policy.parse_role_map(None), policy.DEFAULT_ROLE_MAP)
		self.assertEqual(policy.parse_role_map(""), policy.DEFAULT_ROLE_MAP)
		self.assertEqual(policy.parse_role_map("not json"), policy.DEFAULT_ROLE_MAP)
		self.assertEqual(policy.parse_role_map('{"Boss": ["System Manager"]}')["boss"], ["System Manager"])
		self.assertEqual(policy.parse_role_map({"Boss": ["A"]})["*"], [])
		self.assertEqual(policy.parse_role_map({"Boss": "A"}), policy.DEFAULT_ROLE_MAP)
		self.assertEqual(policy.parse_role_map({"Boss": [1]}), policy.DEFAULT_ROLE_MAP)

	def test_split_name(self):
		self.assertEqual(policy.split_name("Abebe Kebede Girma"), ("Abebe", "Kebede Girma"))
		self.assertEqual(policy.split_name("Abebe"), ("Abebe", ""))
		self.assertEqual(policy.split_name(""), ("EtelID", "Member"))
		self.assertEqual(policy.split_name(None), ("EtelID", "Member"))

	def test_decide_login(self):
		m = {"status": "active"}
		self.assertEqual(policy.decide_login(m, {"role_at": "staff"}, True), (True, "ok"))
		self.assertEqual(policy.decide_login(m, None, True), (False, "no_role"))
		self.assertEqual(policy.decide_login(m, None, False), (True, "ok"))
		self.assertEqual(policy.decide_login({"status": "suspended"}, {"role_at": "x"}, True), (False, "inactive"))
		self.assertEqual(policy.decide_login({}, {"role_at": "x"}, True), (False, "inactive"))
		self.assertEqual(policy.decide_login({"status": "active", "profile_limited": True}, {"role_at": "x"}, True), (False, "limited"))


class ClientTests(unittest.TestCase):
	def test_rejects_bad_host_and_weak_key(self):
		for host in ("", "bad host", "evil.com/x", "a.com:99", "http://a.com", "a.com\r\nX: y"):
			with self.assertRaises(EtelIDError) as cm:
				EtelIDClient(host, KEY)
			self.assertEqual(cm.exception.code, "not_configured")
		for key in ("", None, "short"):
			with self.assertRaises(EtelIDError) as cm:
				EtelIDClient("ethelmarket.com", key)
			self.assertEqual(cm.exception.code, "not_configured")

	def test_authenticate_success_sends_key_and_app(self):
		seen = []
		member = {"etel_id": "ETL-1", "full_name": "A B", "status": "active", "phone": "0911"}
		c = EtelIDClient("ethelmarket.com", KEY, transport=fake_transport(200, {"ok": True, "member": member}, seen))
		self.assertEqual(c.authenticate("ETL-1", "pw", "123456"), member)
		url, headers, payload, timeout = seen[0]
		self.assertEqual(url, "https://ethelmarket.com/EtelId/wp-json/etelid/v1/service/authenticate")
		self.assertEqual(headers["X-Etel-Service-Key"], KEY)
		self.assertEqual(payload, {"identifier": "ETL-1", "password": "pw", "app": "etelerp", "totp_code": "123456"})
		self.assertEqual(timeout, 10)

	def test_totp_omitted_when_empty(self):
		seen = []
		c = EtelIDClient("ethelmarket.com", KEY, transport=fake_transport(200, {"ok": True, "member": {"etel_id": "E"}}, seen))
		c.authenticate("E", "pw")
		self.assertNotIn("totp_code", seen[0][2])

	def test_wp_error_is_mapped(self):
		body = {"code": "wrong_password", "message": "Incorrect EtelID password.", "data": {"status": 401}}
		c = EtelIDClient("ethelmarket.com", KEY, transport=fake_transport(401, body))
		with self.assertRaises(EtelIDError) as cm:
			c.authenticate("E", "pw")
		self.assertEqual((cm.exception.code, cm.exception.status), ("wrong_password", 401))

	def test_requires_2fa_code_is_kept(self):
		body = {"code": "requires_2fa", "message": "Enter the 6-digit code", "data": {"status": 401, "requires_2fa": True}}
		c = EtelIDClient("ethelmarket.com", KEY, transport=fake_transport(401, body))
		with self.assertRaises(EtelIDError) as cm:
			c.authenticate("E", "pw")
		self.assertEqual(cm.exception.code, "requires_2fa")

	def test_network_failure_and_bad_body(self):
		c = EtelIDClient("ethelmarket.com", KEY, transport=fake_transport(0, OSError("down")))
		with self.assertRaises(EtelIDError) as cm:
			c.authenticate("E", "pw")
		self.assertEqual(cm.exception.code, "unreachable")
		self.assertNotIn("down", cm.exception.message)  # no internal detail leaks
		c = EtelIDClient("ethelmarket.com", KEY, transport=fake_transport(200, "<html>"))
		with self.assertRaises(EtelIDError) as cm:
			c.authenticate("E", "pw")
		self.assertEqual(cm.exception.code, "bad_response")
		c = EtelIDClient("ethelmarket.com", KEY, transport=fake_transport(200, {"ok": True, "member": {}}))
		with self.assertRaises(EtelIDError) as cm:
			c.authenticate("E", "pw")
		self.assertEqual(cm.exception.code, "bad_response")

	def test_check_role(self):
		ok = {"ok": True, "role_at": "Manager", "etel_id": "ETL-1"}
		c = EtelIDClient("ethelmarket.com", KEY, transport=fake_transport(200, ok))
		self.assertEqual(c.check_role("my-co", "0911"), {"role_at": "Manager", "etel_id": "ETL-1"})
		nf = {"code": "not_found", "message": "No active role", "data": {"status": 404}}
		c = EtelIDClient("ethelmarket.com", KEY, transport=fake_transport(404, nf))
		self.assertIsNone(c.check_role("my-co", "0911"))
		bad = {"code": "forbidden", "message": "no", "data": {"status": 403}}
		c = EtelIDClient("ethelmarket.com", KEY, transport=fake_transport(403, bad))
		with self.assertRaises(EtelIDError):
			c.check_role("my-co", "0911")


if __name__ == "__main__":
	unittest.main()
