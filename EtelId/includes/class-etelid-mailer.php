<?php
/**
 * Etel-ID Premium — Email Mailer
 *
 * @package    Etel-ID Premium
 * @version    2.0.0
 * @author     Etel Group · ኢትኤል ቡድን
 * @link       https://etelgroup.com
 * @since      2.0.0
 *
 * Sends the welcome / ID-issued email to new members via wp_mail().
 * Includes Etel-ID, FIN, issue date, expiry, and verify URL.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class EtelID_Mailer {
    public static function send_welcome( $member ) {
        if ( empty( $member->email ) ) return false;
        $subject = 'Your Etel-ID Has Been Issued — ' . $member->etel_id;
        $body    = "Dear {$member->full_name},\n\n"
                 . "Your Etel Group Corporate ID has been issued.\n\n"
                 . "Etel-ID:    {$member->etel_id}\n"
                 . "FIN:        {$member->fin}\n"
                 . "Issued:     {$member->issue_date}\n"
                 . "Expires:    {$member->expiry_date}\n\n"
                 . "Verify your ID at: " . EtelID_QR::verify_url( $member->etel_id ) . "\n\n"
                 . "Etel Group · ኢትኤል ቡድን\n"
                 . get_option( 'etelid_org_website', 'etelgroup.com' );
        return wp_mail( $member->email, $subject, $body );
    }

    /** Sends a 6-digit password reset code by email. Used by the new
     *  self-service "Forgot Password" flow (see class-etelid-password-reset.php). */
    public static function send_reset_code( $member, $code ) {
        if ( empty( $member->email ) ) return false;
        $org     = get_option( 'etelid_org_name', 'Etel Group' );
        $subject = 'Your Etel-ID Password Reset Code';
        $body    = "Dear {$member->full_name},\n\n"
                 . "You (or someone else) requested to reset the password for your Etel-ID account ({$member->etel_id}).\n\n"
                 . "Your reset code is:\n\n"
                 . "    {$code}\n\n"
                 . "This code expires in 15 minutes. If you didn't request this, you can safely ignore this email - your password will not change.\n\n"
                 . "{$org}\n"
                 . get_option( 'etelid_org_website', 'etelgroup.com' );
        return wp_mail( $member->email, $subject, $body );
    }
}
