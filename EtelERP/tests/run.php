<?php
/**
 * EtelERP tests. Needs a THROWAWAY MariaDB/MySQL database (the tables are dropped and recreated):
 *   TEST_DB_HOST=127.0.0.1 TEST_DB_NAME=etelerp_test TEST_DB_USER=etelerp TEST_DB_PASS=testpass php EtelERP/tests/run.php
 * EtelID is replaced by a fake, so nothing leaves the machine.
 */
error_reporting(E_ALL);
date_default_timezone_set('Africa/Addis_Ababa');
$app = dirname(__DIR__) . '/app/';
foreach (array('ApiError', 'Env', 'Db', 'Money', 'Calc', 'Perm', 'Plans', 'RateLimit', 'Audit', 'EtelId', 'Auth', 'Businesses', 'Customers', 'Items', 'Payments', 'Invoices', 'Expenses', 'Reports', 'Router') as $c) require_once $app . $c . '.php';

$GLOBALS['pass'] = 0; $GLOBALS['fail'] = 0;
function check($name, $cond) { if ($cond) { $GLOBALS['pass']++; } else { $GLOBALS['fail']++; echo "  FAIL  $name\n"; } }
function section($t) { echo "$t\n"; }

$host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
$dbn = getenv('TEST_DB_NAME') ?: 'etelerp_test';
$pdo = new PDO("mysql:host=$host;dbname=$dbn;charset=utf8mb4", getenv('TEST_DB_USER') ?: 'etelerp', getenv('TEST_DB_PASS') ?: 'testpass',
    array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false));
$pdo->exec("SET time_zone = '+03:00'");
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) $pdo->exec("DROP TABLE `$t`");
$pdo->exec(file_get_contents(dirname(__DIR__) . '/db/schema.sql'));
Db::set($pdo);

/* ---- fake EtelID ---- */
$USERS = array(
    'ETL-1' => array('pw' => 'pw1', 'm' => array('etel_id' => 'ETL-1', 'full_name' => 'Abebe Owner', 'phone' => '0911000001', 'status' => 'active')),
    'ETL-2' => array('pw' => 'pw2', 'm' => array('etel_id' => 'ETL-2', 'full_name' => 'Tigist Manager', 'phone' => '0911000002', 'status' => 'active')),
    'ETL-3' => array('pw' => 'pw3', 'm' => array('etel_id' => 'ETL-3', 'full_name' => 'Dawit Cashier', 'phone' => '0911000003', 'status' => 'active')),
    'ETL-4' => array('pw' => 'pw4', 'm' => array('etel_id' => 'ETL-4', 'full_name' => 'Rival Owner', 'phone' => '0911000004', 'status' => 'active')),
    'ETL-5' => array('pw' => 'pw5', 'm' => array('etel_id' => 'ETL-5', 'full_name' => 'Extra Person', 'phone' => '0911000005', 'status' => 'active')),
    'ETL-S' => array('pw' => 'pws', 'm' => array('etel_id' => 'ETL-S', 'full_name' => 'Suspended', 'phone' => '0911000009', 'status' => 'suspended')),
    'ETL-L' => array('pw' => 'pwl', 'm' => array('etel_id' => 'ETL-L', 'full_name' => 'Limited', 'phone' => '0911000008', 'status' => 'active', 'profile_limited' => true)),
);
putenv('ETELID_SERVICE_KEY=' . str_repeat('k', 40));
EtelId::setTransport(function ($url, $headers, $payload) use (&$USERS) {
    $route = substr($url, strrpos($url, '/') + 1);
    $id = isset($payload['identifier']) ? $payload['identifier'] : '';
    $found = null;
    foreach ($USERS as $u) { if ($u['m']['etel_id'] === $id || $u['m']['phone'] === $id) $found = $u; }
    if (!$found) return array(404, array('code' => 'not_found', 'message' => 'No EtelID account found for that identifier.'));
    if ($route === 'resolve') return array(200, array('ok' => true, 'member' => $found['m']));
    if ($payload['password'] !== $found['pw']) return array(401, array('code' => 'wrong_password', 'message' => 'Incorrect EtelID password.'));
    if ($found['m']['etel_id'] === 'ETL-S') return array(403, array('code' => 'suspended', 'message' => 'This EtelID is suspended.'));
    return array(200, array('ok' => true, 'member' => $found['m']));
});

function call($action, $method = 'GET', $body = array(), $token = null, $biz = 0, $query = array()) {
    return Router::dispatch(array('method' => $method, 'action' => $action, 'query' => $query, 'body' => $body, 'token' => $token,
        'business' => $biz, 'ip' => '10.0.0.1', 'csrf' => $method === 'POST' ? 'etelerp' : ''));
}
function login($etel, $pw) { $r = call('auth.login', 'POST', array('identifier' => $etel, 'password' => $pw)); return $r['status'] === 200 ? $r['cookie']['token'] : null; }
function ok($r) { return $r['status'] === 200 && $r['body']['ok'] === true; }
function data($r) { return $r['body']['data']; }
function err($r) { return isset($r['body']['error']) ? $r['body']['error'] : ''; }

