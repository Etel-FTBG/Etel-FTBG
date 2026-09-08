/* ============================================================
   ETEL AI — login.html logic
   Talks directly to api/etel-os.php's existing `login` action
   (phone+password against Etelid_etelid_members, same DB the rest
   of EtelAI already reads). On success it writes localStorage in
   EXACTLY the shape core.js already expects ({token, member}) under
   the same 'etel_sso' key Delivery/Ride's Auth.save() writes - so
   every existing page (admin.html, the customer widget) picks it up
   with zero changes on their end.
   ============================================================ */

function loginRedirectTarget() {
  const params = new URLSearchParams(window.location.search);
  const target = params.get('redirect');
  // Only two known destinations - anything else falls back to the
  // customer page rather than trusting an arbitrary redirect value.
  return target === 'admin' ? 'admin.html' : 'index.html';
}

function showLoginError(msg) {
  const el = document.getElementById('login-error');
  el.textContent = msg;
  el.style.display = 'block';
}

(function init() {
  // Already signed in? Skip the form entirely.
  if (etelaiGetToken()) {
    window.location.href = loginRedirectTarget();
    return;
  }

  const form   = document.getElementById('login-form');
  const submit = document.getElementById('login-submit');

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    document.getElementById('login-error').style.display = 'none';

    const phone    = document.getElementById('phone').value.trim();
    const password = document.getElementById('password').value;
    if (!phone || !password) return;

    submit.disabled = true;
    submit.textContent = 'Signing in...';

    const res = await etelaiApi('etel-os.php?action=login', {
      phone, password, project: 'etel-ai',
    });

    submit.disabled = false;
    submit.textContent = 'Sign In';

    if (!res.ok) {
      showLoginError(res.error || 'Sign in failed. Check your phone number and password.');
      return;
    }

    try {
      localStorage.setItem('etel_sso', JSON.stringify({
        token: res.data.token,
        member: res.data.member,
      }));
    } catch (err) {
      showLoginError('Signed in, but could not save your session locally. Check browser storage settings.');
      return;
    }

    window.location.href = loginRedirectTarget();
  });
})();
