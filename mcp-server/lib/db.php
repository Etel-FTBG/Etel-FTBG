<?php
/**
 * Read-only DB access per system. Connections are opened lazily and cached
 * per request. Every query goes through mcp_db_select(), which enforces
 * SELECT-only, single-statement, and a hard row cap - this server is a
 * read/inspection tool, not a general SQL console.
 */

function mcp_db_connect($systemKey) {
    static $cache = array();

    if (isset($cache[$systemKey])) {
        return $cache[$systemKey];
    }

    $registry = mcp_db_registry();
    if (!isset($registry[$systemKey])) {
        throw new InvalidArgumentException("Unknown system: $systemKey");
    }

    $cfg = $registry[$systemKey];
    if (!$cfg['name']) {
        throw new RuntimeException("No DB configured for {$cfg['label']} (set {$systemKey}_DB_NAME in .env)");
    }

    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $cfg['host'], $cfg['name']);
    $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], array(
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5,
    ));

    $cache[$systemKey] = $pdo;
    return $pdo;
}

function mcp_db_prefix($systemKey) {
    $registry = mcp_db_registry();
    return isset($registry[$systemKey]) ? $registry[$systemKey]['prefix'] : '';
}

/**
 * Execute a read-only SELECT. Rejects anything that isn't a single SELECT
 * statement (no writes, no stacked queries, no INTO OUTFILE) and caps rows.
 */
function mcp_db_select($systemKey, $sql, array $params = array(), $maxRows = 200) {
    $trimmed = ltrim($sql);
    if (!preg_match('/^SELECT\s/i', $trimmed)) {
        throw new InvalidArgumentException('Only SELECT statements are allowed.');
    }
    if (strpos($sql, ';') !== false) {
        throw new InvalidArgumentException('Multiple/stacked statements are not allowed.');
    }
    if (preg_match('/\b(INTO\s+OUTFILE|INTO\s+DUMPFILE|LOAD_FILE)\b/i', $sql)) {
        throw new InvalidArgumentException('File I/O functions are not allowed.');
    }

    $maxRows = max(1, min((int) $maxRows, 500));

    $pdo = mcp_db_connect($systemKey);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $rows = array();
    $count = 0;
    while ($count < $maxRows && ($row = $stmt->fetch())) {
        $rows[] = $row;
        $count++;
    }
    $truncated = ($stmt->fetch() !== false);

    return array('rows' => $rows, 'row_count' => count($rows), 'truncated' => $truncated);
}
