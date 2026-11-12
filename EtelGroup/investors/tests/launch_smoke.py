"""
Manual smoke test for the launch period, launch budget and delivery-order income (needs PHP 8 + MariaDB, NOT part of CI).
Same setup as deal_sheet_smoke.py, plus a second throwaway database 'deltest' that stands in for Delivery & Ride:

  mysql -e "CREATE DATABASE deltest; CREATE USER 'ro'@'localhost' IDENTIFIED BY 'ropass'; GRANT SELECT ON deltest.* TO 'ro'@'localhost'"
  (the test creates the rides and orders tables itself)
  cd EtelGroup && source the same environment variables as deal_sheet_smoke.py, start php -S 127.0.0.1:8781 -t .
  python3 investors/tests/launch_smoke.py        # run from EtelGroup, on an empty etelgroup_test database
"""
import json, subprocess, sys, urllib.request

API = 'http://127.0.0.1:8781/investors/api/boot.php'
tok = subprocess.check_output(['php', '-r', 'require "api/auth.php"; echo eg_issue_token("EG-1","Test Admin");']).decode()
fails = []

subprocess.check_call(['mysql', 'deltest', '-e', """
DROP TABLE IF EXISTS rides; DROP TABLE IF EXISTS orders;
CREATE TABLE rides (id INT AUTO_INCREMENT PRIMARY KEY, status VARCHAR(20), fare_paid DECIMAL(12,2), completed_at DATETIME);
CREATE TABLE orders (id INT AUTO_INCREMENT PRIMARY KEY, status VARCHAR(20), fare_total DECIMAL(12,2), delivered_at DATETIME);
INSERT INTO rides (status,fare_paid,completed_at) VALUES ('completed',1000,'2026-10-05 10:00:00'),('completed',500,'2026-10-06 10:00:00');
INSERT INTO orders (status,fare_total,delivered_at) VALUES ('delivered',2000,'2026-10-05 12:00:00'),('cancelled',900,'2026-10-05 13:00:00'),('delivered',400,'2026-11-20 12:00:00');
"""])
subprocess.check_call(['php', '-r', """
require 'php/config.php'; require 'investors/api/lib/Settings.php'; require 'investors/api/lib/Crypto.php';
$p = eg_db();
foreach (['delivery_db_host' => 'localhost', 'delivery_db_name' => 'deltest', 'delivery_db_user' => 'ro'] as $k => $v) ir_set_setting($p, $k, $v);
ir_set_setting($p, 'delivery_db_pass_enc', ir_encrypt('ropass'));
"""])


def call(action, body=None, auth=True):
    h = {'Content-Type': 'application/json'}
    if auth:
        h['Authorization'] = 'Bearer ' + tok
    r = urllib.request.Request(API + '?action=' + action, data=json.dumps(body or {}).encode(), headers=h, method='POST')
    try:
        with urllib.request.urlopen(r, timeout=20) as f:
            return f.status, json.loads(f.read())
    except urllib.error.HTTPError as e:
        return e.code, json.loads(e.read() or b'{}')


def check(ok, name, detail=''):
    print(('PASS  ' if ok else 'FAIL  ') + name + ('' if ok else '  -> ' + str(detail)))
    if not ok:
        fails.append(name)


check(call('admin_launch_get', auth=False)[0] == 401, 'the launch page needs an admin')
s, r = call('admin_launch_get')
d = r['data']
check(s == 200 and len(d['summary']['categories']) == 6 and d['summary']['total_budget'] == 400000, 'six budget lines add up to the Stage 1 raise', r)
check(not d['state']['active'], 'launch period starts off')
call('admin_save_cost_category', {'name': 'Rent', 'monthly_amount': 100, 'is_active': True})

# distributions: preview works during the launch period, locking is refused
s, r = call('admin_launch_save', {'active': True, 'until': '2099-01-01', 'note': 'test'})
check(s == 200 and r['data']['state']['active'], 'launch period can be switched on', r)
s, r = call('admin_preview_distribution', {'period_start': '2026-10-01', 'period_end': '2026-10-31'})
check(s == 200 and r['data']['gross_fare_total'] == 1500 and r['data']['commission_revenue'] == 75 and r['data']['net_profit'] == -25, 'preview counts rides only while orders are off', r)
s, r = call('admin_run_distribution', {'period_start': '2026-10-01', 'period_end': '2026-10-31'})
check(s == 409 and 'Launch period' in r.get('error', ''), 'locking a payout is refused during the launch period', r)

# order income
check(call('admin_orders_income_save', {'include_orders': True, 'order_commission_rate': '2'})[0] == 400, 'an order rate above 1 is refused')
s, r = call('admin_orders_income_save', {'include_orders': True, 'order_commission_rate': ''})
check(s == 200 and r['data']['orders']['include_orders'], 'order income can be switched on', r)
s, r = call('admin_preview_distribution', {'period_start': '2026-10-01', 'period_end': '2026-10-31'})
x = r['data'] if s == 200 else {}
check(x.get('orders_gross') == 2000 and x.get('orders_count') == 1 and x.get('gross_fare_total') == 3500 and x.get('commission_revenue') == 175 and x.get('net_profit') == 75, 'delivered orders (not cancelled, not other months) count at the ride rate', r)
call('admin_orders_income_save', {'include_orders': True, 'order_commission_rate': '0.1'})
s, r = call('admin_preview_distribution', {'period_start': '2026-10-01', 'period_end': '2026-10-31'})
check(s == 200 and r['data']['commission_revenue'] == 75 + 200, 'a separate order rate is used', r)
call('admin_orders_income_save', {'include_orders': True, 'order_commission_rate': ''})

