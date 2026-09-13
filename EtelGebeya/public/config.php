<?php
// ============================================================
//  EtelAudit Pro -- real API keys (server only, never in git)
//  Loaded by proxy.php. Keep this file OUT of version control.
// ============================================================
declare(strict_types=1);

define('GEMINI_API_KEY', 'CHANGE_ME');
define('CLAUDE_API_KEY', 'CHANGE_ME');
define('KIMI_API_KEY',   'YOUR_KIMI_KEY_HERE');
define('OPENAI_API_KEY', 'YOUR_OPENAI_KEY_HERE');
// Must match every caller's ETEL_AUDIT_SECRET / the client-side
// PROXY_SECRET in this tool's own index.html pages.
define('AUDIT_SECRET',   'CHANGE_ME');
