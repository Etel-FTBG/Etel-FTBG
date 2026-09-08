<?php
/**
 * Etel-ID Premium — Self-service Password Reset (v1.0)
 *
 * Public "Forgot Password" flow: enter phone or Etel-ID -> receive a
 * 6-digit code by email (Telegram too, if the member has already linked
 * their chat_id) -> enter the code + a new password.
 *
 * Before this, the ONLY way to give a member a working password was an
 * admin manually doing it in wp-admin (admin/views/password-reset.php,
 * which requires manage_options) - members had no self-service path at
 * all, including the very first login after applying (pin_hash starts
 * empty at creation).
 *
 * PHP 5.6+ compatible.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class EtelID_Password_Reset {

    public static function init() {
        add_action( 'wp_ajax_etelid_forgot_password',        array( __CLASS__, 'request_code' ) );
        add_action( 'wp_ajax_nopriv_etelid_forgot_password', array( __CLASS__, 'request_code' ) );
        add_action( 'wp_ajax_etelid_reset_password',         array( __CLASS__, 'reset_password' ) );
        add_action( 'wp_ajax_nopriv_etelid_reset_password',  array( __CLASS__, 'reset_password' ) );
        // Admin-only in effect (admin_issue_manual_link() checks
        // EtelID_Auth::is_site_admin() internally, the real gate) - but
        // registers BOTH variants for the exact reason just found and
        // fixed in class-etelid-telegram.php: an EtelID admin/support
        // staff member is not necessarily also a real WordPress-core
        // user, so wp_ajax_ alone would silently never fire for them,
        // identical to the "Could not get Telegram link" bug from
        // earlier tonight. The nopriv variant is safe here specifically
        // because the function's own internal check is the real
        // authorization boundary, not which WP hook fired it.
        add_action( 'wp_ajax_etelid_admin_issue_reset_link',        array( __CLASS__, 'admin_issue_manual_link' ) );
        add_action( 'wp_ajax_nopriv_etelid_admin_issue_reset_link', array( __CLASS__, 'admin_issue_manual_link' ) );
    }

    /* ── Step 1: request a code ───────────────────────────────
     * Always responds with the same generic success message whether or
     * not a matching account was found - so this can never be used to
     * check which phone numbers/Etel-IDs are registered. */
    public static function request_code() {
        if ( ! check_ajax_referer( 'etelid_public', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => 'Your session has expired - please refresh the page and try again.' ) );
        }
        $ip = EtelID_Security::get_ip();
        /* Was a single flat per-IP cap (5/hour, any identifier) - meant
         * "hammer one account 5 times" and "test 5 different phone
         * numbers once each" cost exactly the same budget, so anyone
         * legitimately testing multiple numbers from one network got
         * blocked almost immediately. Split into two tiers instead:
         *   - a tight cap keyed to IP + the specific identifier being
         *     tried, so switching phone numbers naturally gets a fresh
         *     budget instead of sharing one pool
         *   - a much looser cap on the raw IP alone, purely to stop one
         *     IP from being able to try an unbounded number of
         *     different accounts (real volumetric abuse), which the
         *     per-identifier cap alone wouldn't catch
         * The existing per-account cap further down (reset_acct_<id>,
         * IP-independent) is unchanged - that's what actually stops one
         * specific account from being hammered regardless of source. */
        if ( ! EtelID_Security::rate_limit( 'reset_ip_raw_' . $ip, 30, 3600 ) ) {
            wp_send_json_error( array( 'message' => 'Too many requests from this network. Please try again later.' ) );
        }

        $identifier = isset( $_POST['identifier'] ) ? sanitize_text_field( wp_unslash( $_POST['identifier'] ) ) : '';
        /* Which channel the user picked on the reset page - 'email',
         * 'telegram', 'whatsapp', 'sms', or 'auto' (the original
         * cascade behavior, kept as the default so any older caller of
         * this same action that doesn't send a channel still works
         * exactly as before). Never used to reveal anything: if the
         * member doesn't actually have the chosen channel available,
         * this silently sends nothing and returns the same generic
         * message as always - same anti-enumeration posture as every
         * other branch in this method. */
        $channel_choice = isset( $_POST['channel'] ) ? sanitize_text_field( wp_unslash( $_POST['channel'] ) ) : 'auto';
        if ( ! in_array( $channel_choice, array( 'auto', 'email', 'telegram', 'whatsapp', 'sms' ), true ) ) {
            $channel_choice = 'auto';
        }
        $generic_ok = array(
            'message' => 'If that account exists, a reset code has been sent to the email on file.',
        );
        if ( ! $identifier ) wp_send_json_success( $generic_ok );

        /* Tight per-(IP + identifier) cap - this is the one that
         * actually matters for "someone hammering this one phone
         * number/Etel-ID from this one place". Hashed since the key
         * itself is stored in a transient name (visible in the DB) and
         * there's no reason to leave a plaintext phone number sitting
         * in an option_name column. Checked after the generic
         * empty-identifier bail above so an empty submission can't
         * burn budget from either tier. */
        if ( ! EtelID_Security::rate_limit( 'reset_ip_id_' . md5( $ip . '|' . $identifier ), 5, 3600 ) ) {
            wp_send_json_success( $generic_ok ); // generic, same as "no matching account" - don't reveal a rate limit exists for this specific identifier
        }

        $member = EtelID_DB::get_by_etel_id( $identifier );
        if ( ! $member ) $member = EtelID_DB::find_by_phone( $identifier );
        if ( ! $member ) wp_send_json_success( $generic_ok ); // don't reveal non-existence

        /* ESCALATING PER-ACCOUNT SCHEDULE - replaces the old flat "5 per
         * hour" limiter with a graduated one: 3 tries immediately, then
         * a 1-minute wait, then 2 more tries, then a 5-minute wait, then
         * 1 try, then a 30-minute wait, then 1 final try - 7 total
         * self-service attempts - after which the account is locked out
         * of self-service reset entirely and directed to contact
         * support, who can issue a manual reset link (see
         * EtelID_Password_Reset::admin_issue_manual_link() below,
         * bypasses this schedule entirely since a support agent has
         * already verified the person's identity some other way). */
        $gate = self::check_reset_schedule( $member->id );
        if ( ! $gate['allowed'] ) {
            EtelID_DB::log( $member->id, $member->etel_id, 'reset_rate_limited',
                $gate['contact_support']
                    ? 'Password reset blocked - all 7 self-service attempts used, account now requires support to issue a manual link'
                    : 'Password reset blocked - waiting ' . $gate['wait_seconds'] . 's before the next allowed attempt (escalating schedule)' );
            wp_send_json_success( array(
                'message' => $gate['contact_support']
                    ? "You've reached the limit for reset attempts. Please contact support and we'll send you a reset link directly."
                    : 'Please wait ' . self::human_wait( $gate['wait_seconds'] ) . ' before requesting another code.',
                'contact_support' => $gate['contact_support'],
                'wait_seconds'    => $gate['contact_support'] ? null : $gate['wait_seconds'],
            ) );
        }

        global $wpdb;
        $code      = str_pad( (string) wp_rand( 0, 999999 ), 6, '0', STR_PAD_LEFT );
        $code_hash = password_hash( $code, PASSWORD_DEFAULT );

        $sent_email    = false;
        $sent_telegram = false;
        $sent_whatsapp = false;
        $sent_sms      = false;

        /* When the user picked a specific channel, only attempt that
         * one - if it's not actually available for this member (no
         * email on file, Telegram not linked, etc.) nothing is sent,
         * same generic response either way. 'auto' keeps the original
         * cascade: try email, then Telegram, then WhatsApp, then SMS,
         * stopping at the first success - this is also what still runs
         * for any request that doesn't specify a channel at all. */
        $try_email    = ( $channel_choice === 'auto' || $channel_choice === 'email' );
        $try_telegram = ( $channel_choice === 'auto' || $channel_choice === 'telegram' );
        $try_whatsapp = ( $channel_choice === 'auto' || $channel_choice === 'whatsapp' );
        $try_sms      = ( $channel_choice === 'auto' || $channel_choice === 'sms' );

        if ( $try_email && ! empty( $member->email ) ) {
            $sent_email = (bool) EtelID_Mailer::send_reset_code( $member, $code );
        }
        // Telegram delivery, only if this member has already linked
        // their chat_id (see EtelID_Telegram_Link for how that happens).
        if ( ! $sent_email && $try_telegram && ! empty( $member->tg_chat_id ) && class_exists( 'EtelID_Notify' ) ) {
            $tg_result = self::send_via_telegram( $member, $code );
            $sent_telegram = ( $tg_result === true );
        }
        /* WHATSAPP + SMS — both go straight to the member's phone number,
         * so they work regardless of whether anything's been linked.
         * In 'auto' mode these are only attempted after email and
         * Telegram both come up empty (the original fallback-of-last-
         * resort behavior); when the user explicitly chose WhatsApp or
         * SMS, honor that choice directly instead of waiting on the
         * other two to fail first. */
        if ( ! empty( $member->phone ) ) {
            if ( $channel_choice === 'whatsapp' ) {
                $sent_whatsapp = self::send_via_whatsapp( $member, $code );
            } elseif ( $channel_choice === 'sms' ) {
                $sent_sms = self::send_via_sms( $member, $code );
            } elseif ( $channel_choice === 'auto' && ! $sent_email && ! $sent_telegram ) {
                $sent_whatsapp = self::send_via_whatsapp( $member, $code );
                if ( ! $sent_whatsapp ) {
                    $sent_sms = self::send_via_sms( $member, $code );
                }
            }
        }

        $channel_used = $sent_telegram ? 'telegram' : ( $sent_whatsapp ? 'whatsapp' : ( $sent_sms ? 'sms' : 'email' ) );

        if ( $sent_email || $sent_telegram || $sent_whatsapp || $sent_sms ) {
            $wpdb->query( $wpdb->prepare(
                "INSERT INTO " . EtelID_DB::t_password_resets() . "
                 (member_id, code_hash, channel, expires_at, used, created_at)
                 VALUES (%d, %s, %s, NOW() + INTERVAL 15 MINUTE, 0, NOW())",
                $member->id, $code_hash, $channel_used
            ));
            EtelID_DB::log( $member->id, $member->etel_id, 'reset_requested',
                'Password reset code sent via ' . $channel_used
                . ( $channel_choice !== 'auto' ? ' (user selected: ' . $channel_choice . ')' : ' (auto)' ) );
        } elseif ( empty( $member->email ) && empty( $member->tg_chat_id ) && empty( $member->phone ) ) {
            EtelID_DB::log( $member->id, $member->etel_id, 'reset_no_channel',
                'Password reset requested but this account has no email, Telegram, or phone on file - nothing could be sent' );
        } else {
            EtelID_DB::log( $member->id, $member->etel_id, 'reset_delivery_failed',
                'Password reset attempted via ' . ( $channel_choice !== 'auto' ? 'the user-selected channel (' . $channel_choice . ')' : 'every configured channel (email/Telegram/WhatsApp/SMS)' ) . ', but delivery failed' );
        }
        // Respond with the same generic message either way - if neither
        // channel is configured for this member, they simply won't
        // receive anything, but we still don't reveal that here.

        wp_send_json_success( array(
            'message'       => 'If that account exists, a reset code has been sent via '
                . ( $sent_email ? 'email' : ( $sent_telegram ? 'Telegram' : ( $sent_whatsapp ? 'WhatsApp' : ( $sent_sms ? 'SMS' : 'email' ) ) ) ) . '.',
            'sent_telegram' => $sent_telegram,
            'sent_whatsapp' => $sent_whatsapp,
            'sent_sms'      => $sent_sms,
        ));
    }

    /* ── Step 2: verify code + set new password ── */
    public static function reset_password() {
        if ( ! check_ajax_referer( 'etelid_public', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => 'Your session has expired - please refresh the page and try again.' ) );
        }
        $ip = EtelID_Security::get_ip();
        if ( ! EtelID_Security::rate_limit( 'reset_verify_' . $ip, 10, 3600 ) ) {
            wp_send_json_error( array( 'message' => 'Too many attempts. Please try again later.' ) );
        }

        $identifier = isset( $_POST['identifier'] ) ? sanitize_text_field( wp_unslash( $_POST['identifier'] ) ) : '';
        $code       = isset( $_POST['code'] )        ? preg_replace( '/[^0-9]/', '', wp_unslash( $_POST['code'] ) ) : '';
        $new_pass   = isset( $_POST['new_password'] ) ? (string) wp_unslash( $_POST['new_password'] ) : '';

        if ( ! $identifier || ! $code || ! $new_pass ) {
            wp_send_json_error( array( 'message' => 'All fields are required.' ) );
        }
        if ( strlen( $new_pass ) < 8 ) {
            wp_send_json_error( array( 'message' => 'Password must be at least 8 characters.' ) );
        }

        $member = EtelID_DB::get_by_etel_id( $identifier );
        if ( ! $member ) $member = EtelID_DB::find_by_phone( $identifier );
        if ( ! $member ) {
            wp_send_json_error( array( 'message' => 'Invalid or expired code.' ) ); // generic on purpose
        }

        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM " . EtelID_DB::t_password_resets() . "
             WHERE member_id = %d AND used = 0 AND expires_at > NOW()
             ORDER BY id DESC LIMIT 5",
            $member->id
        ));

        $match = null;
        foreach ( $rows as $row ) {
            if ( password_verify( $code, $row->code_hash ) ) { $match = $row; break; }
        }
        if ( ! $match ) {
            wp_send_json_error( array( 'message' => 'Invalid or expired code.' ) );
        }

        $wpdb->update( EtelID_DB::t_password_resets(), array( 'used' => 1 ), array( 'id' => $match->id ) );
        EtelID_DB::update_member( $member->id, array(
            'pin_hash' => EtelID_Auth::hash_password( $new_pass ),
        ));
        EtelID_DB::log( $member->id, $member->etel_id, 'password_reset', 'Password reset via self-service (' . $match->channel . ' code)' );
        // Successful reset - the escalating attempt schedule no longer
        // applies, so the next time (if ever) this member needs a reset,
        // they start fresh at try #1 rather than wherever they left off.
        self::clear_reset_schedule( $member->id );

        wp_send_json_success( array( 'message' => 'Password updated. You can now sign in.' ) );
    }

    /* ── WhatsApp delivery (CallMeBot, free tier) — same integration
     *    already working on the Delivery side's own OTP flow, ported
     *    here so it also covers this member's real phone number rather
     *    than depending on them having linked Telegram. Config lives in
     *    wp-config.php / server env, same style as WA_APIKEY/WA_PHONE
     *    already used on the Delivery side. ── */
    /* Shared phone normalizer - both providers below need E.164-style
     * digits (country code, no leading zero, no +), but member->phone
     * is stored in local format (e.g. "0966734664" - confirmed against
     * a live export, same format mismatch already found and fixed in
     * the group Telegram webhook). Returns false if the input doesn't
     * look like a valid Ethiopian mobile number in any of the three
     * formats people actually enter. */
    private static function to_e164_digits( $raw ) {
        $d = preg_replace( '/[^\d]/', '', (string) $raw );
        if ( strlen( $d ) === 9 && $d[0] === '9' )              return '251' . $d;
        if ( strlen( $d ) === 10 && $d[0] === '0' )             return '251' . substr( $d, 1 );
        if ( strlen( $d ) === 12 && substr( $d, 0, 3 ) === '251' ) return $d;
        return false;
    }

    /* ── WhatsApp delivery (Meta WhatsApp Cloud API) ──
     * Previously used CallMeBot's free API, which has a hard
     * architectural limitation that only became apparent once tested
     * with a real member: it only works for ONE pre-authorized phone
     * number (whoever messaged the bot to opt in and received that
     * specific API key) - it cannot send to arbitrary recipients. That
     * made it fundamentally unable to serve more than one person no
     * matter how correctly it was configured.
     * Meta's Cloud API is the real, scalable path: free for the first
     * 1,000 conversations/month, and OTP codes are exactly what Meta
     * calls an "Authentication" template category, purpose-built for
     * this. Requires one-time setup in Meta's WhatsApp Business
     * Platform before this can send anything:
     *   1. A Meta Business account + WhatsApp Business Platform app
     *   2. A verified phone number registered as your WhatsApp sender
     *      -> gives you the Phone Number ID used below
     *   3. A permanent access token (System User token, not the
     *      short-lived one from the quick-start page)
     *   4. An approved message template in the "Authentication"
     *      category with one body variable for the code (Meta reviews
     *      these, typically within minutes to a day) - name it
     *      whatever you like and set ETELID_WA_TEMPLATE_NAME to match
     * Config (wp-config.php):
     *   define('ETELID_WA_ACCESS_TOKEN', '...');
     *   define('ETELID_WA_PHONE_ID', '...');       // from step 2
     *   define('ETELID_WA_TEMPLATE_NAME', 'otp_code'); // your template's name
     *   define('ETELID_WA_TEMPLATE_LANG', 'en_US');    // must match template's language
     * ── */
    private static function send_via_whatsapp( $member, $code ) {
        $token    = defined( 'ETELID_WA_ACCESS_TOKEN' ) ? ETELID_WA_ACCESS_TOKEN : getenv( 'ETELID_WA_ACCESS_TOKEN' );
        $phone_id = defined( 'ETELID_WA_PHONE_ID' )     ? ETELID_WA_PHONE_ID     : getenv( 'ETELID_WA_PHONE_ID' );
        $template = defined( 'ETELID_WA_TEMPLATE_NAME' ) ? ETELID_WA_TEMPLATE_NAME : ( getenv( 'ETELID_WA_TEMPLATE_NAME' ) ?: 'otp_code' );
        $lang     = defined( 'ETELID_WA_TEMPLATE_LANG' ) ? ETELID_WA_TEMPLATE_LANG : ( getenv( 'ETELID_WA_TEMPLATE_LANG' ) ?: 'en_US' );
        if ( ! $token || ! $phone_id || empty( $member->phone ) ) return false;

        $to = self::to_e164_digits( $member->phone );
        if ( ! $to ) return false;

        $resp = wp_remote_post( "https://graph.facebook.com/v20.0/{$phone_id}/messages", array(
            'timeout' => 10,
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ),
            'body' => wp_json_encode( array(
                'messaging_product' => 'whatsapp',
                'to'                => $to,
                'type'              => 'template',
                'template'          => array(
                    'name'       => $template,
                    'language'   => array( 'code' => $lang ),
                    'components' => array(
                        array(
                            'type'       => 'body',
                            'parameters' => array( array( 'type' => 'text', 'text' => $code ) ),
                        ),
                        /* If your approved template also includes the
                         * built-in "Copy code" button component (Meta's
                         * Authentication templates support this), also
                         * send the code as the button parameter:
                        array(
                            'type'       => 'button',
                            'sub_type'   => 'url',
                            'index'      => '0',
                            'parameters' => array( array( 'type' => 'text', 'text' => $code ) ),
                        ),
                        */
                    ),
                ),
            ) ),
        ) );
        if ( is_wp_error( $resp ) ) return false;
        $data = json_decode( wp_remote_retrieve_body( $resp ), true );
        return isset( $data['messages'][0]['id'] ); // Meta returns a message-id array on success, an "error" object on failure
    }

    /* ── SMS delivery (Africa's Talking) — this is what actually reaches
     *    both Ethio Telecom and Safaricom Ethiopia numbers. Neither
     *    carrier exposes a direct per-developer SMS API to third
     *    parties in Ethiopia; delivery goes through a licensed bulk-SMS
     *    aggregator that already has termination agreements with both
     *    networks, same as the Delivery system's own OTP flow already
     *    relies on - one integration, both carriers, no separate
     *    Ethio-Telecom-specific vs Safaricom-specific code path needed
     *    or available. ── */
    private static function send_via_sms( $member, $code ) {
        $at_key  = defined('ETELID_AT_API_KEY') ? ETELID_AT_API_KEY : getenv('ETELID_AT_API_KEY');
        $at_user = defined('ETELID_AT_USERNAME') ? ETELID_AT_USERNAME : (getenv('ETELID_AT_USERNAME') ?: 'sandbox');
        if ( ! $at_key || empty( $member->phone ) ) return false;
        $to = self::to_e164_digits( $member->phone );
        if ( ! $to ) return false;
        $resp = wp_remote_post( 'https://api.africastalking.com/version1/messaging', array(
            'timeout' => 10,
            'headers' => array( 'apiKey' => $at_key, 'Accept' => 'application/json' ),
            'body'    => array(
                'username' => $at_user,
                'to'       => '+' . $to,
                'message'  => "Your Etel-ID password reset code is: {$code} (expires in 15 minutes)",
                'from'     => 'ETEL',
            ),
        ));
        if ( is_wp_error( $resp ) ) return false;
        $data = json_decode( wp_remote_retrieve_body( $resp ), true );
        return isset( $data['SMSMessageData']['Recipients'][0]['status'] )
            && $data['SMSMessageData']['Recipients'][0]['status'] === 'Success';
    }

    /* ── Telegram delivery for the reset code (separate from the
     *    credential-delivery message EtelID_Notify builds for new
     *    members - this is a short standalone code, not a welcome
     *    message with a temp password already embedded). ── */
    /* ── Escalating reset-attempt schedule ──────────────────
     * 3 tries -> wait 1 min -> 2 tries -> wait 5 min -> 1 try ->
     * wait 30 min -> 1 try -> locked out, contact support.
     * State is a small array {count, last} in one transient per
     * member, so this needs no schema change. 24h TTL as an outer
     * ceiling (so someone who tries once, walks away, and comes back
     * next week isn't still mid-schedule) - the real gating is the
     * per-stage wait below, not this outer TTL. Cleared entirely on a
     * successful password change (see reset_password() below) so a
     * legitimate reset doesn't leave a stale partial schedule behind
     * for next time. */
    private static function check_reset_schedule( $member_id ) {
        $key   = 'etelid_reset_sched_' . $member_id;
        $state = get_transient( $key );
        if ( ! is_array( $state ) || ! isset( $state['count'] ) ) {
            $state = array( 'count' => 0, 'last' => 0 );
        }
        $count = (int) $state['count'];
        $now   = time();

        // Wait required BEFORE the attempt at this count is allowed.
        $required_wait = 0;
        if ( $count === 3 )      $required_wait = 60;          // before attempt #4
        elseif ( $count === 5 )  $required_wait = 5 * 60;       // before attempt #6
        elseif ( $count === 6 )  $required_wait = 30 * 60;      // before attempt #7
        elseif ( $count >= 7 ) {
            return array( 'allowed' => false, 'contact_support' => true, 'wait_seconds' => 0 );
        }

        if ( $required_wait > 0 && ( $now - (int) $state['last'] ) < $required_wait ) {
            $remaining = $required_wait - ( $now - (int) $state['last'] );
            return array( 'allowed' => false, 'contact_support' => false, 'wait_seconds' => $remaining );
        }

        $state['count'] = $count + 1;
        $state['last']  = $now;
        set_transient( $key, $state, DAY_IN_SECONDS );
        return array( 'allowed' => true, 'contact_support' => false, 'wait_seconds' => 0 );
    }

    private static function clear_reset_schedule( $member_id ) {
        delete_transient( 'etelid_reset_sched_' . $member_id );
    }

    private static function human_wait( $seconds ) {
        if ( $seconds < 90 )  return $seconds . ' second' . ( $seconds === 1 ? '' : 's' );
        $mins = (int) ceil( $seconds / 60 );
        return $mins . ' minute' . ( $mins === 1 ? '' : 's' );
    }

    /* ── Support-issued manual reset link - the "contact support"
     * escape hatch once the schedule above is exhausted. A support
     * agent who has verified the person's identity some other way
     * (phone call, in person, whatever your process is) can call this
     * to generate a working reset code immediately, bypassing the
     * schedule entirely - it's a deliberate admin-privileged override,
     * not a hole in it: only reachable by someone who already has
     * EtelID_DB admin capability. Delivers over whichever channel is
     * actually available for that member, same send logic as
     * request_code() above, and clears their exhausted schedule so a
     * normal self-service reset works again afterward too. */
    public static function admin_issue_manual_link() {
        check_ajax_referer( 'etelid_auth', 'nonce' );
        $admin = EtelID_Auth::get_current_member();
        if ( ! $admin || ! EtelID_Auth::is_site_admin( $admin ) ) {
            wp_send_json_error( array( 'message' => 'Not authorized.' ) );
        }
        $identifier = isset( $_POST['identifier'] ) ? sanitize_text_field( wp_unslash( $_POST['identifier'] ) ) : '';
        if ( ! $identifier ) wp_send_json_error( array( 'message' => 'Provide the member\'s phone or Etel-ID.' ) );

        $member = EtelID_DB::get_by_etel_id( $identifier );
        if ( ! $member ) $member = EtelID_DB::find_by_phone( $identifier );
        if ( ! $member ) wp_send_json_error( array( 'message' => 'No matching member found.' ) );

        self::clear_reset_schedule( $member->id ); // support override - the schedule no longer applies

        global $wpdb;
        $code      = str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
        $code_hash = password_hash( $code, PASSWORD_BCRYPT );

        $sent_email = ! empty( $member->email ) && EtelID_Mailer::send_reset_code( $member, $code );
        $sent_tg    = ! $sent_email && ! empty( $member->tg_chat_id ) && self::send_via_telegram( $member, $code ) === true;
        $sent_wa    = ! $sent_email && ! $sent_tg && ! empty( $member->phone ) && self::send_via_whatsapp( $member, $code );
        $sent_sms   = ! $sent_email && ! $sent_tg && ! $sent_wa && ! empty( $member->phone ) && self::send_via_sms( $member, $code );
        $channel    = $sent_email ? 'email' : ( $sent_tg ? 'telegram' : ( $sent_wa ? 'whatsapp' : ( $sent_sms ? 'sms' : null ) ) );

        if ( ! $channel ) {
            wp_send_json_error( array( 'message' => 'This member has no working delivery channel (no email, Telegram, or phone on file). Reset manually via the admin panel instead.' ) );
        }

        $wpdb->query( $wpdb->prepare(
            "INSERT INTO " . EtelID_DB::t_password_resets() . "
             (member_id, code_hash, channel, expires_at, used, created_at)
             VALUES (%d, %s, %s, NOW() + INTERVAL 15 MINUTE, 0, NOW())",
            $member->id, $code_hash, $channel
        ) );
        EtelID_DB::log( $member->id, $member->etel_id, 'reset_admin_issued',
            'Support-issued manual reset code sent via ' . $channel . ' by admin #' . $admin->id );

        wp_send_json_success( array( 'message' => 'Reset code sent via ' . $channel . '.', 'channel' => $channel ) );
    }

    private static function send_via_telegram( $member, $code ) {
        $token = get_option( 'etelid_tg_bot_token', '' );
        if ( ! $token || empty( $member->tg_chat_id ) ) return false;
        $text = "Your Etel-ID password reset code is:\n\n{$code}\n\nExpires in 15 minutes.";
        $resp = wp_remote_post( 'https://api.telegram.org/bot' . $token . '/sendMessage', array(
            'timeout' => 15,
            'body'    => array( 'chat_id' => $member->tg_chat_id, 'text' => $text ),
        ));
        if ( is_wp_error( $resp ) ) return false;
        $code_status = wp_remote_retrieve_response_code( $resp );
        return ( $code_status >= 200 && $code_status < 300 );
    }
}

EtelID_Password_Reset::init();