/* ================================================================ money and invoice maths */
section('money and calc');
check('cents parse', Money::toCents('12.5') === 1250 && Money::toCents('1,200.00') === 120000 && Money::toCents(5) === 500 && Money::toCents('0') === 0);
check('cents reject junk', (function () { foreach (array('abc', '', '1.234', '1e3', null, array(), '--1', '12.') as $v) { try { Money::toCents($v); return false; } catch (ApiError $e) {} } return true; })());
check('milli parse', Money::toMilli('2.5') === 2500 && Money::toMilli('0.001') === 1 && Money::toMilli('10') === 10000);
check('format', Money::fromCents(5) === '0.05' && Money::fromCents(123456) === '1234.56' && Money::fromMilli(1500) === '1.500' && Money::fromCents(-250) === '-2.50');
check('vat 15% of 200.00 is 30.00', Money::vat(20000, 1500) === 3000);
check('rounding half up', Money::vat(1, 5000) === 1 && Money::vat(333, 1500) === 50 && Money::lineSubtotal(333, 1000) === 333);
check('0.333 x 10.00 = 3.33', Money::lineSubtotal(Money::toMilli('0.333'), Money::toCents('10.00')) === 333);
$c = Calc::invoice(array(array('item' => null, 'description' => 'Work', 'qty' => '2', 'unit_price' => '100')), true, 1500);
check('invoice 2 x 100 + 15% = 230', $c['subtotal'] === 20000 && $c['vat'] === 3000 && $c['total'] === 23000);
$c = Calc::invoice(array(array('item' => null, 'description' => 'Work', 'qty' => '2', 'unit_price' => '100')), false, 1500);
check('not VAT registered: no VAT', $c['vat'] === 0 && $c['total'] === 20000);
$c = Calc::invoice(array(array('item' => array('id' => 1, 'vat_exempt' => 1), 'description' => 'Bread', 'qty' => '1', 'unit_price' => '10'), array('item' => null, 'description' => 'Fee', 'qty' => '1', 'unit_price' => '10')), true, 1500);
check('exempt line has no VAT, other does', $c['vat'] === 150 && $c['total'] === 2150);
check('total is sum of line totals', (function () {
    $c = Calc::invoice(array(array('item' => null, 'description' => 'a', 'qty' => '3', 'unit_price' => '3.33'), array('item' => null, 'description' => 'b', 'qty' => '0.5', 'unit_price' => '7.77')), true, 1500);
    $s = 0; foreach ($c['lines'] as $l) $s += $l['total_cents']; return $s === $c['total'];
})());
foreach (array(array(array(), 'no lines'), array(array(array('item' => null, 'description' => '', 'qty' => '1', 'unit_price' => '1')), 'no description'),
         array(array(array('item' => null, 'description' => 'x', 'qty' => '0', 'unit_price' => '1')), 'zero qty'),
         array(array(array('item' => null, 'description' => 'x', 'qty' => '-1', 'unit_price' => '1')), 'negative qty'),
         array(array(array('item' => null, 'description' => 'x', 'qty' => '1', 'unit_price' => '-1')), 'negative price')) as $bad) {
    $thrown = false; try { Calc::invoice($bad[0], true, 1500); } catch (ApiError $e) { $thrown = true; }
    check('calc rejects: ' . $bad[1], $thrown);
}
check('payment status', Calc::paymentStatus(1000, 0) === 'unpaid' && Calc::paymentStatus(1000, 500) === 'partial' && Calc::paymentStatus(1000, 1000) === 'paid');
check('roles', Perm::can('owner', 'team.manage') && !Perm::can('manager', 'team.manage') && Perm::can('manager', 'invoice.void') && !Perm::can('cashier', 'invoice.void') && !Perm::can('owner', 'nonsense') && !Perm::can('nobody', 'invoice.sell'));

