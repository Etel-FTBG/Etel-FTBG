"""
Manual smoke test for the digital Investor Deal Sheet API (needs PHP 8 + a throwaway MySQL database, NOT part of CI).

  mysql -e "CREATE DATABASE etelgroup_test CHARACTER SET utf8mb4; CREATE USER 'egt'@'localhost' IDENTIFIED BY 'egtpass'; GRANT ALL ON etelgroup_test.* TO 'egt'@'localhost'"
  cd EtelGroup && export DB_HOST=localhost DB_NAME=etelgroup_test DB_USER=egt DB_PASS=egtpass ADMIN_TOKEN_SECRET=testsecret INV_TOKEN_SECRET=x INV_CRYPTO_KEY=x
  php -S 127.0.0.1:8781 -t . &
  python3 investors/tests/deal_sheet_smoke.py        # run from EtelGroup with the same environment variables
"""
import base64, json, struct, subprocess, sys, urllib.request, zlib

API = 'http://127.0.0.1:8781/investors/api/boot.php'
tok = subprocess.check_output(['php', '-r', 'require "api/auth.php"; echo eg_issue_token("EG-1","Test Admin");']).decode()
fails = []


def call(action, body=None, auth=False):
    h = {'Content-Type': 'application/json'}
    if auth:
        h['Authorization'] = 'Bearer ' + tok
    r = urllib.request.Request(API + '?action=' + action, data=json.dumps(body or {}).encode(), headers=h, method='POST')
    try:
        with urllib.request.urlopen(r, timeout=20) as f:
            return f.status, json.loads(f.read())
    except urllib.error.HTTPError as e:
        return e.code, json.loads(e.read() or b'{}')


def png():
    w = h = 200
    raw = b''.join(b'\x00' + bytes([i % 256, 0, 0] * w) for i in range(h))
    def ch(t, d):
        c = struct.pack('>I', len(d)) + t + d
        return c + struct.pack('>I', zlib.crc32(t + d) & 0xffffffff)
    data = b'\x89PNG\r\n\x1a\n' + ch(b'IHDR', struct.pack('>IIBBBBB', w, h, 8, 2, 0, 0, 0)) + ch(b'IDAT', zlib.compress(raw)) + ch(b'IEND', b'')
    return 'data:image/png;base64,' + base64.b64encode(data).decode()


def check(ok, name, detail=''):
    print(('PASS  ' if ok else 'FAIL  ') + name + ('' if ok else '  -> ' + str(detail)))
    if not ok:
        fails.append(name)


s, r = call('deal_config')
check(s == 200 and r['data']['unit_price'] == 1000 and r['data']['pool_units'] == 2000, 'config shows the standard terms', r)
open_units = r['data']['available']
good = dict(lang='am', investor_name='Almaz Tesfaye', id_number='ET1234567', phone='0911223344', email='a@x.com', units=100, payout_period='monthly',
            payout_days=15, bank_account='CBE 1000', signature_name='Almaz Tesfaye', signature_png=png(), agree=True)
