# Support desk - tests

Not wired into CI (they need a database and a browser).

| File | What it checks |
|---|---|
| `backend_support.php` | 64 checks: settings validation, ticket create rules and limits, privacy between customers, internal notes never shown, staff reply, solve / rate / auto-close, canned replies, stats, Telegram off by default and short notices only, desk pause |
| `ui_help_page.js` | Help page: sign-in, topics, new request (HTML is shown as text), thread, unread dot, rating, deep link from another app, Amharic, light and dark, WCAG AA contrast, 320 px |
| `ui_admin_desk.js` | Admin > Support desk: queue and filters, thread drawer, canned reply, note, assign, priority, close, stats, canned replies, settings (Telegram refused without a target) |
| `ui_help_button.js` | The Help button appears on every platform's user pages with the right app, and not on staff pages |

Run: see `../agents/README.md` for the database and server set-up, then apply `api/migrations/103_support_desk.sql`, then `php delivery/tests/support/backend_support.php`.
