<?php
/**
 * EtelID_Biometric — Biometric enrollment & verification engine
 * Version: 3.0.0
 *
 * Supported modalities:
 *   fingerprint — SHA-256 of ISO/IEC 19794-2 WSQ template bytes
 *   face        — SHA-256 of FaceNet/DeepFace 128-D embedding
 *   nfc         — Physical NFC/RFID card UID (HEX, stored as-is)
 *   iris        — SHA-256 of IrisCode (ISO/IEC 19794-6)
 *   voice       — SHA-256 of MFCC voice-print vector
 *   webauthn    — FIDO2/WebAuthn public key credential
 *
 * Architecture:
 *   PHP stores hashed templates only — never raw biometric data.
 *   The companion Android scanner app captures live biometrics,
 *   computes the template hash, and posts it via REST API.
 *   Admin can also manually paste hashes for enrollment.
 *
 * PHP 5.6+ compatible — no arrow functions.
 *
 * @package Etel-ID Premium
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class EtelID_Biometric {

    const METHODS = array( 'fingerprint', 'face', 'nfc', 'iris', 'voice', 'webauthn' );

    /* ─── Method display info ─── */
    public static function method_info( $method ) {
        $info = array(
            'fingerprint' => array( 'icon' => '🔏', 'label' => 'Fingerprint',     'std' => 'ISO/IEC 19794-2' ),
            'face'        => array( 'icon' => '👤', 'label' => 'Face',            'std' => 'FaceNet 128-D'   ),
            'nfc'         => array( 'icon' => '📡', 'label' => 'NFC / RFID Card', 'std' => 'ISO 14443 / 15693' ),
            'iris'        => array( 'icon' => '👁',  'label' => 'Iris',            'std' => 'ISO/IEC 19794-6' ),
            'voice'       => array( 'icon' => '🎤', 'label' => 'Voice Print',     'std' => 'MFCC Vector'     ),
            'webauthn'    => array( 'icon' => '🔑', 'label' => 'WebAuthn / FIDO2','std' => 'FIDO2 / W3C'    ),
        );
        return isset( $info[ $method ] ) ? $info[ $method ] : array( 'icon' => '?', 'label' => $method, 'std' => '' );
    }

    /* ─── Enroll a biometric modality ─── */
    public static function enroll( $member_id, $etel_id, $method, $value, $quality, $enrolled_by ) {
        if ( ! in_array( $method, self::METHODS, true ) ) {
            return new WP_Error( 'invalid_method', 'Unknown biometric method: ' . $method );
        }
        if ( ! $value ) {
            return new WP_Error( 'empty_value', 'Biometric value cannot be empty.' );
        }

        $data = array(
            'enrolled_by' => sanitize_text_field( $enrolled_by ),
        );

        if ( $method === 'nfc' ) {
            /* NFC: store UID as-is (uppercase HEX) */
            $data['nfc_uid']       = strtoupper( preg_replace( '/[^0-9A-Fa-f:]/', '', $value ) );
            $data['nfc_card_type'] = '';  /* set separately if needed */
        } elseif ( $method === 'webauthn' ) {
            /* WebAuthn: store credential_id and public_key as-is */
            $parts = explode( '|', $value, 2 );
            $data['webauthn_credential_id'] = sanitize_text_field( $parts[0] );
            $data['webauthn_public_key']    = isset( $parts[1] ) ? wp_kses_post( $parts[1] ) : '';
            $data['webauthn_sign_count']    = 0;
        } else {
            /* All others: hash the value with SHA-256 */
            $hash_field    = $method . '_hash';
            $quality_field = $method . '_quality';
            $data[ $hash_field ]    = hash( 'sha256', $value );
            $data[ $quality_field ] = min( 100, max( 0, (int) $quality ) );
        }

        /* Bump liveness level */
        $bio = EtelID_DB::get_biometric( $member_id );
        $current_level = $bio ? (int) $bio->liveness_level : 0;
        if ( $method === 'face' && $current_level < 1 ) {
            $data['liveness_level']      = 1;
            $data['liveness_confidence'] = (int) $quality;
        }
        if ( $method === 'webauthn' ) {
            $data['liveness_level']      = 3;
            $data['liveness_confidence'] = 100;
        }

        $result = EtelID_DB::upsert_biometric( $member_id, $etel_id, $data );
        if ( false === $result ) {
            return new WP_Error( 'db_error', 'Failed to save biometric data.' );
        }

        EtelID_DB::log( $member_id, $etel_id, 'bio_enrolled',
            $method . ' enrolled by ' . $enrolled_by . ' (quality ' . $quality . '%)' );

        return true;
    }

    /* ─── Verify a presented value against stored record ─── */
    public static function verify( $member_id, $method, $presented_value ) {
        $bio = EtelID_DB::get_biometric( $member_id );
        if ( ! $bio ) {
            return array( 'result' => 'no_data', 'confidence' => 0 );
        }

        if ( $method === 'nfc' ) {
            $stored    = isset( $bio->nfc_uid ) ? $bio->nfc_uid : '';
            if ( ! $stored ) return array( 'result' => 'not_enrolled', 'confidence' => 0 );
            $presented = strtoupper( preg_replace( '/[^0-9A-Fa-f:]/', '', $presented_value ) );
            $match     = hash_equals( $stored, $presented );
            return array( 'result' => $match ? 'success' : 'failed', 'confidence' => $match ? 100 : 0 );
        }

        if ( $method === 'webauthn' ) {
            $stored = isset( $bio->webauthn_credential_id ) ? $bio->webauthn_credential_id : '';
            if ( ! $stored ) return array( 'result' => 'not_enrolled', 'confidence' => 0 );
            $match = hash_equals( $stored, sanitize_text_field( $presented_value ) );
            return array( 'result' => $match ? 'success' : 'failed', 'confidence' => $match ? 100 : 0 );
        }

        $hash_field    = $method . '_hash';
        $quality_field = $method . '_quality';
        $stored_hash   = isset( $bio->$hash_field ) ? $bio->$hash_field : '';
        if ( ! $stored_hash ) return array( 'result' => 'not_enrolled', 'confidence' => 0 );
        $presented_hash = hash( 'sha256', $presented_value );
        $match = hash_equals( $stored_hash, $presented_hash );
        $quality = isset( $bio->$quality_field ) ? (int) $bio->$quality_field : 0;
        return array( 'result' => $match ? 'success' : 'failed', 'confidence' => $match ? $quality : 0 );
    }

    /* ─── Enrollment completeness score (0-100%) ─── */
    public static function enrollment_score( $bio ) {
        if ( ! $bio ) return 0;
        $checks = array(
            'fingerprint_hash',
            'face_hash',
            'nfc_uid',
            'iris_hash',
            'voice_hash',
            'webauthn_credential_id',
        );
        $enrolled = 0;
        foreach ( $checks as $f ) {
            if ( ! empty( $bio->$f ) ) $enrolled++;
        }
        return round( ( $enrolled / count( $checks ) ) * 100 );
    }

    /* ─── Liveness level label ─── */
    public static function liveness_label( $level ) {
        $labels = array(
            0 => array( 'label' => 'Not Enrolled', 'color' => '#555' ),
            1 => array( 'label' => 'Photo Captured', 'color' => '#f39c12' ),
            2 => array( 'label' => 'Liveness Passed', 'color' => '#27ae60' ),
            3 => array( 'label' => 'WebAuthn / FIDO2', 'color' => '#D4AF37' ),
        );
        return isset( $labels[ (int) $level ] ) ? $labels[ (int) $level ] : $labels[0];
    }

    /* ─── One-time enrollment token for scanner app ─── */
    public static function generate_enrollment_token( $member_id ) {
        $token = wp_generate_password( 40, false );
        set_transient( 'etelid_bio_token_' . $token, (int) $member_id, 30 * MINUTE_IN_SECONDS );
        return $token;
    }

    public static function consume_enrollment_token( $token ) {
        $key = 'etelid_bio_token_' . sanitize_text_field( $token );
        $mid = get_transient( $key );
        if ( $mid ) delete_transient( $key );
        return $mid ? (int) $mid : false;
    }

    /* ─── REST API routes (called from etelid_boot) ─── */
    public static function register_rest_routes() {
        /* POST /wp-json/etelid/v1/enroll  — scanner app posts hash */
        register_rest_route( 'etelid/v1', '/enroll', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_enroll' ),
            'permission_callback' => array( __CLASS__, 'rest_auth' ),
        ));

        /* POST /wp-json/etelid/v1/checkin — NFC/fingerprint tap */
        register_rest_route( 'etelid/v1', '/checkin', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_checkin' ),
            'permission_callback' => '__return_true',
        ));

        /* GET /wp-json/etelid/v1/verify/{etel_id} — public */
        register_rest_route( 'etelid/v1', '/verify/(?P<etel_id>[A-Z0-9\-]+)', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_verify' ),
            'permission_callback' => '__return_true',
        ));
    }

    public static function rest_auth( $request ) {
        $auth = $request->get_header( 'Authorization' );
        if ( $auth && strpos( $auth, 'Bearer ' ) === 0 ) {
            $token = substr( $auth, 7 );
            $mid   = self::consume_enrollment_token( $token );
            if ( $mid ) {
                $request->set_param( '_token_member_id', $mid );
                return true;
            }
        }
        return current_user_can( 'manage_options' );
    }

    public static function rest_enroll( $request ) {
        $method  = sanitize_key( $request->get_param( 'method' ) );
        $value   = sanitize_text_field( $request->get_param( 'value' ) );
        $quality = min( 100, max( 0, (int) $request->get_param( 'quality' ) ) );
        $mid     = (int) $request->get_param( '_token_member_id' );
        if ( ! $mid ) $mid = (int) $request->get_param( 'member_id' );
        $m = $mid ? EtelID_DB::get_member( $mid ) : null;
        if ( ! $m ) return new WP_Error( 'not_found', 'Member not found.', array( 'status' => 404 ) );
        $result = self::enroll( $mid, $m->etel_id, $method, $value, $quality, 'Scanner App' );
        if ( is_wp_error( $result ) ) return $result;
        $bio = EtelID_DB::get_biometric( $mid );
        return rest_ensure_response( array(
            'success' => true,
            'method'  => $method,
            'score'   => self::enrollment_score( $bio ),
        ));
    }

    public static function rest_checkin( $request ) {
        $method   = sanitize_key( $request->get_param( 'method' ) );
        $value    = sanitize_text_field( $request->get_param( 'value' ) );
        $etel_id  = strtoupper( sanitize_text_field( $request->get_param( 'etel_id' ) ) );
        $location = sanitize_text_field( $request->get_param( 'location' ) );
        $device   = sanitize_text_field( $request->get_param( 'device_id' ) );

        /* Look up member */
        if ( $method === 'nfc' && ! $etel_id ) {
            $bio_row = EtelID_DB::get_biometric_by_nfc( $value );
            $m = $bio_row ? EtelID_DB::get_member( $bio_row->member_id ) : null;
        } else {
            $m = EtelID_DB::get_by_etel_id( $etel_id );
        }

        if ( ! $m ) {
            EtelID_DB::log_bio_checkin( array( 'member_id' => 0, 'etel_id' => $etel_id ?: 'unknown', 'method' => $method, 'result' => 'failed', 'confidence' => 0, 'location' => $location, 'device_id' => $device ) );
            return new WP_Error( 'not_found', 'Member not found.', array( 'status' => 404 ) );
        }

        if ( $m->status !== 'active' ) {
            EtelID_DB::log_bio_checkin( array( 'member_id' => $m->id, 'etel_id' => $m->etel_id, 'method' => $method, 'result' => 'suspended', 'confidence' => 0, 'location' => $location, 'device_id' => $device ) );
            return rest_ensure_response( array( 'result' => 'suspended', 'name' => $m->full_name ) );
        }

        $vr = self::verify( $m->id, $method, $value );
        EtelID_DB::log_bio_checkin( array(
            'member_id'  => $m->id,
            'etel_id'    => $m->etel_id,
            'method'     => $method,
            'result'     => $vr['result'],
            'confidence' => $vr['confidence'],
            'location'   => $location,
            'device_id'  => $device,
        ));
        if ( $vr['result'] === 'success' ) {
            EtelID_DB::log( $m->id, $m->etel_id, 'checkin', $method . ' at ' . $location );
        }
        return rest_ensure_response( array(
            'result'     => $vr['result'],
            'confidence' => $vr['confidence'],
            'name'       => $m->full_name,
            'etel_id'    => $m->etel_id,
            'tier'       => $m->tier,
            'company'    => $m->primary_company,
            'photo'      => $m->photo_url,
        ));
    }

    public static function rest_verify( $request ) {
        $m = EtelID_DB::get_by_etel_id( strtoupper( $request->get_param( 'etel_id' ) ) );
        if ( ! $m ) return new WP_Error( 'not_found', 'Not found.', array( 'status' => 404 ) );
        $bio = EtelID_DB::get_biometric( $m->id );
        return rest_ensure_response( array(
            'valid'          => $m->status === 'active',
            'name'           => $m->full_name,
            'company'        => $m->primary_company,
            'tier'           => $m->tier,
            'issued'         => $m->issue_date,
            'expiry'         => $m->expiry_date,
            'bio_score'      => self::enrollment_score( $bio ),
            'liveness_level' => $bio ? (int) $bio->liveness_level : 0,
        ));
    }
}

/* Register REST routes on init */
add_action( 'rest_api_init', array( 'EtelID_Biometric', 'register_rest_routes' ) );
