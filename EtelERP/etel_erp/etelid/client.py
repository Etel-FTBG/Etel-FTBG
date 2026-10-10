"""Client for the EtelID Service API (server-to-server).

Pure Python: no Frappe import, so it can be tested on its own. EtelID lives on
ethelmarket.com (WordPress plugin etel-id-v3); its REST namespace is
/EtelId/wp-json/etelid/v1 and every call carries the per-app key in the
X-Etel-Service-Key header. Passwords are only ever sent to EtelID over HTTPS and
are never logged or stored here.
"""
import re

_HOST_RE = re.compile(r"^[A-Za-z0-9]([A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$")

DEFAULT_BASE_PATH = "/EtelId/wp-json/etelid/v1"


class EtelIDError(Exception):
	"""A failed EtelID call. `code` is EtelID's own error code when it sent one
	(wrong_password, not_found, requires_2fa, profile_incomplete, ...), or
	`unreachable` / `bad_response` / `not_configured` for local problems."""

	def __init__(self, code, message, status=0):
		Exception.__init__(self, message)
		self.code = code
		self.message = message
		self.status = status


def _default_transport(url, headers, payload, timeout):
	import requests

	r = requests.post(url, json=payload, headers=headers, timeout=timeout)
	try:
		body = r.json()
	except ValueError:
		body = None
	return r.status_code, body


class EtelIDClient:
	def __init__(self, host, service_key, app="etelerp", base_path=DEFAULT_BASE_PATH, timeout=10, transport=None):
		host = (host or "").strip()
		if not host or not _HOST_RE.match(host):
			raise EtelIDError("not_configured", "EtelID host is not configured correctly.")
		if not service_key or len(service_key) < 24:
			raise EtelIDError("not_configured", "EtelID service key is not configured.")
		self.url_base = "https://" + host + base_path
		self.service_key = service_key
		self.app = app
		self.timeout = timeout
		self._transport = transport or _default_transport

	def _post(self, route, payload):
		url = self.url_base + "/service/" + route
		headers = {"X-Etel-Service-Key": self.service_key, "Content-Type": "application/json"}
		try:
			status, body = self._transport(url, headers, payload, self.timeout)
		except Exception:
			raise EtelIDError("unreachable", "Could not reach EtelID. Please try again.")
		if not isinstance(body, dict):
			raise EtelIDError("bad_response", "EtelID returned an unexpected response.", status)
		if body.get("ok") is True:
			return body
		# WordPress WP_Error shape: {"code": ..., "message": ..., "data": {"status": N}}
		code = str(body.get("code") or "bad_response")
		message = str(body.get("message") or "EtelID refused the request.")
		data = body.get("data") if isinstance(body.get("data"), dict) else {}
		raise EtelIDError(code, message, int(data.get("status") or status or 0))

	def authenticate(self, identifier, password, totp_code=None):
		"""Check EtelID credentials. Returns the member dict EtelID sent back."""
		payload = {"identifier": identifier, "password": password, "app": self.app}
		if totp_code:
			payload["totp_code"] = totp_code
		body = self._post("authenticate", payload)
		member = body.get("member")
		if not isinstance(member, dict) or not member.get("etel_id"):
			raise EtelIDError("bad_response", "EtelID returned an unexpected response.")
		return member

	def check_role(self, company_slug, phone):
		"""Active role of the member (by phone) at a company, or None if they have none."""
		try:
			body = self._post("check_role", {"company_slug": company_slug, "phone": phone})
		except EtelIDError as e:
			if e.code == "not_found":
				return None
			raise
		return {"role_at": str(body.get("role_at") or ""), "etel_id": body.get("etel_id")}
