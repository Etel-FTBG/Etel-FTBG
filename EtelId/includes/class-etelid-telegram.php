<?php
/**
 * Etel-ID Premium — Telegram Account Linking (v1.0)
 *
 * Two halves:
 *   1. A logged-in member requests a one-time deep link
 *      (t.me/<bot>?start=<signed token>), taps it, Telegram opens and
 *      shows "Start" for our bot.
 *   2. Telegram calls our webhook the moment they tap Start - we verify
 *      the token (HMAC-signed, no DB round-trip needed to check it),
 *      and save their numeric chat_id onto that member's row.
 *
 * After that, the existing EtelID_Notify::telegram_send_api() and the
 * password-reset flow can actually deliver messages to them - this is
 * the missing piece those already assumed would exist.
 *
 * One-time setup required (run once from a terminal or a browser, not
 * part of this plugin's UI - see README note at the bottom of this
 * file): register the webhook URL with Telegram via the Bot API's
 * setWebhook endpoint.
 *
 * PHP 5.6+ compatible.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class EtelID_Telegram {

    public static function init() {
        add_action( 'wp_ajax_etelid_get_telegram_link',        array( __CLASS__, 'get_link' ) );
        /* MISSING PIECE - this file only registered the wp_ajax_ variant,
         * which WordPress only ever fires for a real WP-core logged-in
         * user (is_user_logged_in() true). EtelID members authenticate
         * through their own separate Etelid_etelid_sessions cookie
         * system, not WP's - so any member who isn't also a genuine WP
         * user (i.e. everyone except accounts that happen to double as
         * real wp-admin users) is invisible to this hook entirely: the
         * action never fires, admin-ajax.php just echoes "0", and the
         * frontend sees a message-less generic failure. get_link()
         * itself already correctly checks EtelID_Auth::get_current_member()
         * (the real, custom session check) as its actual auth gate, so
         * adding the nopriv hook doesn't weaken anything - a genuinely
         * signed-out visitor still just gets "Please sign in again." from
         * inside the function; this only fixes it actually being called
         * for legitimately logged-in-via-EtelID members in the first
         * place. class-etelid-password-reset.php already registers both
         * variants correctly elsewhere in this same plugin - this file
         * just missed it. */
        add_action( 'wp_ajax_nopriv_etelid_get_telegram_link', array( __CLASS__, 'get_link' ) );
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    public static function register_routes() {
        register_rest_route( 'etelid/v1', '/telegram-webhook', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'webhook' ),
            'permission_callback' => '__return_true', // Telegram itself calls this, no WP auth/nonce applies
        ));
    }

    /* ── Half 1: logged-in member asks for their deep link ── */
    public static function get_link() {
        check_ajax_referer( 'etelid_auth', 'nonce' );
        $member = EtelID_Auth::get_current_member();
        if ( ! $member ) wp_send_json_error( array( 'message' => 'Please sign in again.' ) );

        $bot_username = get_option( 'etelid_tg_bot_username', '' );
        if ( ! $bot_username ) {
            wp_send_json_error( array( 'message' => 'Telegram bot username is not configured yet (Settings → Telegram).' ) );
        }

        $token = self::make_token( $member->id );
        wp_send_json_success( array(
            'url'          => 'https://t.me/' . ltrim( $bot_username, '@' ) . '?start=' . $token,
            'already_linked' => ! empty( $member->tg_chat_id ),
        ));
    }

    /* ── Half 2: Telegram calls this the moment the member taps Start ── */
    public static function webhook( WP_REST_Request $request ) {
        $update = $request->get_json_params();
        if ( empty( $update['message']['text'] ) || empty( $update['message']['chat']['id'] ) ) {
            return rest_ensure_response( array( 'ok' => true ) ); // ignore anything that isn't a /start message
        }
        $text    = trim( $update['message']['text'] );
        $chat_id = (string) $update['message']['chat']['id'];
        $tg_user = isset( $update['message']['from']['username'] ) ? $update['message']['from']['username'] : '';

        if ( strpos( $text, '/start ' ) !== 0 ) {
            self::reply( $chat_id, "Hi! Open your Etel-ID portal and tap \"Link Telegram\" to connect this chat to your account." );
            return rest_ensure_response( array( 'ok' => true ) );
        }

        $token     = trim( substr( $text, 7 ) );
        $member_id = self::verify_token( $token );
        if ( ! $member_id ) {
            self::reply( $chat_id, "That link has expired or is invalid. Please request a new one from your Etel-ID portal." );
            return rest_ensure_response( array( 'ok' => true ) );
        }

        $member = EtelID_DB::get_member( $member_id );
        if ( ! $member ) {
            self::reply( $chat_id, "Account not found." );
            return rest_ensure_response( array( 'ok' => true ) );
        }

        EtelID_DB::update_member( $member_id, array(
            'tg_chat_id'  => $chat_id,
            'tg_username' => $tg_user,
        ));
        EtelID_DB::log( $member_id, $member->etel_id, 'telegram_linked', 'Telegram account linked (chat_id on file)' );
        self::reply( $chat_id, "✅ Your Telegram is now linked to Etel-ID {$member->etel_id}. You'll receive password reset codes and other notices here." );

        return rest_ensure_response( array( 'ok' => true ) );
    }

    private static function reply( $chat_id, $text ) {
        $token = get_option( 'etelid_tg_bot_token', '' );
        if ( ! $token ) return;
        wp_remote_post( 'https://api.telegram.org/bot' . $token . '/sendMessage', array(
            'timeout' => 10,
            'body'    => array( 'chat_id' => $chat_id, 'text' => $text ),
        ));
    }

    /* ── Signed, stateless token: member_id|expiry|hmac.
     *    No new DB column/table needed to track pending link requests -
     *    the signature itself proves validity and the timestamp proves
     *    it hasn't expired (10 minutes). ── */
    /* ── Deep-link token: short, opaque, server-side lookup ──
     * Was a self-contained signed payload (member_id|expiry|HMAC-SHA256,
     * base64'd) - correct in principle, but far too long: 103 characters
     * for a typical member_id/expiry pair. Telegram hard-caps the
     * `/start` deep-link parameter at 64 characters
     * (^[A-Za-z0-9_-]{1,64}$) and silently drops anything longer -
     * Telegram's own client falls back to sending a bare "/start" with
     * no payload at all, which is exactly the symptom this was causing
     * (webhook() saw no "/start <token>" match, replied with the
     * generic "tap Link Telegram" message, member_id was never
     * resolved, tg_chat_id never got set - the link button "worked"
     * but could never actually complete for anyone, regardless of
     * member_id).
     * Fixed by generating a short random token instead and storing the
     * member_id it belongs to server-side in a transient (10-minute
     * TTL, same as before) - the token itself carries no information,
     * it's just a lookup key, so length is unrelated to member_id size
     * and always comfortably under Telegram's limit. */
    private static function make_token( $member_id ) {
        $token = wp_generate_password( 24, false, false ); // alphanumeric only, 24 chars - well under the 64-char cap
        set_transient( 'etelid_tg_link_' . $token, (int) $member_id, 10 * MINUTE_IN_SECONDS );
        return $token;
    }

    private static function verify_token( $token ) {
        $key       = 'etelid_tg_link_' . $token;
        $member_id = get_transient( $key );
        if ( $member_id === false ) return false;
        delete_transient( $key ); // single-use, same as the old expiry-based scheme effectively was
        return (int) $member_id;
    }
}

EtelID_Telegram::init();

/**
 * ── ONE-TIME SETUP (do this once, not part of this plugin's UI) ──
 *
 * 1. In wp-admin → Settings (wherever this plugin's Telegram settings
 *    live), make sure these two options are filled in:
 *      etelid_tg_bot_token     (from @BotFather)
 *      etelid_tg_bot_username  (your bot's @username, no @ symbol)
 *
 * 2. Tell Telegram where to send updates - run this ONE TIME from any
 *    browser address bar or terminal (replace <TOKEN> and <SITE>):
 *
 *    https://api.telegram.org/bot<TOKEN>/setWebhook?url=https://<SITE>/wp-json/etelid/v1/telegram-webhook
 *
 *    Example:
 *    https://api.telegram.org/bot123456:ABC-DEF/setWebhook?url=https://ethelmarket.com/EtelId/wp-json/etelid/v1/telegram-webhook
 *
 *    Telegram will reply {"ok":true,"result":true,...} if it worked.
 *    You only need to do this once - it stays registered until changed.
 */
