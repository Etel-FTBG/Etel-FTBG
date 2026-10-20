<?php
/**
 * Etel-ID Premium — ID Generator & Company Registry
 *
 * @package    Etel-ID Premium
 * @version    2.0.0
 * @author     Etel Group · ኢትኤል ቡድን
 * @link       https://etelgroup.com
 * @since      2.0.0
 *
 * Generates all unique identity numbers (Etel-ID, FIN, card number,
 * serial, QR token), handles Ethiopian calendar conversion,
 * maintains the 9-company registry, and orchestrates member creation.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class EtelID_Generator {

    /* ─── Company Registry ─────────────────────────────────── */
    public static function companies() {
        return array(
            'etel-group' => array(
                'name'   => 'Etel Group',
                'name_am'=> 'ኢትኤል ቡድን',
                'code'   => 'EG',
                'color'  => '#c8922a',
                'status' => 'active',
            ),
            'etel-madeya' => array(
                'name'   => 'Etel Madeya',
                'name_am'=> 'ኢትኤል ማደያ',
                'code'   => 'EM',
                'color'  => '#e05555',
                'status' => 'active',
            ),
            'etel-market' => array(
                'name'   => 'Etel Market',
                'name_am'=> 'ኢትኤል ማርኬት',
                'code'   => 'MK',
                'color'  => '#22c55e',
                'status' => 'active',
            ),
            'etel-delivery' => array(
                'name'   => 'Etel Delivery',
                'name_am'=> 'ኢትኤል ዴሊቨሪ',
                'code'   => 'DL',
                'color'  => '#3b82f6',
                'status' => 'active',
            ),
            'etel-transport' => array(
                'name'   => 'Etel Transport',
                'name_am'=> 'ኢትኤል ትራንስፖርት',
                'code'   => 'TR',
                'color'  => '#f59e0b',
                'status' => 'planned',
            ),
            'etel-laundry' => array(
                'name'   => 'Etel Laundry',
                'name_am'=> 'ኢትኤል ላውንድሪ',
                'code'   => 'LN',
                'color'  => '#0ea5e9',
                'status' => 'active',
            ),
            'etel-pay' => array(
                'name'   => 'Etel Pay',
                'name_am'=> 'ኢትኤል ፔይ',
                'code'   => 'EP',
                'color'  => '#10b981',
                'status' => 'planned',
            ),
            'etel-media' => array(
                'name'   => 'Etel Media',
                'name_am'=> 'ኢትኤል ሚዲያ',
                'code'   => 'MD',
                'color'  => '#8b5cf6',
                'status' => 'planned',
            ),
            'luxline' => array(
                'name'   => 'LuxLine',
                'name_am'=> 'ሉክስ ላይን',
                'code'   => 'LX',
                'color'  => '#ec4899',
                'status' => 'planned',
            ),
        );
    }

    public static function get_company( $slug ) {
        $companies = self::companies();
        return isset( $companies[ $slug ] ) ? $companies[ $slug ] : null;
    }

    public static function company_name_to_slug( $name ) {
        foreach ( self::companies() as $slug => $data ) {
            if ( $data['name'] === $name ) return $slug;
        }
        return 'etel-group';
    }

    /* ─── Tier definitions ─────────────────────────────────── */
    public static function tiers() {
        return array(
            'standard'  => array( 'label' => 'Standard',  'color' => '#6b7280' ),
            'staff'     => array( 'label' => 'Staff',     'color' => '#3b82f6' ),
            'manager'   => array( 'label' => 'Manager',   'color' => '#8b5cf6' ),
            'executive' => array( 'label' => 'Executive', 'color' => '#c8922a' ),
            'vip'       => array( 'label' => 'VIP',       'color' => '#ec4899' ),
        );
    }

    /* ─── ID Number Generators ─────────────────────────────── */

    /**
     * Generate unique Etel-ID: EG-2025-00001
     */
    public static function make_etel_id() {
        $prefix = get_option( 'etelid_id_prefix', 'EG' );
        $year   = date( 'Y' );
        $max    = 50;
        for ( $i = 0; $i < $max; $i++ ) {
            $n      = EtelID_DB::next_counter();
            $id     = $prefix . '-' . $year . '-' . str_pad( $n, 5, '0', STR_PAD_LEFT );
            if ( ! EtelID_DB::unique_exists( 'etel_id', $id ) ) return $id;
        }
        return $prefix . '-' . $year . '-' . str_pad( mt_rand( 10000, 99999 ), 5, '0', STR_PAD_LEFT ) . '-X';
    }

    /**
     * Generate unique FIN: XXXX-XXXX-XXXX (like Fayda)
     */
    public static function make_fin() {
        for ( $i = 0; $i < 20; $i++ ) {
            $fin = implode( '-', array(
                str_pad( mt_rand( 1000, 9999 ), 4, '0', STR_PAD_LEFT ),
                str_pad( mt_rand( 1000, 9999 ), 4, '0', STR_PAD_LEFT ),
                str_pad( mt_rand( 1000, 9999 ), 4, '0', STR_PAD_LEFT ),
            ));
            if ( ! EtelID_DB::unique_exists( 'fin', $fin ) ) return $fin;
        }
        return '';
    }

    /**
     * Generate unique 16-digit card number: XXXX XXXX XXXX XXXX
     */
    public static function make_card_number() {
        for ( $i = 0; $i < 20; $i++ ) {
            $num = implode( ' ', array(
                str_pad( mt_rand( 1000, 9999 ), 4, '0', STR_PAD_LEFT ),
                str_pad( mt_rand( 1000, 9999 ), 4, '0', STR_PAD_LEFT ),
                str_pad( mt_rand( 1000, 9999 ), 4, '0', STR_PAD_LEFT ),
                str_pad( mt_rand( 1000, 9999 ), 4, '0', STR_PAD_LEFT ),
            ));
            if ( ! EtelID_DB::unique_exists( 'card_number', $num ) ) return $num;
        }
        return '';
    }

    /**
     * Generate unique 7-digit serial number
     */
    public static function make_serial() {
        for ( $i = 0; $i < 20; $i++ ) {
            $s = (string) mt_rand( 1000000, 9999999 );
            if ( ! EtelID_DB::unique_exists( 'serial_number', $s ) ) return $s;
        }
        return '';
    }

    /**
     * Generate a cryptographic QR token (32-char hex)
     */
    public static function make_qr_token() {
        if ( function_exists( 'random_bytes' ) ) {
            return bin2hex( random_bytes( 16 ) );
        }
        return md5( uniqid( mt_rand(), true ) . time() );
    }

    /* ─── Ethiopian Calendar ───────────────────────────────── */

    /**
     * Convert Gregorian date string (YYYY-MM-DD) to Ethiopian calendar display.
     * Returns e.g. "2017/Jan/15" or "16/08/1993 | 2001/Apr/24"
     */
    public static function to_ethiopian( $gregorian_date ) {
        if ( ! $gregorian_date || $gregorian_date === '0000-00-00' ) return '';
        $ts = strtotime( $gregorian_date );
        if ( ! $ts ) return '';

        $g_year  = (int) date( 'Y', $ts );
        $g_month = (int) date( 'n', $ts );
        $g_day   = (int) date( 'j', $ts );

        // Ethiopian New Year is ~September 11 (Meskerem 1)
        if ( $g_month < 9 || ( $g_month === 9 && $g_day < 11 ) ) {
            $eth_year = $g_year - 8;
        } else {
            $eth_year = $g_year - 7;
        }

        $months = array(
            1=>'Jan', 2=>'Feb', 3=>'Mar', 4=>'Apr',
            5=>'May', 6=>'Jun', 7=>'Jul', 8=>'Aug',
            9=>'Sep', 10=>'Oct', 11=>'Nov', 12=>'Dec',
        );

        return $eth_year . '/' . $months[ $g_month ] . '/' . str_pad( $g_day, 2, '0', STR_PAD_LEFT );
    }

    /**
     * Full bilingual date display: "16/08/1993 | 2001/Apr/24"
     */
    public static function bilingual_date( $gregorian_date ) {
        if ( ! $gregorian_date || $gregorian_date === '0000-00-00' ) return '';
        $ts = strtotime( $gregorian_date );
        if ( ! $ts ) return '';
        $greg = date( 'd/m/Y', $ts );
        $eth  = self::to_ethiopian( $gregorian_date );
        return $greg . ' | ' . $eth;
    }

    /* ─── Create a new member ──────────────────────────────── */

    /**
     * Full member creation: validates, generates all IDs, inserts, links companies, logs.
     *
     * @param  array  $data  Sanitized input fields.
     * @return int|WP_Error  Inserted member DB row id, or WP_Error on failure.
     */
    public static function create( $data ) {
        /* One phone number = one EtelID, whichever sign-up door was used (the public apply form, Register, Quick Register,
         * the Fayda form or the wp-admin Issue screen). Only Register and Quick Register used to check this, so the same
         * number could get a second account through the apply form. The number is compared by its last 9 digits (so
         * 0924.., +251924.. and 924.. are the same), and one lock per number makes two requests that arrive together
         * create only one account. */
        global $wpdb;
        $phone_in = isset( $data['phone'] ) ? (string) $data['phone'] : '';
        $digits   = preg_replace( '/[^0-9]/', '', $phone_in );
        $lock     = '';
        if ( $digits !== '' ) {
            $lock = 'etelid_phone_' . substr( $digits, -9 );
            $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock ) );
            if ( EtelID_DB::find_by_phone( $phone_in ) ) {
                $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
                return new WP_Error( 'phone_exists', 'An account with this phone number already exists. Please sign in.' );
            }
        }
        $result = self::create_member( $data );
        if ( $lock !== '' ) {
            $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
        }
        return $result;
    }

    /* The real insert (create() above is the door that checks the phone number first). */
    private static function create_member( $data ) {
        $etel_id      = self::make_etel_id();
        $fin          = self::make_fin();
        $card_number  = self::make_card_number();
        $serial       = self::make_serial();
        $qr_token     = self::make_qr_token();

        if ( ! $etel_id || ! $fin || ! $card_number || ! $serial ) {
            return new WP_Error( 'gen_fail', 'Failed to generate unique ID numbers. Please try again.' );
        }

        $issue_date  = date( 'Y-m-d' );
        $years       = (int) get_option( 'etelid_expiry_years', 5 );
        $expiry_date = date( 'Y-m-d', strtotime( "+{$years} years" ) );

        $dob         = isset( $data['dob'] )  ? $data['dob']  : '';
        $dob_eth     = self::to_ethiopian( $dob );
        $issue_eth   = self::to_ethiopian( $issue_date );
        $expiry_eth  = self::to_ethiopian( $expiry_date );

        $company_slug = isset( $data['primary_company_slug'] )
            ? $data['primary_company_slug']
            : self::company_name_to_slug( isset( $data['primary_company'] ) ? $data['primary_company'] : '' );

        $company_data = self::get_company( $company_slug );
        $company_name = $company_data ? $company_data['name'] : 'Etel Group';

        $row = array(
            'etel_id'         => $etel_id,
            'fin'             => $fin,
            'card_number'     => $card_number,
            'serial_number'   => $serial,
            'pin_hash'        => '',
            'full_name'       => isset( $data['full_name'] )    ? $data['full_name']    : '',
            'full_name_am'    => isset( $data['full_name_am'] ) ? $data['full_name_am'] : '',
            'dob'             => $dob,
            'sex'             => isset( $data['sex'] )          ? $data['sex']          : 'Male',
            'blood_type'      => isset( $data['blood_type'] )   ? $data['blood_type']   : '',
            'nationality'     => isset( $data['nationality'] )  ? $data['nationality']  : 'Ethiopian',
            'phone'           => isset( $data['phone'] )        ? $data['phone']        : '',
            'email'           => isset( $data['email'] )        ? $data['email']        : '',
            'region'          => isset( $data['region'] )       ? $data['region']       : '',
            'zone'            => isset( $data['zone'] )         ? $data['zone']         : '',
            'woreda'          => isset( $data['woreda'] )       ? $data['woreda']       : '',
            'address_detail'  => isset( $data['address_detail'] ) ? $data['address_detail'] : '',
            'primary_company' => $company_name,
            'role'            => isset( $data['role'] )         ? $data['role']         : 'Member',
            'employee_number' => isset( $data['employee_number'] ) ? $data['employee_number'] : '',
            'department'      => isset( $data['department'] )   ? $data['department']   : '',
            'photo_url'       => isset( $data['photo_url'] )    ? $data['photo_url']    : '',
            'photo_attachment_id' => isset( $data['photo_attachment_id'] ) ? (int) $data['photo_attachment_id'] : 0,
            /* Fayda ID front/back, submitted directly during registration
             * instead of requiring a second logged-in trip to the portal
             * afterward. Mirrors exactly what fayda_id_submit() (in
             * class-etelid-auth-ajax.php, used by the already-existing
             * Edit Profile page) writes for an EXISTING member - same
             * column names, same 'pending' review status - just folded
             * into this one INSERT since there's no member_id yet to
             * UPDATE against at registration time. Both optional: a
             * registration with no Fayda photos attached behaves exactly
             * as before (empty strings, no pass2_status set), so this
             * doesn't force every new member through Fayda capture if
             * they skip that part of the form. */
            'fayda_front_image'  => isset( $data['fayda_front_image'] ) ? $data['fayda_front_image'] : '',
            'fayda_back_image'   => isset( $data['fayda_back_image'] )  ? $data['fayda_back_image']  : '',
            'fayda_captured_at'  => ( ! empty( $data['fayda_front_image'] ) && ! empty( $data['fayda_back_image'] ) ) ? current_time( 'mysql' ) : null,
            'pass2_status'       => ( ! empty( $data['fayda_front_image'] ) && ! empty( $data['fayda_back_image'] ) ) ? 'pending' : '',
            'pass2_submitted_at' => ( ! empty( $data['fayda_front_image'] ) && ! empty( $data['fayda_back_image'] ) ) ? current_time( 'mysql' ) : null,
            'issue_date'      => $issue_date,
            'expiry_date'     => $expiry_date,
            'dob_eth'         => $dob_eth,
            'issue_date_eth'  => $issue_eth,
            'expiry_date_eth' => $expiry_eth,
            'status'          => get_option( 'etelid_require_approval', 0 ) ? 'suspended' : 'active',
            'tier'            => isset( $data['tier'] )         ? $data['tier']         : 'standard',
            'qr_token'        => $qr_token,
            'notes'           => isset( $data['notes'] )        ? $data['notes']        : '',
            'created_by'      => (int) get_current_user_id(),
        );

        $insert_id = EtelID_DB::insert_member( $row );
        if ( ! $insert_id ) {
            return new WP_Error( 'db_fail', 'Database insert failed.' );
        }

        /* Link primary company */
        EtelID_DB::link_company( $insert_id, $company_slug, $company_name, $row['role'] );

        /* Link additional companies if provided */
        if ( ! empty( $data['extra_companies'] ) && is_array( $data['extra_companies'] ) ) {
            foreach ( $data['extra_companies'] as $slug ) {
                $cd = self::get_company( $slug );
                if ( $cd && $slug !== $company_slug ) {
                    EtelID_DB::link_company( $insert_id, $slug, $cd['name'], 'Member' );
                }
            }
        }

        /* Audit log */
        EtelID_DB::log( $insert_id, $etel_id, 'issued', 'New Etel-ID created for ' . $row['full_name'] );

        return $insert_id;
    }

    /* ─── Regions ──────────────────────────────────────────── */
    public static function regions() {
        return array(
            'Addis Ababa', 'Oromia', 'Amhara', 'SNNP', 'Tigray',
            'Sidama', 'South West Ethiopia', 'Central Ethiopia',
            'Afar', 'Somali', 'Benishangul-Gumuz', 'Gambela',
            'Harari', 'Dire Dawa',
        );
    }

    public static function blood_types() {
        return array( 'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-', 'Unknown' );
    }

    /* ─── Zones/cities per region ──────────────────────────────
     * Used to make the registration wizard's Zone/City field a
     * dropdown scoped to the selected Region, instead of free text
     * that can name a city belonging to a completely different
     * region (e.g. "Shashamane" typed while "Tigray" is selected -
     * a real case seen live). Not an exhaustive list of every
     * administrative zone/woreda - a practical set of the region's
     * major zones and cities, enough to catch a genuine region/zone
     * mismatch without being a strict government-form gazetteer. */
    public static function zones_by_region() {
        return array(
            'Addis Ababa' => array( 'Bole', 'Kirkos', 'Yeka', 'Arada', 'Lideta', 'Kolfe Keranio', 'Nifas Silk-Lafto', 'Gulele', 'Addis Ketema', 'Akaky Kaliti', 'Lemi Kura' ),
            'Oromia' => array( 'East Shewa', 'West Shewa', 'North Shewa (Oromia)', 'South West Shewa', 'Arsi', 'West Arsi', 'Bale', 'Guji', 'West Guji', 'Jimma', 'Illubabor', 'East Wellega', 'West Wellega', 'Horo Guduru Wellega', 'Kelem Wellega', 'East Hararghe', 'West Hararghe', 'Borena', 'Adama', 'Bishoftu', 'Jimma City' ),
            'Amhara' => array( 'North Gondar', 'South Gondar', 'North Wollo', 'South Wollo', 'East Gojjam', 'West Gojjam', 'North Shewa (Amhara)', 'Awi', 'Wag Hemra', 'Oromia Zone (Amhara)', 'Bahir Dar', 'Gondar City', 'Dessie', 'Debre Markos' ),
            'SNNP' => array( 'Gurage', 'Hadiya', 'Kembata Tembaro', 'Wolayita', 'Gamo', 'South Omo', 'Dawro', 'Konta', 'Basketo', 'Amaro', 'Burji', 'Silte', 'Yem' ),
            'Tigray' => array( 'Mekelle', 'Central Tigray', 'Eastern Tigray', 'Southern Tigray', 'Western Tigray', 'North Western Tigray', 'South Eastern Tigray' ),
            'Sidama' => array( 'Hawassa', 'Aleta Wondo', 'Boricha', 'Dale', 'Wondo Genet', 'Bensa', 'Hula', 'Chuko', 'Shebedino', 'Yirgalem' ),
            'South West Ethiopia' => array( 'Bench Sheko', 'Sheka', 'Kaffa', 'West Omo', 'Konta Special Woreda', 'Bonga' ),
            'Central Ethiopia' => array( 'Gurage (Central Ethiopia)', 'Hadiya (Central Ethiopia)', 'Kembata Tembaro (Central Ethiopia)', 'Silte (Central Ethiopia)', 'Halaba', 'Wolkite' ),
            'Afar' => array( 'Zone 1 (Awsi Rasu)', 'Zone 2 (Kilbet Rasu)', 'Zone 3 (Gabi Rasu)', 'Zone 4 (Fantana Rasu)', 'Zone 5 (Hari Rasu)', 'Semera' ),
            'Somali' => array( 'Jijiga', 'Shinile', 'Fafan', 'Doolo', 'Nogob', 'Jarar', 'Korahe', 'Shabelle', 'Afder', 'Liben', 'Erer' ),
            'Benishangul-Gumuz' => array( 'Assosa', 'Metekel', 'Kamashi', 'Mao-Komo Special Woreda' ),
            'Gambela' => array( 'Agnuak', 'Nuer', 'Majang', 'Etang Special Woreda', 'Gambela City' ),
            'Harari' => array( 'Harar' ),
            'Dire Dawa' => array( 'Dire Dawa City' ),
        );
    }
}
