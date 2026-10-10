"""Install-time setup: the EtelID link field on User and the Etel branding."""
import frappe
from frappe.custom.doctype.custom_field.custom_field import create_custom_fields

LOGO = "/assets/etel_erp/images/etel-logo.png"

CUSTOM_FIELDS = {
	"User": [
		{
			"fieldname": "etel_id",
			"label": "EtelID",
			"fieldtype": "Data",
			"unique": 1,
			"read_only": 1,
			"no_copy": 1,
			"search_index": 1,
			"insert_after": "username",
			"description": "Set automatically when this person signs in with EtelID.",
		}
	]
}


def after_install():
	apply()


def after_migrate():
	apply()


def apply():
	create_custom_fields(CUSTOM_FIELDS, update=True)
	apply_branding()
	frappe.db.commit()


def apply_branding():
	"""Etel name, logo and a clean sign-in. Idempotent; safe to run on every migrate."""
	ws = "Website Settings"
	frappe.db.set_single_value(ws, "app_name", "EtelERP")
	frappe.db.set_single_value(ws, "app_logo", LOGO)
	frappe.db.set_single_value(ws, "splash_image", LOGO)
	frappe.db.set_single_value(ws, "favicon", LOGO)
	frappe.db.set_single_value(ws, "footer_powered", "")
	frappe.db.set_single_value(ws, "copyright", "Etel Group")
	frappe.db.set_single_value(ws, "disable_signup", 1)  # people join through EtelID, not self sign-up
	frappe.db.set_single_value(ws, "hide_footer_signup", 1)

	frappe.db.set_single_value("Navbar Settings", "app_logo", LOGO)
	navbar = frappe.get_doc("Navbar Settings")
	navbar.set("help_dropdown", [])  # no links to ERPNext/Frappe docs, forum or issue tracker
	navbar.save(ignore_permissions=True)
