<?php
/**
 * Etel-ID Premium — Gmail SMTP for reliable outgoing mail
 *
 * wp_mail() normally uses PHP's built-in mail() function, which is
 * frequently blocked, unreliable, or silently swallowed on shared
 * hosting - exactly what caused the password reset codes to never
 * arrive. This routes all outgoing mail (password reset codes, welcome
 * emails, etc.) through Gmail's real SMTP servers instead.
 *
 * Setup: add these two lines to wp-config.php (NOT the plugin's own
 * database settings - wp-config.php isn't web-accessible, which is
 * the right place for real credentials):
 *
 *   define( 'ETELID_SMTP_USER', 'youraddress@gmail.com' );
 *   define( 'ETELID_SMTP_PASS', 'your16charapppassword' );  // no spaces
 *
 * (The App Password from myaccount.google.com/apppasswords - NOT your
 * normal Gmail password, which Google blocks for this.)
 *
 * If those constants aren't defined, this does nothing and wp_mail()
 * falls back to its normal (unreliable) default behavior - so it's
 * always safe to deploy this file even before you've set them up.
 *
 * PHP 5.6+ compatible.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class EtelID_SMTP {

    public static function init() {
        if ( ! defined( 'ETELID_SMTP_USER' ) || ! defined( 'ETELID_SMTP_PASS' ) ) return;
        add_action( 'phpmailer_init', array( __CLASS__, 'configure' ) );
        add_filter( 'wp_mail_from',      array( __CLASS__, 'from_email' ) );
        add_filter( 'wp_mail_from_name', array( __CLASS__, 'from_name' ) );
    }

    public static function configure( $phpmailer ) {
        $phpmailer->isSMTP();
        $phpmailer->Host       = 'smtp.gmail.com';
        $phpmailer->Port       = 587;
        $phpmailer->SMTPSecure = 'tls';
        $phpmailer->SMTPAuth   = true;
        $phpmailer->Username   = ETELID_SMTP_USER;
        $phpmailer->Password   = ETELID_SMTP_PASS;
    }

    public static function from_email( $email ) {
        return ETELID_SMTP_USER;
    }

    public static function from_name( $name ) {
        return get_option( 'etelid_org_name', 'Etel Group' );
    }
}

EtelID_SMTP::init();