/* ================================================================ sign in */
section('sign in');
$r = call('auth.login', 'POST', array('identifier' => 'ETL-1', 'password' => 'wrong'));
check('wrong password refused, generic message', $r['status'] === 401 && err($r) === 'Invalid login credentials');
$r = call('auth.login', 'POST', array('identifier' => 'nobody', 'password' => 'x'));
check('unknown account looks the same', $r['status'] === 401 && err($r) === 'Invalid login credentials');
$r = call('auth.login', 'POST', array('identifier' => 'ETL-S', 'password' => 'pws'));
check('suspended refused', $r['status'] === 401);
$r = call('auth.login', 'POST', array('identifier' => 'ETL-L', 'password' => 'pwl'));
check('limited profile refused', $r['status'] === 403);
check('login needs the request header', Router::dispatch(array('method' => 'POST', 'action' => 'auth.login', 'body' => array('identifier' => 'ETL-1', 'password' => 'pw1'), 'ip' => '10.0.0.1'))['status'] === 403);
$t1 = login('ETL-1', 'pw1'); $t2 = login('ETL-2', 'pw2'); $t3 = login('ETL-3', 'pw3'); $t4 = login('ETL-4', 'pw4'); $t5 = login('ETL-5', 'pw5');
check('good logins give 64-char tokens', strlen($t1) === 64 && $t1 !== $t2);
check('token itself is not stored, only its hash', (int) Db::val('SELECT COUNT(*) FROM sessions WHERE token_hash = ?', array($t1)) === 0 && (int) Db::val('SELECT COUNT(*) FROM sessions WHERE token_hash = ?', array(hash('sha256', $t1))) === 1);
check('phone works as the identifier', login('0911000001', 'pw1') !== null);
check('members row created', Db::val('SELECT full_name FROM members WHERE etel_id = ?', array('ETL-1')) === 'Abebe Owner');
check('me needs a session', call('auth.me')['status'] === 401 && call('auth.me', 'GET', array(), 'x')['status'] === 401 && call('auth.me', 'GET', array(), str_repeat('a', 64))['status'] === 401);
check('me works with a session', ok(call('auth.me', 'GET', array(), $t1)) && data(call('auth.me', 'GET', array(), $t1))['member']['etel_id'] === 'ETL-1');
Db::run('UPDATE sessions SET expires_at = ? WHERE token_hash = ?', array(date('Y-m-d H:i:s', time() - 5), hash('sha256', $t5)));
check('expired session refused', call('auth.me', 'GET', array(), $t5)['status'] === 401);
$t5 = login('ETL-5', 'pw5');
check('logout kills the session', ok(call('auth.logout', 'POST', array(), $t5)) && call('auth.me', 'GET', array(), $t5)['status'] === 401);
$t5 = login('ETL-5', 'pw5');
for ($i = 0; $i < 8; $i++) call('auth.login', 'POST', array('identifier' => 'ETL-3', 'password' => 'bad'));
check('too many wrong passwords for one account are slowed down', call('auth.login', 'POST', array('identifier' => 'ETL-3', 'password' => 'pw3'))['status'] === 429);
RateLimit::clear('login-id-etl-3'); RateLimit::clear('login-ip-10.0.0.1');
check('POST without header refused', Router::dispatch(array('method' => 'POST', 'action' => 'business.create', 'body' => array('name' => 'x'), 'token' => $t1))['status'] === 403);
check('unknown action 404, wrong method 405', call('nope', 'GET', array(), $t1)['status'] === 404 && call('auth.me', 'POST', array(), $t1)['status'] === 405);
check('EtelID down gives a safe message', (function () use ($USERS) {
    EtelId::setTransport(function () { throw new Exception('secret internal detail'); });
    $r = call('auth.login', 'POST', array('identifier' => 'ETL-1', 'password' => 'pw1'));
    return $r['status'] === 503 && strpos(err($r), 'secret') === false;
})());
EtelId::setTransport(function ($url, $headers, $payload) use (&$USERS) {
    $route = substr($url, strrpos($url, '/') + 1); $id = isset($payload['identifier']) ? $payload['identifier'] : ''; $found = null;
    foreach ($USERS as $u) { if ($u['m']['etel_id'] === $id || $u['m']['phone'] === $id) $found = $u; }
    if (!$found) return array(404, array('code' => 'not_found', 'message' => 'No EtelID account found.'));
    if ($route === 'resolve') return array(200, array('ok' => true, 'member' => $found['m']));
    if ($payload['password'] !== $found['pw']) return array(401, array('code' => 'wrong_password', 'message' => 'Incorrect EtelID password.'));
    return array(200, array('ok' => true, 'member' => $found['m']));
});
RateLimit::clear('login-ip-10.0.0.1');

