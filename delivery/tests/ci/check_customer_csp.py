"""customer.html and servicegiver.html are served with an ENFORCED policy that has NO 'unsafe-inline' for scripts (violations still reported to
api/csp-report.php). The other pages keep the site-wide policy."""
import re
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
ht = read('.htaccess')
bad = []
def strict_block(page):
    m = re.search(r'<Files "%s">(.*?)</Files>' % re.escape(page), ht, re.S)
    if not m:
        bad.append('.htaccess has no <Files "%s"> block' % page); return
    blk = m.group(1)
    if 'Report-Only' in blk:
        bad.append('%s must be ENFORCED, not report-only' % page)
    h = re.search(r'Header always set Content-Security-Policy\s+"([^"]*)"', blk)
    if not h:
        bad.append('%s needs an enforced Content-Security-Policy header' % page); return
    pol = h.group(1)
    script = re.search(r"script-src([^;]*);", pol)
    if not script or "'unsafe-inline'" in script.group(1) or "'unsafe-eval'" in script.group(1):
        bad.append("%s: script-src must not allow 'unsafe-inline' or 'unsafe-eval'" % page)
    if 'report-uri /delivery/api/csp-report.php' not in pol:
        bad.append('%s: the policy must keep reporting to /delivery/api/csp-report.php' % page)
    if "frame-ancestors 'none'" not in pol:
        bad.append("%s: keep frame-ancestors 'none'" % page)
strict_block('customer.html')
strict_block('servicegiver.html')
site = ht.split('<Files "customer.html">')[0]
if not re.search(r"Header always set Content-Security-Policy \"default-src 'self'; script-src 'self' 'unsafe-inline'", site):
    bad.append('the site-wide enforced policy is missing')
try:
    read('api/csp-report.php')
except OSError:
    bad.append('api/csp-report.php is missing')
if bad:
    print('check_customer_csp FAILED:')
    for b in bad:
        print(' -', b)
    sys.exit(1)
print('check_customer_csp ok')
