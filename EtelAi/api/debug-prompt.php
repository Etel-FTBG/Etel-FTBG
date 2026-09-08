<?php
// TEMPORARY DIAGNOSTIC - shows the exact system prompt chat.php is
// currently building and sending to the AI, so we can confirm whether
// a given code change actually took effect on the live server, instead
// of guessing from the AI's behavior alone. Delete this file afterward.
header('Content-Type: application/json');

// Reuse chat.php's own buildSystemPrompt() function without triggering
// its request dispatch - chat.php's functions are defined before its
// dispatch logic runs, but requiring it directly would still execute
// that dispatch. Instead, extract and eval just the function
// definitions by reading the file up to (not including) the dispatch
// section, matching the same safety approach used for boot.php earlier.
$src = file_get_contents(__DIR__ . '/chat.php');
$lines = explode("\n", $src);
$defsOnly = implode("\n", array_slice($lines, 1, 136)); // lines 2-137 (0-indexed 1..136): everything up through buildSystemPrompt's closing brace
eval($defsOnly);

$fakeMember = array(
    'full_name' => 'Test Member',
    'etel_id'   => 'EG-2026-TEST',
    'fin'       => '0000-0000-0000',
    'status'    => 'active',
    'companies' => array(),
);
$prompt = buildSystemPrompt($fakeMember, 'delivery', array());
echo json_encode(array('ok' => true, 'system_prompt' => $prompt), JSON_PRETTY_PRINT);
