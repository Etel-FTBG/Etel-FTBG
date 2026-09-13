<?php
require_once __DIR__ . '/../lib/wallet_ops.php';
require_once __DIR__ . '/../lib/equb_processing.php';

// Digital Equb - access is admin-controlled (mirrors the investor
// EtelID-link model): a person must be explicitly permitted before
// they can create or join any group. Real money moves automatically
// via the wallet - see lib/equb_processing.php for the actual
// per-round debit/payout logic, shared with equb_cron.php.

function equb_require_permitted($pdo, $userId) {
    try {
        $s = $pdo->prepare("SELECT status FROM equb_participants WHERE user_id=?");
        $s->execute(array($userId));
        $row = $s->fetch();
    } catch (Exception $e) {
        fail('Equb is not set up yet - has migration 041_equb_participants.sql been applied?', 500);
    }
    if (!$row || $row['status'] !== 'permitted') {
        fail('You need admin approval to use Equb. Contact the founder.', 403);
    }
}

switch ($action) {

case 'equb_am_i_permitted': {
    // Cheap check any app can call to decide whether to show the
    // floating Equb button at all - same pattern as the investor
    // portal's am_i_investor check.
    $pdo = db();
    $u = require_auth($pdo);
    try {
        $s = $pdo->prepare("SELECT status FROM equb_participants WHERE user_id=?");
        $s->execute(array($u['id']));
        $row = $s->fetch();
    } catch (Exception $e) {
        ok(array('is_permitted' => false)); // migration not applied yet - fail safe
        break;
    }
    ok(array('is_permitted' => (bool) ($row && $row['status'] === 'permitted')));
    break;
}

case 'equb_create_group': {
    $pdo = db();
    $admin = require_admin($pdo, 'equb.manage');
    $name = isset($body['name']) ? trim($body['name']) : '';
    $amount = isset($body['contribution_amount']) ? etel_validate_amount($body['contribution_amount'], false) : false;
    $frequency = isset($body['frequency']) ? $body['frequency'] : 'weekly';
    $maxMembers = isset($body['max_members']) ? intval($body['max_members']) : 0;
    $payoutOrder = isset($body['payout_order']) ? $body['payout_order'] : 'lottery';
    if ($name === '') fail('Group name is required', 400);
    if ($amount === false || $amount <= 0) fail('contribution_amount must be a positive amount', 400);
    if (!in_array($frequency, array('weekly', 'biweekly', 'monthly'), true)) fail('frequency must be weekly, biweekly, or monthly', 400);
    if ($maxMembers < 2 || $maxMembers > 50) fail('max_members must be between 2 and 50', 400);
    if (!in_array($payoutOrder, array('lottery', 'join_order'), true)) fail('payout_order must be lottery or join_order', 400);
    try {
        $s = $pdo->prepare('INSERT INTO equb_groups (name, created_by, contribution_amount, frequency, max_members, payout_order) VALUES (?,?,?,?,?,?)');
        $s->execute(array($name, $admin['id'], $amount, $frequency, $maxMembers, $payoutOrder));
        $groupId = $pdo->lastInsertId();
        // The admin is the organizer/controller, not automatically a
        // participant - unlike the earlier self-service design. If the
        // admin also wants to be in the group personally, they join
        // like anyone else via equb_join_group.
        ok(array('message' => 'Equb group created - add members or wait for permitted users to join', 'group_id' => $groupId));
    } catch (Exception $e) {
        fail_ex($e, 'Could not create group - has migration 040_equb_system.sql been applied?', 500);
    }
    break;
}

case 'equb_join_group': {
    $pdo = db();
    $u = require_auth($pdo);
    equb_require_permitted($pdo, $u['id']);
    $groupId = isset($body['group_id']) ? intval($body['group_id']) : 0;
    if ($groupId <= 0) fail('group_id is required', 400);
    try {
        $pdo->beginTransaction();
        $g = $pdo->prepare('SELECT * FROM equb_groups WHERE id=? FOR UPDATE');
        $g->execute(array($groupId));
        $group = $g->fetch();
        if (!$group) { $pdo->rollBack(); fail('Group not found', 404); }
        if ($group['status'] !== 'forming') { $pdo->rollBack(); fail('This group has already started and is no longer accepting new members', 400); }
        $c = $pdo->prepare('SELECT COUNT(*) AS n FROM equb_members WHERE group_id=? AND status=\'active\'');
        $c->execute(array($groupId));
        $count = intval($c->fetch()['n']);
        if ($count >= $group['max_members']) { $pdo->rollBack(); fail('This group is full', 400); }
        try {
            $pdo->prepare('INSERT INTO equb_members (group_id, user_id, join_order) VALUES (?,?,?)')->execute(array($groupId, $u['id'], $count + 1));
        } catch (Exception $dupe) {
            $pdo->rollBack();
            fail('You have already joined this group', 400);
        }
        $pdo->commit();
        ok(array('message' => 'Joined group', 'members_count' => $count + 1, 'max_members' => intval($group['max_members'])));
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fail_ex($e, 'Could not join group', 500);
    }
    break;
}

case 'equb_leave_group': {
    $pdo = db();
    $u = require_auth($pdo);
    $groupId = isset($body['group_id']) ? intval($body['group_id']) : 0;
    if ($groupId <= 0) fail('group_id is required', 400);
    try {
        $g = $pdo->prepare('SELECT status, created_by FROM equb_groups WHERE id=?');
        $g->execute(array($groupId));
        $group = $g->fetch();
        if (!$group) fail('Group not found', 404);
        // Leaving is only allowed before the group starts - once
        // active, payout rounds are already assigned and a member
        // leaving mid-cycle is a real financial/trust problem that
        // needs a human (admin) decision, not a self-service button.
        if ($group['status'] !== 'forming') fail('You can only leave a group before it starts. Once active, contact an admin.', 400);
        if (intval($group['created_by']) === $u['id']) fail('As the creator, cancel the group instead of leaving it (Equb admin tool)', 400);
        $pdo->prepare("UPDATE equb_members SET status='left' WHERE group_id=? AND user_id=?")->execute(array($groupId, $u['id']));
        ok(array('message' => 'Left the group'));
    } catch (Exception $e) {
        fail_ex($e, 'Could not leave group', 500);
    }
    break;
}

case 'equb_start_group': {
    $pdo = db();
    require_admin($pdo, 'equb.manage');
    $groupId = isset($body['group_id']) ? intval($body['group_id']) : 0;
    if ($groupId <= 0) fail('group_id is required', 400);
    try {
        $pdo->beginTransaction();
        $g = $pdo->prepare('SELECT * FROM equb_groups WHERE id=? FOR UPDATE');
        $g->execute(array($groupId));
        $group = $g->fetch();
        if (!$group) { $pdo->rollBack(); fail('Group not found', 404); }
        if ($group['status'] !== 'forming') { $pdo->rollBack(); fail('Group has already started', 400); }
        $m = $pdo->prepare("SELECT id, user_id FROM equb_members WHERE group_id=? AND status='active' ORDER BY join_order ASC");
        $m->execute(array($groupId));
        $members = $m->fetchAll(PDO::FETCH_ASSOC);
        if (count($members) < 2) { $pdo->rollBack(); fail('Need at least 2 members to start', 400); }

        // Assign which round each member receives their payout in.
        // Lottery = random shuffle (fair, no one can game their
        // position); join_order = first-to-join gets round 1, etc.
        $order = range(1, count($members));
        if ($group['payout_order'] === 'lottery') {
            shuffle($order);
        }
        $stmt = $pdo->prepare('UPDATE equb_members SET payout_round=? WHERE id=?');
        foreach ($members as $i => $mem) {
            $stmt->execute(array($order[$i], $mem['id']));
        }

        $nextDate = date('Y-m-d'); // first round's contributions are due immediately; equb_cron.php picks this up on its next run
        $pdo->prepare("UPDATE equb_groups SET status='active', current_round=1, total_rounds=?, next_contribution_date=?, started_at=NOW() WHERE id=?")
            ->execute(array(count($members), $nextDate, $groupId));

        // Pre-create round 1's pending contribution rows so the cron
        // job and the group-detail view both have something concrete
        // to check off, rather than inferring "who owes what" implicitly.
        $cstmt = $pdo->prepare("INSERT INTO equb_contributions (group_id, member_id, round_number, amount, status) VALUES (?,?,1,?,'pending')");
        foreach ($members as $mem) {
            $cstmt->execute(array($groupId, $mem['id'], $group['contribution_amount']));
        }
        $pdo->commit();
        ok(array('message' => 'Group started', 'total_rounds' => count($members), 'first_contribution_date' => $nextDate));
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fail_ex($e, 'Could not start group', 500);
    }
    break;
}

case 'equb_get_my_groups': {
    $pdo = db();
    $u = require_auth($pdo);
    try {
        $s = $pdo->prepare("SELECT g.id, g.name, g.contribution_amount, g.frequency, g.max_members, g.status, g.current_round, g.total_rounds, g.next_contribution_date, em.payout_round AS my_payout_round,
            (SELECT COUNT(*) FROM equb_members WHERE group_id=g.id AND status='active') AS members_count
            FROM equb_groups g JOIN equb_members em ON em.group_id=g.id AND em.user_id=? AND em.status='active'
            ORDER BY g.created_at DESC");
        $s->execute(array($u['id']));
        ok(array('groups' => $s->fetchAll(PDO::FETCH_ASSOC)));
    } catch (Exception $e) {
        fail_ex($e, 'Could not load your groups - has migration 040_equb_system.sql been applied?', 500);
    }
    break;
}

case 'equb_get_group_detail': {
    $pdo = db();
    $u = require_auth($pdo);
    $groupId = isset($body['group_id']) ? intval($body['group_id']) : (isset($_GET['group_id']) ? intval($_GET['group_id']) : 0);
    if ($groupId <= 0) fail('group_id is required', 400);
    try {
        $g = $pdo->prepare('SELECT * FROM equb_groups WHERE id=?');
        $g->execute(array($groupId));
        $group = $g->fetch();
        if (!$group) fail('Group not found', 404);
        $mCheck = $pdo->prepare('SELECT id FROM equb_members WHERE group_id=? AND user_id=?');
        $mCheck->execute(array($groupId, $u['id']));
        if (!$mCheck->fetch()) fail('You are not a member of this group', 403);

        $m = $pdo->prepare("SELECT em.id, em.user_id, u.name, em.join_order, em.payout_round, em.status,
            (SELECT status FROM equb_contributions WHERE group_id=? AND member_id=em.id AND round_number=? LIMIT 1) AS current_round_status
            FROM equb_members em JOIN users u ON u.id=em.user_id WHERE em.group_id=? ORDER BY em.join_order ASC");
        $m->execute(array($groupId, $group['current_round'], $groupId));
        $members = $m->fetchAll(PDO::FETCH_ASSOC);

        $p = $pdo->prepare('SELECT ep.round_number, ep.amount, ep.paid_at, u.name AS recipient_name FROM equb_payouts ep
            JOIN equb_members em ON em.id=ep.recipient_member_id JOIN users u ON u.id=em.user_id
            WHERE ep.group_id=? ORDER BY ep.round_number ASC');
        $p->execute(array($groupId));
        $payouts = $p->fetchAll(PDO::FETCH_ASSOC);

        ok(array('group' => $group, 'members' => $members, 'payouts' => $payouts));
    } catch (Exception $e) {
        fail_ex($e, 'Could not load group', 500);
    }
    break;
}

case 'equb_admin_create_package': {
    $pdo = db();
    $admin = require_admin($pdo, 'equb.manage');
    $name = isset($body['name']) ? trim($body['name']) : '';
    $tier = isset($body['tier']) ? $body['tier'] : 'bronze';
    $description = isset($body['description']) ? trim($body['description']) : '';
    $amount = isset($body['contribution_amount']) ? etel_validate_amount($body['contribution_amount'], false) : false;
    $frequency = isset($body['frequency']) ? $body['frequency'] : 'weekly';
    $maxMembers = isset($body['max_members']) ? intval($body['max_members']) : 0;
    $payoutOrder = isset($body['payout_order']) ? $body['payout_order'] : 'lottery';
    if ($name === '') fail('Package name is required', 400);
    if (!in_array($tier, array('bronze', 'silver', 'gold', 'platinum'), true)) fail('Invalid tier', 400);
    if ($amount === false || $amount <= 0) fail('contribution_amount must be a positive amount', 400);
    if (!in_array($frequency, array('weekly', 'biweekly', 'monthly'), true)) fail('Invalid frequency', 400);
    if ($maxMembers < 2 || $maxMembers > 50) fail('max_members must be between 2 and 50', 400);
    if (!in_array($payoutOrder, array('lottery', 'join_order'), true)) fail('Invalid payout_order', 400);
    try {
        $s = $pdo->prepare('INSERT INTO equb_packages (name, tier, description, contribution_amount, frequency, max_members, payout_order, created_by) VALUES (?,?,?,?,?,?,?,?)');
        $s->execute(array($name, $tier, $description, $amount, $frequency, $maxMembers, $payoutOrder, $admin['id']));
        ok(array('message' => 'Package created', 'package_id' => $pdo->lastInsertId()));
    } catch (Exception $e) {
        fail_ex($e, 'Could not create package - has migration 042_equb_packages.sql been applied?', 500);
    }
    break;
}

case 'equb_admin_update_package': {
    $pdo = db();
    require_admin($pdo, 'equb.manage');
    $id = isset($body['id']) ? intval($body['id']) : 0;
    $active = isset($body['active']) ? (bool) $body['active'] : true;
    if ($id <= 0) fail('id is required', 400);
    try {
        $pdo->prepare('UPDATE equb_packages SET active=? WHERE id=?')->execute(array($active ? 1 : 0, $id));
        ok(array('message' => $active ? 'Package activated' : 'Package deactivated'));
    } catch (Exception $e) {
        fail_ex($e, 'Could not update package', 500);
    }
    break;
}

case 'equb_admin_list_packages': {
    $pdo = db();
    require_admin($pdo, 'equb.view');
    try {
        $s = $pdo->query("SELECT p.*, (SELECT COUNT(*) FROM equb_groups WHERE package_id=p.id) AS groups_spawned
            FROM equb_packages p ORDER BY p.created_at DESC");
        ok(array('packages' => $s->fetchAll(PDO::FETCH_ASSOC)));
    } catch (Exception $e) {
        fail_ex($e, 'Could not load packages - has migration 042_equb_packages.sql been applied?', 500);
    }
    break;
}

case 'equb_list_packages': {
    // Browsing for permitted users - only active packages, with how
    // many groups from this package still have room, so someone can
    // see at a glance whether picking this tier means joining an
    // existing group or starting a fresh one.
    $pdo = db();
    $u = require_auth($pdo);
    equb_require_permitted($pdo, $u['id']);
    try {
        $s = $pdo->query("SELECT * FROM equb_packages WHERE active=1 ORDER BY contribution_amount ASC");
        $packages = $s->fetchAll(PDO::FETCH_ASSOC);
        foreach ($packages as &$p) {
            $c = $pdo->prepare("SELECT COUNT(*) AS n FROM equb_groups WHERE package_id=? AND status='forming'
                AND (SELECT COUNT(*) FROM equb_members WHERE group_id=equb_groups.id AND status='active') < max_members");
            $c->execute(array($p['id']));
            $p['open_groups_waiting'] = intval($c->fetch()['n']);
        }
        ok(array('packages' => $packages));
    } catch (Exception $e) {
        fail_ex($e, 'Could not load packages', 500);
    }
    break;
}

case 'equb_select_package': {
    // The self-service heart of the package system: find an existing
    // forming group spun up from this package with room, and join it;
    // otherwise auto-create a new group instance from the package
    // template and join as its first member. Either way, the terms
    // come entirely from the admin-approved package - the user never
    // invents their own contribution amount or member count.
    $pdo = db();
    $u = require_auth($pdo);
    equb_require_permitted($pdo, $u['id']);
    $packageId = isset($body['package_id']) ? intval($body['package_id']) : 0;
    if ($packageId <= 0) fail('package_id is required', 400);
    try {
        $pdo->beginTransaction();
        $pkg = $pdo->prepare('SELECT * FROM equb_packages WHERE id=? AND active=1 FOR UPDATE');
        $pkg->execute(array($packageId));
        $package = $pkg->fetch();
        if (!$package) { $pdo->rollBack(); fail('Package not found or no longer available', 404); }

        // Look for an existing forming group from this package with room.
        $existing = $pdo->prepare("SELECT g.* FROM equb_groups g WHERE g.package_id=? AND g.status='forming'
            AND (SELECT COUNT(*) FROM equb_members WHERE group_id=g.id AND status='active') < g.max_members
            ORDER BY g.created_at ASC LIMIT 1 FOR UPDATE");
        $existing->execute(array($packageId));
        $group = $existing->fetch();

        if (!$group) {
            // None available - spin up a new instance from the template.
            $ins = $pdo->prepare('INSERT INTO equb_groups (package_id, name, created_by, contribution_amount, frequency, max_members, payout_order) VALUES (?,?,?,?,?,?,?)');
            $ins->execute(array($packageId, $package['name'], $package['created_by'], $package['contribution_amount'], $package['frequency'], $package['max_members'], $package['payout_order']));
            $groupId = $pdo->lastInsertId();
            $joinOrder = 1;
        } else {
            $groupId = $group['id'];
            $c = $pdo->prepare("SELECT COUNT(*) AS n FROM equb_members WHERE group_id=? AND status='active'");
            $c->execute(array($groupId));
            $joinOrder = intval($c->fetch()['n']) + 1;
        }

        try {
            $pdo->prepare('INSERT INTO equb_members (group_id, user_id, join_order) VALUES (?,?,?)')->execute(array($groupId, $u['id'], $joinOrder));
        } catch (Exception $dupe) {
            $pdo->rollBack();
            fail('You are already in a group from this package', 400);
        }
        $pdo->commit();
        ok(array('message' => 'Joined "' . $package['name'] . '"', 'group_id' => $groupId));
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fail_ex($e, 'Could not select package', 500);
    }
    break;
}

case 'equb_admin_add_member': {
    $pdo = db();
    require_admin($pdo, 'equb.manage');
    $groupId = isset($body['group_id']) ? intval($body['group_id']) : 0;
    $phone = isset($body['phone']) ? preg_replace('/[^0-9+]/', '', trim($body['phone'])) : '';
    if ($groupId <= 0) fail('group_id is required', 400);
    if ($phone === '') fail('phone is required', 400);
    try {
        $pdo->beginTransaction();
        $g = $pdo->prepare('SELECT * FROM equb_groups WHERE id=? FOR UPDATE');
        $g->execute(array($groupId));
        $group = $g->fetch();
        if (!$group) { $pdo->rollBack(); fail('Group not found', 404); }
        if ($group['status'] !== 'forming') { $pdo->rollBack(); fail('Can only add members before the group starts', 400); }
        $u = $pdo->prepare('SELECT id, name FROM users WHERE phone=?');
        $u->execute(array($phone));
        $user = $u->fetch();
        if (!$user) { $pdo->rollBack(); fail('No EtelID account found with that phone number', 404); }
        $p = $pdo->prepare("SELECT status FROM equb_participants WHERE user_id=?");
        $p->execute(array($user['id']));
        $perm = $p->fetch();
        if (!$perm || $perm['status'] !== 'permitted') { $pdo->rollBack(); fail($user['name'] . ' has not been granted Equb access yet - grant them access first', 400); }
        $c = $pdo->prepare("SELECT COUNT(*) AS n FROM equb_members WHERE group_id=? AND status='active'");
        $c->execute(array($groupId));
        $count = intval($c->fetch()['n']);
        if ($count >= $group['max_members']) { $pdo->rollBack(); fail('Group is already full', 400); }
        try {
            $pdo->prepare('INSERT INTO equb_members (group_id, user_id, join_order) VALUES (?,?,?)')->execute(array($groupId, $user['id'], $count + 1));
        } catch (Exception $dupe) {
            $pdo->rollBack();
            fail($user['name'] . ' is already in this group', 400);
        }
        $pdo->commit();
        ok(array('message' => $user['name'] . ' added to the group', 'members_count' => $count + 1));
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fail_ex($e, 'Could not add member', 500);
    }
    break;
}

case 'equb_list_open_groups': {
    // Discovery for permitted users - groups still 'forming' (not yet
    // started, not full) that this person hasn't already joined, so
    // people can actually find a group to join rather than needing
    // a group_id passed out-of-band.
    $pdo = db();
    $u = require_auth($pdo);
    equb_require_permitted($pdo, $u['id']);
    try {
        $s = $pdo->prepare("SELECT g.id, g.name, g.contribution_amount, g.frequency, g.max_members, u.name AS creator_name,
            (SELECT COUNT(*) FROM equb_members WHERE group_id=g.id AND status='active') AS members_count
            FROM equb_groups g JOIN users u ON u.id=g.created_by
            WHERE g.status='forming'
            AND g.id NOT IN (SELECT group_id FROM equb_members WHERE user_id=? AND status='active')
            HAVING members_count < g.max_members
            ORDER BY g.created_at DESC");
        $s->execute(array($u['id']));
        ok(array('groups' => $s->fetchAll(PDO::FETCH_ASSOC)));
    } catch (Exception $e) {
        fail_ex($e, 'Could not load open groups', 500);
    }
    break;
}

case 'equb_admin_grant_access': {
    $pdo = db();
    $admin = require_admin($pdo, 'equb.manage');
    $phone = isset($body['phone']) ? preg_replace('/[^0-9+]/', '', trim($body['phone'])) : '';
    if ($phone === '') fail('phone is required', 400);
    try {
        $u = $pdo->prepare('SELECT id, name FROM users WHERE phone=?');
        $u->execute(array($phone));
        $user = $u->fetch();
        if (!$user) fail('No EtelID account found with that phone number', 404);
        $pdo->prepare("INSERT INTO equb_participants (user_id, status, permitted_by) VALUES (?,'permitted',?)
            ON DUPLICATE KEY UPDATE status='permitted', permitted_by=VALUES(permitted_by), permitted_at=NOW()")
            ->execute(array($user['id'], $admin['id']));
        ok(array('message' => $user['name'] . ' can now use Equb', 'user_id' => $user['id'], 'user_name' => $user['name']));
    } catch (Exception $e) {
        fail_ex($e, 'Could not grant access - has migration 041_equb_participants.sql been applied?', 500);
    }
    break;
}

case 'equb_admin_revoke_access': {
    $pdo = db();
    require_admin($pdo, 'equb.manage');
    $userId = isset($body['user_id']) ? intval($body['user_id']) : 0;
    if ($userId <= 0) fail('user_id is required', 400);
    try {
        $pdo->prepare("UPDATE equb_participants SET status='revoked' WHERE user_id=?")->execute(array($userId));
        ok(array('message' => 'Access revoked'));
    } catch (Exception $e) {
        fail_ex($e, 'Could not revoke access', 500);
    }
    break;
}

case 'equb_admin_list_participants': {
    $pdo = db();
    require_admin($pdo, 'equb.view');
    try {
        $s = $pdo->query("SELECT ep.id, ep.user_id, ep.status, ep.permitted_at, u.name, u.phone,
            (SELECT COUNT(*) FROM equb_members WHERE user_id=ep.user_id AND status='active') AS active_groups
            FROM equb_participants ep JOIN users u ON u.id=ep.user_id ORDER BY ep.permitted_at DESC");
        ok(array('participants' => $s->fetchAll(PDO::FETCH_ASSOC)));
    } catch (Exception $e) {
        fail_ex($e, 'Could not load participants - has migration 041_equb_participants.sql been applied?', 500);
    }
    break;
}

case 'equb_admin_force_process_round': {
    // Manually trigger the same processing a scheduled cron run would
    // do, for one specific group - useful for recovering an 'at_risk'
    // group after chasing down a missing member, or for testing
    // before a real cron job is scheduled. Uses the exact same
    // function equb_cron.php calls, so behavior can never drift
    // between the two entry points.
    $pdo = db();
    require_admin($pdo, 'equb.manage');
    $groupId = isset($body['group_id']) ? intval($body['group_id']) : 0;
    if ($groupId <= 0) fail('group_id is required', 400);
    $log = equb_process_group_round($pdo, $groupId);
    ok(array('log' => $log));
    break;
}

case 'equb_admin_list_groups': {
    $pdo = db();
    require_admin($pdo, 'equb.view');
    try {
        $s = $pdo->query("SELECT g.*, (SELECT COUNT(*) FROM equb_members WHERE group_id=g.id AND status='active') AS members_count,
            u.name AS creator_name FROM equb_groups g JOIN users u ON u.id=g.created_by ORDER BY g.created_at DESC");
        ok(array('groups' => $s->fetchAll(PDO::FETCH_ASSOC)));
    } catch (Exception $e) {
        fail_ex($e, 'Could not load groups - has migration 040_equb_system.sql been applied?', 500);
    }
    break;
}

}
