<?php
/**
 * Tool definitions and handlers, namespaced per company. Each *_query tool
 * shares the same shape (system-scoped read-only SELECT via mcp_db_select).
 */

function mcp_public_systems() {
    return array('etelid', 'etelgroup', 'etelpay', 'delivery', 'eteltaxi', 'etelgebeya', 'etelmadeya');
}

function mcp_tool_list() {
    $tools = array();

    $labels = array();
    foreach (mcp_db_registry() as $key => $cfg) {
        $labels[$key] = $cfg['label'];
    }

    foreach (mcp_public_systems() as $key) {
        $tools[] = array(
            'name' => $key . '_query',
            'description' => "Run a read-only SELECT query against {$labels[$key]}'s database. "
                . "Table prefix (if any): '" . mcp_db_prefix($key) . "'. Max 500 rows per call.",
            'inputSchema' => array(
                'type' => 'object',
                'properties' => array(
                    'sql' => array(
                        'type' => 'string',
                        'description' => 'A single SELECT statement. Use ? placeholders and the params array for values.',
                    ),
                    'params' => array(
                        'type' => 'array',
                        'items' => array('type' => array('string', 'number', 'null')),
                        'description' => 'Positional values for ? placeholders in sql.',
                    ),
                    'max_rows' => array('type' => 'integer', 'description' => 'Row cap, default 200, hard max 500.'),
                ),
                'required' => array('sql'),
            ),
        );
    }

    $tools[] = array(
        'name' => 'etelid_check_role',
        'description' => 'Check whether an EtelID member holds a company role, via EtelID Service API check_role. '
            . 'Use the canonical company slug (etel-group, etel-pay, etel-delivery, etel-transport [=EtelTaxi], '
            . 'etel-market [=EtelGebeya], etel-madeya, etel-media, luxline), not the marketing name.',
        'inputSchema' => array(
            'type' => 'object',
            'properties' => array(
                'member_id' => array('type' => 'string', 'description' => 'EtelID member id or phone number.'),
                'company_slug' => array('type' => 'string'),
            ),
            'required' => array('member_id', 'company_slug'),
        ),
    );

    $tools[] = array(
        'name' => 'etelid_list_company_roles',
        'description' => 'List all role assignments for a company via EtelID Service API list_company_roles. '
            . 'Use the canonical company slug, not the marketing name.',
        'inputSchema' => array(
            'type' => 'object',
            'properties' => array('company_slug' => array('type' => 'string')),
            'required' => array('company_slug'),
        ),
    );

    $tools[] = array(
        'name' => 'system_health',
        'description' => 'HTTP health check across one or all Etel Group systems (reachability + response time only, no auth).',
        'inputSchema' => array(
            'type' => 'object',
            'properties' => array(
                'system' => array(
                    'type' => 'string',
                    'description' => 'One of: ' . implode(', ', mcp_public_systems()) . ', or "all" (default).',
                ),
            ),
        ),
    );

    return $tools;
}

function mcp_tool_call($name, $arguments) {
    $arguments = is_array($arguments) ? $arguments : array();

    if (substr($name, -6) === '_query') {
        $systemKey = substr($name, 0, -6);
        if (!in_array($systemKey, mcp_public_systems(), true)) {
            throw new InvalidArgumentException("Unknown tool: $name");
        }
        if (empty($arguments['sql'])) {
            throw new InvalidArgumentException('Missing required argument: sql');
        }
        $params = isset($arguments['params']) && is_array($arguments['params']) ? $arguments['params'] : array();
        $maxRows = isset($arguments['max_rows']) ? (int) $arguments['max_rows'] : 200;
        return mcp_db_select($systemKey, $arguments['sql'], $params, $maxRows);
    }

    switch ($name) {
        case 'etelid_check_role':
            if (empty($arguments['member_id']) || empty($arguments['company_slug'])) {
                throw new InvalidArgumentException('member_id and company_slug are required.');
            }
            return mcp_etelid_call('check_role', array(
                'member_id' => $arguments['member_id'],
                'company_slug' => $arguments['company_slug'],
            ));

        case 'etelid_list_company_roles':
            if (empty($arguments['company_slug'])) {
                throw new InvalidArgumentException('company_slug is required.');
            }
            return mcp_etelid_call('list_company_roles', array('company_slug' => $arguments['company_slug']));

        case 'system_health':
            return mcp_system_health(isset($arguments['system']) ? $arguments['system'] : 'all');

        default:
            throw new InvalidArgumentException("Unknown tool: $name");
    }
}

function mcp_system_health($which) {
    $urls = array(
        'etelid' => 'https://ethelmarket.com/EtelId/',
        'etelgroup' => 'https://ethelmarket.com/',
        'etelpay' => 'https://ethelmarket.com/Etel.Pay/wallet/',
        'delivery' => 'https://ethelmarket.com/delivery/',
        'eteltaxi' => 'https://ethelmarket.com/EtelTaxi/',
        'etelgebeya' => 'https://ethelmarket.com/EtelGebeya/',
        'etelmadeya' => 'https://ethelmarket.com/EtelMadeya/',
    );

    $targets = ($which === 'all' || !$which) ? array_keys($urls) : array($which);
    $results = array();

    foreach ($targets as $key) {
        if (!isset($urls[$key])) {
            $results[$key] = array('error' => 'unknown system');
            continue;
        }
        $start = microtime(true);
        $ch = curl_init($urls[$key]);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => true,
        ));
        curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        curl_close($ch);
        $ms = round((microtime(true) - $start) * 1000);

        $results[$key] = $errno
            ? array('reachable' => false, 'error' => $err)
            : array('reachable' => $status > 0 && $status < 500, 'http_status' => $status, 'ms' => $ms);
    }

    return $results;
}