# launch period over: the payout can be locked
call('admin_launch_save', {'active': True, 'until': '2000-01-01', 'note': ''})
s, r = call('admin_launch_get')
check(not r['data']['state']['active'] and r['data']['state']['switch_on'], 'a launch period ends by itself on its end date', r)
s, r = call('admin_run_distribution', {'period_start': '2026-10-01', 'period_end': '2026-10-31'})
check(s == 200 and r['data'].get('id') and r['data']['net_profit'] == 75, 'the payout locks once the launch period is over', r)


# commission really charged by Delivery & Ride (rides.commission_amt) counts as it was charged; older rides use the rate set here
call('admin_orders_income_save', {'include_orders': False, 'order_commission_rate': ''})
subprocess.check_call(['mysql', 'deltest', '-e', """
ALTER TABLE rides ADD COLUMN commission_amt DECIMAL(10,2) NULL DEFAULT NULL;
INSERT INTO rides (status,fare_paid,completed_at,commission_amt) VALUES
 ('completed',2000,'2026-11-02 10:00:00',0),
 ('completed',1000,'2026-11-03 10:00:00',30),
 ('completed',500,'2026-11-04 10:00:00',NULL);
"""])
s, r = call('admin_preview_distribution', {'period_start': '2026-11-01', 'period_end': '2026-11-30'})
x = r['data'] if s == 200 else {}
check(x.get('gross_fare_total') == 3500 and x.get('rides_commission_actual') == 30 and x.get('commission_revenue') == 55 and x.get('net_profit') == -45,
      'a free trip counts 0, a charged trip counts what was charged, an older trip uses the rate set here', r)
s, r = call('admin_preview_distribution', {'period_start': '2026-10-01', 'period_end': '2026-10-31'})
check(s == 200 and r['data']['commission_revenue'] == 75 and r['data']['rides_commission_actual'] == 0, 'a month with only older trips is unchanged', r)

# budget tracker
s, r = call('admin_launch_spend_add', {'category': 'marketing', 'amount': 1500, 'spent_on': '2026-11-01', 'note': 'posters'})
m = [c for c in r['data']['summary']['categories'] if c['category'] == 'marketing'][0] if s == 200 else {}
check(m.get('spent') == 1500 and m.get('left') == 98500, 'a spend reduces what is left', r)
check(call('admin_launch_spend_add', {'category': 'nonsense', 'amount': 5})[0] == 400, 'unknown budget line refused')
check(call('admin_launch_spend_add', {'category': 'bonus', 'amount': 0})[0] == 400, 'a zero spend is refused')
check(call('admin_launch_budget_save', {'category': 'marketing', 'budget': 120000})[0] == 200, 'a budget line can be changed')
sid = r['data']['summary']['spend'][0]['id']
s, r = call('admin_launch_spend_delete', {'id': sid})
check(s == 200 and r['data']['summary']['total_spent'] == 0, 'a spend can be removed', r)
check(call('investor_launch', auth=False)[0] == 401, 'the investor view needs an investor login')
s, r = call('deal_config', auth=False)
check(s == 200 and 'launch' in r['data'], 'the deal sheet config tells about the launch period', r)

# spending counted automatically from the platform (promo discounts, driver bonuses, referral rewards stored on the rides)
subprocess.check_call(['mysql', 'deltest', '-e', """
ALTER TABLE rides ADD COLUMN promo_discount DECIMAL(10,2) NULL, ADD COLUMN incentive_bonus DECIMAL(10,2) NULL, ADD COLUMN incentive_referral DECIMAL(10,2) NULL;
UPDATE rides SET promo_discount = 20, incentive_bonus = 30 WHERE completed_at = '2026-11-02 10:00:00';
UPDATE rides SET incentive_referral = 100 WHERE completed_at = '2026-11-03 10:00:00';
UPDATE rides SET promo_discount = 999 WHERE completed_at = '2026-10-05 10:00:00';
"""])
s, r = call('admin_launch_save', {'active': False, 'until': '', 'note': '', 'auto_from': '2026-11-01'})
cats = {c['category']: c for c in r['data']['summary']['categories']} if s == 200 else {}
sm = r['data']['summary'] if s == 200 else {}
check(cats.get('promotion', {}).get('spent_auto') == 20 and cats.get('bonus', {}).get('spent_auto') == 30 and cats.get('referral', {}).get('spent_auto') == 100,
      'promo, bonus and referral spending is counted automatically from the platform', r)
check(sm.get('total_spent') == 150 and cats.get('promotion', {}).get('left') == 60000 - 20, 'the totals and what is left include it', r)
check(sm.get('auto', {}).get('ok') is True and r['data']['state']['auto_from'] == '2026-11-01', 'the count-from date is kept', r)
s, r = call('admin_launch_spend_add', {'category': 'promotion', 'amount': 1000, 'spent_on': '2026-11-05', 'note': 'by hand'})
cats = {c['category']: c for c in r['data']['summary']['categories']}
check(cats['promotion']['spent'] == 1020 and cats['promotion']['spent_manual'] == 1000 and cats['promotion']['spent_auto'] == 20, 'a hand-entered spend and the automatic amount add up', r)
call('admin_launch_spend_delete', {'id': r['data']['summary']['spend'][0]['id']})
s, r = call('admin_launch_save', {'active': False, 'until': '', 'note': '', 'auto_from': ''})
check(r['data']['summary']['total_spent'] == 0 and r['data']['summary']['auto']['ok'] is False, 'with no count-from date nothing is counted automatically', r)
print('\n' + ('ALL PASS' if not fails else '%d FAILED' % len(fails)))
sys.exit(1 if fails else 0)
