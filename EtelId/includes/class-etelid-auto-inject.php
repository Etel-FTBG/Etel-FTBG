<?php
/**
 * Etel-ID Premium — Auto-Inject v1.0
 *
 * Automatically places all 8 shortcode features on the site
 * WITHOUT requiring manual shortcode insertion. Uses WP hooks:
 *
 *  1. [etelid_button]   → Added to WP nav menus automatically
 *  2. [etelid_nav]      → Injected via wp_body_open (after <body>)
 *  3. [etelid_services] → Injected into homepage/pages content
 *  4. [etelid_apply]    → Auto-page created if none exists
 *  5. [etelid_verify]   → Widget registered + auto-added to sidebar
 *  6. [etelid_stats]    → Admin dashboard widget
 *  7. [etelid_my_id]    → Shown inside portal (already there)
 *  8. [etelid_button]   → Floating button on all pages for guests
 *
 * Admin can toggle each feature ON/OFF from Settings.
 * PHP 5.6 compatible.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class EtelID_Auto_Inject {

    public static function init() {

        /* ── Auto-inject hooks ── */
        add_action( 'wp_body_open',      array( __CLASS__, 'inject_floating_bar' ),   5   );
        add_action( 'wp_footer',         array( __CLASS__, 'inject_floating_button' ), 99  );
        add_action( 'wp_footer',         array( __CLASS__, 'inject_etel_ai_widget' ),  99  );
        add_action( 'wp_enqueue_scripts',array( __CLASS__, 'inject_styles' )               );
        add_filter( 'the_content',       array( __CLASS__, 'inject_into_content' ),   20  );
        add_action( 'widgets_init',      array( __CLASS__, 'register_widgets' )            );
        add_action( 'wp_dashboard_setup',array( __CLASS__, 'add_dashboard_widget' )        );
        add_filter( 'wp_nav_menu_items', array( __CLASS__, 'inject_nav_button' ),     10, 2 );

        /* ── One-time setup on activation ── */
        add_action( 'init', array( __CLASS__, 'maybe_create_pages' ), 20 );
    }

    /* ══════════════════════════════════════════════════════
       1. FLOATING TOP BAR — shown on all non-Etel-ID pages
    ══════════════════════════════════════════════════════ */
    public static function inject_floating_bar() {
        if ( ! get_option('etelid_inject_topbar', 1) ) return;

        /* Don't show on Etel-ID standalone pages */
        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        if ( strpos($uri, '/etel-id/') !== false ) return;
        if ( is_admin() ) return;

        $member   = class_exists('EtelID_Auth') ? EtelID_Auth::get_current_member() : null;
        $logo_src = defined('ETELID_LOGO_B64') ? ETELID_LOGO_B64 : ETELID_URL.'public/images/etel-logo.png';
        $org      = get_option('etelid_org_name','Etel Group');
        ?>
        <div id="etelid-topbar" style="
            position:fixed;top:0;left:0;right:0;z-index:99999;
            background:linear-gradient(135deg,#2A1A06,#4A3210);
            border-bottom:2px solid #D4AF37;
            padding:0 20px;height:46px;
            display:flex;align-items:center;justify-content:space-between;
            box-shadow:0 2px 20px rgba(0,0,0,.4);
            font-family:Arial,sans-serif;
            transition:transform .3s;">
            <!-- Brand -->
            <a href="<?php echo esc_url(home_url('/apply/')); ?>"
               style="display:flex;align-items:center;gap:10px;text-decoration:none;">
                <img src="<?php echo esc_attr($logo_src); ?>"
                     style="height:28px;width:auto;object-fit:contain;filter:brightness(1.2);" alt="">
                <span style="font-size:13px;font-weight:800;color:#F5E6A3;letter-spacing:.3px;">
                    <?php echo esc_html($org); ?>
                </span>
            </a>
            <!-- Right side -->
            <div style="display:flex;align-items:center;gap:8px;">
                <?php if ( $member ) : ?>
                <a href="<?php echo esc_url(home_url('/portal/')); ?>"
                   style="display:inline-flex;align-items:center;gap:7px;padding:6px 14px;background:rgba(212,175,55,.15);border:1px solid rgba(212,175,55,.4);border-radius:20px;text-decoration:none;color:#F5E6A3;font-size:12px;font-weight:700;">
                    🪪 <?php echo esc_html($member->full_name); ?>
                </a>
                <a href="<?php echo esc_url(home_url('/logout/')); ?>"
                   style="padding:6px 12px;color:#fca5a5;font-size:11px;text-decoration:none;border:1px solid rgba(239,68,68,.3);border-radius:6px;">
                    Sign Out
                </a>
                <?php else : ?>
                <a href="<?php echo esc_url(home_url('/apply/')); ?>"
                   style="padding:6px 14px;color:#D4AF37;font-size:11px;font-weight:700;text-decoration:none;border:1px solid rgba(212,175,55,.3);border-radius:6px;">
                    Apply for ID
                </a>
                <a href="<?php echo esc_url(home_url('/login/')); ?>"
                   style="padding:6px 16px;background:linear-gradient(135deg,#C8922A,#D4AF37);color:#1A0E00;font-size:12px;font-weight:800;text-decoration:none;border-radius:6px;box-shadow:0 2px 8px rgba(100,60,0,.3);">
                    🔑 Sign In
                </a>
                <?php endif; ?>
                <!-- Dismiss button -->
                <button onclick="etelIDDismissBar()" style="background:none;border:none;color:rgba(212,175,55,.4);font-size:18px;cursor:pointer;padding:0;line-height:1;">×</button>
            </div>
        </div>
        <!-- Push page content down by topbar height -->
        <div id="etelid-topbar-spacer" style="height:46px;"></div>
        <script>
        function etelIDDismissBar() {
            document.getElementById('etelid-topbar').style.transform = 'translateY(-100%)';
            document.getElementById('etelid-topbar-spacer').style.height = '0';
            sessionStorage.setItem('etelid_bar_dismissed','1');
        }
        if (sessionStorage.getItem('etelid_bar_dismissed') === '1') {
            document.getElementById('etelid-topbar').style.transform = 'translateY(-100%)';
            document.getElementById('etelid-topbar-spacer').style.height = '0';
        }
        </script>
        <?php
    }

    /* ══════════════════════════════════════════════════════
       2. FLOATING ACTION BUTTON — bottom right corner
          Shows "Sign In" or "My Portal" on every page
    ══════════════════════════════════════════════════════ */
    public static function inject_floating_button() {
        if ( ! get_option('etelid_inject_fab', 1) ) return;

        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        if ( strpos($uri, '/etel-id/') !== false ) return;
        if ( is_admin() ) return;

        $member = class_exists('EtelID_Auth') ? EtelID_Auth::get_current_member() : null;

        if ( $member ) {
            $href  = home_url('/portal/');
            $label = '🪪 My Portal';
            $color = 'linear-gradient(135deg,#4A3210,#7A5518,#C8922A,#D4AF37)';
        } else {
            $href  = home_url('/login/');
            $label = '🔑 Sign In';
            $color = 'linear-gradient(135deg,#1A0E00,#4A3210,#C8922A,#D4AF37)';
        }
        ?>
        <a id="etelid-fab" href="<?php echo esc_url($href); ?>"
           style="position:fixed;bottom:28px;right:24px;z-index:99998;
                  display:inline-flex;align-items:center;gap:8px;
                  padding:13px 22px;
                  background:<?php echo $color; ?>;
                  color:#FDF6D8;text-decoration:none;
                  border-radius:30px;
                  font-family:Arial,sans-serif;font-size:14px;font-weight:800;
                  box-shadow:0 6px 0 #2A1A06,0 10px 28px rgba(80,40,0,.4);
                  transition:transform .15s,box-shadow .15s;
                  letter-spacing:.3px;"
           onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 8px 0 #2A1A06,0 14px 32px rgba(80,40,0,.45)';"
           onmouseout="this.style.transform='';this.style.boxShadow='0 6px 0 #2A1A06,0 10px 28px rgba(80,40,0,.4)';">
            <?php echo $label; ?>
        </a>
        <?php
    }

    /* ══════════════════════════════════════════════════════
       2b. ETEL AI — cross-company assistant widget (all pages)
    ══════════════════════════════════════════════════════ */
    public static function inject_etel_ai_widget() {
        if ( is_admin() ) return;
        echo '<script src="https://ethelmarket.com/EtelAi/etel-ai-widget.js" data-project="etel-id"></script>';
    }

    /* ══════════════════════════════════════════════════════
       3. INJECT INTO PAGE CONTENT
          - Services grid on homepage
          - Verify widget on verify-type pages
          - Stats on pages with "stats" in slug
    ══════════════════════════════════════════════════════ */
    public static function inject_into_content( $content ) {
        if ( is_admin() || ! is_singular() ) return $content;

        global $post;
        if ( ! $post ) return $content;

        $slug = strtolower( $post->post_name );

        /* Homepage or Services page — append services grid */
        $service_pages = array('services','our-services','etel-services','home','homepage');
        if ( is_front_page() || is_home() || in_array($slug, $service_pages) ) {
            if ( get_option('etelid_inject_services', 1) ) {
                $content .= self::render_services_section();
            }
        }

        /* Verify/lookup pages — append verify widget */
        $verify_pages = array('verify','verify-id','id-verify','lookup','check-id');
        if ( in_array($slug, $verify_pages) ) {
            $content .= self::render_verify_section();
        }

        /* Stats pages */
        $stats_pages = array('stats','statistics','dashboard','reports');
        if ( in_array($slug, $stats_pages) ) {
            $content .= self::render_stats_section();
        }

        return $content;
    }

    /* ══════════════════════════════════════════════════════
       4. INJECT SIGN IN BUTTON INTO NAV MENUS
    ══════════════════════════════════════════════════════ */
    public static function inject_nav_button( $items, $args ) {
        if ( ! get_option('etelid_inject_nav_btn', 1) ) return $items;
        if ( is_admin() ) return $items;

        /* Only inject once, into the primary menu */
        $target_menus = array('primary','main','main-menu','header','header-menu','top-menu');
        $menu_location = isset($args->theme_location) ? $args->theme_location : '';
        if ( ! in_array(strtolower($menu_location), $target_menus) ) return $items;

        $member = class_exists('EtelID_Auth') ? EtelID_Auth::get_current_member() : null;

        if ( $member ) {
            $parts = explode(' ', trim($member->full_name));
            $init  = '';
            foreach ($parts as $p) { if ($p) $init .= strtoupper(substr($p,0,1)); }
            $init  = substr($init,0,2);
            $btn   = '<li class="menu-item etelid-nav-item" style="display:flex;align-items:center;">'
                   . '<a href="' . esc_url(home_url('/portal/')) . '" '
                   . 'style="display:inline-flex;align-items:center;gap:7px;padding:7px 16px;background:linear-gradient(135deg,#C8922A,#D4AF37);color:#1A0E00;border-radius:20px;font-weight:800;font-size:13px;text-decoration:none;">'
                   . '<span style="width:22px;height:22px;border-radius:50%;background:rgba(0,0,0,.2);display:inline-flex;align-items:center;justify-content:center;font-size:10px;font-weight:800;">' . esc_html($init) . '</span>'
                   . esc_html($member->full_name)
                   . '</a></li>';
        } else {
            $btn = '<li class="menu-item etelid-nav-item" style="display:flex;align-items:center;">'
                 . '<a href="' . esc_url(home_url('/login/')) . '" '
                 . 'style="display:inline-flex;align-items:center;gap:6px;padding:7px 18px;background:linear-gradient(135deg,#4A3210,#C8922A,#D4AF37);color:#FDF6D8;border-radius:20px;font-weight:800;font-size:13px;text-decoration:none;box-shadow:0 2px 8px rgba(80,40,0,.3);">'
                 . '🔑 Sign In'
                 . '</a></li>';
        }

        return $items . $btn;
    }

    /* ══════════════════════════════════════════════════════
       5. REGISTER SIDEBAR WIDGET
    ══════════════════════════════════════════════════════ */
    public static function register_widgets() {
        register_widget( 'EtelID_Widget' );
    }

    /* ══════════════════════════════════════════════════════
       6. WP DASHBOARD WIDGET (admin stats)
    ══════════════════════════════════════════════════════ */
    public static function add_dashboard_widget() {
        wp_add_dashboard_widget(
            'etelid_stats_widget',
            '🪪 Etel-ID — Member Statistics',
            array( __CLASS__, 'render_dashboard_widget' )
        );
    }

    public static function render_dashboard_widget() {
        global $wpdb;
        $t       = $wpdb->prefix . 'etelid_members';
        $total   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t" );
        $active  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE status='active'" );
        $today   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE DATE(created_at)=CURDATE()" );
        $pending = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE status='pending'" );

        echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin:8px 0;">';
        $stats = array(
            array('Total Members', $total,   '#D4AF37'),
            array('Active',        $active,  '#22c55e'),
            array('Issued Today',  $today,   '#3b82f6'),
            array('Pending',       $pending, '#f59e0b'),
        );
        foreach ($stats as $s) {
            echo '<div style="background:linear-gradient(135deg,#2A1A06,#4A3210);border:1px solid rgba(212,175,55,.25);border-radius:8px;padding:14px;text-align:center;">'
               . '<div style="font-size:28px;font-weight:800;color:' . $s[2] . ';line-height:1;">' . number_format($s[1]) . '</div>'
               . '<div style="font-size:11px;color:rgba(212,175,55,.7);margin-top:4px;font-family:monospace;letter-spacing:1px;text-transform:uppercase;">' . esc_html($s[0]) . '</div>'
               . '</div>';
        }
        echo '</div>';
        echo '<p style="text-align:center;margin-top:8px;">'
           . '<a href="' . esc_url(admin_url('admin.php?page=etel-id')) . '" '
           . 'style="display:inline-flex;align-items:center;gap:6px;padding:8px 18px;background:linear-gradient(135deg,#4A3210,#D4AF37);color:#FDF6D8;text-decoration:none;border-radius:6px;font-size:12px;font-weight:800;">'
           . '🪪 Manage Members →'
           . '</a></p>';
    }

    /* ══════════════════════════════════════════════════════
       7. AUTO-CREATE PAGES on first activation
    ══════════════════════════════════════════════════════ */
    public static function maybe_create_pages() {
        if ( get_option('etelid_pages_created') ) return;
        if ( ! is_admin() ) return;
        if ( ! current_user_can('manage_options') ) return;

        $pages = array(
            array(
                'title'   => 'Apply for Etel-ID',
                'slug'    => 'apply-for-etel-id',
                'content' => '<p>Fill in the form below to get your official Etel-ID.</p>',
                'status'  => 'publish',
            ),
            array(
                'title'   => 'Etel-ID Sign In',
                'slug'    => 'etel-id-signin',
                'content' => '',
                'status'  => 'publish',
            ),
            array(
                'title'   => 'Our Services',
                'slug'    => 'our-services',
                'content' => '<p>Etel Group provides 8 premium services across Ethiopia.</p>',
                'status'  => 'publish',
            ),
            array(
                'title'   => 'Verify Etel-ID',
                'slug'    => 'verify-id',
                'content' => '<p>Enter an Etel-ID number below to verify its authenticity.</p>',
                'status'  => 'publish',
            ),
        );

        foreach ( $pages as $page ) {
            $existing = get_page_by_path( $page['slug'] );
            if ( ! $existing ) {
                wp_insert_post( array(
                    'post_title'   => $page['title'],
                    'post_name'    => $page['slug'],
                    'post_content' => $page['content'],
                    'post_status'  => $page['status'],
                    'post_type'    => 'page',
                ) );
            }
        }

        update_option('etelid_pages_created', 1);
    }

    /* ══════════════════════════════════════════════════════
       8. INJECT STYLES
    ══════════════════════════════════════════════════════ */
    public static function inject_styles() {
        if ( is_admin() ) return;
        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        if ( strpos($uri, '/etel-id/') !== false ) return;

        echo '<style>
        /* Push WP theme content below Etel-ID topbar */
        body.etelid-topbar-active {
            margin-top: 46px !important;
            padding-top: 0 !important;
        }
        #etelid-topbar { transition: transform .3s ease; }
        @media(max-width:600px){
            #etelid-topbar .etelid-hide-mobile { display:none !important; }
            #etelid-fab { bottom:16px; right:14px; padding:11px 18px; font-size:12px; }
        }
        </style>';
    }

    /* ══════════════════════════════════════════════════════
       RENDER HELPERS
    ══════════════════════════════════════════════════════ */
    private static function render_services_section() {
        if ( ! class_exists('EtelID_Generator') ) return '';
        $companies = EtelID_Generator::companies();
        if ( ! $companies ) return '';

        $colors = array('#C8922A','#3b82f6','#22c55e','#a855f7','#ef4444','#f59e0b','#06b6d4','#ec4899');
        $icons  = array('🛢','🛒','🚚','🚛','👕','💳','📱','💎');

        $html  = '<div class="etelid-services-section" style="margin:40px 0;padding:32px 0;border-top:2px solid rgba(212,175,55,.2);">';
        $html .= '<h2 style="font-family:\'Syne\',Arial,sans-serif;font-size:24px;font-weight:800;color:#1A0E00;text-align:center;margin-bottom:8px;">Our 8 Companies</h2>';
        $html .= '<p style="text-align:center;color:#7A5520;font-size:14px;margin-bottom:28px;">ኢትኤል ቡድን · Etel Group Subsidiaries</p>';
        $html .= '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:16px;max-width:900px;margin:0 auto;">';

        $i = 0;
        foreach ( $companies as $slug => $co ) {
            $c = $colors[$i % count($colors)];
            $ic = $icons[$i % count($icons)];
            $i++;
            $html .= '<div style="background:linear-gradient(135deg,#1A0E00,#2A1A06);border:1px solid rgba(212,175,55,.25);border-radius:12px;padding:20px 16px;text-align:center;cursor:default;transition:transform .2s;" onmouseover="this.style.transform=\'translateY(-4px)\'" onmouseout="this.style.transform=\'\'">';
            $html .= '<div style="width:50px;height:50px;border-radius:12px;background:' . $c . '22;border:2px solid ' . $c . ';display:flex;align-items:center;justify-content:center;font-size:22px;margin:0 auto 10px;">' . $ic . '</div>';
            $html .= '<div style="font-weight:800;font-size:13px;color:#F5E6A3;margin-bottom:4px;">' . esc_html($co['name']) . '</div>';
            if ( !empty($co['desc']) ) {
                $html .= '<div style="font-size:11px;color:#A07020;">' . esc_html($co['desc']) . '</div>';
            }
            $html .= '</div>';
        }

        $html .= '</div>';
        $html .= '<div style="text-align:center;margin-top:24px;">';
        $html .= '<a href="' . esc_url(home_url('/apply/')) . '" style="display:inline-flex;align-items:center;gap:8px;padding:13px 28px;background:linear-gradient(135deg,#2A1A06,#7A5518,#C8922A,#D4AF37);color:#FDF6D8;border-radius:10px;text-decoration:none;font-weight:800;font-size:15px;box-shadow:0 4px 0 #2A1A06;">📋 Apply for Your Etel-ID</a>';
        $html .= '</div>';
        $html .= '</div>';
        return $html;
    }

    private static function render_verify_section() {
        return '<div class="etelid-verify-section" style="margin:32px 0;padding:24px;background:rgba(212,175,55,.06);border:1.5px solid rgba(212,175,55,.2);border-radius:12px;">'
             . '<h3 style="font-weight:800;margin-bottom:12px;color:#1A0E00;">🔍 Verify an Etel-ID</h3>'
             . '<div style="display:flex;gap:10px;flex-wrap:wrap;">'
             . '<input id="etelid-verify-input" type="text" placeholder="Enter Etel-ID e.g. EG-2026-00001" style="flex:1;min-width:200px;padding:11px 14px;border:1.5px solid rgba(212,175,55,.3);border-radius:8px;font-size:14px;">'
             . '<button onclick="etelIDVerify()" style="padding:11px 22px;background:linear-gradient(135deg,#4A3210,#D4AF37);color:#FDF6D8;border:none;border-radius:8px;font-weight:800;cursor:pointer;font-size:14px;">Verify →</button>'
             . '</div>'
             . '<div id="etelid-verify-result" style="margin-top:12px;"></div>'
             . '</div>'
             . '<script>function etelIDVerify(){var id=document.getElementById("etelid-verify-input").value.trim();if(id)window.location.href="' . home_url('/verify/') . '"+id;}</script>';
    }

    private static function render_stats_section() {
        global $wpdb;
        $t      = $wpdb->prefix . 'etelid_members';
        $total  = (int) $wpdb->get_var("SELECT COUNT(*) FROM $t");
        $active = (int) $wpdb->get_var("SELECT COUNT(*) FROM $t WHERE status='active'");
        return '<div class="etelid-stats-section" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:16px;margin:24px 0;">'
             . '<div style="background:linear-gradient(135deg,#2A1A06,#4A3210);border:1px solid rgba(212,175,55,.25);border-radius:10px;padding:20px;text-align:center;">'
             . '<div style="font-size:36px;font-weight:800;color:#D4AF37;">' . number_format($total) . '</div>'
             . '<div style="font-size:11px;color:rgba(212,175,55,.6);margin-top:4px;text-transform:uppercase;letter-spacing:1px;">Total IDs</div>'
             . '</div>'
             . '<div style="background:linear-gradient(135deg,#052010,#0a3018);border:1px solid rgba(34,197,94,.25);border-radius:10px;padding:20px;text-align:center;">'
             . '<div style="font-size:36px;font-weight:800;color:#22c55e;">' . number_format($active) . '</div>'
             . '<div style="font-size:11px;color:rgba(34,197,94,.6);margin-top:4px;text-transform:uppercase;letter-spacing:1px;">Active</div>'
             . '</div></div>';
    }
}

