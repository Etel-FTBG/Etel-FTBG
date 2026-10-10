<?php
/**
 * Request in, response out. Pure of globals so the tests can drive it directly.
 * $req: method, action, query[], body[], token, business (id from X-Business), ip, csrf (X-Requested-With value)
 * returns: status, body (array), cookie (null | array(token|null))
 */
class Router
{
    const COOKIE = 'etelerp_sid';

    /** action => array(http method, access, permission, handler(ctx, req)). access: public | member | business */
    private static function routes()
    {
        return array(
            'health'          => array('GET',  'public', null, function ($c, $r) { return array('time' => date('c')); }),
            'auth.me'         => array('GET',  'member', null, function ($c, $r) { return array('member' => array('etel_id' => $c['member']['etel_id'], 'full_name' => $c['member']['full_name'], 'phone' => $c['member']['phone']), 'businesses' => Businesses::mine($c['member_id'])); }),
            'auth.logout'     => array('POST', 'member', null, function ($c, $r) { Auth::logout($r['token']); return array('bye' => true); }),
            'business.create' => array('POST', 'member', null, function ($c, $r) { return array('id' => Businesses::create($c, $r['body'])); }),

            'business.get'    => array('GET',  'business', 'dashboard.view', function ($c, $r) { return Businesses::get($c); }),
            'business.update' => array('POST', 'business', 'business.edit', function ($c, $r) { Businesses::update($c, $r['body']); return Businesses::get($c); }),
            'team.list'       => array('GET',  'business', 'team.view',   function ($c, $r) { return Businesses::team($c); }),
            'team.add'        => array('POST', 'business', 'team.manage', function ($c, $r) { Businesses::addMember($c, $r['body']); return Businesses::team($c); }),
            'team.set'        => array('POST', 'business', 'team.manage', function ($c, $r) { Businesses::setMember($c, $r['body']); return Businesses::team($c); }),

            'dashboard'       => array('GET',  'business', 'dashboard.view', function ($c, $r) { return Reports::dashboard($c); }),
            'report.sales'    => array('GET',  'business', 'report.view', function ($c, $r) { list($f, $t) = self::range($r); return Reports::sales($c, $f, $t); }),

            'customers.list'  => array('GET',  'business', 'customer.view',  function ($c, $r) { return Customers::listAll($c, self::q($r, 'q')); }),
            'customers.save'  => array('POST', 'business', 'customer.write', function ($c, $r) { return array('id' => Customers::save($c, $r['body'])); }),
            'customers.archive' => array('POST', 'business', 'customer.write', function ($c, $r) { Customers::archive($c, self::bodyId($r)); return array('archived' => true); }),

            'items.list'      => array('GET',  'business', 'item.view',  function ($c, $r) { return Items::listAll($c, self::q($r, 'q')); }),
            'items.save'      => array('POST', 'business', 'item.write', function ($c, $r) { return array('id' => Items::save($c, $r['body'])); }),
            'items.archive'   => array('POST', 'business', 'item.write', function ($c, $r) { Items::archive($c, self::bodyId($r)); return array('archived' => true); }),
            'items.adjust'    => array('POST', 'business', 'stock.adjust', function ($c, $r) { Items::adjust($c, $r['body']); return array('adjusted' => true); }),

            'invoices.list'   => array('GET',  'business', 'invoice.view', function ($c, $r) { return Invoices::listAll($c, $r['query']); }),
            'invoices.get'    => array('GET',  'business', 'invoice.view', function ($c, $r) { return Invoices::get($c, isset($r['query']['id']) ? $r['query']['id'] : 0); }),
            'invoices.save'   => array('POST', 'business', 'invoice.write', function ($c, $r) { return array('id' => Invoices::save($c, $r['body'])); }),
            'invoices.issue'  => array('POST', 'business', 'invoice.write', function ($c, $r) { Invoices::issue($c, self::bodyId($r)); return Invoices::get($c, self::bodyId($r)); }),
            'invoices.sell'   => array('POST', 'business', 'invoice.sell',  function ($c, $r) { $id = Invoices::sell($c, $r['body']); return Invoices::get($c, $id); }),
            'invoices.void'   => array('POST', 'business', 'invoice.void',  function ($c, $r) { Invoices::void($c, self::bodyId($r), isset($r['body']['reason']) ? $r['body']['reason'] : ''); return array('voided' => true); }),

            'payments.add'    => array('POST', 'business', 'payment.add',  function ($c, $r) { $id = Payments::add($c, $r['body']); return Invoices::get($c, (int) $r['body']['invoice_id']); }),
            'payments.void'   => array('POST', 'business', 'payment.void', function ($c, $r) { Payments::void($c, self::bodyId($r)); return array('voided' => true); }),

            'expenses.list'   => array('GET',  'business', 'expense.view',  function ($c, $r) { list($f, $t) = self::range($r); return Expenses::listAll($c, $f, $t); }),
            'expenses.add'    => array('POST', 'business', 'expense.write', function ($c, $r) { return array('id' => Expenses::add($c, $r['body'])); }),
            'expenses.void'   => array('POST', 'business', 'expense.write', function ($c, $r) { Expenses::void($c, self::bodyId($r)); return array('voided' => true); }),
        );
    }

