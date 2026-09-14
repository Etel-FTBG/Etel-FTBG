<?php
// ============================================================
// Etel Group super-page — contact form handler
// Deploy as: EtelGroup/php/contact.php (called by js/main.js)
// ============================================================
declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://ethelmarket.com');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/config.php';

function ok($d)            { echo json_encode(['ok' => true,  'data' => $d]); exit; }
function err($m, $c = 400) { http_response_code($c); echo json_encode(['ok' => false, 'error' => $m]); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') err('Method not allowed', 405);

$body    = json_decode(file_get_contents('php://input'), true) ?? [];
$name    = trim((string)($body['name']    ?? ''));
$email   = trim((string)($body['email']   ?? ''));
$subject = trim((string)($body['subject'] ?? ''));
$company = trim((string)($body['company'] ?? ''));
$message = trim((string)($body['message'] ?? ''));

if ($name === '' || $email === '' || $message === '') {
    err('Name, email, and message are required.');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    err('Please enter a valid email address.');
}
if (strlen($message) > 5000) {
    err('Message is too long (max 5000 characters).');
}

$ip = $_SERVER['REMOTE_ADDR'] ?? '';

try {
    $pdo = eg_db();

    // The ip column was already being stored on every submission but never
    // used for anything - an unlimited POST loop could fill this table and
    // spam info@ethelmarket.com with no mitigation at all. Cap it at 5 per
    // hour per IP, checked against the same table we're about to insert into.
    if ($ip !== '') {
        $rl = $pdo->prepare(
            "SELECT COUNT(*) FROM contact_messages WHERE ip = ? AND created_at > (NOW() - INTERVAL 1 HOUR)"
        );
        $rl->execute([$ip]);
        if ((int)$rl->fetchColumn() >= 5) {
            err('Too many messages sent recently. Please try again in an hour.', 429);
        }
    }

    $pdo->prepare(
        "INSERT INTO contact_messages (name, email, subject, company, message, ip, created_at)
         VALUES (?, ?, ?, ?, ?, ?, NOW())"
    )->execute([
        $name, $email, $subject ?: null, $company ?: null, $message, $ip ?: null,
    ]);
} catch (\Throwable $e) {
    err('Could not save your message right now. Please try again shortly.', 500);
}

// Best-effort notification email — failure here shouldn't fail the request,
// the message is already safely stored in the database.
$to = getenv('CONTACT_TO_EMAIL') ?: 'info@ethelmarket.com';
$headers = "From: Etel Group Website <no-reply@ethelmarket.com>\r\nReply-To: " . $email;
$body2   = "New contact form submission\n\nName: $name\nEmail: $email\nSubject: $subject\nCompany: $company\n\nMessage:\n$message";
@mail($to, 'New contact message from ethelmarket.com', $body2, $headers);

ok(['sent' => true]);