/* ================================================================ businesses and team */
section('businesses and team');
$r = call('business.create', 'POST', array('name' => 'Abebe Shop', 'vat_registered' => true, 'invoice_prefix' => 'abe', 'tin' => '0012345678'), $t1);
check('business created', ok($r)); $B1 = (int) data($r)['id'];
$r = call('business.create', 'POST', array('name' => 'Rival Co'), $t4); $B2 = (int) data($r)['id'];
check('owner role and prefix cleaned', Db::val('SELECT role FROM memberships WHERE business_id = ? AND member_id = (SELECT id FROM members WHERE etel_id = ?)', array($B1, 'ETL-1')) === 'owner' && Db::val('SELECT invoice_prefix FROM businesses WHERE id = ?', array($B1)) === 'ABE');
check('name required', call('business.create', 'POST', array('name' => '  '), $t1)['status'] === 422);
check('me lists my businesses', count(data(call('auth.me', 'GET', array(), $t1))['businesses']) === 1);
check('non-member cannot enter a business', call('business.get', 'GET', array(), $t4, $B1)['status'] === 403 && call('dashboard', 'GET', array(), $t4, $B1)['status'] === 403);
check('no business header refused', call('dashboard', 'GET', array(), $t1, 0)['status'] === 403);
check('owner adds a manager on the free plan (owner + 1 = 2 users)', ok(call('team.add', 'POST', array('identifier' => 'ETL-2', 'role' => 'manager'), $t1, $B1)));
check('free plan allows 2 users, a third is refused with 402', call('team.add', 'POST', array('identifier' => '0911000003', 'role' => 'cashier'), $t1, $B1)['status'] === 402);
Db::run("UPDATE businesses SET plan = 'standard' WHERE id = ?", array($B1));
check('standard plan allows more (cashier by phone, and another)', ok(call('team.add', 'POST', array('identifier' => '0911000003', 'role' => 'cashier'), $t1, $B1)) && ok(call('team.add', 'POST', array('identifier' => 'ETL-5', 'role' => 'cashier'), $t1, $B1)));
check('unknown EtelID refused', call('team.add', 'POST', array('identifier' => 'ETL-404', 'role' => 'cashier'), $t1, $B1)['status'] === 404);
check('suspended EtelID cannot be added', call('team.add', 'POST', array('identifier' => 'ETL-S', 'role' => 'cashier'), $t1, $B1)['status'] === 404);
check('bad role refused', call('team.add', 'POST', array('identifier' => 'ETL-5', 'role' => 'owner'), $t1, $B1)['status'] === 422);
check('manager cannot manage the team', call('team.add', 'POST', array('identifier' => 'ETL-4', 'role' => 'cashier'), $t2, $B1)['status'] === 403 && call('team.set', 'POST', array('id' => 1, 'role' => 'cashier'), $t2, $B1)['status'] === 403);
check('manager can see the team, cashier cannot', ok(call('team.list', 'GET', array(), $t2, $B1)) && call('team.list', 'GET', array(), $t3, $B1)['status'] === 403);
$owner = Db::val('SELECT id FROM memberships WHERE business_id = ? AND role = ?', array($B1, 'owner'));
check('the owner cannot be changed or removed', call('team.set', 'POST', array('id' => $owner, 'role' => 'cashier'), $t1, $B1)['status'] === 409 && call('team.set', 'POST', array('id' => $owner, 'active' => false), $t1, $B1)['status'] === 409);
check('adding the owner as staff refused', call('team.add', 'POST', array('identifier' => 'ETL-1', 'role' => 'cashier'), $t1, $B1)['status'] === 409);
$m5 = Db::val('SELECT id FROM memberships WHERE business_id = ? AND member_id = (SELECT id FROM members WHERE etel_id = ?)', array($B1, 'ETL-5'));
check('removed staff lose access at once', ok(call('team.set', 'POST', array('id' => $m5, 'active' => false), $t1, $B1)) && call('dashboard', 'GET', array(), $t5, $B1)['status'] === 403);
check('a membership id from another business cannot be edited', call('team.set', 'POST', array('id' => Db::val('SELECT id FROM memberships WHERE business_id = ? LIMIT 1', array($B2)), 'role' => 'cashier'), $t1, $B1)['status'] === 404);
check('only the owner edits settings', call('business.update', 'POST', array('name' => 'Hacked'), $t2, $B1)['status'] === 403 && ok(call('business.update', 'POST', array('name' => 'Abebe Shop', 'vat_registered' => true, 'invoice_prefix' => 'ABE'), $t1, $B1)));

