"""Guards the Payments hub (js/admin-payments-hub.js): it parses, is built on AdminKit and loaded after it and after admin.js, replaces the old stacked sections (no leftover
containers or scripts), keeps the loaders admin-ui.js calls on opening the page, uses kit dialogs (never window.prompt/confirm/alert), asks before any money decision,
sends the exact decision values the server expects, escapes server values, has no inline handlers, and never uses a class name containing "-card"."""
import re
import subprocess

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
bad = []

js = read('js/admin-payments-hub.js')
if subprocess.call(['node', '--check', root + '/js/admin-payments-hub.js']) != 0:
    bad.append('js/admin-payments-hub.js does not parse')
if re.search(r"""[\s'"`>]on[a-z]+\s*=\s*\\?["']""", js):
    bad.append('no inline handlers in admin-payments-hub.js')
if re.search(r'window\.(prompt|confirm|alert)\(|[^.\w](prompt|confirm|alert)\(', js):
    bad.append('use AdminKit dialogs, not window.prompt/confirm/alert')
if re.search(r'class="[^"]*-card', js) or re.search(r"'[^']*-card[ '\"]", js):
    bad.append('a class name containing "-card" gets the admin glare overlay; rename it')
for must in ('window.AdminKit', 'admin_financial_report', 'admin_list_chapa_payments', 'admin_list_telebirr_payments', 'admin_confirm_telebirr_payment',
             'admin_list_bank_payments', 'admin_confirm_bank_payment', 'admin_unpaid_delivered', 'admin_mark_order_paid', 'admin_list_payout_requests',
             'admin_review_payout_request', 'get_payment_method_logos', 'admin_set_payment_logo',
             "decision: 'confirm'", "decision: 'reject'", "decision: 'approve'", 'payment_id: id', 'request_id: id', 'order_ids: ids',
             'window.loadTelebirrPayments', 'telebirr-badge', 'safeUrl', 'uploadImageForAdmin'):
    if must not in js:
        bad.append('admin-payments-hub.js lost: ' + must)
for ask in ("title: 'Confirm this payment?'", "title: 'Reject this payment?'", "title: 'Approve the new payout details?'", "title: 'Reject this payout change?'", "label: 'Mark as received'"):
    if ask not in js:
        bad.append('a money decision no longer asks first: ' + ask)
if 'innerHTML' in js and 'esc(' not in js:
    bad.append('server values must go through AdminKit.esc')

html = read('admin.html')
tags = [(m.start(), m.group(0)) for m in re.finditer(r'<script src="js/(admin\.js|admin-kit\.js|admin-payments-hub\.js)[^"]*"', html)]
order = [re.search(r'js/([\w.-]+?)\.js', t).group(1) for _, t in tags]
if order.count('admin-payments-hub') != 1 or order.index('admin-payments-hub') < max(order.index('admin'), order.index('admin-kit')):
    bad.append('admin.html must load admin-payments-hub.js after admin.js and admin-kit.js')
if 'id="pay-hub"' not in html:
    bad.append('admin.html lost #pay-hub')
for gone in ('id="chapa-online-payments"', 'id="cash-reconcile"', 'id="payments-report"', 'id="telebirr-list"', 'id="bank-payments-list"', 'id="payout-requests-list"', 'id="payment-logos-editor"',
             'admin-chapa-payments.js', 'admin-cash-reconcile.js'):
    if gone in html:
        bad.append('the old Payments section is back in admin.html: ' + gone)
ui = read('js/admin/admin-ui.js')
if "page === 'telebirr'" not in ui or 'loadTelebirrPayments' not in ui:
    bad.append('admin-ui.js no longer opens the Payments page through loadTelebirrPayments')

if bad:
    print('check_payments_hub_ui FAILED:\n  - ' + '\n  - '.join(bad))
    raise SystemExit(1)
print('check_payments_hub_ui: ok')
