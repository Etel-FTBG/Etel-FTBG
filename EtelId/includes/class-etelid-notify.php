<?php
/**
 * Etel-ID Premium — Credential Delivery (Telegram / WhatsApp / Email)
 *
 * @package    Etel-ID Premium
 * @version    3.10.0
 * @author     Etel Group · ኢትኤል ቡድን
 *
 * Honesty note (read before wiring this up for Etel):
 *
 *   - WhatsApp "send" always works with ZERO configuration via a
 *     click-to-chat link (wa.me) — the admin taps it, their own WhatsApp
 *     opens with the message pre-filled, they tap Send. That's a manual
 *     tap, not silent automation, but it requires no account setup.
 *     TRUE unattended auto-send needs a Meta WhatsApp Cloud API business
 *     account (etelid_wa_token + etelid_wa_phone_id options below) —
 *     Etel has to create that account; this class will use it automatically
 *     once those options are filled in under Settings.
 *
 *   - Telegram auto-send (Bot API sendMessage) requires (a) a bot token
 *     Etel creates via @BotFather, AND (b) the member's numeric chat_id,
 *     which Telegram only reveals once that member has started a
 *     conversation with the bot. There is no bot/linking flow built yet
 *     in this codebase, so for a brand-new member at registration time
 *     chat_id is normally unknown. This class still supports auto-send
 *     for members who already have a tg_chat_id on file (e.g. linked via
 *     some future flow), and otherwise gives the admin a
 *     "open Telegram chat" link (works only if tg_username is known) plus
 *     a one-tap "Copy credentials" fallback that always works.
 *
 * PHP 5.6+ compatible — no arrow functions.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class EtelID_Notify {

    /* ─── Build the human-readable credentials message ─── */
    public static function credentials_message( $member, $temp_password ) {
        $org = get_option( 'etelid_org_name', 'Etel Group' );
        return
            "Welcome to {$org}! Your Etel-ID has been created.\n\n" .
            "Etel-ID: {$member->etel_id}\n" .
            "Phone:   {$member->phone}\n" .
            "Temporary password: {$temp_password}\n\n" .
            "Sign in here: " . home_url( '/login/' ) . "\n" .
            "You will be asked to set a new password the first time you sign in.\n\n" .
            "ኢትኤል ቡድን · Etel Group";
    }

    /* ─── WhatsApp ─── */

    /** Zero-config click-to-chat link the admin can open and tap Send. */
    public static function whatsapp_link( $member, $temp_password ) {
        $phone = self::e164( ! empty( $member->wa_phone ) ? $member->wa_phone : $member->phone );
        if ( ! $phone ) return '';
        $text = self::credentials_message( $member, $temp_password );
        return 'https://wa.me/' . $phone . '?text=' . rawurlencode( $text );
    }

    /** Optional true automation via Meta WhatsApp Cloud API, if configured. */
    public static function whatsapp_send_api( $member, $temp_password ) {
        $token    = get_option( 'etelid_wa_token', '' );
        $phone_id = get_option( 'etelid_wa_phone_id', '' );
        $to       = self::e164( ! empty( $member->wa_phone ) ? $member->wa_phone : $member->phone );
        if ( ! $token || ! $phone_id || ! $to ) {
            return new WP_Error( 'not_configured', 'WhatsApp Cloud API is not configured (Settings → WhatsApp).' );
        }
        $text = self::credentials_message( $member, $temp_password );
        $resp = wp_remote_post( 'https://graph.facebook.com/v19.0/' . $phone_id . '/messages', array(
            'timeout' => 15,
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ),
            'body' => wp_json_encode( array(
                'messaging_product' => 'whatsapp',
                'to'                => $to,
                'type'              => 'text',
                'text'              => array( 'body' => $text ),
            )),
        ));
        if ( is_wp_error( $resp ) ) return $resp;
        $code = wp_remote_retrieve_response_code( $resp );
        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'wa_api_error', 'WhatsApp API returned HTTP ' . $code . ': ' . wp_remote_retrieve_body( $resp ) );
        }
        return true;
    }

    /* ─── Telegram ─── */

    /** Real auto-send — only works if member.tg_chat_id is already known. */
    public static function telegram_send_api( $member, $temp_password ) {
        $token = get_option( 'etelid_tg_bot_token', '' );
        if ( ! $token ) {
            return new WP_Error( 'not_configured', 'Telegram bot token is not configured (Settings → Telegram).' );
        }
        if ( empty( $member->tg_chat_id ) ) {
            return new WP_Error( 'no_chat_id', 'This member has not linked Telegram yet (no chat_id on file).' );
        }
        $text = self::credentials_message( $member, $temp_password );
        $resp = wp_remote_post( 'https://api.telegram.org/bot' . $token . '/sendMessage', array(
            'timeout' => 15,
            'body'    => array(
                'chat_id' => $member->tg_chat_id,
                'text'    => $text,
            ),
        ));
        if ( is_wp_error( $resp ) ) return $resp;
        $code = wp_remote_retrieve_response_code( $resp );
        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'tg_api_error', 'Telegram API returned HTTP ' . $code . ': ' . wp_remote_retrieve_body( $resp ) );
        }
        return true;
    }

    /** Manual fallback: open the chat by @username (no pre-filled text — Telegram doesn't support that without a bot session). */
    public static function telegram_link( $member ) {
        if ( empty( $member->tg_username ) ) return '';
        return 'https://t.me/' . ltrim( $member->tg_username, '@' );
    }

    /* ─── Orchestrator used by the admin screen ───
     * Tries the best available channel automatically, always also
     * returns the manual fallback links/text so the admin has a backup
     * no matter what. */
    public static function deliver( $member, $temp_password, $preferred_channel = 'auto' ) {
        $result = array(
            'auto_sent'      => false,
            'auto_channel'   => '',
            'auto_error'     => '',
            'whatsapp_link'  => self::whatsapp_link( $member, $temp_password ),
            'telegram_link'  => self::telegram_link( $member ),
            'message_text'   => self::credentials_message( $member, $temp_password ),
        );

        $try_order = array();
        if ( $preferred_channel === 'whatsapp' ) $try_order = array( 'whatsapp' );
        elseif ( $preferred_channel === 'telegram' ) $try_order = array( 'telegram' );
        else $try_order = array( 'telegram', 'whatsapp' ); /* Telegram is live; WhatsApp Cloud API not yet configured */

        foreach ( $try_order as $ch ) {
            if ( $ch === 'whatsapp' ) {
                $r = self::whatsapp_send_api( $member, $temp_password );
            } else {
                $r = self::telegram_send_api( $member, $temp_password );
            }
            if ( $r === true ) {
                $result['auto_sent']    = true;
                $result['auto_channel'] = $ch;
                EtelID_DB::update_member( $member->id, array( 'notify_channel' => $ch ) );
                EtelID_DB::log( $member->id, $member->etel_id, 'notified', 'Credentials auto-sent via ' . $ch );
                break;
            } else {
                $result['auto_error'] .= ucfirst( $ch ) . ': ' . ( is_wp_error( $r ) ? $r->get_error_message() : 'unknown error' ) . ' ';
            }
        }

        return $result;
    }

    /* ─── Helpers ─── */
    private static function e164( $phone ) {
        $p = preg_replace( '/[^0-9+]/', '', (string) $phone );
        if ( ! $p ) return '';
        if ( $p[0] === '0' ) $p = '251' . substr( $p, 1 );      /* Ethiopian local → country code */
        $p = ltrim( $p, '+' );
        return $p;
    }
}