    private static function q($r, $k)
    {
        return isset($r['query'][$k]) && is_string($r['query'][$k]) ? substr($r['query'][$k], 0, 80) : '';
    }

    private static function bodyId($r)
    {
        return (int) (isset($r['body']['id']) ? $r['body']['id'] : 0);
    }

    private static function range($r)
    {
        $d = '/^\d{4}-\d{2}-\d{2}$/';
        $from = isset($r['query']['from']) && preg_match($d, (string) $r['query']['from']) ? $r['query']['from'] : date('Y-m-01');
        $to = isset($r['query']['to']) && preg_match($d, (string) $r['query']['to']) ? $r['query']['to'] : date('Y-m-d');
        return array($from, $to);
    }

    public static function dispatch($req)
    {
        $req += array('method' => 'GET', 'action' => '', 'query' => array(), 'body' => array(), 'token' => null, 'business' => 0, 'ip' => '', 'csrf' => '');
        try {
            if ($req['action'] === 'auth.login') return self::login($req);
            $routes = self::routes();
            if (!isset($routes[$req['action']])) throw new ApiError(404, 'Unknown action.');
            list($method, $access, $perm, $handler) = $routes[$req['action']];
            if ($req['method'] !== $method) throw new ApiError(405, 'Wrong method.');
            if ($method === 'POST' && $req['csrf'] !== 'etelerp') throw new ApiError(403, 'Request refused.');
            if (!is_array($req['body'])) throw new ApiError(400, 'Invalid request body.');

            $ctx = array('ip' => $req['ip'], 'member_id' => null, 'business_id' => null, 'role' => null, 'member' => null);
            if ($access !== 'public') {
                $m = Auth::member($req['token']);
                if (!$m) throw new ApiError(401, 'Please sign in.');
                $ctx['member'] = $m;
                $ctx['member_id'] = (int) $m['id'];
            }
            if ($access === 'business') {
                $bid = (int) $req['business'];
                $row = $bid ? Db::one(
                    'SELECT ms.role, b.status FROM memberships ms JOIN businesses b ON b.id = ms.business_id WHERE ms.business_id = ? AND ms.member_id = ? AND ms.active = 1',
                    array($bid, $ctx['member_id'])) : null;
                if (!$row || $row['status'] !== 'active') throw new ApiError(403, 'You do not have access to that business.');
                $ctx['business_id'] = $bid;
                $ctx['role'] = $row['role'];
                Perm::require_($ctx['role'], $perm);
            }
            $data = call_user_func($handler, $ctx, $req);
            return array('status' => 200, 'body' => array('ok' => true, 'data' => $data), 'cookie' => null);
        } catch (ApiError $e) {
            return array('status' => $e->status, 'body' => array('ok' => false, 'error' => $e->getMessage()), 'cookie' => null);
        } catch (Exception $e) {
            error_log('[EtelERP] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
            return array('status' => 500, 'body' => array('ok' => false, 'error' => 'Something went wrong. Please try again.'), 'cookie' => null);
        }
    }

    private static function login($req)
    {
        if ($req['method'] !== 'POST') throw new ApiError(405, 'Wrong method.');
        if ($req['csrf'] !== 'etelerp') throw new ApiError(403, 'Request refused.');
        $b = is_array($req['body']) ? $req['body'] : array();
        list($token, $member) = Auth::login(isset($b['identifier']) ? $b['identifier'] : '', isset($b['password']) ? $b['password'] : '', isset($b['totp_code']) ? $b['totp_code'] : '', $req['ip']);
        return array('status' => 200, 'cookie' => array('token' => $token),
            'body' => array('ok' => true, 'data' => array('member' => array('etel_id' => $member['etel_id'], 'full_name' => $member['full_name']), 'businesses' => Businesses::mine((int) $member['id']))));
    }
}
