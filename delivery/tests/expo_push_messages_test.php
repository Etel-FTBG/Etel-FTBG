<?php
/**
 * Expo push messages (api/webpush.php, etel_expo_messages): a normal push is one high-priority message on the default channel; a new
 * ride request is also given a 60 second life and a second, data-only message that wakes the app's golden light in the background.
 *   php delivery/tests/expo_push_messages_test.php      (no network, no database)
 */
$src = file_get_contents(__DIR__ . '/../api/webpush.php');
$a = strpos($src, 'function etel_expo_messages(');
$b = strpos($src, 'function etel_send_expo_push(');
if ($a === false || $b === false) { echo "FAIL: cannot find etel_expo_messages in webpush.php\n"; exit(1); }
eval(substr($src, $a, $b - $a));
$fails = 0;
function check($l, $c) { global $fails; if ($c) echo "ok   $l\n"; else { echo "FAIL $l\n"; $fails++; } }

$m = etel_expo_messages('ExponentPushToken[x]', array('title' => 'Hi', 'body' => 'There', 'data' => array('type' => 'ride_accepted', 'ride_id' => 5)));
check('a normal push is ONE message', count($m) === 1);
check('it is high priority on the default channel with sound', $m[0]['priority'] === 'high' && $m[0]['channelId'] === 'default' && $m[0]['sound'] === 'default');
check('it keeps title, body and data', $m[0]['title'] === 'Hi' && $m[0]['body'] === 'There' && $m[0]['data']['ride_id'] === 5);
check('a normal push has no short expiry', !isset($m[0]['ttl']));

$m = etel_expo_messages('ExponentPushToken[x]', array('title' => 'New ride request nearby!', 'body' => 'ETB 200', 'data' => array('type' => 'new_ride_request', 'ride_id' => 9, 'url' => '/delivery/driver/')));
check('a new ride request is TWO messages', count($m) === 2);
check('the visible one expires after 60 seconds', $m[0]['ttl'] === 60 && $m[0]['title'] === 'New ride request nearby!' && $m[0]['priority'] === 'high');
check('the second is data-only (no title, no body, no sound)', !isset($m[1]['title']) && !isset($m[1]['body']) && !isset($m[1]['sound']));
check('the second wakes the app: high priority, content available, 60 seconds', $m[1]['priority'] === 'high' && $m[1]['_contentAvailable'] === true && $m[1]['ttl'] === 60);
check('the second carries wake=1 and the same ride', $m[1]['data']['wake'] === 1 && $m[1]['data']['ride_id'] === 9 && $m[1]['data']['type'] === 'new_ride_request');
check('both go to the same device', $m[0]['to'] === $m[1]['to'] && $m[0]['to'] === 'ExponentPushToken[x]');
check('the visible message does not carry the wake flag', !isset($m[0]['data']['wake']));

$m = etel_expo_messages('t', array('title' => 'x', 'body' => 'y'));
check('a push with no data still works', count($m) === 1 && $m[0]['data'] === array());
echo $fails ? "\n$fails FAILED\n" : "\nall passed\n"; exit($fails ? 1 : 0);
