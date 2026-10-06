<?php
/**
 * Etel-ID Premium — Shortcodes
 *
 * @package    Etel-ID Premium
 * @version    2.0.0
 * @author     Etel Group · ኢትኤል ቡድን
 * @link       https://etelgroup.com
 * @since      2.0.0
 *
 * Registers all five public shortcodes:
 *   [etelid_apply]                       — 4-step application form
 *   [etelid_verify]                      — inline lookup widget
 *   [etelid_card id="EG-2025-00001"]     — 3D flippable card display
 *   [etelid_stats]                       — live statistics widget
 *   [etelid_my_id]                       — logged-in user's own card
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class EtelID_Shortcodes {

    public static function init() {
        add_shortcode( 'etelid_apply',      array( __CLASS__, 'sc_apply'      ) );
        add_shortcode( 'etelid_verify',     array( __CLASS__, 'sc_verify'     ) );
        add_shortcode( 'etelid_card',       array( __CLASS__, 'sc_card'       ) );
        add_shortcode( 'etelid_stats',      array( __CLASS__, 'sc_stats'      ) );
        add_shortcode( 'etelid_my_id',      array( __CLASS__, 'sc_my_id'      ) );
        add_shortcode( 'etelid_login_link', array( __CLASS__, 'sc_login_link' ) );
        add_shortcode( 'etelid_auth',       array( __CLASS__, 'sc_auth_box'   ) );
    }

    /* ── [etelid_apply] ──────────────────────────────── */
    public static function sc_apply( $atts ) {
        wp_enqueue_style( 'etelid-fonts', ETELID_URL . 'public/css/etelid-fonts.css', array(), ETELID_VERSION );
        wp_enqueue_style(  'etelid-card',   ETELID_URL . 'public/css/etelid-card.css',   array(), ETELID_VERSION );
        wp_enqueue_style(  'etelid-public', ETELID_URL . 'public/css/etelid-public.css', array('etelid-card'), ETELID_VERSION );
        wp_enqueue_script( 'etelid-front',  ETELID_URL . 'public/js/etelid-front.js',    array(), ETELID_VERSION, true );
        wp_localize_script( 'etelid-front', 'EtelIDConfig', array(
            'ajax'  => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'etelid_public' ),
        ));
        ob_start();
        include ETELID_PATH . 'templates/apply-form.php';
        return ob_get_clean();
    }

    /* ── [etelid_verify] ─────────────────────────────── */
    public static function sc_verify( $atts ) {
        wp_enqueue_style( 'etelid-public', ETELID_URL . 'public/css/etelid-public.css', array(), ETELID_VERSION );
        wp_enqueue_style( 'etelid-card',   ETELID_URL . 'public/css/etelid-card.css',   array(), ETELID_VERSION );
        ob_start();
        include ETELID_PATH . 'templates/verify-form.php';
        return ob_get_clean();
    }

    /* ── [etelid_card id="EG-2025-00001"] ────────────── */
    public static function sc_card( $atts ) {
        $atts   = shortcode_atts( array( 'id' => '', 'flip' => '1', 'print' => '0' ), $atts );
        $etelid = sanitize_text_field( $atts['id'] );
        if ( ! $etelid ) return '<p style="color:#ef4444">No ID specified. Use [etelid_card id="EG-2025-00001"]</p>';

        $member = EtelID_DB::get_by_etel_id( $etelid );
        if ( ! $member ) return '<p style="color:#ef4444">Etel-ID not found: ' . esc_html( $etelid ) . '</p>';

        wp_enqueue_style( 'etelid-fonts', ETELID_URL . 'public/css/etelid-fonts.css', array(), ETELID_VERSION );
        wp_enqueue_style(  'etelid-card',   ETELID_URL . 'public/css/etelid-card.css',   array(), ETELID_VERSION );
        wp_enqueue_script( 'etelid-front',  ETELID_URL . 'public/js/etelid-front.js',    array(), ETELID_VERSION, true );

        $companies     = EtelID_DB::get_companies( $member->id );
        $show_flip_btn = (bool) $atts['flip'];
        $show_print_btn= (bool) $atts['print'];

        ob_start();
        include ETELID_PATH . 'templates/card-view.php';
        return ob_get_clean();
    }

    /* ── [etelid_stats] ──────────────────────────────── */
    public static function sc_stats( $atts ) {
        $atts   = shortcode_atts( array( 'style' => 'cards' ), $atts );
        $total  = EtelID_DB::count_all();
        $counts = EtelID_DB::count_by_status();
        $cos    = count( EtelID_Generator::companies() );

        ob_start(); ?>
        <link rel="stylesheet" href="<?php echo esc_url( ETELID_URL . 'public/css/etelid-icons.css?v=' . (int) @filemtime( ETELID_PATH . 'public/css/etelid-icons.css' ) ); ?>">
        <style>
        .eid-stats-widget{display:flex;flex-wrap:wrap;gap:14px;font-family:'Rajdhani',sans-serif;margin:20px 0}
        .eid-sw-card{background:#13151c;border:1px solid rgba(200,146,42,.2);border-radius:10px;padding:16px 20px;min-width:130px;flex:1;position:relative;overflow:hidden}
        .eid-sw-card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:var(--acc,#c8922a);opacity:.7}
        .eid-sw-lbl{font-family:'JetBrains Mono',monospace;font-size:9px;color:#5a4a30;letter-spacing:2px;text-transform:uppercase;display:block;margin-bottom:6px}
        .eid-sw-num{font-family:'Syne',sans-serif;font-size:28px;font-weight:800;color:var(--acc,#c8922a);line-height:1;display:block}
        </style>
        <div class="eid-stats-widget">
          <div class="eid-sw-card" style="--acc:#c8922a">
            <span class="eid-sw-lbl">Total IDs</span>
            <span class="eid-sw-num"><?php echo number_format( $total ); ?></span>
          </div>
          <div class="eid-sw-card" style="--acc:#22c55e">
            <span class="eid-sw-lbl">Active</span>
            <span class="eid-sw-num" style="color:#22c55e"><?php echo number_format( $counts['active'] ); ?></span>
          </div>
          <div class="eid-sw-card" style="--acc:#f59e0b">
            <span class="eid-sw-lbl">Suspended</span>
            <span class="eid-sw-num" style="color:#f59e0b"><?php echo number_format( $counts['suspended'] ); ?></span>
          </div>
          <div class="eid-sw-card" style="--acc:#3b82f6">
            <span class="eid-sw-lbl">Companies</span>
            <span class="eid-sw-num" style="color:#3b82f6"><?php echo (int) $cos; ?></span>
          </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ── [etelid_my_id] ──────────────────────────────── */
    public static function sc_my_id( $atts ) {
        $member = EtelID_Auth::get_current_member();
        if ( ! $member ) {
            return '<p style="color:#a89060;font-family:Rajdhani,sans-serif;font-size:15px">Please <a href="' . esc_url( home_url('/login/') ) . '" style="color:#e8b84b;font-weight:700">sign in to your Etel-ID account</a> to view your card.</p>';
        }
        wp_enqueue_style( 'etelid-fonts', ETELID_URL . 'public/css/etelid-fonts.css', array(), ETELID_VERSION );
        wp_enqueue_style(  'etelid-card',  ETELID_URL . 'public/css/etelid-card.css', array(), ETELID_VERSION );
        wp_enqueue_script( 'etelid-front', ETELID_URL . 'public/js/etelid-front.js',  array(), ETELID_VERSION, true );
        $companies = EtelID_DB::get_companies( $member->id );
        $show_flip_btn = true; $show_print_btn = true;
        ob_start();
        include ETELID_PATH . 'templates/card-view.php';
        return ob_get_clean();
    }

    /* ── [etelid_login_link] ─────────────────────────── */
    public static function sc_login_link( $atts ) {
        $atts   = shortcode_atts( array( 'login_text' => 'Sign In', 'logout_text' => 'Sign Out', 'portal_text' => 'My Etel-ID' ), $atts );
        $member = EtelID_Auth::get_current_member();
        if ( $member ) {
            return '<a href="' . esc_url( home_url('/portal/') ) . '" style="color:#e8b84b;font-family:Syne,sans-serif;font-weight:800;text-decoration:none">' . esc_html($atts['portal_text']) . ' · ' . esc_html($member->full_name) . '</a>'
                 . ' &nbsp;|&nbsp; <a href="' . esc_url( home_url('/logout/') ) . '" style="color:#a89060;font-family:Syne,sans-serif;font-size:12px;text-decoration:none">' . esc_html($atts['logout_text']) . '</a>';
        }
        return '<a href="' . esc_url( home_url('/login/') ) . '" style="color:#e8b84b;font-family:Syne,sans-serif;font-weight:800;text-decoration:none">' . esc_html($atts['login_text']) . '</a>'
             . ' &nbsp;|&nbsp; <a href="' . esc_url( home_url('/signup/') ) . '" style="color:#a89060;font-family:Syne,sans-serif;font-size:12px;text-decoration:none">Create Account</a>';
    }

    /* ── [etelid_auth] — inline sign-in/up widget ────── */
    public static function sc_auth_box( $atts ) {
        $member = EtelID_Auth::get_current_member();
        $s = 'style="background:#13151c;border:1px solid rgba(200,146,42,.25);border-radius:10px;padding:16px 20px;font-family:Rajdhani,sans-serif;display:flex;align-items:center;gap:14px;flex-wrap:wrap"';
        $sig = '<span style="font-family:Syne,sans-serif;font-size:20px;font-weight:800;color:#c8922a">ኢE</span>';
        if ( $member ) {
            return '<div ' . $s . '>' . $sig
                 . '<div><div style="font-size:14px;font-weight:600;color:#e8e0cc">' . esc_html($member->full_name) . '</div>'
                 . '<div style="font-family:\'JetBrains Mono\',monospace;font-size:11px;color:#5a4a30">' . esc_html($member->etel_id) . '</div></div>'
                 . '<a href="' . esc_url(home_url('/portal/')) . '" style="margin-left:auto;background:linear-gradient(135deg,#c8922a,#7a5518);color:#fff;padding:8px 16px;border-radius:7px;font-family:Syne,sans-serif;font-size:12px;font-weight:800;text-decoration:none">My Portal →</a>'
                 . '<a href="' . esc_url(home_url('/logout/')) . '" style="color:#a89060;font-size:12px;font-family:Syne,sans-serif;font-weight:700;text-decoration:none">Sign Out</a></div>';
        }
        return '<div ' . $s . '>' . $sig
             . '<div><div style="font-size:14px;color:#e8e0cc">Sign in to your Etel-ID account</div>'
             . '<div style="font-size:12px;color:#5a4a30">Access your ID card and companies</div></div>'
             . '<a href="' . esc_url(home_url('/login/')) . '" style="margin-left:auto;background:linear-gradient(135deg,#c8922a,#7a5518);color:#fff;padding:8px 16px;border-radius:7px;font-family:Syne,sans-serif;font-size:12px;font-weight:800;text-decoration:none">Sign In →</a>'
             . '<a href="' . esc_url(home_url('/signup/')) . '" style="color:#a89060;font-size:12px;font-family:Syne,sans-serif;font-weight:700;text-decoration:none">Create Account</a></div>';
    }
}

