<?php
/**
 * AJAX: Admin Forgot / Reset Password flow.
 *
 * Flow:
 *   1. madeya_admin_forgot_send   — validate email → generate 6-digit code → send email
 *   2. madeya_admin_forgot_verify — check code (valid 15 min, max 5 attempts)
 *   3. madeya_admin_forgot_reset  — after verified: save new username + password
 *
 * WordPress Admin (manage_options):
 *   4. madeya_wp_admin_save_creds — save credentials from the WP admin page (requires WP login)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ── 1. Send reset code to registered admin email ───────────────
add_action( 'wp_ajax_nopriv_madeya_admin_forgot_send', 'madeya_ajax_admin_forgot_send' );
add_action( 'wp_ajax_madeya_admin_forgot_send',        'madeya_ajax_admin_forgot_send' );

function madeya_ajax_admin_forgot_send() {
    check_ajax_referer( 'madeya_nonce', 'nonce' );

    // Rate-limit: max 3 send attempts per 10 minutes per IP
    $rate_key = 'madeya_rst_rate_' . md5( $_SERVER['REMOTE_ADDR'] ?? '' );
    $attempts = (int) get_transient( $rate_key );
    if ( $attempts >= 3 ) {
        wp_send_json_error( array(
            'message' => 'Too many reset attempts. Please wait 10 minutes before trying again.',
            'code'    => 'RATE_LIMIT',
        ) );
    }
    set_transient( $rate_key, $attempts + 1, 10 * MINUTE_IN_SECONDS );

    $input_email = sanitize_email( $_POST['email'] ?? '' );
    if ( ! $input_email ) {
        wp_send_json_error( array( 'message' => 'Please enter your registered email address.', 'code' => 'NO_EMAIL' ) );
    }

    $stored_email = get_option( 'madeya_admin_email', '' );
    if ( ! $stored_email ) {
        wp_send_json_error( array(
            'message' => 'No recovery email is configured for this admin account. '
                       . 'Please contact your WordPress site administrator.',
            'code'    => 'NO_STORED_EMAIL',
        ) );
    }

    // Constant-time comparison to prevent timing attacks
    if ( ! hash_equals( strtolower( $stored_email ), strtolower( $input_email ) ) ) {
        wp_send_json_error( array(
            'message' => 'That email address does not match our records.',
            'code'    => 'EMAIL_MISMATCH',
        ) );
    }

    // Generate a secure 6-digit code
    $code = str_pad( (string) wp_rand( 0, 999999 ), 6, '0', STR_PAD_LEFT );

    // Store code with 15-minute expiry (hashed so even DB admins can't see it)
    $code_hash = wp_hash( $code );
    set_transient( 'madeya_rst_code',     $code_hash,  15 * MINUTE_IN_SECONDS );
    set_transient( 'madeya_rst_verified', false,        15 * MINUTE_IN_SECONDS );
    set_transient( 'madeya_rst_tries',    0,            15 * MINUTE_IN_SECONDS );
    delete_transient( 'madeya_rst_ok' ); // clear any prior verified token

    // Send the email
    $site_name = get_bloginfo( 'name' );
    $subject   = "[{$site_name}] ETEL MADEYA Admin — Password Reset Code";

    $body = madeya_reset_email_html( $code, $site_name );

    $headers = array(
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . $site_name . ' <' . get_option( 'admin_email' ) . '>',
    );

    $sent = wp_mail( $stored_email, $subject, $body, $headers );

    if ( ! $sent ) {
        wp_send_json_error( array(
            'message' => 'Failed to send email. Please check your WordPress mail configuration.',
            'code'    => 'MAIL_FAIL',
        ) );
    }

    // Mask email for display: ab****@gmail.com
    $masked = madeya_mask_email( $stored_email );

    wp_send_json_success( array(
        'message' => "A 6-digit verification code has been sent to {$masked}. It expires in 15 minutes.",
        'masked'  => $masked,
    ) );
}

// ── 2. Verify the submitted code ───────────────────────────────
add_action( 'wp_ajax_nopriv_madeya_admin_forgot_verify', 'madeya_ajax_admin_forgot_verify' );
add_action( 'wp_ajax_madeya_admin_forgot_verify',        'madeya_ajax_admin_forgot_verify' );

function madeya_ajax_admin_forgot_verify() {
    check_ajax_referer( 'madeya_nonce', 'nonce' );

    $code_input = sanitize_text_field( $_POST['code'] ?? '' );
    $code_input = preg_replace( '/\D/', '', $code_input ); // digits only

    if ( strlen( $code_input ) !== 6 ) {
        wp_send_json_error( array( 'message' => 'Please enter the full 6-digit code.', 'code' => 'INVALID_FORMAT' ) );
    }

    $stored_hash = get_transient( 'madeya_rst_code' );
    if ( ! $stored_hash ) {
        wp_send_json_error( array(
            'message' => 'This code has expired or was never issued. Please request a new one.',
            'code'    => 'EXPIRED',
        ) );
    }

    // Track attempts (max 5)
    $tries = (int) get_transient( 'madeya_rst_tries' );
    if ( $tries >= 5 ) {
        delete_transient( 'madeya_rst_code' );
        wp_send_json_error( array(
            'message' => 'Too many incorrect attempts. Please request a new code.',
            'code'    => 'MAX_TRIES',
        ) );
    }

    if ( ! hash_equals( $stored_hash, wp_hash( $code_input ) ) ) {
        set_transient( 'madeya_rst_tries', $tries + 1, 15 * MINUTE_IN_SECONDS );
        $left = 5 - ( $tries + 1 );
        wp_send_json_error( array(
            'message' => "Incorrect code. {$left} attempt(s) remaining.",
            'code'    => 'WRONG_CODE',
            'tries_left' => $left,
        ) );
    }

    // Code correct — issue a short-lived verification token
    $token = wp_generate_password( 32, false );
    set_transient( 'madeya_rst_ok',     wp_hash( $token ), 10 * MINUTE_IN_SECONDS );
    delete_transient( 'madeya_rst_code' );  // invalidate so code can't be reused
    delete_transient( 'madeya_rst_tries' );

    wp_send_json_success( array(
        'message' => 'Code verified! You may now set your new credentials.',
        'token'   => $token,   // sent back to JS, submitted with the reset form
    ) );
}

// ── 3. Save new credentials after successful verification ──────
add_action( 'wp_ajax_nopriv_madeya_admin_forgot_reset', 'madeya_ajax_admin_forgot_reset' );
add_action( 'wp_ajax_madeya_admin_forgot_reset',        'madeya_ajax_admin_forgot_reset' );

function madeya_ajax_admin_forgot_reset() {
    check_ajax_referer( 'madeya_nonce', 'nonce' );

    // Validate the verification token from step 2
    $token       = sanitize_text_field( $_POST['token'] ?? '' );
    $stored_tok  = get_transient( 'madeya_rst_ok' );
    if ( ! $token || ! $stored_tok || ! hash_equals( $stored_tok, wp_hash( $token ) ) ) {
        wp_send_json_error( array(
            'message' => 'Session expired or invalid. Please start the reset process again.',
            'code'    => 'BAD_TOKEN',
        ) );
    }

    $new_user  = sanitize_text_field( $_POST['new_username'] ?? '' );
    $new_pass  = $_POST['new_password']  ?? '';
    $new_pass2 = $_POST['confirm_password'] ?? '';
    $new_email = sanitize_email( $_POST['new_email'] ?? '' );

    if ( ! $new_user ) {
        wp_send_json_error( array( 'message' => 'Username cannot be empty.' ) );
    }
    if ( strlen( $new_pass ) < 8 ) {
        wp_send_json_error( array( 'message' => 'Password must be at least 8 characters.' ) );
    }
    if ( $new_pass !== $new_pass2 ) {
        wp_send_json_error( array( 'message' => 'Passwords do not match.' ) );
    }

    // Save
    update_option( 'madeya_admin_username', $new_user );
    update_option( 'madeya_admin_password', password_hash( $new_pass, PASSWORD_DEFAULT ) );
    if ( $new_email ) {
        update_option( 'madeya_admin_email', $new_email );
    }

    // Invalidate the reset token immediately
    delete_transient( 'madeya_rst_ok' );

    // Log for audit
    update_option( 'madeya_admin_last_reset', current_time( 'mysql' ) );

    // Send confirmation email
    $stored_email = get_option( 'madeya_admin_email', '' );
    if ( $stored_email ) {
        $site_name = get_bloginfo( 'name' );
        $body = madeya_reset_confirm_email_html( $new_user, $site_name );
        wp_mail(
            $stored_email,
            "[{$site_name}] ETEL MADEYA Admin — Credentials Updated Successfully",
            $body,
            array( 'Content-Type: text/html; charset=UTF-8' )
        );
    }

    wp_send_json_success( array(
        'message' => 'Credentials updated successfully! You can now log in with your new username and password.',
    ) );
}

// ── 4. WP Admin AJAX — save credentials (requires manage_options) ──
add_action( 'wp_ajax_madeya_wp_admin_save_creds', 'madeya_ajax_wp_admin_save_creds' );

function madeya_ajax_wp_admin_save_creds() {
    check_ajax_referer( 'madeya_wp_creds_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Permission denied.' ) );
    }

    $new_user  = sanitize_text_field( $_POST['new_username'] ?? '' );
    $new_email = sanitize_email( $_POST['new_email'] ?? '' );
    $new_pass  = $_POST['new_password'] ?? '';
    $new_pass2 = $_POST['confirm_password'] ?? '';

    if ( ! $new_user ) {
        wp_send_json_error( array( 'message' => 'Username cannot be empty.' ) );
    }
    if ( $new_pass && strlen( $new_pass ) < 8 ) {
        wp_send_json_error( array( 'message' => 'Password must be at least 8 characters.' ) );
    }
    if ( $new_pass && $new_pass !== $new_pass2 ) {
        wp_send_json_error( array( 'message' => 'Passwords do not match.' ) );
    }

    update_option( 'madeya_admin_username', $new_user );
    if ( $new_email ) update_option( 'madeya_admin_email', $new_email );
    if ( $new_pass )  update_option( 'madeya_admin_password', password_hash( $new_pass, PASSWORD_DEFAULT ) );
    update_option( 'madeya_admin_last_reset', current_time( 'mysql' ) );

    wp_send_json_success( array( 'message' => 'Admin credentials saved successfully.' ) );
}

// ── Email helpers ──────────────────────────────────────────────
function madeya_mask_email( $email ) {
    $parts  = explode( '@', $email );
    $local  = $parts[0] ?? '';
    $domain = $parts[1] ?? '';
    $show   = min( 2, strlen( $local ) );
    return substr( $local, 0, $show ) . str_repeat( '*', max( 0, strlen( $local ) - $show ) ) . '@' . $domain;
}

function madeya_reset_email_html( $code, $site_name ) {
    $digits = str_split( $code );
    $digit_cells = '';
    foreach ( $digits as $d ) {
        $digit_cells .= "<td style='width:44px;height:52px;background:#1e2330;border:2px solid #F5A623;border-radius:8px;text-align:center;vertical-align:middle;font-size:28px;font-weight:900;color:#F5A623;font-family:\"Courier New\",monospace;letter-spacing:0;'>{$d}</td><td style='width:6px;'></td>";
    }
    return "<!DOCTYPE html><html><body style='margin:0;padding:0;background:#09090d;font-family:\"Segoe UI\",Arial,sans-serif;'>
<table width='100%' cellpadding='0' cellspacing='0' style='background:#09090d;padding:40px 20px;'>
<tr><td align='center'>
<table width='480' cellpadding='0' cellspacing='0' style='background:#11141a;border:1px solid #272f3d;border-radius:16px;overflow:hidden;'>
  <!-- Header -->
  <tr><td style='background:linear-gradient(135deg,#07080d,#0e1220);border-bottom:3px solid #F5A623;padding:24px 32px;text-align:center;'>
    <div style='font-size:22px;font-weight:900;color:#F5A623;letter-spacing:3px;'>ETEL MADEYA</div>
    <div style='font-size:11px;color:#2980B9;letter-spacing:1px;margin-top:4px;'>ኢትኤል ማደያ · Admin Password Reset</div>
  </td></tr>
  <!-- Body -->
  <tr><td style='padding:32px;'>
    <p style='color:#dde4f0;font-size:15px;margin:0 0 8px;'>Hello,</p>
    <p style='color:#6b7a99;font-size:13px;margin:0 0 24px;line-height:1.6;'>
      A password reset was requested for the <strong style='color:#F5A623;'>{$site_name}</strong> 
      ETEL MADEYA admin panel. Use the verification code below to proceed.
    </p>
    <!-- Code box -->
    <div style='background:#181d26;border:1px solid #272f3d;border-radius:12px;padding:24px;text-align:center;margin-bottom:24px;'>
      <div style='font-size:10px;color:#6b7a99;letter-spacing:2px;text-transform:uppercase;margin-bottom:14px;'>Your Verification Code</div>
      <table cellpadding='0' cellspacing='0' style='margin:0 auto;border-spacing:0;'><tr>{$digit_cells}</tr></table>
      <div style='margin-top:14px;font-size:11px;color:#6b7a99;'>⏱ Expires in <strong style='color:#F5A623;'>15 minutes</strong> · Max 5 attempts</div>
    </div>
    <div style='background:rgba(192,57,43,.08);border:1px solid rgba(192,57,43,.25);border-radius:8px;padding:12px 16px;margin-bottom:24px;'>
      <p style='color:#C0392B;font-size:12px;margin:0;'><strong>⚠ Security notice:</strong> If you did not request this reset, 
      your admin panel is secure — no action was taken. You may ignore this email.</p>
    </div>
    <p style='color:#6b7a99;font-size:11px;margin:0;'>This code is single-use and will be invalidated once used or after 15 minutes.</p>
  </td></tr>
  <!-- Footer -->
  <tr><td style='background:#0f121a;border-top:1px solid #272f3d;padding:16px 32px;text-align:center;'>
    <div style='font-size:10px;color:#4d5e78;'>® ኢE PATENT &amp; TRADEMARK OF ETEL DEV · ethelmarket.com/etel.Madeya</div>
  </td></tr>
</table>
</td></tr></table></body></html>";
}

function madeya_reset_confirm_email_html( $new_username, $site_name ) {
    $time = current_time( 'M j, Y \a\t H:i T' );
    return "<!DOCTYPE html><html><body style='margin:0;padding:0;background:#09090d;font-family:\"Segoe UI\",Arial,sans-serif;'>
<table width='100%' cellpadding='0' cellspacing='0' style='background:#09090d;padding:40px 20px;'>
<tr><td align='center'>
<table width='480' cellpadding='0' cellspacing='0' style='background:#11141a;border:1px solid #272f3d;border-radius:16px;overflow:hidden;'>
  <tr><td style='background:linear-gradient(135deg,#07080d,#0e1220);border-bottom:3px solid #27AE60;padding:24px 32px;text-align:center;'>
    <div style='font-size:22px;font-weight:900;color:#F5A623;letter-spacing:3px;'>ETEL MADEYA</div>
    <div style='font-size:11px;color:#27AE60;letter-spacing:1px;margin-top:4px;'>✓ Credentials Updated Successfully</div>
  </td></tr>
  <tr><td style='padding:32px;'>
    <p style='color:#dde4f0;font-size:15px;margin:0 0 16px;'>Your admin credentials have been updated.</p>
    <div style='background:#181d26;border:1px solid #272f3d;border-radius:10px;padding:18px;margin-bottom:20px;'>
      <div style='display:flex;margin-bottom:8px;'><span style='color:#6b7a99;font-size:12px;width:110px;'>New username:</span><strong style='color:#F5A623;font-size:13px;font-family:monospace;'>{$new_username}</strong></div>
      <div style='display:flex;'><span style='color:#6b7a99;font-size:12px;width:110px;'>Changed at:</span><span style='color:#dde4f0;font-size:12px;'>{$time}</span></div>
    </div>
    <div style='background:rgba(192,57,43,.08);border:1px solid rgba(192,57,43,.25);border-radius:8px;padding:12px 16px;'>
      <p style='color:#C0392B;font-size:12px;margin:0;'><strong>⚠ Not you?</strong> If you did not make this change, contact your site administrator immediately and change your credentials.</p>
    </div>
  </td></tr>
  <tr><td style='background:#0f121a;border-top:1px solid #272f3d;padding:16px 32px;text-align:center;'>
    <div style='font-size:10px;color:#4d5e78;'>® ኢE PATENT &amp; TRADEMARK OF ETEL DEV · ethelmarket.com/etel.Madeya</div>
  </td></tr>
</table>
</td></tr></table></body></html>";
}
