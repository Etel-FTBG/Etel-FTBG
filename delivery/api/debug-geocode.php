<?php
// Retired diagnostic (spent Google Geocoding quota for anyone who called it).
// This one-off/temporary script was removed during security hardening
// (it was reachable without proper authentication). Safe to delete from the server.
http_response_code(410);
header('Content-Type: application/json');
echo json_encode(array('ok' => false, 'error' => 'This endpoint has been retired.'));
