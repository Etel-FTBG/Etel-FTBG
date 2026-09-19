<?php
/**
 * Etel-ID Premium — Database Layer
 *
 * @package    Etel-ID Premium
 * @version    2.0.0
 * @author     Etel Group · ኢትኤል ቡድን
 * @link       https://etelgroup.com
 * @since      2.0.0
 *
 * Manages all database operations for the Etel-ID system.
 *
 * Tables:
 *   {prefix}etelid_members   — core identity records (35+ columns)
 *   {prefix}etelid_companies — member ↔ company links (many-to-many)
 *   {prefix}etelid_log       — full audit trail of all ID events
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class EtelID_DB {

    /* ─── Table name helpers ──────────────────────────────── */
    public static function t_members()   { global $wpdb; return $wpdb->prefix . 'etelid_members'; }
    public static function t_companies() { global $wpdb; return $wpdb->prefix . 'etelid_companies'; }
    public static function t_log()       { global $wpdb; return $wpdb->prefix . 'etelid_log'; }
    public static function t_biometric() { global $wpdb; return $wpdb->prefix . 'etelid_biometric'; }    /* v3.0.0 */
    public static function t_bio_log()   { global $wpdb; return $wpdb->prefix . 'etelid_bio_log'; }       /* v3.0.0 */
    public static function t_password_resets() { global $wpdb; return $wpdb->prefix . 'etelid_password_resets'; }

    /* ─── Install / Activate ──────────────────────────────── */
    /* ── Schema upgrades — runs on every boot, idempotent ── */
    public static function upgrade_schema() {
        global $wpdb;
        $c = $wpdb->get_charset_collate();

        /* Upgrade 1: photo_url VARCHAR(500) → MEDIUMTEXT (supports base64 images) */
        $col = $wpdb->get_row(
            "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = '" . self::t_members() . "'
               AND COLUMN_NAME  = 'photo_url'"
        );
        if ( $col && stripos( $col->COLUMN_TYPE, 'varchar' ) !== false ) {
            $wpdb->query(
                "ALTER TABLE " . self::t_members() . "
                 MODIFY COLUMN photo_url MEDIUMTEXT NOT NULL DEFAULT ''"
            );
        }

        /* Upgrade 2: pin_hash VARCHAR(64) → VARCHAR(255)
         * bcrypt output is 60 chars today but PHP docs warn length may
         * grow in future versions. VARCHAR(255) is the officially recommended
         * column size for password_hash() output. This ALTER is idempotent. */
        $ph_col = $wpdb->get_row(
            "SELECT CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = '" . self::t_members() . "'
               AND COLUMN_NAME  = 'pin_hash'"
        );
        if ( $ph_col && (int) $ph_col->CHARACTER_MAXIMUM_LENGTH < 255 ) {
            $wpdb->query(
                "ALTER TABLE " . self::t_members() . "
                 MODIFY COLUMN pin_hash VARCHAR(255) NOT NULL DEFAULT ''
                 COMMENT 'bcrypt hash (password_hash); legacy SHA-256 accepted during upgrade window'"
            );
        }

        /* Upgrade 3: Add Telegram chat_id + WhatsApp phone columns
         * Used for free OTP delivery across all Etel Group apps.
         * Idempotent — checks column existence first. */
        $tg_col = $wpdb->get_row(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = '" . self::t_members() . "'
               AND COLUMN_NAME  = 'tg_chat_id'"
        );
        if ( ! $tg_col ) {
            $wpdb->query(
                "ALTER TABLE " . self::t_members() . "
                 ADD COLUMN tg_chat_id  VARCHAR(30)   NOT NULL DEFAULT '' COMMENT 'Telegram chat_id for OTP delivery',
                 ADD COLUMN tg_username VARCHAR(100)  NOT NULL DEFAULT '' COMMENT 'Telegram @username',
                 ADD COLUMN wa_phone    VARCHAR(30)   NOT NULL DEFAULT '' COMMENT 'WhatsApp number for OTP delivery (E.164)',
                 ADD KEY idx_tg_chat_id (tg_chat_id)"
            );
        }

        /* Upgrade 4 — FAYDA quick-register + two-pass KYC (v3.10.0)
         * Idempotent — checks column existence first. */
        $kyc_col = $wpdb->get_row(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = '" . self::t_members() . "'
               AND COLUMN_NAME  = 'kyc_level'"
        );
        if ( ! $kyc_col ) {
            $wpdb->query(
                "ALTER TABLE " . self::t_members() . "
                 ADD COLUMN kyc_level            TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0=none 1=Fayda quick-register 2=biometric+info (EtelPay unlocked)',
                 ADD COLUMN must_change_password TINYINT(1)       NOT NULL DEFAULT 0 COMMENT 'Force password change on next login',
                 ADD COLUMN fayda_fin             VARCHAR(20)      NOT NULL DEFAULT '' COMMENT 'Government Fayda National ID number (12-digit)',
                 ADD COLUMN fayda_front_image     MEDIUMTEXT       NULL COMMENT 'Base64/URL of captured Fayda card front',
                 ADD COLUMN fayda_back_image      MEDIUMTEXT       NULL COMMENT 'Base64/URL of captured Fayda card back',
                 ADD COLUMN fayda_ocr_raw         MEDIUMTEXT       NULL COMMENT 'Raw OCR text snapshot for audit',
                 ADD COLUMN fayda_captured_at     DATETIME         NULL,
                 ADD COLUMN pass2_completed_at    DATETIME         NULL,
                 ADD COLUMN occupation            VARCHAR(150)     NOT NULL DEFAULT '',
                 ADD COLUMN income_range          VARCHAR(50)      NOT NULL DEFAULT '',
                 ADD COLUMN source_of_funds       VARCHAR(150)     NOT NULL DEFAULT '',
                 ADD COLUMN next_of_kin_name      VARCHAR(150)     NOT NULL DEFAULT '',
                 ADD COLUMN next_of_kin_phone     VARCHAR(30)      NOT NULL DEFAULT '',
                 ADD COLUMN notify_channel        VARCHAR(20)      NOT NULL DEFAULT '' COMMENT 'Last channel used to deliver credentials',
                 ADD KEY idx_kyc_level (kyc_level)"
            );
        }

        /* Upgrade 5 — kyc_access_log table used by EtelID_Service_KYC for
         * cross-system audit (EtelPay/Delivery/Ride checking eligibility).
         * CREATE TABLE IF NOT EXISTS is naturally idempotent. */
        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS " . $wpdb->prefix . "etelid_kyc_access_log (
                id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                member_id    BIGINT UNSIGNED NOT NULL,
                accessor_app VARCHAR(60)     NOT NULL DEFAULT '',
                accessor_ip  VARCHAR(45)     NOT NULL DEFAULT '',
                accessed_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_member (member_id),
                KEY idx_accessed (accessed_at)
            ) " . $c . ";"
        );

        /* Upgrade 6 — Pass 2 review state for member-submitted Fayda ID
         * photos (v3.11.0). Reuses the existing fayda_front_image /
         * fayda_back_image / fayda_captured_at columns from Upgrade 4
         * as the storage; these new columns just track who submitted
         * vs who approved, so a member's own upload can sit pending
         * until an admin reviews it, while an admin's own upload (via
         * the Members Controller) goes straight to approved. */
        $pass2_col = $wpdb->get_row(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = '" . self::t_members() . "'
               AND COLUMN_NAME  = 'pass2_status'"
        );
        if ( ! $pass2_col ) {
            $wpdb->query(
                "ALTER TABLE " . self::t_members() . "
                 ADD COLUMN pass2_status       VARCHAR(20)  NOT NULL DEFAULT '' COMMENT 'empty|pending|approved|rejected — Fayda ID photo Pass 2 review state',
                 ADD COLUMN pass2_submitted_at DATETIME     NULL COMMENT 'When the member submitted their own Fayda ID photos',
                 ADD COLUMN pass2_reviewed_by  BIGINT UNSIGNED NULL COMMENT 'Admin member id who approved/rejected',
                 ADD COLUMN pass2_review_note  VARCHAR(255) NOT NULL DEFAULT '',
                 ADD KEY idx_pass2_status (pass2_status)"
            );
        }

        /* Upgrade 7 — self-service password reset via OTP code
         * (email now, Telegram once a member has tg_chat_id on file).
         * Previously the only way to give a member a working password
         * was an admin manually doing it in wp-admin
         * (admin/views/password-reset.php, which requires
         * manage_options) - members themselves had no way in. */
        $c2 = $wpdb->get_charset_collate();
        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS " . self::t_password_resets() . " (
                id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                member_id   BIGINT UNSIGNED NOT NULL,
                code_hash   VARCHAR(255)    NOT NULL,
                channel     VARCHAR(20)     NOT NULL DEFAULT 'email',
                expires_at  DATETIME        NOT NULL,
                used        TINYINT(1)      NOT NULL DEFAULT 0,
                created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_member (member_id)
            ) $c2"
        );
    }

    public static function install() {
        global $wpdb;
        $c = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        /* ── Members table ──────────────────────────────────── */
        $sql_members = "CREATE TABLE IF NOT EXISTS " . self::t_members() . " (
            id               BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,

            -- Identity numbers
            etel_id          VARCHAR(24)      NOT NULL  COMMENT 'Primary ID: EG-2025-00001',
            fin              VARCHAR(16)      NOT NULL  COMMENT 'Financial ID: 3580-1754-1932',
            card_number      VARCHAR(20)      NOT NULL  COMMENT '16-digit: 6408 3195 8602 8154',
            serial_number    VARCHAR(10)      NOT NULL  COMMENT '7-digit serial',
            pin_hash         VARCHAR(255)     NOT NULL  DEFAULT '' COMMENT 'bcrypt hash (password_hash); legacy SHA-256 accepted during upgrade window',

            -- Personal info
            full_name        VARCHAR(120)     NOT NULL,
            full_name_am     VARCHAR(200)     NOT NULL  DEFAULT '',
            dob              DATE             NOT NULL,
            sex              ENUM('Male','Female','Other') NOT NULL DEFAULT 'Male',
            blood_type       VARCHAR(5)       NOT NULL  DEFAULT '',
            nationality      VARCHAR(80)      NOT NULL  DEFAULT 'Ethiopian',
            phone            VARCHAR(30)      NOT NULL,
            email            VARCHAR(160)     NOT NULL  DEFAULT '',

            -- Address
            region           VARCHAR(100)     NOT NULL  DEFAULT '',
            zone             VARCHAR(100)     NOT NULL  DEFAULT '',
            woreda           VARCHAR(100)     NOT NULL  DEFAULT '',
            address_detail   VARCHAR(255)     NOT NULL  DEFAULT '',

            -- Company affiliation
            primary_company  VARCHAR(80)      NOT NULL  DEFAULT 'Etel Group',
            role             VARCHAR(100)     NOT NULL  DEFAULT 'Member',
            employee_number  VARCHAR(30)      NOT NULL  DEFAULT '',
            department       VARCHAR(100)     NOT NULL  DEFAULT '',

            -- Photo
            photo_url        MEDIUMTEXT       NOT NULL  DEFAULT '',
            photo_attachment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,

            -- Dates
            issue_date       DATE             NOT NULL,
            expiry_date      DATE             NOT NULL,
            dob_eth          VARCHAR(30)      NOT NULL  DEFAULT '' COMMENT 'Ethiopian calendar DOB',
            issue_date_eth   VARCHAR(30)      NOT NULL  DEFAULT '',
            expiry_date_eth  VARCHAR(30)      NOT NULL  DEFAULT '',

            -- Status & security
            status           ENUM('active','suspended','expired','revoked') NOT NULL DEFAULT 'active',
            tier             ENUM('standard','staff','manager','executive','vip') NOT NULL DEFAULT 'standard',
            qr_token         VARCHAR(64)      NOT NULL  DEFAULT '' COMMENT 'Secret token in QR payload',
            qr_scan_count    INT UNSIGNED     NOT NULL  DEFAULT 0,
            last_verified    DATETIME                   DEFAULT NULL,
            notes            TEXT,

            -- Timestamps
            created_at       DATETIME         NOT NULL  DEFAULT CURRENT_TIMESTAMP,
            updated_at       DATETIME         NOT NULL  DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_by       BIGINT UNSIGNED  NOT NULL  DEFAULT 0 COMMENT 'WP user ID who issued this',

            PRIMARY KEY (id),
            UNIQUE KEY uk_etel_id      (etel_id),
            UNIQUE KEY uk_fin          (fin),
            UNIQUE KEY uk_card_number  (card_number),
            UNIQUE KEY uk_serial       (serial_number),
            KEY        idx_phone       (phone),
            KEY        idx_email       (email),
            KEY        idx_status      (status),
            KEY        idx_company     (primary_company),
            KEY        idx_tier        (tier),
            KEY        idx_created     (created_at)
        ) $c;";

        /* ── Company links table ───────────────────────────── */
        $sql_companies = "CREATE TABLE IF NOT EXISTS " . self::t_companies() . " (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            member_id    BIGINT UNSIGNED NOT NULL,
            company_slug VARCHAR(40)     NOT NULL  COMMENT 'etel-market, etel-delivery, etc.',
            company_name VARCHAR(80)     NOT NULL,
            role_at      VARCHAR(100)    NOT NULL  DEFAULT 'Member',
            linked_at    DATETIME        NOT NULL  DEFAULT CURRENT_TIMESTAMP,
            active       TINYINT(1)      NOT NULL  DEFAULT 1,
            PRIMARY KEY (id),
            KEY idx_member  (member_id),
            KEY idx_company (company_slug),
            UNIQUE KEY uk_member_company (member_id, company_slug)
        ) $c;";

        /* ── Audit log table ───────────────────────────────── */
        $sql_log = "CREATE TABLE IF NOT EXISTS " . self::t_log() . " (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            member_id  BIGINT UNSIGNED NOT NULL DEFAULT 0,
            etel_id    VARCHAR(24)     NOT NULL DEFAULT '',
            event      VARCHAR(60)     NOT NULL COMMENT 'issued, verified, suspended, revoked, printed, updated',
            actor_id   BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'WP user or 0 for public',
            actor_ip   VARCHAR(45)     NOT NULL DEFAULT '',
            actor_ua   VARCHAR(255)    NOT NULL DEFAULT '',
            detail     TEXT,
            created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_member    (member_id),
            KEY idx_etel_id   (etel_id),
            KEY idx_event     (event),
            KEY idx_created   (created_at)
        ) $c;";

        /* ── Biometric data table (v3.0.0) ────────────────────── */
        $sql_biometric = "CREATE TABLE IF NOT EXISTS " . self::t_biometric() . " (
            id                  BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
            member_id           BIGINT UNSIGNED  NOT NULL,
            etel_id             VARCHAR(24)      NOT NULL DEFAULT '',

            -- Fingerprint (ISO/IEC 19794-2 WSQ template → SHA-256)
            fingerprint_hash    VARCHAR(64)      NOT NULL DEFAULT '',
            fingerprint_quality TINYINT UNSIGNED NOT NULL DEFAULT 0,

            -- Face (FaceNet/DeepFace 128-D embedding → SHA-256)
            face_hash           VARCHAR(64)      NOT NULL DEFAULT '',
            face_quality        TINYINT UNSIGNED NOT NULL DEFAULT 0,
            face_photo_url      VARCHAR(500)     NOT NULL DEFAULT '',

            -- NFC / RFID card UID (HEX, stored as-is)
            nfc_uid             VARCHAR(64)      NOT NULL DEFAULT '',
            nfc_card_type       VARCHAR(40)      NOT NULL DEFAULT '',

            -- Iris (ISO/IEC 19794-6 IrisCode → SHA-256)
            iris_hash           VARCHAR(64)      NOT NULL DEFAULT '',
            iris_quality        TINYINT UNSIGNED NOT NULL DEFAULT 0,

            -- Voice print (MFCC vector → SHA-256)
            voice_hash          VARCHAR(64)      NOT NULL DEFAULT '',
            voice_quality       TINYINT UNSIGNED NOT NULL DEFAULT 0,

            -- WebAuthn / FIDO2 credential
            webauthn_credential_id  VARCHAR(500) NOT NULL DEFAULT '',
            webauthn_public_key     TEXT,
            webauthn_sign_count     INT UNSIGNED NOT NULL DEFAULT 0,

            -- Liveness
            liveness_level      TINYINT UNSIGNED NOT NULL DEFAULT 0  COMMENT '0=none 1=photo 2=liveness 3=webauthn',
            liveness_confidence TINYINT UNSIGNED NOT NULL DEFAULT 0,

            -- Physical biometrics
            height_cm           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            weight_kg           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            eye_color           VARCHAR(30)       NOT NULL DEFAULT '',
            hair_color          VARCHAR(30)       NOT NULL DEFAULT '',
            skin_tone           VARCHAR(30)       NOT NULL DEFAULT '',
            distinguishing_marks TEXT,

            -- Digital signature
            signature_data_url  MEDIUMTEXT,

            -- Enrollment metadata
            enrolled_by         VARCHAR(120)     NOT NULL DEFAULT '',
            enrolled_at         DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at          DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY (id),
            UNIQUE KEY uk_member (member_id),
            KEY idx_etel_id (etel_id),
            KEY idx_nfc (nfc_uid)
        ) $c;";

        /* ── Biometric check-in / access log (v3.0.0) ─────────── */
        $sql_bio_log = "CREATE TABLE IF NOT EXISTS " . self::t_bio_log() . " (
            id          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
            member_id   BIGINT UNSIGNED  NOT NULL DEFAULT 0,
            etel_id     VARCHAR(24)      NOT NULL DEFAULT '',
            method      VARCHAR(30)      NOT NULL COMMENT 'fingerprint|face|nfc|iris|voice|webauthn',
            result      VARCHAR(20)      NOT NULL COMMENT 'success|failed|not_enrolled|suspended',
            confidence  TINYINT UNSIGNED NOT NULL DEFAULT 0,
            location    VARCHAR(120)     NOT NULL DEFAULT '',
            device_id   VARCHAR(80)      NOT NULL DEFAULT '',
            actor_ip    VARCHAR(45)      NOT NULL DEFAULT '',
            created_at  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_member  (member_id),
            KEY idx_method  (method),
            KEY idx_result  (result),
            KEY idx_created (created_at)
        ) $c;";

        dbDelta( $sql_members );
        dbDelta( $sql_companies );
        dbDelta( $sql_log );
        dbDelta( $sql_biometric );
        dbDelta( $sql_bio_log );

        /* Default options */
        add_option( 'etelid_db_version',   ETELID_VERSION );
        add_option( 'etelid_counter',      0 );
        add_option( 'etelid_org_name',     'Etel Group · ኢትኤል ቡድን' );
        add_option( 'etelid_org_website',  'etelgroup.com' );
        add_option( 'etelid_org_email',    'security@etelgroup.com' );
        add_option( 'etelid_expiry_years', 5 );
        add_option( 'etelid_id_prefix',    'EG' );
        add_option( 'etelid_require_approval', 0 );

        flush_rewrite_rules();
    }

    public static function deactivate() {
        flush_rewrite_rules();
    }

    public static function uninstall() {
        global $wpdb;
        $wpdb->query( "DROP TABLE IF EXISTS " . self::t_bio_log() );
        $wpdb->query( "DROP TABLE IF EXISTS " . self::t_biometric() );
        $wpdb->query( "DROP TABLE IF EXISTS " . self::t_log() );
        $wpdb->query( "DROP TABLE IF EXISTS " . self::t_companies() );
        $wpdb->query( "DROP TABLE IF EXISTS " . self::t_members() );
        $options = array(
            'etelid_db_version','etelid_counter','etelid_org_name',
            'etelid_org_website','etelid_org_email','etelid_expiry_years',
            'etelid_id_prefix','etelid_require_approval',
        );
        foreach ( $options as $o ) delete_option( $o );
    }

    /* ─── Counter ─────────────────────────────────────────── */
    public static function next_counter() {
        $n = (int) get_option( 'etelid_counter', 0 ) + 1;
        update_option( 'etelid_counter', $n );
        return $n;
    }

    /* ─── INSERT ──────────────────────────────────────────── */
    public static function insert_member( $data ) {
        global $wpdb;
        $wpdb->insert( self::t_members(), $data );
        return $wpdb->insert_id;
    }

    public static function link_company( $member_id, $slug, $name, $role = 'Member' ) {
        global $wpdb;
        $wpdb->replace( self::t_companies(), array(
            'member_id'    => (int) $member_id,
            'company_slug' => $slug,
            'company_name' => $name,
            'role_at'      => $role,
            'active'       => 1,
        ));
    }

    public static function log( $member_id, $etel_id, $event, $detail = '' ) {
        global $wpdb;
        $wpdb->insert( self::t_log(), array(
            'member_id'  => (int) $member_id,
            'etel_id'    => $etel_id,
            'event'      => $event,
            'actor_id'   => (int) get_current_user_id(),
            'actor_ip'   => self::get_ip(),
            'actor_ua'   => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : '',
            'detail'     => $detail,
        ));
    }

    /* ─── SELECT ──────────────────────────────────────────── */
    public static function get_member( $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::t_members() . " WHERE id = %d LIMIT 1", (int) $id
        ));
    }

    public static function get_by_etel_id( $etel_id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::t_members() . " WHERE etel_id = %s LIMIT 1", $etel_id
        ));
    }

    public static function get_by_fin( $fin ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::t_members() . " WHERE fin = %s LIMIT 1", $fin
        ));
    }

    public static function get_by_card( $card ) {
        global $wpdb;
        $clean = preg_replace( '/\s+/', '', $card );
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::t_members() . " WHERE REPLACE(card_number,' ','') = %s LIMIT 1", $clean
        ));
    }

    public static function find_by_phone( $phone ) {
        global $wpdb;
        // Compare by the last 9 digits rather than an exact string match -
        // a phone typed as 0924445206, 924445206, or +251924445206 are all
        // the same real number, but an exact match would only recognize
        // whichever exact format happens to be stored. This was silently
        // breaking password reset for anyone who typed their number in a
        // different format than however it was originally saved.
        //
        // Also: if more than one account shares this phone (duplicate
        // signups - a real data-quality issue worth cleaning up
        // separately), prefer whichever one is actually usable for
        // delivery (has an email or Telegram linked), then the most
        // recently created, rather than an arbitrary/unspecified row.
        // This doesn't fix the duplicates themselves, but stops password
        // reset from silently picking a dead-end account when a working
        // one exists for the same number.
        $digits = preg_replace( '/[^0-9]/', '', $phone );
        $last9  = strlen( $digits ) >= 9 ? substr( $digits, -9 ) : $digits;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::t_members() . "
             WHERE RIGHT(REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'+',''), 9) = %s
             ORDER BY (email != '' OR tg_chat_id != '') DESC, id DESC
             LIMIT 1", $last9
        ));
    }

    /* Matches on full_name (trimmed, case-insensitive) AND dob together,
     * not name alone - two genuinely different people can share a common
     * name, but the same name plus the same date of birth is a real
     * duplicate-registration signal (e.g. someone re-registering with a
     * different phone number to get a second ID card, or a test name
     * typed in twice). Used by the registration wizard's live check
     * (etelid_check_duplicate_name AJAX action) and re-checked server-side
     * in EtelID_Auth::register() itself. */
    public static function find_duplicate_registration( $full_name, $dob ) {
        global $wpdb;
        $name = trim( preg_replace( '/\s+/', ' ', (string) $full_name ) );
        if ( '' === $name || empty( $dob ) ) return null;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::t_members() . "
             WHERE LOWER(TRIM(full_name)) = LOWER(%s) AND dob = %s
             LIMIT 1", $name, $dob
        ));
    }

    /* Name-only match (no dob), used for a non-blocking live "this name
     * looks like it might already be registered" notice while filling
     * in the wizard - distinct from find_duplicate_registration() above,
     * which requires name+dob together and is what actually blocks
     * registration. Name alone is too weak a signal to block on (two
     * real, different people can share a name), but still worth
     * surfacing so the person filling the form can double-check before
     * continuing. */
    /* Component-wise match, not an exact full-string match: an exact
     * comparison missed a real case ("Dagmawi Dikasso" typed at
     * registration vs. "Dagmawi Dikasso Aydiko" already on file - a
     * different string, but every word the user typed is part of that
     * existing name). Requires every word in $full_name to appear
     * (as a substring, case-insensitive) somewhere in an existing
     * member's full_name - i.e. first name matches AND middle/last
     * name(s) match, not just any one word in common (which would
     * false-positive on every other "Dagmawi" in the system). */
    public static function find_by_name( $full_name ) {
        global $wpdb;
        $words = preg_split( '/\s+/', trim( (string) $full_name ), -1, PREG_SPLIT_NO_EMPTY );
        if ( empty( $words ) ) return null;
        $where  = array();
        $params = array();
        foreach ( $words as $w ) {
            $where[]  = 'full_name LIKE %s';
            $params[] = '%' . $wpdb->esc_like( $w ) . '%';
        }
        $sql = "SELECT * FROM " . self::t_members() . " WHERE " . implode( ' AND ', $where ) . " LIMIT 1";
        return $wpdb->get_row( $wpdb->prepare( $sql, $params ) );
    }

    /* Email has no UNIQUE key at the schema level, so this is the only
     * thing stopping the same email being reused across accounts -
     * checked live during registration and re-checked server-side. */
    public static function find_by_email( $email ) {
        global $wpdb;
        $email = trim( (string) $email );
        if ( '' === $email ) return null;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::t_members() . "
             WHERE email != '' AND LOWER(email) = LOWER(%s)
             LIMIT 1", $email
        ));
    }

    public static function get_companies( $member_id ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM " . self::t_companies() . " WHERE member_id = %d AND active = 1 ORDER BY linked_at ASC",
            (int) $member_id
        ));
    }

    /** Distinct company_slug values actually seen (from real login activity),
        which is the real source of truth -- not any hardcoded list, since
        each project sends whatever raw slug it wants at login time. */
    public static function get_known_company_slugs() {
        global $wpdb;
        return $wpdb->get_col( "SELECT DISTINCT company_slug FROM " . self::t_companies() . " ORDER BY company_slug ASC" );
    }

    /** All company links, optionally filtered by company_slug, newest first. */
    public static function get_all_company_links( $company_slug = '' ) {
        global $wpdb;
        $t  = self::t_companies();
        $mt = self::t_members();
        if ( $company_slug ) {
            return $wpdb->get_results( $wpdb->prepare(
                "SELECT c.*, m.full_name, m.phone, m.etel_id
                   FROM {$t} c JOIN {$mt} m ON m.id = c.member_id
                  WHERE c.company_slug = %s
                  ORDER BY c.linked_at DESC",
                $company_slug
            ));
        }
        return $wpdb->get_results(
            "SELECT c.*, m.full_name, m.phone, m.etel_id
               FROM {$t} c JOIN {$mt} m ON m.id = c.member_id
              ORDER BY c.linked_at DESC LIMIT 200"
        );
    }

    /** Create or update a member's role for a company_slug (admin action). */
    public static function set_company_role( $member_id, $company_slug, $company_name, $role_at ) {
        global $wpdb;
        return $wpdb->query( $wpdb->prepare(
            "INSERT INTO " . self::t_companies() . " (member_id, company_slug, company_name, role_at)
             VALUES (%d, %s, %s, %s)
             ON DUPLICATE KEY UPDATE role_at = VALUES(role_at), company_name = VALUES(company_name), active = 1",
            (int) $member_id, $company_slug, $company_name, $role_at
        ));
    }

    /** Deactivate a member's link to a company (soft delete). */
    public static function remove_company_link( $link_id ) {
        global $wpdb;
        return $wpdb->update( self::t_companies(), array( 'active' => 0 ), array( 'id' => (int) $link_id ) );
    }

    /** Look up a single company-role link row by its id (for verifying a
     *  caller's company_slug actually matches before removing it, and for
     *  logging what was removed). */
    public static function get_company_link( $link_id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::t_companies() . " WHERE id = %d LIMIT 1", (int) $link_id
        ));
    }

    public static function get_log( $member_id, $limit = 20 ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM " . self::t_log() . " WHERE member_id = %d ORDER BY created_at DESC LIMIT %d",
            (int) $member_id, (int) $limit
        ));
    }

    /**
     * Get all members with optional search, pagination, and filters.
     */
    public static function get_all( $args = array() ) {
        global $wpdb;
        $t       = self::t_members();
        $limit   = isset( $args['limit'] )   ? max( 1, (int) $args['limit'] )   : 25;
        $offset  = isset( $args['offset'] )  ? max( 0, (int) $args['offset'] )  : 0;
        $search  = isset( $args['search'] )  ? trim( $args['search'] )           : '';
        $status  = isset( $args['status'] )  ? $args['status']                   : '';
        $company = isset( $args['company'] ) ? $args['company']                  : '';
        $tier    = isset( $args['tier'] )    ? $args['tier']                     : '';
        $orderby = isset( $args['orderby'] ) ? $args['orderby']                  : 'id';
        $order   = isset( $args['order'] ) && strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

        $allowed_orderby = array( 'id','full_name','etel_id','issue_date','status','tier','primary_company' );
        if ( ! in_array( $orderby, $allowed_orderby, true ) ) $orderby = 'id';

        $where   = array( '1=1' );
        $params  = array();

        if ( $search ) {
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $where[] = '(full_name LIKE %s OR full_name_am LIKE %s OR etel_id LIKE %s OR phone LIKE %s OR email LIKE %s OR fin LIKE %s)';
            $params  = array_merge( $params, array( $like, $like, $like, $like, $like, $like ) );
        }
        if ( $status )  { $where[] = 'status = %s';          $params[] = $status; }
        if ( $company ) { $where[] = 'primary_company = %s'; $params[] = $company; }
        if ( $tier )    { $where[] = 'tier = %s';            $params[] = $tier; }

        $where_sql = implode( ' AND ', $where );
        $sql = "SELECT * FROM {$t} WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
        $params[] = $limit;
        $params[] = $offset;

        if ( $params ) {
            return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
        }
        return $wpdb->get_results( $sql );
    }

    public static function count_all( $args = array() ) {
        global $wpdb;
        $t      = self::t_members();
        $search  = isset( $args['search'] )  ? trim( $args['search'] )  : '';
        $status  = isset( $args['status'] )  ? $args['status']          : '';
        $company = isset( $args['company'] ) ? $args['company']         : '';

        $where  = array( '1=1' );
        $params = array();

        if ( $search ) {
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $where[]  = '(full_name LIKE %s OR etel_id LIKE %s OR phone LIKE %s OR fin LIKE %s)';
            $params   = array_merge( $params, array( $like, $like, $like, $like ) );
        }
        if ( $status )  { $where[] = 'status = %s';          $params[] = $status; }
        if ( $company ) { $where[] = 'primary_company = %s'; $params[] = $company; }

        $where_sql = implode( ' AND ', $where );
        $sql = "SELECT COUNT(*) FROM {$t} WHERE {$where_sql}";

        if ( $params ) return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
        return (int) $wpdb->get_var( $sql );
    }

    public static function count_by_status() {
        global $wpdb;
        $t   = self::t_members();
        $rows = $wpdb->get_results( "SELECT status, COUNT(*) as n FROM {$t} GROUP BY status" );
        $out = array( 'active' => 0, 'suspended' => 0, 'expired' => 0, 'revoked' => 0 );
        foreach ( $rows as $r ) {
            if ( isset( $out[ $r->status ] ) ) $out[ $r->status ] = (int) $r->n;
        }
        return $out;
    }

    /* ─── UPDATE ──────────────────────────────────────────── */
    public static function update_member( $id, $data ) {
        global $wpdb;
        return $wpdb->update( self::t_members(), $data, array( 'id' => (int) $id ) );
    }

    public static function set_status( $id, $status ) {
        return self::update_member( $id, array( 'status' => $status ) );
    }

    public static function increment_scan( $id ) {
        global $wpdb;
        $t = self::t_members();
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$t} SET qr_scan_count = qr_scan_count + 1, last_verified = NOW() WHERE id = %d",
            (int) $id
        ));
    }

    /* ─── DELETE ──────────────────────────────────────────── */
    public static function delete_member( $id ) {
        global $wpdb;
        $member = self::get_member( $id );
        if ( ! $member ) return false;
        $wpdb->delete( self::t_companies(), array( 'member_id' => (int) $id ) );
        self::log( $id, $member->etel_id, 'deleted', 'Hard delete by admin' );
        return $wpdb->delete( self::t_members(), array( 'id' => (int) $id ) );
    }

    /* ─── Helpers ─────────────────────────────────────────── */
    private static function get_ip() {
        $keys = array( 'HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR' );
        foreach ( $keys as $k ) {
            if ( ! empty( $_SERVER[ $k ] ) ) {
                return sanitize_text_field( wp_unslash( explode( ',', $_SERVER[ $k ] )[0] ) );
            }
        }
        return '';
    }

    public static function unique_exists( $column, $value ) {
        global $wpdb;
        $t = self::t_members();
        $allowed = array( 'etel_id', 'fin', 'card_number', 'serial_number' );
        if ( ! in_array( $column, $allowed, true ) ) return false;
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$t} WHERE {$column} = %s", $value
        ));
    }

    /* ════════════════════════════════════════════════════════
       BIOMETRIC DATA — v3.0.0
    ════════════════════════════════════════════════════════ */

    public static function get_biometric( $member_id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::t_biometric() . " WHERE member_id = %d LIMIT 1",
            (int) $member_id
        ));
    }

    public static function get_biometric_by_nfc( $nfc_uid ) {
        global $wpdb;
        $nfc = strtoupper( preg_replace( '/[^0-9A-Fa-f:]/', '', $nfc_uid ) );
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::t_biometric() . " WHERE nfc_uid = %s LIMIT 1", $nfc
        ));
    }

    public static function get_biometric_by_webauthn_id( $credential_id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::t_biometric() . " WHERE webauthn_credential_id = %s LIMIT 1", $credential_id
        ));
    }

    public static function upsert_biometric( $member_id, $etel_id, $data ) {
        global $wpdb;
        $exists = self::get_biometric( $member_id );
        $data['member_id'] = (int) $member_id;
        $data['etel_id']   = $etel_id;
        if ( $exists ) {
            return $wpdb->update( self::t_biometric(), $data, array( 'member_id' => (int) $member_id ) );
        }
        return $wpdb->insert( self::t_biometric(), $data );
    }

    public static function biometric_global_stats() {
        global $wpdb;
        $t = self::t_biometric();
        return array(
            'total'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t" ),
            'fingerprint' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE fingerprint_hash != ''" ),
            'face'        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE face_hash != ''" ),
            'nfc'         => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE nfc_uid != ''" ),
            'iris'        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE iris_hash != ''" ),
            'voice'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE voice_hash != ''" ),
            'webauthn'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE webauthn_credential_id != ''" ),
            'liveness2'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE liveness_level >= 2" ),
            'liveness3'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE liveness_level >= 3" ),
            'full'        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE fingerprint_hash != '' AND face_hash != '' AND nfc_uid != ''" ),
        );
    }

    public static function log_bio_checkin( $data ) {
        global $wpdb;
        $data['actor_ip'] = self::get_ip();
        return $wpdb->insert( self::t_bio_log(), $data );
    }

    public static function get_bio_log( $member_id = 0, $limit = 100 ) {
        global $wpdb;
        $t = self::t_bio_log();
        if ( $member_id ) {
            return $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM $t WHERE member_id = %d ORDER BY id DESC LIMIT %d",
                (int) $member_id, (int) $limit
            ));
        }
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $t ORDER BY id DESC LIMIT %d", (int) $limit
        ));
    }

    public static function bio_checkin_stats() {
        global $wpdb;
        $t = self::t_bio_log();
        return array(
            'today'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE DATE(created_at) = CURDATE()" ),
            'week'        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)" ),
            'success'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE result = 'success'" ),
            'failed'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE result = 'failed'" ),
            'fingerprint' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE method = 'fingerprint'" ),
            'face'        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE method = 'face'" ),
            'nfc'         => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE method = 'nfc'" ),
            'webauthn'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE method = 'webauthn'" ),
        );
    }
}
