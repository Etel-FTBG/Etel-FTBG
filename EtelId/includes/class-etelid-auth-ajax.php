<?php
/**
 * Etel-ID Premium — Auth AJAX Handlers
 *
 * @package    Etel-ID Premium
 * @version    2.1.0
 * @author     Etel Group · ኢትኤል ቡድን
 * @since      2.1.0
 *
 * Handles: etelid_auth_register, etelid_auth_login,
 *          etelid_auth_logout, etelid_auth_portal_data
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class EtelID_Auth_Ajax {

    public static function init() {
        /* Explicit action → method map to avoid str_replace bugs */
        $map = array(
            'etelid_auth_register'        => 'register',
            'etelid_quick_register'       => 'quick_register',
            'etelid_check_duplicate_name'  => 'check_duplicate_name',
            'etelid_check_duplicate_email' => 'check_duplicate_email',
            'etelid_check_duplicate_phone' => 'check_duplicate_phone',
            'etelid_auth_login'           => 'login',
            'etelid_auth_logout'          => 'logout',
            'etelid_auth_portal_data'     => 'portal_data',
            'etelid_auth_change_password' => 'change_password',
            'etelid_profile_update'       => 'profile_update',
            'etelid_pass2_submit'         => 'pass2_submit',
            'etelid_fayda_id_submit'      => 'fayda_id_submit',
            'etelid_fayda_quick_register' => 'fayda_quick_register',
            'etelid_webauthn_register_options' => 'webauthn_register_options',
            'etelid_webauthn_register_verify'  => 'webauthn_register_verify',
            'etelid_webauthn_login_options'    => 'webauthn_login_options',
            'etelid_webauthn_login_verify'     => 'webauthn_login_verify',
            'etelid_webauthn_devices'          => 'webauthn_devices',
            'etelid_csp_report'                => 'csp_report',
            'etelid_2fa_status'                => 'twofa_status',
            'etelid_2fa_begin'                 => 'twofa_begin',
            'etelid_2fa_enable'                => 'twofa_enable',
            'etelid_2fa_disable'               => 'twofa_disable',
            'etelid_2fa_backup_codes'          => 'twofa_backup_codes',
            'etelid_webauthn_remove'           => 'webauthn_remove',
        );
        foreach ( $map as $action => $method ) {
            add_action( 'wp_ajax_'        . $action, array( __CLASS__, $method ) );
            add_action( 'wp_ajax_nopriv_' . $action, array( __CLASS__, $method ) );
        }
    }

    /* ── Register ───────────────────────────────────────── */
    public static function register() {
        check_ajax_referer( 'etelid_auth', 'nonce' );

        $ip = EtelID_Security::get_ip();
        if ( ! EtelID_Security::rate_limit( 'register_' . $ip, 5, 3600 ) ) {
            wp_send_json_error( array( 'message' => 'Too many attempts. Please wait an hour and try again.' ) );
        }

        $fields = array(
            'full_name','full_name_am','phone','password','password_confirm',
            'dob','sex','blood_type','nationality','region','zone','woreda',
            'primary_company_slug','role','department','email',
        );
        $data = array();
        foreach ( $fields as $f ) {
            $data[ $f ] = isset( $_POST[ $f ] ) ? sanitize_text_field( wp_unslash( $_POST[ $f ] ) ) : '';
        }
        /* Photo/Fayda fields were missing from the $fields whitelist above
         * entirely - the registration wizard's new photo-and-ID step could
         * submit them, but they'd get silently dropped right here before
         * ever reaching EtelID_Auth::register(), regardless of the
         * generator/validation support added there. Handled separately
         * from the loop above (rather than just adding to $fields) because
         * sanitize_text_field() is the wrong sanitizer for base64 image
         * data - it can mangle content that isn't plain text. wp_unslash()
         * alone matches exactly what fayda_id_submit() already does for
         * this same kind of data elsewhere in this file. */
        $data['photo_url']          = isset( $_POST['photo_url'] )          ? wp_unslash( $_POST['photo_url'] )          : '';
        $data['fayda_front_image']  = isset( $_POST['fayda_front_image'] )  ? wp_unslash( $_POST['fayda_front_image'] )  : '';
        $data['fayda_back_image']   = isset( $_POST['fayda_back_image'] )   ? wp_unslash( $_POST['fayda_back_image'] )   : '';

        /* Basic validation - mirrors the wizard's own client-side checks
         * in templates/auth-page.php's regNext(2) (defense-in-depth: the
         * client check is only ever a convenience, never the real gate). */
        if ( empty( $data['full_name'] ) ) {
            wp_send_json_error( array( 'field' => 'full_name', 'message' => 'Full name is required.' ) );
        }
        if ( ! EtelID_Auth_Ajax::is_real_full_name( $data['full_name'] ) ) {
            wp_send_json_error( array( 'field' => 'full_name', 'message' => "Enter your real full name - at least two names (e.g. given name and father's name), each at least 2 letters." ) );
        }
        if ( ! empty( $data['full_name_am'] ) && ! EtelID_Auth_Ajax::is_real_amharic_name( $data['full_name_am'] ) ) {
            wp_send_json_error( array( 'field' => 'full_name_am', 'message' => 'Enter your full name in Amharic script (at least two words), or leave this blank.' ) );
        }
        if ( empty( $data['phone'] ) ) {
            wp_send_json_error( array( 'field' => 'phone', 'message' => 'Phone number is required.' ) );
        }
        if ( ! EtelID_Security::valid_ethiopian_phone( $data['phone'] ) ) {
            wp_send_json_error( array( 'field' => 'phone', 'message' => 'Enter a valid Ethiopian phone number, e.g. 0912345678 or +251123456789.' ) );
        }
        /* An empty password is allowed through here - it means the caller
         * (apply-form.php's Full Registration) wants a temp password
         * auto-issued, which EtelID_Auth::register() below handles; a
         * caller that IS collecting one (auth-page.php's wizard) still
         * gets the normal 8-char minimum enforced. */
        if ( '' !== $data['password'] ) {
            if ( strlen( $data['password'] ) < 8 ) {
                wp_send_json_error( array( 'field' => 'password', 'message' => 'Password must be at least 8 characters.' ) );
            }
            if ( $data['password'] !== $data['password_confirm'] ) {
                wp_send_json_error( array( 'field' => 'password_confirm', 'message' => 'Passwords do not match.' ) );
            }
        }
        if ( empty( $data['dob'] ) ) {
            wp_send_json_error( array( 'field' => 'dob', 'message' => 'Date of birth is required.' ) );
        }
        $dob_time = strtotime( $data['dob'] );
        if ( ! $dob_time ) {
            wp_send_json_error( array( 'field' => 'dob', 'message' => 'Enter a valid date.' ) );
        }
        $age = (int) floor( ( time() - $dob_time ) / ( 365.25 * DAY_IN_SECONDS ) );
        if ( $age < 0 ) {
            wp_send_json_error( array( 'field' => 'dob', 'message' => 'Date of birth cannot be in the future.' ) );
        }
        if ( $age < 10 ) {
            wp_send_json_error( array( 'field' => 'dob', 'message' => 'You must be at least 10 years old to register.' ) );
        }
        if ( $age > 120 ) {
            wp_send_json_error( array( 'field' => 'dob', 'message' => 'Enter a valid date of birth.' ) );
        }
        if ( ! empty( $data['email'] ) && ! is_email( $data['email'] ) ) {
            wp_send_json_error( array( 'field' => 'email', 'message' => 'Invalid email address.' ) );
        }
        if ( ! empty( $data['email'] ) && EtelID_DB::find_by_email( $data['email'] ) ) {
            wp_send_json_error( array( 'field' => 'email', 'message' => 'This email is already registered. Please use a different email, or sign in instead.' ) );
        }
        if ( empty( $data['region'] ) ) {
            wp_send_json_error( array( 'field' => 'region', 'message' => 'Region is required.' ) );
        }
        if ( empty( $data['zone'] ) ) {
            wp_send_json_error( array( 'field' => 'zone', 'message' => 'Please select your zone/city.' ) );
        }
        $valid_zones = EtelID_Generator::zones_by_region();
        if ( ! isset( $valid_zones[ $data['region'] ] ) || ! in_array( $data['zone'], $valid_zones[ $data['region'] ], true ) ) {
            wp_send_json_error( array( 'field' => 'zone', 'message' => 'Select a zone/city that belongs to the chosen region.' ) );
        }
        /* Re-check the same name+DOB duplicate signal the wizard's live
         * check (check_duplicate_name() below) already offered during
         * entry - defense-in-depth in case that step was skipped or the
         * name/dob changed after it ran. */
        if ( EtelID_DB::find_duplicate_registration( $data['full_name'], $data['dob'] ) ) {
            wp_send_json_error( array( 'field' => 'full_name', 'message' => 'An account with this name and date of birth already exists. Please sign in instead, or contact support if this is an error.' ) );
        }
        /* Same re-check for the given-name+father's-name match level (see
         * EtelID_DB::name_match_level()) the wizard's Step 2 already
         * blocks on - defense-in-depth in case that step was bypassed. */
        if ( 2 === EtelID_DB::name_match_level( $data['full_name'] ) ) {
            wp_send_json_error( array( 'field' => 'full_name', 'message' => "This given name and father's name match an existing record. Please also add your grandfather's name (a 3rd name) to tell the two of you apart." ) );
        }

        $result = EtelID_Auth::register( $data );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        /* EtelID_Auth::register() returns array('member_id'=>..,
         * 'temp_password'=>..|null) rather than a bare id, so a caller
         * that omitted 'password' entirely (apply-form.php's Full
         * Registration) gets the generated temp password back to show
         * the person, the same way quick_register()'s response already
         * does - a caller that sent its own password (auth-page.php's
         * wizard) just gets temp_password: null, no behavior change. */
        $member_id     = $result['member_id'];
        $temp_password = $result['temp_password'];

        $member = EtelID_DB::get_member( $member_id );
        /* Auto login after register */
        EtelID_Auth::create_session( $member_id );
        if ( $member ) EtelID_Mailer::send_welcome( $member );

        wp_send_json_success( array(
            'message'       => 'Account created! Welcome to Etel Group.',
            'etel_id'       => $member ? $member->etel_id : '',
            'fin'           => $member ? $member->fin : '',
            'temp_password' => $temp_password,
            'verify_url'    => $member ? EtelID_QR::verify_url( $member->etel_id ) : '',
            'redirect'      => home_url( '/portal/' ),
        ));
    }

    /* ── Live duplicate-name check (called from the registration wizard
     * while the user fills in Step 2, on blur of Full Name and again
     * right before advancing to Step 3) ──
     * Matches on full_name + dob together (see
     * EtelID_DB::find_duplicate_registration()'s own comment for why
     * name alone isn't used) so two different people who happen to
     * share a name aren't blocked from both registering. Public/nopriv -
     * this only reveals whether a name+dob combination is taken, the
     * same kind of signal the phone-duplicate check already exposes. */
    public static function check_duplicate_name() {
        check_ajax_referer( 'etelid_auth', 'nonce' );

        $ip = EtelID_Security::get_ip();
        if ( ! EtelID_Security::rate_limit( 'check_dup_name_' . $ip, 30, 600 ) ) {
            wp_send_json_error( array( 'message' => 'Too many checks. Please wait a moment.' ) );
        }

        $full_name = isset( $_POST['full_name'] ) ? sanitize_text_field( wp_unslash( $_POST['full_name'] ) ) : '';
        $dob       = isset( $_POST['dob'] )       ? sanitize_text_field( wp_unslash( $_POST['dob'] ) )       : '';

        if ( empty( $full_name ) ) {
            wp_send_json_success( array( 'duplicate' => false, 'match_level' => 0 ) );
        }

        /* Three signals, from weakest to strongest: an exact name+dob
         * match is a real duplicate-registration block (see
         * find_duplicate_registration()'s own comment). Short of that,
         * name_match_level() (see its own comment in class-etelid-db.php)
         * tells the wizard how many leading name components matched -
         * given-name-only is too common to act on, given+father's-name
         * together is worth blocking until a 3rd (grandfather's) name is
         * added to tell two same-named people apart, and a full 3+-word
         * match is only ever a non-blocking heads-up since it's still not
         * DOB-backed. */
        $duplicate = ! empty( $dob ) && EtelID_DB::find_duplicate_registration( $full_name, $dob );
        wp_send_json_success( array(
            'duplicate'   => (bool) $duplicate,
            'match_level' => EtelID_DB::name_match_level( $full_name ),
        ) );
    }

    /* Live email-uniqueness check, same reasoning as check_duplicate_name()
     * above but blocking (unlike a name, an email really should map to one
     * account - it's how password reset and notifications reach someone). */
    public static function check_duplicate_email() {
        check_ajax_referer( 'etelid_auth', 'nonce' );

        $ip = EtelID_Security::get_ip();
        if ( ! EtelID_Security::rate_limit( 'check_dup_email_' . $ip, 30, 600 ) ) {
            wp_send_json_error( array( 'message' => 'Too many checks. Please wait a moment.' ) );
        }

        $email = isset( $_POST['email'] ) ? sanitize_text_field( wp_unslash( $_POST['email'] ) ) : '';
        if ( empty( $email ) || ! is_email( $email ) ) {
            wp_send_json_success( array( 'taken' => false ) );
        }

        wp_send_json_success( array( 'taken' => (bool) EtelID_DB::find_by_email( $email ) ) );
    }

    /* Live phone-uniqueness check for Step 1 of the registration wizard,
     * same reasoning as check_duplicate_email() - EtelID_Auth::register()
     * already blocks a duplicate phone server-side (find_by_phone()), this
     * just surfaces the same signal before the person fills in the rest
     * of the form instead of only at final submit. */
    public static function check_duplicate_phone() {
        check_ajax_referer( 'etelid_auth', 'nonce' );

        $ip = EtelID_Security::get_ip();
        if ( ! EtelID_Security::rate_limit( 'check_dup_phone_' . $ip, 30, 600 ) ) {
            wp_send_json_error( array( 'message' => 'Too many checks. Please wait a moment.' ) );
        }

        $phone = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
        if ( empty( $phone ) || ! EtelID_Security::valid_ethiopian_phone( $phone ) ) {
            wp_send_json_success( array( 'taken' => false ) );
        }

        wp_send_json_success( array( 'taken' => (bool) EtelID_DB::find_by_phone( $phone ) ) );
    }

    /* -- Quick Register (public, self-service fast track) --------
     * A deliberately minimal alternative to register() above: full
     * name, profile photo, Fayda front/back, email, phone - no
     * password, no address, no DOB. Reuses the same temp-password +
     * must_change_password mechanism EtelID_Fayda::quick_register()
     * already established for the admin-driven Fayda capture flow
     * (same portal overlay picks it up automatically - see
     * templates/portal.php's $etelid_must_change block), but this one
     * is public (wp_ajax_nopriv) and auto-logs the member in directly
     * instead of delivering the temp password out-of-band, since the
     * person filling this in IS the one sitting in the browser right
     * now, unlike an admin registering someone else. The account is
     * genuinely usable immediately; empty( $member->dob ) is what the
     * portal's "complete your profile" banner keys off of afterward
     * to nudge them back to /profile/ - no new DB columns needed since
     * dob is already required by the full form and never left empty
     * by any other entry point. */
    public static function quick_register() {
        check_ajax_referer( 'etelid_auth', 'nonce' );

        $ip = EtelID_Security::get_ip();
        if ( ! EtelID_Security::rate_limit( 'quick_register_' . $ip, 5, 3600 ) ) {
            wp_send_json_error( array( 'message' => 'Too many attempts. Please wait an hour and try again.' ) );
        }

        $data = array(
            'full_name' => isset( $_POST['full_name'] ) ? sanitize_text_field( wp_unslash( $_POST['full_name'] ) ) : '',
            'phone'     => isset( $_POST['phone'] )     ? sanitize_text_field( wp_unslash( $_POST['phone'] ) )     : '',
            'email'     => isset( $_POST['email'] )     ? sanitize_text_field( wp_unslash( $_POST['email'] ) )     : '',
        );
        /* Base64 image fields - not sanitize_text_field, same reasoning as register() above. */
        $data['photo_url']         = isset( $_POST['photo_url'] )         ? wp_unslash( $_POST['photo_url'] )         : '';
        $data['fayda_front_image'] = isset( $_POST['fayda_front_image'] ) ? wp_unslash( $_POST['fayda_front_image'] ) : '';
        $data['fayda_back_image']  = isset( $_POST['fayda_back_image'] )  ? wp_unslash( $_POST['fayda_back_image'] )  : '';

        /* Same validation the full register() handler above already
         * enforces (real-name format, phone format/duplicate, email
         * format/duplicate, given+father's-name match level) - this
         * fast-track form was missing all of it except phone format,
         * so a name like "x" or an already-registered email/name could
         * slip through here even though the full form blocked them. No
         * DOB is collected on this form, so the exact name+dob duplicate
         * check (find_duplicate_registration()) doesn't apply here, but
         * name_match_level() doesn't need one. */
        if ( empty( $data['full_name'] ) ) {
            wp_send_json_error( array( 'field' => 'full_name', 'message' => 'Full name is required.' ) );
        }
        if ( ! EtelID_Auth_Ajax::is_real_full_name( $data['full_name'] ) ) {
            wp_send_json_error( array( 'field' => 'full_name', 'message' => "Enter your real full name - at least two names (e.g. given name and father's name), each at least 3 letters, not repeated or a placeholder word." ) );
        }
        if ( empty( $data['phone'] ) ) {
            wp_send_json_error( array( 'field' => 'phone', 'message' => 'Phone number is required.' ) );
        }
        if ( ! EtelID_Security::valid_ethiopian_phone( $data['phone'] ) ) {
            wp_send_json_error( array( 'field' => 'phone', 'message' => 'Enter a valid Ethiopian phone number, e.g. 0912345678 or +251123456789.' ) );
        }
        /* The photo and the Fayda ID (both sides) are NO LONGER required here -
         * Quick Register is name + phone (+ optional email) only, to make
         * signing up fast. An account created without them is a "limited"
         * account: Ride only, until the photo and both Fayda sides are added
         * from the portal (see EtelID_Security::member_refresh_limited()). An older cached
         * form that still sends them is honoured, but only when they are real
         * images; anything else is dropped rather than stored. */
        foreach ( array( 'photo_url', 'fayda_front_image', 'fayda_back_image' ) as $img_key ) {
            if ( '' !== $data[ $img_key ] && 0 !== strpos( $data[ $img_key ], 'data:image' ) ) {
                $data[ $img_key ] = '';
            }
        }
        if ( ! empty( $data['email'] ) && ! is_email( $data['email'] ) ) {
            wp_send_json_error( array( 'field' => 'email', 'message' => 'Invalid email address.' ) );
        }
        if ( ! empty( $data['email'] ) && EtelID_DB::find_by_email( $data['email'] ) ) {
            wp_send_json_error( array( 'field' => 'email', 'message' => 'This email is already registered. Please use a different email, or sign in instead.' ) );
        }
        if ( 2 === EtelID_DB::name_match_level( $data['full_name'] ) ) {
            wp_send_json_error( array( 'field' => 'full_name', 'message' => "This given name and father's name match an existing record. Please also add your grandfather's name (a 3rd name) to tell the two of you apart." ) );
        }

        if ( ! class_exists( 'EtelID_Fayda' ) ) {
            wp_send_json_error( array( 'message' => 'Server setup error: EtelID_Fayda class is not loaded. Re-deploy includes/class-etelid-fayda.php and confirm etel-id.php requires it.' ) );
        }

        $phone = preg_replace( '/\s+/', '', $data['phone'] );
        $existing = EtelID_DB::find_by_phone( $phone );
        if ( $existing ) {
            wp_send_json_error( array( 'message' => 'An account with this phone number already exists. Please sign in instead.' ) );
        }
        $data['phone'] = $phone;

        $member_id = EtelID_Generator::create( $data );
        if ( is_wp_error( $member_id ) ) {
            wp_send_json_error( array( 'message' => $member_id->get_error_message() ) );
        }

        /* Same temp-password mechanism as EtelID_Fayda::quick_register() -
         * the must_change_password portal overlay expects exactly this
         * shape (pin_hash set, must_change_password=1). */
        $temp_password = EtelID_Fayda::generate_temp_password();
        $has_all_docs  = ( '' !== $data['photo_url'] && '' !== $data['fayda_front_image'] && '' !== $data['fayda_back_image'] );
        EtelID_DB::update_member( $member_id, array(
            'pin_hash'             => EtelID_Auth::hash_password( $temp_password ),
            'must_change_password' => 1,
            /* Photo + both Fayda sides on file -> normal level-1 account.
             * Otherwise a limited (Ride-only) account until they are added. */
            'kyc_level'            => $has_all_docs ? 1 : 0,
            'profile_limited'      => $has_all_docs ? 0 : 1,
        ) );

        $member = EtelID_DB::get_member( $member_id );
        EtelID_DB::log( $member_id, $member ? $member->etel_id : '', 'issued',
            'Self-service quick registration (fast form) - ' . ( $has_all_docs
                ? 'remaining profile fields to be completed later'
                : 'LIMITED account (Ride only) until profile photo and Fayda ID are added' ) );

        /* Auto login - unlike the admin-driven quick-register flow, the
         * registrant is present in this browser right now. */
        EtelID_Auth::create_session( $member_id );
        if ( $member ) EtelID_Mailer::send_welcome( $member );

        wp_send_json_success( array(
            'message'       => 'Account created! Finish the rest of your profile any time from the portal.',
            'etel_id'       => $member ? $member->etel_id : '',
            'fin'           => $member ? $member->fin : '',
            'temp_password' => $temp_password,
            'verify_url'    => $member ? EtelID_QR::verify_url( $member->etel_id ) : '',
            'redirect'      => home_url( '/portal/' ),
            'limited'       => ! $has_all_docs,
        ) );
    }

    /* ── Login ──────────────────────────────────────────── */
    public static function login() {
        check_ajax_referer( 'etelid_auth', 'nonce' );

        $ip = EtelID_Security::get_ip();
        if ( ! EtelID_Security::rate_limit( 'login_' . $ip, 10, 600 ) ) {
            wp_send_json_error( array( 'message' => 'Too many login attempts. Please wait 10 minutes.' ) );
        }

        $phone    = isset( $_POST['phone'] )    ? sanitize_text_field( wp_unslash( $_POST['phone'] ) )    : '';
        $password = isset( $_POST['password'] ) ? wp_unslash( $_POST['password'] ) : '';

        if ( ! $phone || ! $password ) {
            wp_send_json_error( array( 'message' => 'Phone number and password are required.' ) );
        }

        $totp = isset( $_POST['totp_code'] ) ? sanitize_text_field( wp_unslash( $_POST['totp_code'] ) ) : '';

        EtelID_Auth::purge_expired();
        $result = EtelID_Auth::login( $phone, $password, $totp );

        if ( is_wp_error( $result ) ) {
            $err = array( 'message' => $result->get_error_message() );
            if ( in_array( $result->get_error_code(), array( 'requires_2fa', 'bad_2fa' ), true ) ) $err['requires_2fa'] = true;
            wp_send_json_error( $err );
        }

        self::finish_login( $result['member'], $phone, $result['token'] );
    }

    /* ── Shared post-login handling (password login + WebAuthn login) ──
       $token is the Etelid_sessions token created by EtelID_Auth::login()
       or EtelID_WebAuthn::verify_login() - previously computed but never
       actually passed through to the browser, meaning the EtelID portal's
       own native login never gave the cross-app widget (etel-ai-widget.js,
       which reads localStorage 'etel_sso') anything to find, even though
       the member really was signed in. Delivery/Ride's own login writes
       this same token after going through their SSO bridge; this makes
       EtelID's own direct login do the same thing at the source. */
    private static function finish_login( $member, $phone = '', $token = '' ) {
        $redirect        = home_url( '/portal/' );
        $is_admin_member = EtelID_Auth::is_site_admin( $member );

        /* If member tried to access wp-admin but is NOT admin → portal only */
        $wanted_admin = ( isset( $_COOKIE['etelid_wants_wpadmin'] ) &&
                          $_COOKIE['etelid_wants_wpadmin'] === '1' ) ||
                        ( isset( $_POST['redirect'] ) && $_POST['redirect'] === 'wpadmin' );
        if ( $wanted_admin && ! $is_admin_member ) {
            /* Clear intent cookie */
            setcookie( 'etelid_wants_wpadmin', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN );
            /* Redirect to portal — non-admins never see wp-admin */
            $redirect = home_url( '/portal/' );
        }

        if ( $is_admin_member ) {
            /* Find the WP admin user */
            $wp_user = null;
            if ( $member->email ) {
                $wp_user = get_user_by( 'email', $member->email );
            }
            if ( ! $wp_user ) {
                $wp_user = get_user_by( 'email', get_option('admin_email') );
            }

            if ( $wp_user && user_can( $wp_user, 'manage_options' ) ) {
                /* Log them into WordPress */
                wp_set_current_user( $wp_user->ID );
                wp_set_auth_cookie( $wp_user->ID, true );

                /* Check if they came from wp-admin */
                $wanted_admin = ( isset( $_COOKIE['etelid_wpadmin_intent'] ) && $_COOKIE['etelid_wpadmin_intent'] === '1' )
                             || ( isset( $_POST['redirect'] ) && $_POST['redirect'] === 'wpadmin' );

                if ( $wanted_admin ) {
                    /* Clear intent cookie */
                    setcookie( 'etelid_wpadmin_intent', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN );
                    $redirect = admin_url();
                }
            }
        }

        wp_send_json_success( array(
            'message'        => 'Welcome back, ' . $member->full_name . '.',
            'etel_id'        => $member->etel_id,
            'redirect'       => $redirect,
            'is_admin'       => $is_admin_member,
            'remember'       => isset( $_POST['remember'] ) && $_POST['remember'] === '1',
            'phone'          => $phone ?: $member->phone,
            'sso_token'      => $token,
        ));
    }

    /* ── Device biometric registration (Face ID / Touch ID / fingerprint) ──
       Member must already be logged in -- this enrolls THIS device's own
       platform authenticator against their existing account. ── */
    public static function webauthn_register_options() {
        check_ajax_referer( 'etelid_auth', 'nonce' );
        $member = EtelID_Auth::get_current_member();
        if ( ! $member ) wp_send_json_error( array( 'message' => 'Please sign in first.' ) );
        wp_send_json_success( EtelID_WebAuthn::registration_options( $member ) );
    }

    public static function webauthn_register_verify() {
        check_ajax_referer( 'etelid_auth', 'nonce' );
        $member = EtelID_Auth::get_current_member();
        if ( ! $member ) wp_send_json_error( array( 'message' => 'Please sign in first.' ) );

        $credential = isset( $_POST['credential'] ) ? json_decode( wp_unslash( $_POST['credential'] ), true ) : null;
        if ( ! $credential ) wp_send_json_error( array( 'message' => 'Malformed response from device.' ) );

        $result = EtelID_WebAuthn::verify_registration( $member, $credential );
        if ( is_wp_error( $result ) ) wp_send_json_error( array( 'message' => $result->get_error_message() ) );

        wp_send_json_success( array( 'message' => 'Device biometric registered! You can now sign in with it.' ) );
    }

    /* ── Device biometric login (no password) ──────────────────────── */
    public static function webauthn_login_options() {
        check_ajax_referer( 'etelid_auth', 'nonce' );
        $identifier = isset( $_POST['identifier'] ) ? sanitize_text_field( wp_unslash( $_POST['identifier'] ) ) : '';

        $ip = EtelID_Security::get_ip();
        if ( ! EtelID_Security::rate_limit( 'wa_login_' . $ip, 15, 600 ) ) {
            wp_send_json_error( array( 'message' => 'Too many attempts. Please wait a few minutes.' ) );
        }

        /* Passkey-first: nothing typed = let the device offer its own passkeys. */
        if ( $identifier === '' ) {
            wp_send_json_success( EtelID_WebAuthn::login_options_discoverable() );
        }

        $options = EtelID_WebAuthn::login_options( $identifier );
        if ( is_wp_error( $options ) ) wp_send_json_error( array( 'message' => $options->get_error_message() ) );
        wp_send_json_success( $options );
    }

    public static function webauthn_login_verify() {
        check_ajax_referer( 'etelid_auth', 'nonce' );

        $ip = EtelID_Security::get_ip();
        if ( ! EtelID_Security::rate_limit( 'wa_verify_' . $ip, 30, 600 ) ) {
            wp_send_json_error( array( 'message' => 'Too many attempts. Please wait a few minutes.' ) );
        }

        $member_id  = isset( $_POST['member_id'] ) ? (int) $_POST['member_id'] : 0;
        $ses        = isset( $_POST['ses'] ) ? sanitize_text_field( wp_unslash( $_POST['ses'] ) ) : '';
        $credential = isset( $_POST['credential'] ) ? json_decode( wp_unslash( $_POST['credential'] ), true ) : null;
        if ( ! is_array( $credential ) || ! isset( $credential['id'], $credential['response'] ) ) {
            wp_send_json_error( array( 'message' => 'Malformed response from device.' ) );
        }

        $chal_key = '';
        if ( $ses !== '' ) {
            /* Passkey-first: the account is whoever owns the credential that signed. */
            if ( ! preg_match( '/^[A-Za-z0-9_-]{16,32}$/', $ses ) ) {
                wp_send_json_error( array( 'message' => 'Sign-in failed. Please sign in with your password.' ) );
            }
            $member_id = EtelID_WebAuthn::member_id_for_credential( (string) $credential['id'] );
            $chal_key  = 'dlogin_' . $ses;
            if ( ! $member_id ) wp_send_json_error( array( 'message' => 'Sign-in failed. Please sign in with your password.' ) );
        }
        if ( ! $member_id ) wp_send_json_error( array( 'message' => 'Malformed response from device.' ) );

        $result = EtelID_WebAuthn::verify_login( $member_id, $credential, $chal_key );
        if ( is_wp_error( $result ) ) wp_send_json_error( array( 'message' => $result->get_error_message() ) );

        self::finish_login( $result['member'], '', $result['token'] );
    }

    /* ── My devices: list and remove (member must be signed in) ─────── */
    public static function webauthn_devices() {
        check_ajax_referer( 'etelid_auth', 'nonce' );
        $member = EtelID_Auth::get_current_member();
        if ( ! $member ) wp_send_json_error( array( 'message' => 'Please sign in first.' ) );
        $list = array();
        foreach ( EtelID_WebAuthn::devices_for_member( $member->id ) as $d ) {
            $list[] = array(
                'id'           => $d->credential_id,
                'label'        => $d->label !== '' ? $d->label : 'Device',
                'created_at'   => $d->created_at,
                'last_used_at' => $d->last_used_at,
            );
        }
        wp_send_json_success( array( 'devices' => $list, 'max' => EtelID_WebAuthn::MAX_DEVICES ) );
    }

    public static function webauthn_remove() {
        check_ajax_referer( 'etelid_auth', 'nonce' );
        $member = EtelID_Auth::get_current_member();
        if ( ! $member ) wp_send_json_error( array( 'message' => 'Please sign in first.' ) );
        $cred = isset( $_POST['credential_id'] ) ? sanitize_text_field( wp_unslash( $_POST['credential_id'] ) ) : '';
        if ( $cred === '' ) wp_send_json_error( array( 'message' => 'Missing device.' ) );
        if ( ! EtelID_WebAuthn::remove_device( $member->id, $cred ) ) {
            wp_send_json_error( array( 'message' => 'That device was not found.' ) );
        }
        EtelID_DB::log( $member->id, $member->etel_id, 'bio_enrolled', 'Device sign-in removed by the member' );
        wp_send_json_success( array( 'message' => 'Device removed.' ) );
    }

    /* ── Two-step sign-in set-up (member must be signed in) ─────────── */
    private static function twofa_member() {
        check_ajax_referer( 'etelid_auth', 'nonce' );
        $member = EtelID_Auth::get_current_member();
        if ( ! $member ) wp_send_json_error( array( 'message' => 'Please sign in first.' ) );
        if ( ! EtelID_Security::rate_limit( 'twofa_' . $member->id, 30, 600 ) ) {
            wp_send_json_error( array( 'message' => 'Too many attempts. Please wait a few minutes.' ) );
        }
        return $member;
    }
    private static function twofa_code() {
        return isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';
    }

    public static function twofa_status() {
        $member = self::twofa_member();
        wp_send_json_success( array(
            'enabled'      => EtelID_2FA::is_enabled( $member->id ),
            'backup_left'  => EtelID_2FA::backup_codes_left( $member->id ),
        ) );
    }

    /* Starting set-up asks for the current password, so a stolen browser
       session alone cannot lock the real owner out behind a new code. */
    public static function twofa_begin() {
        $member = self::twofa_member();
        $pw = isset( $_POST['password'] ) ? wp_unslash( $_POST['password'] ) : '';
        if ( $pw === '' || empty( $member->pin_hash ) || ! EtelID_Auth::verify_password( $pw, $member->pin_hash ) ) {
            wp_send_json_error( array( 'message' => 'Enter your current password to continue.' ) );
        }
        $r = EtelID_2FA::begin( $member );
        if ( is_wp_error( $r ) ) wp_send_json_error( array( 'message' => $r->get_error_message() ) );
        wp_send_json_success( $r );
    }

    public static function twofa_enable() {
        $member = self::twofa_member();
        $r = EtelID_2FA::enable( $member, self::twofa_code() );
        if ( is_wp_error( $r ) ) wp_send_json_error( array( 'message' => $r->get_error_message() ) );
        wp_send_json_success( array( 'backup_codes' => $r ) );
    }

    public static function twofa_disable() {
        $member = self::twofa_member();
        $r = EtelID_2FA::disable( $member, self::twofa_code() );
        if ( is_wp_error( $r ) ) wp_send_json_error( array( 'message' => $r->get_error_message() ) );
        wp_send_json_success( array( 'message' => 'Two-step sign-in is off.' ) );
    }

    public static function twofa_backup_codes() {
        $member = self::twofa_member();
        $r = EtelID_2FA::regenerate_backup( $member, self::twofa_code() );
        if ( is_wp_error( $r ) ) wp_send_json_error( array( 'message' => $r->get_error_message() ) );
        wp_send_json_success( array( 'backup_codes' => $r ) );
    }

    /* ── Browser CSP violation reports (no login, no nonce: the browser sends them) ── */
    public static function csp_report() {
        $ip = EtelID_Security::get_ip();
        if ( EtelID_Security::rate_limit( 'csp_report_' . $ip, 60, 600 ) ) {
            $raw = file_get_contents( 'php://input', false, null, 0, 8192 );
            EtelID_Security::record_csp_report( $raw );
        }
        status_header( 204 );
        exit;
    }

    /* ── Logout ─────────────────────────────────────────── */
    public static function logout() {
        check_ajax_referer( 'etelid_auth', 'nonce' );
        EtelID_Auth::logout();
        wp_send_json_success( array( 'redirect' => home_url( '/login/' ) ) );
    }

    /* ── Portal data (member dashboard) ─────────────────── */
    public static function portal_data() {
        check_ajax_referer( 'etelid_auth', 'nonce' );
        $member = EtelID_Auth::get_current_member();
        if ( ! $member ) {
            wp_send_json_error( array( 'redirect' => home_url( '/login/' ) ) );
        }
        $companies = EtelID_DB::get_companies( $member->id );
        $log       = EtelID_DB::get_log( $member->id, 10 );
        wp_send_json_success( array(
            'member'    => array(
                'id'           => $member->id,
                'etel_id'      => $member->etel_id,
                'fin'          => $member->fin,
                'full_name'    => $member->full_name,
                'full_name_am' => $member->full_name_am,
                'phone'        => $member->phone,
                'email'        => $member->email,
                'dob'          => $member->dob,
                'dob_eth'      => $member->dob_eth,
                'sex'          => $member->sex,
                'blood_type'   => $member->blood_type,
                'nationality'  => $member->nationality,
                'region'       => $member->region,
                'zone'         => $member->zone,
                'primary_company' => $member->primary_company,
                'role'         => $member->role,
                'tier'         => $member->tier,
                'status'       => $member->status,
                'issue_date'   => $member->issue_date,
                'issue_date_eth' => $member->issue_date_eth,
                'expiry_date'  => $member->expiry_date,
                'expiry_date_eth' => $member->expiry_date_eth,
                'card_number'  => $member->card_number,
                'serial_number'=> $member->serial_number,
                'qr_scan_count'=> $member->qr_scan_count,
                'last_verified'=> $member->last_verified,
                'photo_url'    => $member->photo_url,
                'kyc_level'           => isset( $member->kyc_level ) ? (int) $member->kyc_level : 0,
                'profile_limited'     => ! empty( $member->profile_limited ) ? 1 : 0,
                'must_change_password'=> isset( $member->must_change_password ) ? (int) $member->must_change_password : 0,
                'pass2_completed_at'  => isset( $member->pass2_completed_at ) ? $member->pass2_completed_at : null,
            ),
            'companies'    => $companies,
            'recent_log'   => $log,
            'verify_url'   => EtelID_QR::verify_url( $member->etel_id ),
            'print_url'    => home_url( '/print/' . $member->etel_id ),
            'card_qr_src'  => EtelID_QR::src( $member->etel_id, 200 ),
        ));
    }

    /* ── Change password ─────────────────────────────────── */
    public static function change_password() {
        check_ajax_referer( 'etelid_auth', 'nonce' );
        $member = EtelID_Auth::get_current_member();
        if ( ! $member ) wp_send_json_error( array( 'message' => 'Not logged in.' ) );

        $old = isset( $_POST['old_password'] ) ? wp_unslash( $_POST['old_password'] ) : '';
        $new = isset( $_POST['new_password'] ) ? wp_unslash( $_POST['new_password'] ) : '';
        $cfm = isset( $_POST['confirm_password'] ) ? wp_unslash( $_POST['confirm_password'] ) : '';

        if ( ! EtelID_Auth::verify_password( $old, $member->pin_hash ) ) {
            wp_send_json_error( array( 'message' => 'Current password is incorrect.' ) );
        }
        // HARDENING: was a 6-character minimum, inconsistent with the
        // 8-character minimum EtelID_Auth::register() already enforces
        // (see that function's own hardening note) - a member could
        // register with a strong password and then quietly downgrade to
        // a weaker one here. Match the same floor everywhere passwords
        // are set.
        if ( strlen( $new ) < 8 ) {
            wp_send_json_error( array( 'message' => 'New password must be at least 8 characters.' ) );
        }
        if ( $new !== $cfm ) {
            wp_send_json_error( array( 'message' => 'New passwords do not match.' ) );
        }

        EtelID_DB::update_member( $member->id, array(
            'pin_hash'             => EtelID_Auth::hash_password( $new ),
            'must_change_password' => 0,
        ));
        EtelID_DB::log( $member->id, $member->etel_id, 'updated', 'Password changed via portal' );
        /* End every OTHER session; keep the one making this change. */
        $keep_tok = isset( $_COOKIE[ EtelID_Auth::SESSION_COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ EtelID_Auth::SESSION_COOKIE ] ) ) : '';
        EtelID_Auth::revoke_all_sessions( $member->id, $keep_tok );
        wp_send_json_success( array( 'message' => 'Password changed successfully.' ) );
    }

    /* ── Pass 2: biometric + additional KYC info (unlocks EtelPay) ──
     * Self-service version of EtelID_Biometric::enroll() — authenticated
     * by the member's own session cookie, not an admin/scanner-app token. */
    public static function pass2_submit() {
        check_ajax_referer( 'etelid_auth', 'nonce' );
        $member = EtelID_Auth::get_current_member();
        if ( ! $member ) wp_send_json_error( array( 'message' => 'Not logged in.' ) );

        $face_image = isset( $_POST['face_image'] ) ? wp_unslash( $_POST['face_image'] ) : '';
        if ( ! $face_image || strpos( $face_image, 'data:image' ) !== 0 ) {
            wp_send_json_error( array( 'message' => 'A face photo is required to complete verification.' ) );
        }

        $occupation       = isset( $_POST['occupation'] )        ? sanitize_text_field( wp_unslash( $_POST['occupation'] ) )        : '';
        $income_range     = isset( $_POST['income_range'] )      ? sanitize_text_field( wp_unslash( $_POST['income_range'] ) )      : '';
        $source_of_funds  = isset( $_POST['source_of_funds'] )   ? sanitize_text_field( wp_unslash( $_POST['source_of_funds'] ) )   : '';
        $kin_name         = isset( $_POST['next_of_kin_name'] )  ? sanitize_text_field( wp_unslash( $_POST['next_of_kin_name'] ) )  : '';
        $kin_phone        = isset( $_POST['next_of_kin_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['next_of_kin_phone'] ) ) : '';

        if ( ! $occupation || ! $income_range || ! $source_of_funds || ! $kin_name || ! $kin_phone ) {
            wp_send_json_error( array( 'message' => 'Please fill in all the additional information fields.' ) );
        }

        /* 1. Enroll face biometric (reuses the existing biometric engine). */
        $result = EtelID_Biometric::enroll( $member->id, $member->etel_id, 'face', $face_image, 85, 'Self-Service Pass 2' );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        /* 2. Save additional KYC info + bump to kyc_level 2 (EtelPay unlocked). */
        EtelID_DB::update_member( $member->id, array(
            'kyc_level'          => 2,
            'pass2_completed_at' => current_time( 'mysql' ),
            'occupation'         => $occupation,
            'income_range'       => $income_range,
            'source_of_funds'    => $source_of_funds,
            'next_of_kin_name'   => $kin_name,
            'next_of_kin_phone'  => $kin_phone,
        ));

        EtelID_DB::log( $member->id, $member->etel_id, 'pass2_completed', 'Second verification pass completed — EtelPay unlocked' );

        wp_send_json_success( array( 'message' => 'Verification complete. Etel Pay is now unlocked.' ) );
    }

    /** Member self-service: upload Fayda ID front+back from their own profile page.
     *  Unlike pass2_submit() above (face+info, instant), this goes into a pending
     *  review queue — an admin must approve it from the Members Controller before
     *  kyc_level is bumped to 2. Trust boundary: this is the member attesting to
     *  their own documents, so it needs a human check before it unlocks anything. */
    public static function fayda_id_submit() {
        check_ajax_referer( 'etelid_auth', 'nonce' );
        $member = EtelID_Auth::get_current_member();
        if ( ! $member ) wp_send_json_error( array( 'message' => 'Not logged in.' ) );

        $front = isset( $_POST['front_image'] ) ? wp_unslash( $_POST['front_image'] ) : '';
        $back  = isset( $_POST['back_image'] )  ? wp_unslash( $_POST['back_image'] )  : '';

        if ( ! $front || strpos( $front, 'data:image' ) !== 0 ) wp_send_json_error( array( 'message' => 'Please add a photo of the front of your Fayda ID.' ) );
        if ( ! $back  || strpos( $back,  'data:image' ) !== 0 ) wp_send_json_error( array( 'message' => 'Please add a photo of the back of your Fayda ID.' ) );

        EtelID_DB::update_member( $member->id, array(
            'fayda_front_image'  => $front,
            'fayda_back_image'   => $back,
            'fayda_captured_at'  => current_time( 'mysql' ),
            'pass2_status'       => 'pending',
            'pass2_submitted_at' => current_time( 'mysql' ),
            'pass2_review_note'  => '',
        ) );

        EtelID_DB::log( $member->id, $member->etel_id, 'updated', 'Member submitted Fayda ID photos for Pass 2 review.' );

        /* Both sides are now on file - if a profile photo is too, the
         * limited (Ride-only) account is unlocked straight away. The admin
         * review that follows is only for the second pass (Etel Pay). */
        $now_unlimited = EtelID_Security::member_refresh_limited( $member->id );
        $msg = 'Submitted! An admin will review your Fayda ID shortly.';
        if ( EtelID_Security::member_is_limited( $member ) ) {
            $msg = $now_unlimited
                ? 'Submitted! Your account is now unlocked for all Etel services. An admin will review your Fayda ID for Etel Pay.'
                : 'Submitted! Add your profile photo too and your account will be fully unlocked.';
        }

        wp_send_json_success( array( 'message' => $msg, 'limited' => ! $now_unlimited ) );
    }

    /* ── Fayda Quick Register — portal version ───────────────
     * Same logic as EtelID_Admin::page_fayda_register(), but callable
     * from the member portal itself (authenticated by the Etel-ID
     * session, not a WordPress login) so the admin never has to leave
     * the portal / touch wp-admin to register a new member. */
    public static function fayda_quick_register() {
        /* Passing $die=false converts a nonce failure into a normal
         * return value instead of a silent wp_die(-1) — without this,
         * an expired/stale nonce produces a bare "-1" response body
         * that the front-end can't show a useful message for. */
        if ( ! check_ajax_referer( 'etelid_auth', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => 'Your session has expired. Please refresh the page (pull down to reload) and try again.' ) );
        }

        /* Swallow any stray PHP notice/warning output from here on so
         * it can never corrupt the JSON response body. */
        ob_start();

        try {
            $member = EtelID_Auth::get_current_member();
            if ( ! $member || ! EtelID_Auth::is_site_admin( $member ) ) {
                ob_end_clean();
                wp_send_json_error( array( 'message' => 'Not authorized.' ) );
            }

            $fields = array(
                'full_name','full_name_am','dob','sex','nationality',
                'region','zone','woreda','phone','email','fayda_fin',
                'primary_company_slug','role','department','employee_number','notes',
            );
            $data = array();
            foreach ( $fields as $f ) {
                $data[ $f ] = isset( $_POST[ $f ] ) ? sanitize_text_field( wp_unslash( $_POST[ $f ] ) ) : '';
            }
            /* Large base64 image fields — not sanitize_text_field (would mangle base64). */
            $data['fayda_front_image'] = isset( $_POST['fayda_front_image'] ) ? wp_unslash( $_POST['fayda_front_image'] ) : '';
            $data['fayda_back_image']  = isset( $_POST['fayda_back_image']  ) ? wp_unslash( $_POST['fayda_back_image']  ) : '';
            $data['fayda_ocr_raw']     = isset( $_POST['fayda_ocr_raw']     ) ? sanitize_textarea_field( wp_unslash( $_POST['fayda_ocr_raw'] ) ) : '';
            $data['photo_url']         = $data['fayda_front_image'];
            $data['_registered_by_label'] = $member->full_name . ' (' . $member->etel_id . ')';

            if ( ! $data['full_name'] || ! $data['dob'] || ! $data['phone'] ) {
                ob_end_clean();
                wp_send_json_error( array( 'message' => 'Full name, date of birth, and phone are required.' ) );
            }

            if ( ! class_exists( 'EtelID_Fayda' ) ) {
                ob_end_clean();
                wp_send_json_error( array( 'message' => 'Server setup error: EtelID_Fayda class is not loaded. Re-deploy includes/class-etelid-fayda.php and confirm etel-id.php requires it.' ) );
            }

            $outcome = EtelID_Fayda::quick_register( $data );
            if ( is_wp_error( $outcome ) ) {
                ob_end_clean();
                wp_send_json_error( array( 'message' => $outcome->get_error_message() ?: ( 'Registration failed (code: ' . $outcome->get_error_code() . ').' ) ) );
            }

            $preferred_channel = isset( $_POST['notify_channel'] ) ? sanitize_key( wp_unslash( $_POST['notify_channel'] ) ) : 'auto';
            $notify = class_exists( 'EtelID_Notify' )
                ? EtelID_Notify::deliver( $outcome['member'], $outcome['temp_password'], $preferred_channel )
                : array( 'auto_sent' => false, 'auto_error' => 'EtelID_Notify class not loaded.', 'whatsapp_link' => '', 'telegram_link' => '', 'message_text' => '' );

            ob_end_clean();
            wp_send_json_success( array(
                'etel_id'       => $outcome['member']->etel_id,
                'phone'         => $outcome['member']->phone,
                'temp_password' => $outcome['temp_password'],
                'notify'        => $notify,
            ) );

        } catch ( Exception $e ) {
            ob_end_clean();
            wp_send_json_error( array( 'message' => 'Server error: ' . $e->getMessage() ) );
        }
    }

    /* ── Profile Update v3.3.1 ─────────────────────────────── */
    public static function profile_update() {
        check_ajax_referer( 'etelid_profile_update', 'nonce' );

        $member = EtelID_Auth::get_current_member();
        if ( ! $member ) {
            wp_send_json_error( array( 'message' => 'Not logged in.' ) );
            return; /* Ensure execution stops */
        }

        /* Fields the member is allowed to update */
        $fields = array(
            'full_name', 'full_name_am', 'dob', 'sex', 'blood_type',
            'nationality', 'phone', 'email', 'region', 'zone', 'woreda',
            'role', 'department', 'photo_url',
        );

        $data = array();
        foreach ( $fields as $f ) {
            if ( ! isset( $_POST[ $f ] ) ) continue;
            $raw = wp_unslash( $_POST[ $f ] );
            if ( $f === 'email' ) {
                $data[ $f ] = sanitize_email( $raw );
            } elseif ( $f === 'photo_url' ) {
                /* Photo can be a base64 data URL (data:image/...) from FileReader
                 * or a regular URL. esc_url_raw strips data: so handle both. */
                if ( substr( $raw, 0, 5 ) === 'data:' ) {
                    /* Validate it's actually an image data URL */
                    if ( preg_match( '/^data:image\/(jpeg|jpg|png|webp|gif);base64,/', $raw ) ) {
                        $data[ $f ] = $raw; /* Store full base64 */
                    }
                } else {
                    $data[ $f ] = esc_url_raw( $raw );
                }
            } else {
                $data[ $f ] = sanitize_text_field( $raw );
            }
        }

        /* Resolve company slug to name */
        if ( isset( $_POST['primary_company_slug'] ) ) {
            $companies = EtelID_Generator::companies();
            $slug = sanitize_key( $_POST['primary_company_slug'] );
            if ( isset( $companies[ $slug ] ) ) {
                $data['primary_company'] = $companies[ $slug ]['name'];
            }
        }

        /* Validation */
        if ( empty( $data['full_name'] ) ) {
            wp_send_json_error( array( 'message' => 'Full name is required.' ) );
            return;
        }
        if ( ! empty( $data['email'] ) && ! is_email( $data['email'] ) ) {
            wp_send_json_error( array( 'message' => 'Invalid email address.' ) );
            return;
        }

        /* Recalculate Ethiopian DOB if changed */
        if ( ! empty( $data['dob'] ) && class_exists( 'EtelID_EthCalendar' ) ) {
            $data['dob_eth'] = EtelID_EthCalendar::to_ethiopian( $data['dob'] );
        }

        /* Save */
        EtelID_DB::update_member( $member->id, $data );
        EtelID_DB::log( $member->id, $member->etel_id, 'updated', 'Profile updated by member via portal' );

        /* A photo added here may be the last thing a limited (Ride-only)
         * account was missing. */
        $now_unlimited = EtelID_Security::member_refresh_limited( $member->id );
        $msg = 'Profile updated successfully! Your card now reflects the changes.';
        if ( EtelID_Security::member_is_limited( $member ) && $now_unlimited ) {
            $msg .= ' Your account is now fully unlocked for all Etel services.';
        }

        wp_send_json_success( array( 'message' => $msg, 'limited' => ! $now_unlimited ) );
    }

    /* ── Name validation helpers ───────────────────────────
     * Mirror templates/auth-page.php's isRealFullName()/isRealAmharicName()
     * (defense-in-depth for register(), same reasoning as the phone/dob/
     * region/zone checks above). Requires at least two names (the standard
     * Ethiopian given-name + father's-name convention), rejecting a single
     * short word like "Da" that previously passed as long as it was
     * non-empty. */
    /* Placeholder/test-data words that pass a plain "is it letters" check
     * but are never a real Ethiopian given/father's name - "Da fa" (a
     * genuine case seen live: two 2-letter nonsense words) passed the
     * original 2-letter minimum. Raised to 3, added a
     * same-word-repeated-twice check ("Da Da"), and a blocklist for
     * common placeholder/keyboard-mash entries. This still cannot prove a
     * name is real (no authoritative Ethiopian name dictionary is used
     * here - that would risk rejecting genuine unusual names), only that
     * it isn't an obvious placeholder. */
    private static $name_blocklist = array(
        'test','tests','testing','asdf','asdfg','qwerty','xxx','xxxx','aaa','aaaa',
        'sample','dummy','none','unknown','foo','bar','abc','xyz','name','fname',
        'lname','firstname','lastname','fullname','na','nan','null','undefined',
        'admin','user','demo',
    );
    private static function is_placeholder_word( $w ) {
        $lw = strtolower( $w );
        if ( in_array( $lw, self::$name_blocklist, true ) ) return true;
        /* A word that's the same letter repeated ("aaaa", "mmmm") */
        if ( preg_match( '/^([a-z])\1+$/i', $w ) ) return true;
        return false;
    }
    public static function is_real_full_name( $name ) {
        $words = preg_split( '/\s+/', trim( $name ), -1, PREG_SPLIT_NO_EMPTY );
        if ( count( $words ) < 2 ) return false;
        $seen = array();
        foreach ( $words as $w ) {
            if ( ! preg_match( "/^[A-Za-z'-]{3,}$/", $w ) ) return false;
            if ( self::is_placeholder_word( $w ) ) return false;
            $lw = strtolower( $w );
            if ( isset( $seen[ $lw ] ) ) return false; /* same word repeated */
            $seen[ $lw ] = true;
        }
        return true;
    }
    public static function is_real_amharic_name( $name_am ) {
        $words = preg_split( '/\s+/', trim( $name_am ), -1, PREG_SPLIT_NO_EMPTY );
        if ( count( $words ) < 2 ) return false;
        $seen = array();
        foreach ( $words as $w ) {
            $amharic_chars = preg_match_all( '/[\x{1200}-\x{137F}]/u', $w );
            if ( mb_strlen( $w, 'UTF-8' ) < 3 || $amharic_chars < 3 ) return false;
            if ( isset( $seen[ $w ] ) ) return false; /* same word repeated */
            $seen[ $w ] = true;
        }
        return true;
    }
}
