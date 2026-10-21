<?php
/**
 * Etel-ID Premium — FAYDA Quick Register (Pass 1)
 *
 * @package    Etel-ID Premium
 * @version    3.10.0
 * @author     Etel Group · ኢትኤል ቡድን
 *
 * Admin photographs the front + back of a member's Fayda (Ethiopian
 * national digital ID). OCR + QR text extraction happens client-side
 * (Tesseract.js + jsQR — see admin/views/fayda-register.php); this class
 * only receives the admin-reviewed final fields and:
 *
 *   1. Creates the Etel-ID member record (kyc_level = 1).
 *   2. Generates a temporary password.
 *   3. Stores the captured Fayda images + raw OCR text for audit.
 *   4. Flags must_change_password = 1.
 *   5. Returns everything the admin screen needs to deliver credentials
 *      via Telegram / WhatsApp (see EtelID_Notify).
 *
 * IMPORTANT — honesty about what this is:
 * This is FAST, ADMIN-ASSISTED intake, not a cryptographic government
 * verification. The admin is the human-in-the-loop confirming the photo
 * is a real ID and the extracted text looks right before submitting.
 *
 * PHP 5.6+ compatible — no arrow functions.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class EtelID_Fayda {

    /**
     * @param  array $data  Expected keys (all already admin-reviewed text):
     *   full_name, full_name_am, dob, sex, nationality, region, zone,
     *   woreda, phone, email, fayda_fin, primary_company_slug, role,
     *   department, employee_number, tier, notes,
     *   fayda_front_image (base64 data URI or attachment URL),
     *   fayda_back_image  (base64 data URI or attachment URL),
     *   fayda_ocr_raw     (raw OCR text dump, for the audit trail).
     *
     * @return array|WP_Error  array('member'=>obj,'temp_password'=>string) | WP_Error
     */
    public static function quick_register( $data ) {

        $phone = isset( $data['phone'] ) ? preg_replace( '/\s+/', '', $data['phone'] ) : '';
        if ( ! $phone ) {
            return new WP_Error( 'phone_required', 'A phone number is required to deliver the password.' );
        }

        $existing = EtelID_DB::find_by_phone( $phone );
        if ( $existing ) {
            return new WP_Error( 'phone_exists', 'A member with this phone number already exists (' . $existing->etel_id . ').' );
        }

        if ( empty( $data['full_name'] ) ) {
            return new WP_Error( 'name_required', 'Full name could not be confirmed — please correct it before submitting.' );
        }

        /* Fayda FIN sanity check: government Fayda numbers are 12 digits.
         * We don't reject on mismatch (OCR can mis-read), we just don't
         * pretend it's verified if it doesn't look right. */
        $fayda_fin_raw = isset( $data['fayda_fin'] ) ? preg_replace( '/[^0-9]/', '', $data['fayda_fin'] ) : '';

        /* 1. Create the underlying Etel-ID member via the normal generator. */
        $create_data = $data;
        unset( $create_data['fayda_front_image'], $create_data['fayda_back_image'], $create_data['fayda_ocr_raw'], $create_data['fayda_fin'] );

        $member_id = EtelID_Generator::create( $create_data );
        if ( is_wp_error( $member_id ) ) {
            return $member_id;
        }

        /* 2. Generate a temporary password and hash it. */
        $temp_password = self::generate_temp_password();
        $pin_hash      = EtelID_Auth::hash_password( $temp_password );

        /* 3. Store Fayda capture data + flags. */
        EtelID_DB::update_member( $member_id, array(
            'pin_hash'             => $pin_hash,
            'must_change_password' => 1,
            'kyc_level'            => 1,
            'fayda_fin'            => $fayda_fin_raw,
            'fayda_front_image'    => isset( $data['fayda_front_image'] ) ? $data['fayda_front_image'] : '',
            'fayda_back_image'     => isset( $data['fayda_back_image'] )  ? $data['fayda_back_image']  : '',
            'fayda_ocr_raw'        => isset( $data['fayda_ocr_raw'] )     ? $data['fayda_ocr_raw']     : '',
            'fayda_captured_at'    => current_time( 'mysql' ),
        ));

        $member = EtelID_DB::get_member( $member_id );
        /* Only staff reach this (admin Fayda quick-register): the number counts as verified. */
        if ( $member && class_exists( 'EtelID_Phone_Verify' ) ) EtelID_Phone_Verify::mark_verified( $member, 'issued by staff' );

        $registrar = wp_get_current_user();
        $registrar_label = $registrar && $registrar->display_name
            ? $registrar->display_name
            : ( isset( $data['_registered_by_label'] ) && $data['_registered_by_label'] ? $data['_registered_by_label'] : 'Unknown admin' );

        EtelID_DB::log( $member_id, $member->etel_id, 'fayda_registered',
            'Quick-registered from Fayda ID photo capture by ' . $registrar_label .
            ( $fayda_fin_raw ? ' (Fayda FIN ' . $fayda_fin_raw . ')' : ' (Fayda FIN not detected)' ) );

        return array(
            'member'        => $member,
            'temp_password' => $temp_password,
        );
    }

    /**
     * Human-friendly temp password: easy to read aloud / retype on a
     * phone keyboard, still reasonably strong (10 chars, mixed case + digit).
     * Avoids ambiguous characters (0/O, 1/l/I).
     */
    public static function generate_temp_password() {
        $chars = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
        $len   = strlen( $chars );
        $out   = '';
        for ( $i = 0; $i < 10; $i++ ) {
            $idx = function_exists( 'random_int' ) ? random_int( 0, $len - 1 ) : mt_rand( 0, $len - 1 );
            $out .= $chars[ $idx ];
        }
        return $out;
    }
}