/* ══════════════════════════════════════════════════════
   SIDEBAR WIDGET CLASS
══════════════════════════════════════════════════════ */
class EtelID_Widget extends WP_Widget {

    public function __construct() {
        parent::__construct(
            'etelid_id_widget',
            '🪪 Etel-ID Sign In Button',
            array( 'description' => 'Shows a Sign In / My Portal button for Etel-ID members.' )
        );
    }

    public function widget( $args, $instance ) {
        $title  = ! empty($instance['title']) ? $instance['title'] : '';
        $style  = ! empty($instance['style']) ? $instance['style'] : 'gold';
        $member = class_exists('EtelID_Auth') ? EtelID_Auth::get_current_member() : null;

        echo $args['before_widget'];
        if ( $title ) echo $args['before_title'] . esc_html($title) . $args['after_title'];

        if ( $member ) {
            echo '<div style="text-align:center;padding:12px 0;">';
            echo '<a href="' . esc_url(home_url('/portal/')) . '" style="display:inline-flex;align-items:center;gap:8px;padding:11px 22px;background:linear-gradient(135deg,#4A3210,#C8922A,#D4AF37);color:#FDF6D8;border-radius:10px;font-weight:800;font-size:14px;text-decoration:none;">🪪 My Portal — ' . esc_html($member->full_name) . '</a>';
            echo '<p style="margin-top:8px;font-size:11px;color:#7A5520;">' . esc_html($member->etel_id) . '</p>';
            echo '</div>';
        } else {
            echo '<div style="text-align:center;padding:12px 0;display:flex;flex-direction:column;gap:8px;">';
            echo '<a href="' . esc_url(home_url('/login/')) . '" style="display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:11px 22px;background:linear-gradient(135deg,#2A1A06,#7A5518,#C8922A,#D4AF37);color:#FDF6D8;border-radius:10px;font-weight:800;font-size:14px;text-decoration:none;">🔑 Sign In to Etel-ID</a>';
            echo '<a href="' . esc_url(home_url('/apply/')) . '" style="display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:8px 16px;background:rgba(212,175,55,.08);color:#C8922A;border:1px solid rgba(212,175,55,.3);border-radius:8px;font-size:12px;font-weight:700;text-decoration:none;">📋 Apply for Free Etel-ID</a>';
            echo '</div>';
        }

        echo $args['after_widget'];
    }

    public function form( $instance ) {
        $title = isset($instance['title']) ? $instance['title'] : 'Etel-ID';
        echo '<p><label>Title: <input class="widefat" name="' . $this->get_field_name('title') . '" type="text" value="' . esc_attr($title) . '"></label></p>';
    }

    public function update( $new_instance, $old_instance ) {
        $instance          = array();
        $instance['title'] = sanitize_text_field( $new_instance['title'] );
        return $instance;
    }
}
