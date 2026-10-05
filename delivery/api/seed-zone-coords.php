<?php
// Retired zone seeding script (protected only by a key written in the source).
// This one-off/temporary script was removed during security hardening
// (it was reachable without proper authentication). Safe to delete from the server.
http_response_code(410);
header('Content-Type: application/json');
echo json_encode(array('ok' => false, 'error' => 'This endpoint has been retired.'));
