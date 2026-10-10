app_name = "etel_erp"
app_title = "EtelERP"
app_publisher = "Etel Group"
app_description = "EtelERP - Etel Group's branded ERP, signed in with EtelID"
app_email = "info@etelgroup.com"
app_license = "mit"
app_logo_url = "/assets/etel_erp/images/etel-logo.png"

required_apps = ["frappe"]

# Shown on the apps screen
add_to_apps_screen = [
	{
		"name": "etel_erp",
		"logo": "/assets/etel_erp/images/etel-logo.png",
		"title": "EtelERP",
		"route": "/app",
	}
]

# Brand styling (desk and website pages) and the EtelID sign-in on /login
app_include_css = "/assets/etel_erp/css/etel_erp.css"
web_include_css = "/assets/etel_erp/css/etel_erp.css"
web_include_js = "/assets/etel_erp/js/etel_login.js"

website_context = {
	"favicon": "/assets/etel_erp/images/etel-logo.png",
	"splash_image": "/assets/etel_erp/images/etel-logo.png",
}

# Installation: custom fields and branding settings (re-applied on every migrate)
after_install = "etel_erp.setup.install.after_install"
after_migrate = "etel_erp.setup.install.after_migrate"
