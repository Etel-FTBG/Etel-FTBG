"""Guards the courier job distances: the script is loaded on both courier pages, has no inline handlers / eval, and the server action exists, is
registered in boot.php, needs a courier login and never selects the customer's name or phone."""
import re
import subprocess
import sys

root = __file__.rsplit('/tests/', 1)[0]
read = lambda p: open(root + '/' + p, encoding='utf-8').read()
bad = []
for page in ('courier/index.html', 'courier/courier-index.html'):
    if 'courier-job-nav.js' not in read(page):
        bad.append(page + ' does not load courier-job-nav.js')
js = read('js/courier-job-nav.js')
if re.search(r'\beval\(|document\.write|\bonclick=|\bonerror=', js):
    bad.append('courier-job-nav.js uses inline handlers, eval or document.write')
if subprocess.call(['node', '--check', root + '/js/courier-job-nav.js']) != 0:
    bad.append('courier-job-nav.js does not parse')
ctl = read('api/controllers/CourierJobGeoController.php')
if 'require_courier(' not in ctl:
    bad.append('courier_job_geo does not require a courier login')
if "case 'courier_job_geo'" not in read('api/boot.php'):
    bad.append('courier_job_geo is not registered in boot.php')
lib = read('api/lib/courier_job_geo.php')
if re.search(r'recipient_(name|phone)|sender_(name|phone)', lib + ctl):
    bad.append('courier_job_geo touches the customer name or phone')
if bad:
    print('FAILED:\n' + '\n'.join(bad)); sys.exit(1)
print('ok: courier job distances guarded')
