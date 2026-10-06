# Content-Security-Policy for EtelID pages

EtelID's own pages (login, portal, print, verify, reset password, NFC card)
now send a Content-Security-Policy. It starts in **report-only** mode, which
means the browser changes nothing and only tells the server what it WOULD
have blocked.

## Always on (safe)
`X-Content-Type-Options: nosniff`, a trimmed `Referrer-Policy`, and a
`Permissions-Policy` that turns off microphone, payment and USB.

## The policy
Only the site itself (plus the Etel assistant widget and, for the Fayda text
reader, jsDelivr/tessdata) may load scripts, workers, fonts, frames and
connections. Nobody else may frame the pages, submit forms to other sites,
change the base URL or run plugins. Inline scripts and styles are still
allowed because the pages use them.

## Step 1 - watch (default, nothing to do)
Use the site normally for a few days: log in, open the portal, print a card,
scan a card, register, use the Fayda photo reader, reset a password.
Then look in the EtelID audit log for events named **csp_report**. Each line
shows the rule that was hit, what was blocked and which page. Only the first
report of each kind per hour is stored.

## Step 2 - enforce
When the log shows no reports for things you need (reports for strange
outside addresses are the policy doing its job), add to `wp-config.php`:

    define('ETELID_CSP_MODE', 'enforce');

To go back, change it to `'report'`, or `'off'` to remove the header.

## Later
A stricter policy that also blocks inline scripts needs the pages' inline
handlers (`onclick=...`) rewritten first. That is a separate piece of work.
