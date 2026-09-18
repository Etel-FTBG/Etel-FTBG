<?php
declare(strict_types=1);
namespace App\Core;

/**
 * Minimal outbound mail helper -- wraps PHP's native mail(), same as the
 * rest of EtelGebeya (this app has no SMTP/PHPMailer setup, unlike EtelID).
 * That means delivery depends on the shared host's own mail() being
 * configured and not silently swallowing sends -- fine for a low-volume,
 * non-critical notice like a dropship order sheet, but don't build
 * anything time-sensitive on top of it without verifying real delivery.
 */
final class Mailer
{
    public static function send(string $to, string $subject, string $body, string $fromName = 'Etel Gebeya'): bool
    {
        $to = trim($to);
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;

        $appUrl  = $GLOBALS['__config']['app']['url'] ?? '';
        $host    = parse_url($appUrl, PHP_URL_HOST) ?: 'etelgebeya.local';
        $from    = 'no-reply@' . $host;

        // $fromName reaches here as a vendor's own store_name (see
        // VendorController::emailSupplier()) -- a vendor is free to set
        // that to anything, including embedded CRLFs, which would inject
        // arbitrary extra headers (e.g. Bcc:) into a mail() call sent from
        // this server. Strip line breaks before it's ever placed in a
        // header, same as any other header-injection guard.
        $fromName = str_replace(["\r", "\n"], '', $fromName);

        $headers = [];
        $headers[] = 'From: ' . $fromName . ' <' . $from . '>';
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'X-Mailer: EtelGebeya';

        try {
            return @mail($to, $subject, $body, implode("\r\n", $headers));
        } catch (\Throwable $_) {
            return false;
        }
    }
}
