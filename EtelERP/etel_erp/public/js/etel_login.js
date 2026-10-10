/* EtelERP sign-in page: people sign in with their EtelID.
   Wraps the standard login form so it posts to etel_erp.etelid.login.login.
   "Administrator" keeps the normal Frappe login (break-glass), and if this script
   ever fails to load the standard login still works. */
(function () {
	if (!/^\/login\/?$/.test(window.location.pathname)) return;

	function tweak() {
		var $id = $("#login_email");
		if (!$id.length || $("#etel_totp").length) return;
		$id.attr("placeholder", "EtelID number, phone or email");
		var group =
			'<div class="form-group">' +
			'<label class="form-label sr-only" for="etel_totp">Authenticator code</label>' +
			'<input type="text" id="etel_totp" class="form-control" inputmode="numeric" ' +
			'autocomplete="one-time-code" maxlength="12" ' +
			'placeholder="Authenticator code (only if you use two-step sign-in)">' +
			"</div>";
		$("#login_password").closest(".form-group").after(group);
		$(".forgot-password-message").html(
			'<span class="etel-note">Forgot your password? Reset it in the EtelID portal.</span>'
		);
	}

	function wrap() {
		if (!window.login || !login.call || login.__etel) return false;
		login.__etel = true;
		var original = login.call;
		login.call = function (args, callback, url) {
			if (args && args.cmd === "login" && String(args.usr || "").toLowerCase() !== "administrator") {
				login.set_status("Verifying...", "blue");
				return frappe.call({
					type: "POST",
					url: "/api/method/etel_erp.etelid.login.login",
					args: {
						identifier: args.usr,
						password: args.pwd,
						totp_code: ($("#etel_totp").val() || "").trim(),
					},
					callback: callback,
					freeze: true,
					statusCode: login.login_handlers,
				});
			}
			return original.apply(this, arguments);
		};
		return true;
	}

	var tries = 0;
	var timer = setInterval(function () {
		tweak();
		if (wrap() || ++tries > 100) clearInterval(timer);
	}, 50);
})();
