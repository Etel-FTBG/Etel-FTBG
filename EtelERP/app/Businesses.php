<?php
/** Businesses, their settings and their team. Every function takes the checked context. */
class Businesses
{
    const MAX_PER_MEMBER = 3;

    public static function mine($memberId)
    {
        return Db::all(
            'SELECT b.id, b.name, b.plan, ms.role FROM memberships ms JOIN businesses b ON b.id = ms.business_id
             WHERE ms.member_id = ? AND ms.active = 1 AND b.status = ? ORDER BY b.name',
            array($memberId, 'active')
        );
    }

    public static function create($ctx, $b)
    {
        $name = trim((string) (isset($b['name']) ? $b['name'] : ''));
        if ($name === '' || strlen($name) > 160) throw new ApiError(422, 'Business name is required.');
        $owned = (int) Db::val('SELECT COUNT(*) FROM memberships WHERE member_id = ? AND role = ? AND active = 1', array($ctx['member_id'], 'owner'));
        if ($owned >= self::MAX_PER_MEMBER) throw new ApiError(402, 'You can own up to ' . self::MAX_PER_MEMBER . ' businesses.');
        $vat = !empty($b['vat_registered']) ? 1 : 0;
        $id = Db::tx(function () use ($ctx, $b, $name, $vat) {
            $id = Db::insert(
                'INSERT INTO businesses (name, tin, vat_registered, address, phone, invoice_prefix, created_by, created_at) VALUES (?,?,?,?,?,?,?,?)',
                array($name, self::str($b, 'tin', 40), $vat, self::str($b, 'address', 255), self::str($b, 'phone', 40), self::prefix($b), $ctx['member_id'], Db::now())
            );
            Db::run('INSERT INTO memberships (business_id, member_id, role, created_at) VALUES (?,?,?,?)', array($id, $ctx['member_id'], 'owner', Db::now()));
            return $id;
        });
        Audit::log(array('business_id' => $id, 'member_id' => $ctx['member_id'], 'ip' => $ctx['ip']), 'business.create', 'business', $id, $name);
        return $id;
    }

    public static function get($ctx)
    {
        $b = Db::one(
            'SELECT id, name, tin, vat_registered, vat_rate, address, phone, invoice_prefix, allow_negative_stock, plan, plan_expires_at FROM businesses WHERE id = ?',
            array($ctx['business_id'])
        );
        $b['role'] = $ctx['role'];
        $b['effective_plan'] = Plans::effective($b);
        return $b;
    }

    public static function update($ctx, $b)
    {
        Perm::require_($ctx['role'], 'business.edit');
        $name = trim((string) (isset($b['name']) ? $b['name'] : ''));
        if ($name === '' || strlen($name) > 160) throw new ApiError(422, 'Business name is required.');
        Db::run(
            'UPDATE businesses SET name = ?, tin = ?, vat_registered = ?, address = ?, phone = ?, invoice_prefix = ?, allow_negative_stock = ? WHERE id = ?',
            array($name, self::str($b, 'tin', 40), !empty($b['vat_registered']) ? 1 : 0, self::str($b, 'address', 255), self::str($b, 'phone', 40),
                  self::prefix($b), !empty($b['allow_negative_stock']) ? 1 : 0, $ctx['business_id'])
        );
        Audit::log($ctx, 'business.update', 'business', $ctx['business_id']);
    }

    /* ---- team ---- */

    public static function team($ctx)
    {
        return Db::all(
            'SELECT ms.id, ms.role, ms.active, m.etel_id, m.full_name, m.phone FROM memberships ms JOIN members m ON m.id = ms.member_id
             WHERE ms.business_id = ? ORDER BY ms.role = ? DESC, m.full_name',
            array($ctx['business_id'], 'owner')
        );
    }

    public static function addMember($ctx, $b)
    {
        Perm::require_($ctx['role'], 'team.manage');
        $role = isset($b['role']) ? (string) $b['role'] : 'cashier';
        if ($role !== 'manager' && $role !== 'cashier') throw new ApiError(422, 'Role must be manager or cashier.');
        $ident = trim((string) (isset($b['identifier']) ? $b['identifier'] : ''));
        if ($ident === '' || strlen($ident) > 120) throw new ApiError(422, 'Enter their EtelID number or phone.');
        $biz = Db::one('SELECT * FROM businesses WHERE id = ?', array($ctx['business_id']));
        Plans::check($biz, 'users', (int) Db::val('SELECT COUNT(*) FROM memberships WHERE business_id = ? AND active = 1', array($ctx['business_id'])), 'team members');
        $m = EtelId::resolve($ident);
        if (!$m || strtolower((string) (isset($m['status']) ? $m['status'] : '')) !== 'active') {
            throw new ApiError(404, 'No active EtelID found for that number. They need an EtelID first.');
        }
        $etel = substr((string) $m['etel_id'], 0, 60);
        $row = Db::one('SELECT id FROM members WHERE etel_id = ?', array($etel));
        $memberId = $row ? (int) $row['id'] : Db::insert(
            'INSERT INTO members (etel_id, full_name, phone, created_at) VALUES (?,?,?,?)',
            array($etel, substr((string) (isset($m['full_name']) ? $m['full_name'] : ''), 0, 160), substr((string) (isset($m['phone']) ? $m['phone'] : ''), 0, 40), Db::now())
        );
        $ex = Db::one('SELECT id, role, active FROM memberships WHERE business_id = ? AND member_id = ?', array($ctx['business_id'], $memberId));
        if ($ex && $ex['role'] === 'owner') throw new ApiError(409, 'That person is the owner.');
        if ($ex) {
            Db::run('UPDATE memberships SET role = ?, active = 1 WHERE id = ?', array($role, $ex['id']));
        } else {
            Db::run('INSERT INTO memberships (business_id, member_id, role, created_at) VALUES (?,?,?,?)', array($ctx['business_id'], $memberId, $role, Db::now()));
        }
        Audit::log($ctx, 'team.add', 'member', $memberId, $etel . ' as ' . $role);
    }

    public static function setMember($ctx, $b)
    {
        Perm::require_($ctx['role'], 'team.manage');
        $id = (int) (isset($b['id']) ? $b['id'] : 0);
        $row = Db::one('SELECT id, role FROM memberships WHERE id = ? AND business_id = ?', array($id, $ctx['business_id']));
        if (!$row) throw new ApiError(404, 'Team member not found.');
        if ($row['role'] === 'owner') throw new ApiError(409, 'The owner cannot be changed.');
        $role = isset($b['role']) ? (string) $b['role'] : $row['role'];
        if ($role !== 'manager' && $role !== 'cashier') throw new ApiError(422, 'Role must be manager or cashier.');
        $active = array_key_exists('active', $b) ? ($b['active'] ? 1 : 0) : 1;
        Db::run('UPDATE memberships SET role = ?, active = ? WHERE id = ?', array($role, $active, $id));
        Audit::log($ctx, 'team.update', 'membership', $id, $role . ($active ? '' : ' (removed)'));
    }

    private static function str($b, $k, $max)
    {
        return substr(trim((string) (isset($b[$k]) ? $b[$k] : '')), 0, $max);
    }

    private static function prefix($b)
    {
        $p = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) (isset($b['invoice_prefix']) ? $b['invoice_prefix'] : 'INV')));
        return $p === '' ? 'INV' : substr($p, 0, 12);
    }
}
