"""The Customer page's strict-policy trial: customer.html is served with a report-only policy that has NO 'unsafe-inline' for scripts and
reports to api/csp-report.php. The enforced site-wide policy must stay in place, and the page must still be free of inline script."""
import re
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
ht = read('.htaccess')
bad = []
m = re.search(r'<Files "customer\.html">(.*?)</Files>', ht, re.S)
if not m:
    bad.append('.htaccess has no <Files "customer.html"> block')
else:
    blk = m.group(1)
    h = re.search(r'Content-Security-Policy-Report-Only\s+"([^"]*)"', blk)
    if not h:
        bad.append('customer.html needs a Content-Security-Policy-Report-Only header')
    else:
        pol = h.group(1)
        script = re.search(r"script-src([^;]*);", pol)
        if not script or "'unsafe-inline'" in script.group(1) or "'unsafe-eval'" in script.group(1):
            bad.append("the report-only policy's script-src must not allow 'unsafe-inline' or 'unsafe-eval'")
        if 'report-uri /delivery/api/csp-report.php' not in pol:
            bad.append('the report-only policy must report to /delivery/api/csp-report.php')
    if re.search(r'\bContent-Security-Policy\s+"', blk.replace('Content-Security-Policy-Report-Only', '')):
        bad.append('customer.html must not replace the enforced policy yet: this step is report-only')
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
