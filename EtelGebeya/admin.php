<?php
// ============================================================
//  ETEL GEBEYA -- Admin API (root alias)
//  Delegates to the canonical implementation in api/admin.php
//  to avoid duplicated logic between the two entry points.
// ============================================================
declare(strict_types=1);
require __DIR__ . '/api/admin.php';