/* ================================================================ customers and items */
section('customers and items');
$r = call('customers.save', 'POST', array('name' => 'Hotel Lewi', 'phone' => '0911555555'), $t3, $B1); $CUST = (int) data($r)['id'];
check('cashier can add a customer', ok($r) && $CUST > 0);
check('customer search', count(data(call('customers.list', 'GET', array(), $t3, $B1, array('q' => 'lewi')))) === 1 && count(data(call('customers.list', 'GET', array(), $t3, $B1, array('q' => 'zzz')))) === 0);
check('search treats % literally', count(data(call('customers.list', 'GET', array(), $t3, $B1, array('q' => '%')))) === 0);
check('cashier cannot create items, manager can', call('items.save', 'POST', array('name' => 'Coffee', 'price' => '10'), $t3, $B1)['status'] === 403);
$r = call('items.save', 'POST', array('name' => 'Coffee', 'price' => '50.00', 'cost' => '30', 'track_stock' => true, 'reorder_level' => '5', 'unit' => 'cup'), $t2, $B1); $COFFEE = (int) data($r)['id'];
$r = call('items.save', 'POST', array('name' => 'Teff 1kg', 'price' => '100', 'track_stock' => true, 'vat_exempt' => true), $t2, $B1); $TEFF = (int) data($r)['id'];
$r = call('items.save', 'POST', array('name' => 'Consulting hour', 'price' => '500'), $t2, $B1); $SERV = (int) data($r)['id'];
check('items created', $COFFEE && $TEFF && $SERV);
check('bad price refused', call('items.save', 'POST', array('name' => 'X', 'price' => 'abc'), $t2, $B1)['status'] === 422 && call('items.save', 'POST', array('name' => 'X', 'price' => '-5'), $t2, $B1)['status'] === 422);
check('stock cannot be set by editing an item', (function () use ($t2, $B1, $COFFEE) { call('items.save', 'POST', array('id' => $COFFEE, 'name' => 'Coffee', 'price' => '50', 'stock_qty' => '999', 'track_stock' => true), $t2, $B1); return Db::val('SELECT stock_qty FROM items WHERE id = ?', array($COFFEE)) === '0.000'; })());
check('stock adjust: purchase adds', ok(call('items.adjust', 'POST', array('item_id' => $COFFEE, 'qty_change' => '10', 'reason' => 'purchase'), $t2, $B1)) && Db::val('SELECT stock_qty FROM items WHERE id = ?', array($COFFEE)) === '10.000');
call('items.adjust', 'POST', array('item_id' => $TEFF, 'qty_change' => '3'), $t2, $B1);
check('cannot adjust below zero', call('items.adjust', 'POST', array('item_id' => $COFFEE, 'qty_change' => '-11'), $t2, $B1)['status'] === 422);
check('cannot adjust a service', call('items.adjust', 'POST', array('item_id' => $SERV, 'qty_change' => '1'), $t2, $B1)['status'] === 422);
check('cashier cannot adjust stock', call('items.adjust', 'POST', array('item_id' => $COFFEE, 'qty_change' => '1'), $t3, $B1)['status'] === 403);
$r = call('items.save', 'POST', array('name' => 'Rival item', 'price' => '9'), $t4, $B2); $RIVALITEM = (int) data($r)['id'];
$r = call('customers.save', 'POST', array('name' => 'Rival customer'), $t4, $B2); $RIVALCUST = (int) data($r)['id'];
check('other business items are invisible', count(data(call('items.list', 'GET', array(), $t1, $B1, array('q' => 'Rival')))) === 0);
check('cannot edit another business item or customer', call('items.save', 'POST', array('id' => $RIVALITEM, 'name' => 'x', 'price' => '1'), $t2, $B1)['status'] === 404 && call('customers.save', 'POST', array('id' => $RIVALCUST, 'name' => 'x'), $t3, $B1)['status'] === 404);
check('cannot archive another business item', call('items.archive', 'POST', array('id' => $RIVALITEM), $t2, $B1)['status'] === 404 && (int) Db::val('SELECT active FROM items WHERE id = ?', array($RIVALITEM)) === 1);
check('cannot adjust stock of another business item', call('items.adjust', 'POST', array('item_id' => $RIVALITEM, 'qty_change' => '1'), $t2, $B1)['status'] === 404);

/* ================================================================ selling */
section('selling');
$sell = function ($token, $lines, $extra = array(), $biz = null) use ($B1) { return call('invoices.sell', 'POST', array_merge(array('lines' => $lines), $extra), $token, $biz ?: $B1); };
$r = $sell($t3, array(array('item_id' => $COFFEE, 'qty' => '2')), array('customer_id' => $CUST, 'pay' => array('method' => 'cash')));
check('sale with payment ok', ok($r));
$inv = data($r);
check('number is prefix-000001', $inv['number'] === 'ABE-000001' && $inv['status'] === 'issued');
check('coffee 2 x 50 + 15% VAT = 115.00', $inv['subtotal'] === '100.00' && $inv['vat_amount'] === '15.00' && $inv['total'] === '115.00');
check('paid in full', $inv['payment_status'] === 'paid' && $inv['balance'] === '0.00' && count($inv['payments']) === 1);
check('stock went down by 2', Db::val('SELECT stock_qty FROM items WHERE id = ?', array($COFFEE)) === '8.000');
check('stock move written', (int) Db::val("SELECT COUNT(*) FROM stock_moves WHERE item_id = ? AND reason = 'sale' AND ref_id = ?", array($COFFEE, $inv['id'])) === 1);
$r = $sell($t3, array(array('item_id' => $TEFF, 'qty' => '1'), array('item_id' => $SERV, 'qty' => '2')));
$inv2 = data($r);
check('exempt teff 100 + consulting 1000 + VAT on consulting only (150) = 1250', $inv2['subtotal'] === '1100.00' && $inv2['vat_amount'] === '150.00' && $inv2['total'] === '1250.00');
check('second number is 000002 and unpaid', $inv2['number'] === 'ABE-000002' && $inv2['payment_status'] === 'unpaid');
check('cashier cannot change a price', (function () use ($sell, $t3, $COFFEE) { $d = data($sell($t3, array(array('item_id' => $COFFEE, 'qty' => '1', 'unit_price' => '1')))); return $d['subtotal'] === '50.00'; })());
check('cashier cannot add a free-text line', $sell($t3, array(array('description' => 'Discount deal', 'qty' => '1', 'unit_price' => '5')))['status'] === 403);
check('manager can change a price and add a free line', (function () use ($sell, $t2, $COFFEE) { $d = data($sell($t2, array(array('item_id' => $COFFEE, 'qty' => '1', 'unit_price' => '40'), array('description' => 'Delivery', 'qty' => '1', 'unit_price' => '20')))); return $d['subtotal'] === '60.00' && $d['vat_amount'] === '9.00'; })());
$coffeeNow = Db::val('SELECT stock_qty FROM items WHERE id = ?', array($COFFEE));
$next = Db::val('SELECT next_invoice_no FROM businesses WHERE id = ?', array($B1));
$r = $sell($t3, array(array('item_id' => $COFFEE, 'qty' => '500')));
check('not enough stock refused with a clear message', $r['status'] === 409 && strpos(err($r), 'Coffee') !== false);
check('failed sale leaves no trace: stock, number and invoice count unchanged', Db::val('SELECT stock_qty FROM items WHERE id = ?', array($COFFEE)) === $coffeeNow && Db::val('SELECT next_invoice_no FROM businesses WHERE id = ?', array($B1)) === $next);
check('a sale with two lines of the same item checks their combined quantity (4 + 4 > 6 in stock)', $sell($t3, array(array('item_id' => $COFFEE, 'qty' => '4'), array('item_id' => $COFFEE, 'qty' => '4')))['status'] === 409 && Db::val('SELECT stock_qty FROM items WHERE id = ?', array($COFFEE)) === '6.000');
check('item from another business refused', $sell($t3, array(array('item_id' => $RIVALITEM, 'qty' => '1')))['status'] === 422);
check('customer from another business refused', $sell($t3, array(array('item_id' => $SERV, 'qty' => '1')), array('customer_id' => $RIVALCUST))['status'] === 422);
check('empty sale refused', $sell($t3, array())['status'] === 422);

