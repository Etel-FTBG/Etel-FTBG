<?php
// Business Plan & Capital Requirement planning. The actual capital
// calculation reuses REAL data from equity_get_settings and
// platform_economics_get_costs (both already exist) plus the same
// payroll tax bracket math already shipped in admin-payroll.js - this
// controller only persists the two genuinely new things: the hiring
// plan line items and free-text notes.
switch ($action) {

case 'planning_list_hires': {
    $pdo = db();
    require_admin($pdo, 'planning.view');
    try {
        $s = $pdo->query('SELECT id, role_name, monthly_salary, headcount, notes FROM business_hires_plan ORDER BY sort_order ASC, id ASC');
        ok(array('hires' => $s->fetchAll(PDO::FETCH_ASSOC)));
    } catch (Exception $e) {
        fail_ex($e, 'Could not load hiring plan - has migration 045_business_plan.sql been applied?', 500);
    }
    break;
}

case 'planning_add_hire': {
    $pdo = db();
    $u = require_admin($pdo, 'planning.manage');
    $role = isset($body['role_name']) ? trim($body['role_name']) : '';
    $salary = isset($body['monthly_salary']) ? etel_validate_amount($body['monthly_salary'], false) : false;
    $headcount = isset($body['headcount']) ? intval($body['headcount']) : 1;
    $notes = isset($body['notes']) ? trim($body['notes']) : '';
    if ($role === '') fail('role_name is required', 400);
    if ($salary === false || $salary <= 0) fail('monthly_salary must be a positive amount', 400);
    if ($headcount < 1) fail('headcount must be at least 1', 400);
    try {
        $maxOrder = $pdo->query('SELECT COALESCE(MAX(sort_order),0) AS m FROM business_hires_plan')->fetch()['m'];
        $s = $pdo->prepare('INSERT INTO business_hires_plan (role_name, monthly_salary, headcount, notes, sort_order, created_by) VALUES (?,?,?,?,?,?)');
        $s->execute(array($role, $salary, $headcount, $notes, intval($maxOrder) + 1, $u['id']));
        ok(array('message' => 'Hire added to plan', 'id' => $pdo->lastInsertId()));
    } catch (Exception $e) {
        fail_ex($e, 'Could not add hire - has migration 045_business_plan.sql been applied?', 500);
    }
    break;
}

case 'planning_update_hire': {
    $pdo = db();
    require_admin($pdo, 'planning.manage');
    $id = isset($body['id']) ? intval($body['id']) : 0;
    if ($id <= 0) fail('id is required', 400);
    $role = isset($body['role_name']) ? trim($body['role_name']) : '';
    $salary = isset($body['monthly_salary']) ? etel_validate_amount($body['monthly_salary'], false) : false;
    $headcount = isset($body['headcount']) ? intval($body['headcount']) : 1;
    $notes = isset($body['notes']) ? trim($body['notes']) : '';
    if ($role === '') fail('role_name is required', 400);
    if ($salary === false || $salary <= 0) fail('monthly_salary must be a positive amount', 400);
    if ($headcount < 1) fail('headcount must be at least 1', 400);
    try {
        $pdo->prepare('UPDATE business_hires_plan SET role_name=?, monthly_salary=?, headcount=?, notes=? WHERE id=?')
            ->execute(array($role, $salary, $headcount, $notes, $id));
        ok(array('message' => 'Hire updated'));
    } catch (Exception $e) {
        fail_ex($e, 'Could not update hire', 500);
    }
    break;
}

case 'planning_delete_hire': {
    $pdo = db();
    require_admin($pdo, 'planning.manage');
    $id = isset($body['id']) ? intval($body['id']) : 0;
    if ($id <= 0) fail('id is required', 400);
    try {
        $pdo->prepare('DELETE FROM business_hires_plan WHERE id=?')->execute(array($id));
        ok(array('message' => 'Hire removed from plan'));
    } catch (Exception $e) {
        fail_ex($e, 'Could not delete hire', 500);
    }
    break;
}

case 'planning_get_notes': {
    $pdo = db();
    require_admin($pdo, 'planning.view');
    try {
        $row = $pdo->query('SELECT content, updated_at FROM business_plan_notes WHERE id=1')->fetch();
    } catch (Exception $e) {
        fail_ex($e, 'Could not load notes - has migration 045_business_plan.sql been applied?', 500);
    }
    ok(array('content' => $row ? $row['content'] : '', 'updated_at' => $row ? $row['updated_at'] : null));
    break;
}

case 'planning_save_notes': {
    $pdo = db();
    $u = require_admin($pdo, 'planning.manage');
    $content = isset($body['content']) ? $body['content'] : '';
    try {
        $pdo->prepare('UPDATE business_plan_notes SET content=?, updated_by=? WHERE id=1')->execute(array($content, $u['id']));
        ok(array('message' => 'Notes saved'));
    } catch (Exception $e) {
        fail_ex($e, 'Could not save notes', 500);
    }
    break;
}

case 'planning_reset_and_seed_demo': {
    // Wipes the hiring plan and notes, seeds a realistic team sized
    // for the kind of volume modeled in the Growth & Goal Planner
    // (200 drivers, ~72,000 trips/month), and also fills in Platform
    // Economics' cost assumptions with reasonable demo figures for
    // that same scale - so a generated report shows a complete,
    // honest picture instead of real operational costs sitting
    // silently at zero. All figures are estimates for demonstration,
    // not real quotes - the founder should replace them with real
    // numbers before using this for anything but a demo.
    $pdo = db();
    $u = require_admin($pdo, 'planning.manage');
    $demoHires = array(
        array('Operations Manager', 25000, 1, 'Oversees day-to-day driver/courier operations and support team'),
        array('Customer Support Agent', 10000, 3, 'Handles tickets, disputes, and driver onboarding at this volume'),
        array('Mid-Level PHP Developer', 28000, 1, 'Feature development and platform maintenance'),
        array('Junior PHP Developer', 18000, 1, 'Bug fixes and smaller features, mentored by the mid-level dev'),
        array('Driver & Courier Field Recruiter', 10000, 2, 'Base salary - add a per-recruit bonus on top; 2 recruiters for 200-driver scale'),
        array('Finance / Bookkeeper (part-time)', 12000, 1, 'Payroll, pension remittance, Equb and investor record-keeping'),
        array('Marketing / Growth Associate', 15000, 1, 'Customer acquisition, promotions, partnership outreach'),
    );
    $demoCosts = array(
        'payment_processing_pct' => 2.0, // typical mobile-money/bank gateway fee range in Ethiopia - verify your actual provider's rate
        'monthly_customer_acquisition' => 25000,
        'monthly_hosting_cost' => 6000,
        'monthly_support_cost' => 3000, // tools/software, separate from the Support Agent salaries already in payroll
        'monthly_incentive_budget' => 40000, // driver bonuses/promotions - meaningful at 200-driver scale
        'monthly_refunds_estimate' => 8000,
        'monthly_admin_overhead' => 10000, // office, legal, misc
    );
    try {
        $pdo->beginTransaction();
        $pdo->exec('DELETE FROM business_hires_plan');
        $s = $pdo->prepare('INSERT INTO business_hires_plan (role_name, monthly_salary, headcount, notes, sort_order, created_by) VALUES (?,?,?,?,?,?)');
        foreach ($demoHires as $i => $h) {
            $s->execute(array($h[0], $h[1], $h[2], $h[3], $i + 1, $u['id']));
        }
        $costStmt = $pdo->prepare('UPDATE platform_cost_assumptions SET
            payment_processing_pct=:payment_processing_pct, monthly_customer_acquisition=:monthly_customer_acquisition,
            monthly_hosting_cost=:monthly_hosting_cost, monthly_support_cost=:monthly_support_cost,
            monthly_incentive_budget=:monthly_incentive_budget, monthly_refunds_estimate=:monthly_refunds_estimate,
            monthly_admin_overhead=:monthly_admin_overhead, updated_by=:updated_by WHERE id=1');
        $demoCosts['updated_by'] = $u['id'];
        $costStmt->execute($demoCosts);
        $demoNotes = "DEMO DATA — Sample hiring plan and operational costs for illustration, sized for the "
            . "200-driver / ~72,000-trips-per-month scenario in the Growth & Goal Planner.\n\n"
            . "Hiring plan: Operations Manager, 3 Support Agents, a Mid + Junior developer pair, 2 Field Recruiters, "
            . "a part-time Finance/Bookkeeper, and a Marketing/Growth Associate.\n\n"
            . "Platform Economics costs: 2% payment processing (verify your real provider's rate), plus estimated "
            . "monthly acquisition, hosting, support tools, driver incentives, refunds, and admin overhead - all "
            . "editable in Platform Economics → Edit Cost Assumptions.\n\n"
            . "Go to Growth & Goal Planner, use these same 200 drivers / 20 trips assumptions (or your own), and "
            . "click \"Use This Month's Revenue\" to generate a complete Financial Snapshot PDF with real numbers "
            . "across all four areas instead of a zero-cost placeholder.\n\n"
            . "Replace all of this with your real numbers once you're ready to act on it - use Reset again to clear it.";
        $pdo->prepare('UPDATE business_plan_notes SET content=?, updated_by=? WHERE id=1')->execute(array($demoNotes, $u['id']));
        $pdo->commit();
        ok(array('message' => 'Demo hiring plan and operational costs seeded'));
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fail_ex($e, 'Could not seed demo data', 500);
    }
    break;
}

}