check(call('deal_submit', dict(good, agree=False))[0] == 400, 'the declaration is required')
check(call('deal_submit', dict(good, units=open_units + 1))[0] == 409, 'cannot buy more than is open')
check(call('deal_submit', dict(good, signature_png='data:image/png;base64,AAAA'))[0] == 400, 'a fake signature is refused')
check(call('deal_submit', dict(good, website='x'))[0] == 400, 'honeypot field stops bots')
s, r = call('deal_submit', good)
check(s == 200 and r['data']['amount'] == 100000 and abs(r['data']['pool_share_pct'] - 5) < 1e-6, 'a valid sheet is stored with the right maths', r)
check(call('admin_deal_list')[0] == 401, 'the list needs an admin')
s, r = call('admin_deal_list', auth=True)
sid = r['data']['sheets'][0]['id']
check(s == 200 and 'signature_png' not in r['data']['sheets'][0], 'the list leaves the signature out', r)
s, r = call('admin_deal_get', {'id': sid}, True)
check(s == 200 and r['data']['signature_png'].startswith('data:image/png') and r['data']['lang'] == 'am', 'one sheet comes with its signature')
check(call('admin_deal_enroll', {'id': sid}, True)[0] == 409, 'cannot enroll before accepting')
check(call('admin_deal_review', {'id': sid, 'decision': 'rejected'}, True)[0] == 400, 'rejecting needs a note')
check(call('admin_deal_review', {'id': sid, 'decision': 'accepted'}, True)[0] == 200, 'accepting works')
check(call('admin_deal_enroll', {'id': sid, 'record_payment': True, 'tranche_number': 2}, True)[0] == 409, 'a locked tranche is refused')
s, r = call('admin_deal_enroll', {'id': sid, 'record_payment': True, 'tranche_number': 1}, True)
check(s == 200 and r['data']['temp_password'] and r['data']['purchase_recorded'], 'enrolling creates the investor and the purchase', r)
s, r = call('deal_config')
check(r['data']['available'] == open_units - 100, 'the open units drop by the units bought', r)
check(call('admin_deal_enroll', {'id': sid}, True)[0] == 409, 'cannot enroll twice')
# ---- staged raise ----
s, r = call('deal_config')
check(r['data']['stage'] == 1 and len(r['data']['stages']) == 3 and r['data']['stages'][1]['price'] == 1500 and r['data']['stages'][2]['price'] == 2000, 'three stages with rising prices', r)
check(call('admin_unlock_tranche', {'tranche_number': 3, 'note': 'x'}, True)[0] == 409, 'stage 3 cannot open before stage 2')
check(call('admin_unlock_tranche', {'tranche_number': 2}, True)[0] == 400, 'unlocking needs a note')
check(call('admin_save_tranche', {'tranche_number': 1, 'shares_total': 400, 'price_per_share': 1200, 'unlock_trips': 0}, True)[0] == 409, 'a stage with sales keeps its price')
check(call('admin_save_tranche', {'tranche_number': 1, 'shares_total': 50, 'price_per_share': 1000, 'unlock_trips': 0}, True)[0] == 409, 'a stage cannot shrink below what was sold')
check(call('admin_save_tranche', {'tranche_number': 3, 'shares_total': 1500, 'price_per_share': 2000, 'unlock_trips': 1000}, True)[0] == 409, 'stages cannot exceed the pool')
check(call('admin_save_tranche', {'tranche_number': 3, 'shares_total': 1000, 'price_per_share': 2500, 'unlock_trips': 1200}, True)[0] == 200, 'an unsold stage can change price')
check(call('admin_unlock_tranche', {'tranche_number': 2, 'note': 'hit 400 trips/day'}, True)[0] == 200, 'stage 2 unlocks with a note')
# sell out stage 1 -> the sheet price moves to stage 2
s, r = call('deal_config')
check(r['data']['stage'] == 1, 'stage 1 stays current while it has shares', r)
s, r = call('deal_submit', dict(good, units=300, signature_name='B Person'))
sid2 = call('admin_deal_list', auth=True)[1]['data']['sheets'][0]['id']
call('admin_deal_review', {'id': sid2, 'decision': 'accepted'}, True)
check(call('admin_deal_enroll', {'id': sid2, 'record_payment': True, 'login_identifier': 'b@x.com'}, True)[0] == 200, 'second investor fills stage 1')
s, r = call('deal_config')
check(r['data']['stage'] == 2 and r['data']['unit_price'] == 1500 and r['data']['available'] == 600, 'sold-out stage 1 moves the price to stage 2', r)
s, r = call('deal_submit', dict(good, units=10, signature_name='C Person'))
check(s == 200 and r['data']['amount'] == 15000, 'a stage 2 sheet is charged the stage 2 price', r)
# demo mode: the form works but nothing real is stored
before = len(call('admin_deal_list', auth=True)[1]['data']['sheets'])
print(call('setup_seed_demo', {}, True)[0])
s, r = call('deal_config')
check(r['data']['demo_mode'] is True, 'config tells the page demo mode is on', r)
s, r = call('deal_submit', dict(good, units=5))
check(s == 200 and r['data'].get('demo') is True and r['data']['ref_code'] == 'DEMO-PREVIEW', 'demo mode returns a preview', r)
call('setup_clear_demo', {}, True)
after = len(call('admin_deal_list', auth=True)[1]['data']['sheets'])
check(before == after, 'a demo preview is never stored')
# ---- pitch deck data ----
s, r = call('deal_pitch')
check(s == 200 and r['data']['traction']['mode'] == 'hidden' and 'commission_rate' in r['data'], 'pitch hides real traction by default', r)
call('admin_deal_pitch_traction', {'show': True}, True)
s, r = call('deal_pitch')
check(r['data']['traction']['mode'] in ('hidden', 'real'), 'pitch with traction on never fails without a data link', r)
call('admin_deal_pitch_traction', {'show': False}, True)
print(call('setup_seed_demo', {}, True)[0])
s, r = call('deal_pitch')
check(r['data']['traction']['mode'] == 'demo' and r['data']['demo_mode'] is True, 'pitch shows labelled sample traction in demo mode', r)
call('setup_clear_demo', {}, True)
check(call('admin_deal_pitch_traction', {'show': True})[0] == 401, 'the traction switch needs an admin')
# ---- planner scenarios ----
call('admin_save_cost_category', {'name': 'Payroll', 'monthly_amount': 100000, 'is_active': True}, True)
s, r = call('admin_growth_planner', {'registered_drivers': 20, 'trips_per_driver_per_day': 10, 'avg_distance_km': 5, 'base_fare': 60, 'per_km_rate': 20, 'other_monthly_income': 0, 'cost_scale_pct': 50}, True)
sc = r['data']['scenario'] if s == 200 else {}
check(s == 200 and sc.get('cost_scale_pct') == 50 and sc.get('monthly_costs') == 50000 and sc.get('break_even_trips_per_day', 0) > 0, 'planner returns break-even for a lean plan', r)
s2, r2 = call('admin_growth_planner', {'registered_drivers': 20, 'trips_per_driver_per_day': 10, 'avg_distance_km': 5, 'base_fare': 60, 'per_km_rate': 20, 'other_monthly_income': 50000, 'cost_scale_pct': 50}, True)
check(s2 == 200 and r2['data']['periods']['month']['other_income'] == 50000, 'other income is counted in the month', r2)
for _ in range(4):
    call('deal_submit', dict(good, units=1))
check(call('deal_submit', dict(good, units=1))[0] == 429, 'rate limit: 5 sheets per hour per connection')
print('\n' + ('ALL PASS' if not fails else '%d FAILED' % len(fails)))
sys.exit(1 if fails else 0)