section('idempotent sale (double tap on a slow phone)');
call('items.adjust', 'POST', array('item_id' => $COFFEE, 'qty_change' => '20', 'reason' => 'purchase'), $t2, $B1);
$stockBefore = Db::val('SELECT stock_qty FROM items WHERE id = ?', array($COFFEE));
$a = $sell($t3, array(array('item_id' => $COFFEE, 'qty' => '1')), array('client_ref' => 'dup-1', 'pay' => array('method' => 'telebirr', 'reference' => 'TB123')));
$b = $sell($t3, array(array('item_id' => $COFFEE, 'qty' => '1')), array('client_ref' => 'dup-1', 'pay' => array('method' => 'telebirr', 'reference' => 'TB123')));
check('same client_ref gives the same invoice', ok($a) && ok($b) && data($a)['id'] === data($b)['id'] && data($a)['number'] === data($b)['number']);
check('stock taken once, one payment', Money::toMilli($stockBefore) - Money::toMilli(Db::val('SELECT stock_qty FROM items WHERE id = ?', array($COFFEE))) === 1000 && count(data($b)['payments']) === 1);

section('drafts');
$r = call('invoices.save', 'POST', array('lines' => array(array('item_id' => $SERV, 'qty' => '1')), 'customer_id' => $CUST, 'due_date' => '2026-12-31'), $t3, $B1); $DRAFT = (int) data($r)['id'];
$d = data(call('invoices.get', 'GET', array(), $t3, $B1, array('id' => $DRAFT)));
check('draft has no number, status draft, total computed', $d['status'] === 'draft' && $d['number'] === null && $d['total'] === '575.00');
check('draft can be edited', ok(call('invoices.save', 'POST', array('id' => $DRAFT, 'lines' => array(array('item_id' => $SERV, 'qty' => '2'))), $t3, $B1)) && data(call('invoices.get', 'GET', array(), $t3, $B1, array('id' => $DRAFT)))['total'] === '1150.00');
check('a draft cannot take a payment', call('payments.add', 'POST', array('invoice_id' => $DRAFT, 'amount' => '10'), $t3, $B1)['status'] === 409);
check('draft issues once and keeps its number on a second issue', (function () use ($t3, $B1, $DRAFT) {
    $a = data(call('invoices.issue', 'POST', array('id' => $DRAFT), $t3, $B1)); $b = data(call('invoices.issue', 'POST', array('id' => $DRAFT), $t3, $B1)); return $a['status'] === 'issued' && $a['number'] === $b['number'];
})());
check('an issued invoice cannot be edited', call('invoices.save', 'POST', array('id' => $DRAFT, 'lines' => array(array('item_id' => $SERV, 'qty' => '1'))), $t3, $B1)['status'] === 409);
check('cannot read another business invoice', call('invoices.get', 'GET', array(), $t4, $B2, array('id' => $DRAFT))['status'] === 404);
check('cannot issue another business invoice', call('invoices.issue', 'POST', array('id' => $DRAFT), $t4, $B2)['status'] === 404);
check('cannot void another business invoice', call('invoices.void', 'POST', array('id' => $DRAFT, 'reason' => 'x'), $t4, $B2)['status'] === 404);

