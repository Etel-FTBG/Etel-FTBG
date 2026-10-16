/* Service Giver page: sign-in / role gate. Was an inline script in servicegiver.html (comment kept below). */
      /* Auth gate - previously missing entirely, meaning unauthenticated
         visitors saw service-giver page content with no redirect. Also
         carries role=service_giver so the login page can identify this
         app specifically (same pattern driver/index.html already used)
         rather than falling through to the generic bare-launch case.

         BUG FIX: this only ever checked Auth.isLoggedIn() - true for ANY
         signed-in role, not just service_giver - so a customer/driver/
         courier account with a valid session could load this page's full
         content too (same class of gap found and fixed on admin.html).
         Now uses core.js's shared requireRole() helper instead of a
         hand-rolled login-only check - it already does exactly this
         (verifies the session's real role, not just that one exists,
         with admin exempted from every role gate by design) and was
         built specifically for this, per login.html's own comment
         referencing it ("requireRole() on every page ... lets an admin
         account stay there") - it just was never actually wired into
         any page's own gate until now. */
      document.addEventListener('DOMContentLoaded', function () {
        if (typeof requireRole === 'undefined') return; // core.js failed to load - nothing to gate with
        requireRole(['service_giver']);
        // requireRole() above lets an admin session through (admin bypasses
        // every role gate by design), so an admin account CAN end up
        // browsing this app - but a real service_giver has no route back
        // to the dashboard from here. Show the shortcut only for that
        // admin case; every genuine service_giver never sees it.
        var u = (typeof Auth !== 'undefined' && Auth.getUser) ? Auth.getUser() : null;
        if (u && u.role === 'admin') {
          var row = document.getElementById('sgp-admin-row');
          if (row) row.style.display = 'block';
        }
      });
    