section('payments');
$id2 = (int) $inv2['id'];
check('partial payment', data(call('payments.add', 'POST', array('invoice_id' => $id2, 'amount' => '250', 'method' => 'cbe', 'reference' => 'CBE-1'), $t3, $B1))['payment_status'] === 'partial');
check('overpayment refused', call('payments.add', 'POST', array('invoice_id' => $id2, 'amount' => '1000.01'), $t3, $B1)['status'] === 422);
check('zero, negative and junk amounts refused', call('payments.add', 'POST', array('invoice_id' => $id2, 'amount' => '0'), $t3, $B1)['status'] === 422 && call('payments.add', 'POST', array('invoice_id' => $id2, 'amount' => '-5'), $t3, $B1)['status'] === 422 && call('payments.add', 'POST', array('invoice_id' => $id2, 'amount' => 'abc'), $t3, $B1)['status'] === 422);
check('unknown method refused', call('payments.add', 'POST', array('invoice_id' => $id2, 'amount' => '1', 'method' => 'bitcoin'), $t3, $B1)['status'] === 422);
check('same payment client_ref twice counts once', (function () use ($t3, $B1, $id2) {
    call('payments.add', 'POST', array('invoice_id' => $id2, 'amount' => '100', 'client_ref' => 'pay-dup'), $t3, $B1);
    call('payments.add', 'POST', array('invoice_id' => $id2, 'amount' => '100', 'client_ref' => 'pay-dup'), $t3, $B1);
    return Db::val('SELECT paid_amount FROM invoices WHERE id = ?', array($id2)) === '350.00';
})());
check('rest pays it off', data(call('payments.add', 'POST', array('invoice_id' => $id2, 'amount' => '900'), $t3, $B1))['payment_status'] === 'paid');
check('paid invoice takes no more', call('payments.add', 'POST', array('invoice_id' => $id2, 'amount' => '1'), $t3, $B1)['status'] === 422);
check('cannot pay another business invoice', call('payments.add', 'POST', array('invoice_id' => $id2, 'amount' => '1'), $t4, $B2)['status'] === 404);
$pid = (int) Db::val('SELECT id FROM payments WHERE invoice_id = ? ORDER BY id LIMIT 1', array($id2));
check('cashier cannot cancel a payment', call('payments.void', 'POST', array('id' => $pid), $t3, $B1)['status'] === 403);
check('manager cancels it and the invoice reopens', ok(call('payments.void', 'POST', array('id' => $pid), $t2, $B1)) && data(call('invoices.get', 'GET', array(), $t2, $B1, array('id' => $id2)))['payment_status'] === 'partial');
check('cannot cancel a payment twice', call('payments.void', 'POST', array('id' => $pid), $t2, $B1)['status'] === 409);
check('cannot cancel another business payment', call('payments.void', 'POST', array('id' => $pid), $t4, $B2)['status'] === 404);

section('cancelling invoices');
check('cashier cannot cancel an invoice', call('invoices.void', 'POST', array('id' => $id2, 'reason' => 'x'), $t3, $B1)['status'] === 403);
check('invoice with payments cannot be cancelled', call('invoices.void', 'POST', array('id' => $id2, 'reason' => 'mistake'), $t2, $B1)['status'] === 409);
check('reason is required', call('invoices.void', 'POST', array('id' => $id2, 'reason' => ' '), $t2, $B1)['status'] === 422);
$teffBefore = Db::val('SELECT stock_qty FROM items WHERE id = ?', array($TEFF));
foreach (Db::all('SELECT id FROM payments WHERE invoice_id = ? AND voided = 0', array($id2)) as $p) call('payments.void', 'POST', array('id' => $p['id']), $t2, $B1);
check('after cancelling payments the invoice can be cancelled', ok(call('invoices.void', 'POST', array('id' => $id2, 'reason' => 'customer returned goods'), $t2, $B1)));
check('stock came back', Money::toMilli(Db::val('SELECT stock_qty FROM items WHERE id = ?', array($TEFF))) === Money::toMilli($teffBefore) + 1000);
check('cancelling twice is harmless', ok(call('invoices.void', 'POST', array('id' => $id2, 'reason' => 'again'), $t2, $B1)) && Money::toMilli(Db::val('SELECT stock_qty FROM items WHERE id = ?', array($TEFF))) === Money::toMilli($teffBefore) + 1000);
check('cancelled invoice takes no payment and keeps its number', call('payments.add', 'POST', array('invoice_id' => $id2, 'amount' => '1'), $t3, $B1)['status'] === 409 && data(call('invoices.get', 'GET', array(), $t3, $B1, array('id' => $id2)))['number'] === 'ABE-000002');

section('plan limit on invoices');
Db::run("UPDATE businesses SET plan = 'free' WHERE id = ?", array($B1));
$issued = (int) Db::val("SELECT COUNT(*) FROM invoices WHERE business_id = ? AND status <> 'draft'", array($B1));
for ($i = $issued; $i < 30; $i++) { $r = $sell($t3, array(array('item_id' => $SERV, 'qty' => '1'))); if (!ok($r)) { echo "unexpected: " . err($r) . "\n"; break; } }
check('free plan stops at 30 invoices a month with 402', $sell($t3, array(array('item_id' => $SERV, 'qty' => '1')))['status'] === 402);
check('failed limit sale left nothing behind', (int) Db::val("SELECT COUNT(*) FROM invoices WHERE business_id = ? AND status = 'draft'", array($B1)) === 0);
Db::run("UPDATE businesses SET plan = 'standard' WHERE id = ?", array($B1));
check('upgrading lifts it', ok($sell($t3, array(array('item_id' => $SERV, 'qty' => '1')))));
Db::run("UPDATE businesses SET plan = 'standard', plan_expires_at = ? WHERE id = ?", array(date('Y-m-d', time() - 86400), $B1));
check('an expired paid plan counts as free again', $sell($t3, array(array('item_id' => $SERV, 'qty' => '1')))['status'] === 402);
Db::run("UPDATE businesses SET plan = 'standard', plan_expires_at = NULL WHERE id = ?", array($B1));

section('negative stock allowed');
Db::run('UPDATE businesses SET allow_negative_stock = 1 WHERE id = ?', array($B1));
check('with the setting on, overselling is allowed', ok($sell($t3, array(array('item_id' => $COFFEE, 'qty' => '500')))));
Db::run('UPDATE businesses SET allow_negative_stock = 0 WHERE id = ?', array($B1));

/* ================================================================ expenses, dashboard, reports */
section('expenses, dashboard, reports');
check('cashier cannot see or add expenses', call('expenses.list', 'GET', array(), $t3, $B1)['status'] === 403 && call('expenses.add', 'POST', array('amount' => '5'), $t3, $B1)['status'] === 403);
$r = call('expenses.add', 'POST', array('amount' => '120.50', 'category' => 'rent', 'description' => 'Shop rent', 'method' => 'cash'), $t2, $B1); $EXP = (int) data($r)['id'];
check('manager adds an expense', ok($r) && $EXP > 0);
check('zero expense refused', call('expenses.add', 'POST', array('amount' => '0'), $t2, $B1)['status'] === 422);
check('expense list shows it', count(data(call('expenses.list', 'GET', array(), $t2, $B1))) === 1);
check('cannot cancel another business expense', call('expenses.void', 'POST', array('id' => $EXP), $t4, $B2)['status'] === 404);
$dash = data(call('dashboard', 'GET', array(), $t3, $B1));
$expected = Db::val("SELECT COALESCE(SUM(total),0) FROM invoices WHERE business_id = ? AND status = 'issued' AND issue_date = ?", array($B1, date('Y-m-d')));
check('dashboard today sales equal the database', $dash['today']['sales'] === $expected);
check('dashboard excludes cancelled invoices', (int) $dash['today']['invoices'] === (int) Db::val("SELECT COUNT(*) FROM invoices WHERE business_id = ? AND status = 'issued'", array($B1)));
check('dashboard month expenses', $dash['month']['expenses'] === '120.50');
check('dashboard profit = sales excl VAT - expenses', $dash['month']['profit_estimate'] === Money::fromCents(Money::toCents($dash['month']['sales_excl_vat']) - 12050));
check('receivables only count issued unpaid money', $dash['receivables']['amount'] === Db::val("SELECT COALESCE(SUM(total - paid_amount),0) FROM invoices WHERE business_id = ? AND status = 'issued' AND paid_amount < total", array($B1)));
check('other business dashboard is empty', data(call('dashboard', 'GET', array(), $t4, $B2))['today']['sales'] === '0.00');
check('cashier cannot run sales reports, manager can', call('report.sales', 'GET', array(), $t3, $B1)['status'] === 403 && ok(call('report.sales', 'GET', array(), $t2, $B1)));
$rep = data(call('report.sales', 'GET', array(), $t2, $B1));
check('report VAT equals sum of invoice VAT', $rep['vat'] === Db::val("SELECT COALESCE(SUM(vat_amount),0) FROM invoices WHERE business_id = ? AND status = 'issued' AND issue_date BETWEEN ? AND ?", array($B1, date('Y-m-01'), date('Y-m-d'))));
check('money received is split by method', count($rep['received_by_method']) >= 1);
call('items.adjust', 'POST', array('item_id' => $COFFEE, 'qty_change' => '-' . Db::val('SELECT stock_qty FROM items WHERE id = ?', array($COFFEE))), $t2, $B1);
check('low stock shows items at or under their reorder level', count(data(call('dashboard', 'GET', array(), $t2, $B1))['low_stock']) >= 1);

section('invoice list');
check('list filters', count(data(call('invoices.list', 'GET', array(), $t3, $B1, array('status' => 'void')))) === 1 && count(data(call('invoices.list', 'GET', array(), $t3, $B1, array('unpaid' => '1')))) >= 1);
check('list is private to the business', count(data(call('invoices.list', 'GET', array(), $t4, $B2))) === 0);
check('invoice search by number', count(data(call('invoices.list', 'GET', array(), $t3, $B1, array('q' => 'ABE-000001')))) === 1);

section('audit trail');
check('sales and payments are logged', (int) Db::val("SELECT COUNT(*) FROM audit_log WHERE business_id = ? AND action IN ('invoice.issue','payment.add','invoice.void')", array($B1)) >= 5);
check('logins are logged', (int) Db::val("SELECT COUNT(*) FROM audit_log WHERE action = 'login'") >= 5);

echo "\n" . $GLOBALS['pass'] . ' passed, ' . $GLOBALS['fail'] . " failed\n";
exit($GLOBALS['fail'] ? 1 : 0);
