<?php
/**
 * Etel-ID Premium — Navigation & URL Manager v1.0
 *
 * Provides:
 *  1. WP Admin notice listing all live URLs (dismissible)
 *  2. Auto-creates "Etel-ID" nav menu with correct links
 *  3. [etelid_button] shortcode — smart button that shows:
 *       • "Sign In" if not logged in  → /login/
 *       • "My Portal" if logged in    → /portal/
 *       • Member name + avatar
 *  4. [etelid_nav] shortcode — full navigation bar
 *  5. [etelid_services] shortcode — services grid (all 8 companies)
 *
 * NO WordPress pages needed. All URLs are built-in rewrite rules.
 *
 * PHP 5.6+ compatible.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class EtelID_Nav {

    /* All system URLs */
    public static function get_urls() {
        return array(
            'apply'   => array( 'url' => home_url('/apply/'),   'label' => 'Apply for Etel-ID',  'icon' => '📋', 'desc' => 'Public registration form' ),
            'login'   => array( 'url' => home_url('/login/'),   'label' => 'Sign In',             'icon' => '🔑', 'desc' => 'Member login' ),
            'signup'  => array( 'url' => home_url('/signup/'),  'label' => 'Create Account',      'icon' => '✨', 'desc' => 'New member signup' ),
            'portal'  => array( 'url' => home_url('/portal/'),  'label' => 'My Portal',           'icon' => '🪪', 'desc' => 'Member dashboard' ),
            'profile' => array( 'url' => home_url('/profile/'), 'label' => 'Edit Profile',        'icon' => '✏️', 'desc' => 'Update demographics' ),
            'logout'  => array( 'url' => home_url('/logout/'),  'label' => 'Sign Out',            'icon' => '🚪', 'desc' => 'Log out' ),
        );
    }

    public static function init() {
        add_action( 'admin_notices',         array( __CLASS__, 'admin_url_notice' ) );
        add_action( 'wp_ajax_etelid_dismiss_notice', array( __CLASS__, 'dismiss_notice' ) );
        add_shortcode( 'etelid_button',   array( __CLASS__, 'sc_button' ) );
        add_shortcode( 'etelid_nav',      array( __CLASS__, 'sc_nav' ) );
        add_shortcode( 'etelid_services', array( __CLASS__, 'sc_services' ) );
        add_action( 'init', array( __CLASS__, 'maybe_create_menu' ), 20 );
    }

    /* ─── Admin notice showing all URLs ─── */
    public static function admin_url_notice() {
        if ( ! current_user_can('manage_options') ) return;
        if ( get_option('etelid_urls_notice_dismissed') ) return;
        $urls = self::get_urls();
        echo '<div class="notice notice-info is-dismissible" id="etelid-urls-notice" style="border-left-color:#D4AF37;padding:16px 20px;">';
        echo '<div style="display:flex;align-items:center;gap:12px;margin-bottom:10px;">';
        echo '<span style="font-size:22px;">🪪</span>';
        echo '<strong style="font-size:14px;color:#1A0E00;">Etel-ID Premium — No pages needed! All URLs are live:</strong>';
        echo '</div>';
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:8px;">';
        foreach ( $urls as $key => $item ) {
            echo '<div style="background:rgba(212,175,55,.08);border:1px solid rgba(212,175,55,.25);border-radius:6px;padding:8px 12px;display:flex;align-items:center;gap:10px;">';
            echo '<span style="font-size:16px;">' . $item['icon'] . '</span>';
            echo '<div>';
            echo '<div style="font-weight:700;font-size:12px;color:#1A0E00;">' . esc_html($item['label']) . '</div>';
            echo '<a href="' . esc_url($item['url']) . '" target="_blank" style="font-family:monospace;font-size:11px;color:#A07020;">' . esc_html($item['url']) . '</a>';
            echo '</div></div>';
        }
        echo '</div>';
        echo '<p style="margin-top:10px;font-size:12px;color:#7A5520;">Use shortcode <code>[etelid_button]</code> anywhere on your site to show a smart Sign In / My Portal button. <a href="' . admin_url('admin.php?page=etel-id-settings') . '">Settings →</a></p>';
        echo '<button type="button" class="notice-dismiss" onclick="jQuery.post(ajaxurl,{action:\'etelid_dismiss_notice\',nonce:\'' . wp_create_nonce('etelid_dismiss') . '\'})"></button>';
        echo '</div>';
    }

    public static function dismiss_notice() {
        check_ajax_referer('etelid_dismiss','nonce');
        update_option('etelid_urls_notice_dismissed', 1);
        wp_send_json_success();
    }

    /* ─── Auto-create nav menu ─── */
    public static function maybe_create_menu() {
        if ( get_option('etelid_menu_created') ) return;
        if ( ! is_admin() ) return;

        $menu_name = 'Etel-ID Menu';
        $menu_id   = wp_create_nav_menu( $menu_name );
        if ( is_wp_error($menu_id) ) return;

        $urls = self::get_urls();
        $order = 1;
        foreach ( array('apply','login','portal') as $key ) {
            wp_update_nav_menu_item( $menu_id, 0, array(
                'menu-item-title'  => $urls[$key]['label'],
                'menu-item-url'    => $urls[$key]['url'],
                'menu-item-status' => 'publish',
                'menu-item-position' => $order++,
            ) );
        }
        update_option('etelid_menu_created', 1);
        update_option('etelid_menu_id', $menu_id);
    }

    /* ─── [etelid_button] ─── */
    public static function sc_button( $atts ) {
        $a = shortcode_atts( array(
            'size'      => 'normal',  /* normal | large | small */
            'style'     => 'gold',    /* gold | outline | minimal */
            'show_name' => 'yes',
        ), $atts );

        $member    = class_exists('EtelID_Auth') ? EtelID_Auth::get_current_member() : null;
        $logged_in = (bool) $member;

        /* Styles */
        $btn_base = 'display:inline-flex;align-items:center;gap:8px;text-decoration:none;border-radius:10px;font-family:inherit;font-weight:700;cursor:pointer;transition:all .2s;border:none;';
        $sizes    = array(
            'small'  => 'padding:7px 14px;font-size:13px;',
            'normal' => 'padding:11px 22px;font-size:15px;',
            'large'  => 'padding:14px 28px;font-size:17px;',
        );
        $styles_map = array(
            'gold'    => 'background:linear-gradient(135deg,#4A3210,#7A5518,#C8922A,#D4AF37);color:#FDF6D8;box-shadow:0 4px 0 #2A1A06,0 6px 16px rgba(80,40,0,.3);',
            'outline' => 'background:transparent;color:#C8922A;border:2px solid #D4AF37;',
            'minimal' => 'background:none;color:#C8922A;padding-left:0;padding-right:0;',
        );
        $size_css  = isset($sizes[$a['size']])      ? $sizes[$a['size']]      : $sizes['normal'];
        $style_css = isset($styles_map[$a['style']]) ? $styles_map[$a['style']] : $styles_map['gold'];
        $btn_css   = $btn_base . $size_css . $style_css;

        ob_start();

        if ( $logged_in ) {
            $parts = explode(' ', trim($member->full_name));
            $init  = '';
            foreach ($parts as $p) { if ($p) $init .= strtoupper(substr($p,0,1)); }
            $init = substr($init,0,2);
            ?>
            <a href="<?php echo esc_url(home_url('/portal/')); ?>" style="<?php echo $btn_css; ?>">
              <span style="width:26px;height:26px;border-radius:50%;background:rgba(255,255,255,.2);display:inline-flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;flex-shrink:0;">
                <?php if ($member->photo_url): ?>
                <img src="<?php echo esc_url($member->photo_url); ?>" style="width:26px;height:26px;border-radius:50%;object-fit:cover;">
                <?php else: ?><?php echo esc_html($init); ?><?php endif; ?>
              </span>
              <?php if ($a['show_name'] === 'yes'): ?>
              <span><?php echo esc_html($member->full_name); ?></span>
              <?php else: ?>
              <span>My Portal 🪪</span>
              <?php endif; ?>
            </a>
            <?php
        } else {
            ?>
            <a href="<?php echo esc_url(home_url('/login/')); ?>" style="<?php echo $btn_css; ?>">
              🔑 <span>Sign In to Etel-ID</span>
            </a>
            <?php
        }

        return ob_get_clean();
    }

    /* ─── [etelid_nav] ─── */
    public static function sc_nav( $atts ) {
        $member = class_exists('EtelID_Auth') ? EtelID_Auth::get_current_member() : null;
        $urls   = self::get_urls();

        ob_start(); ?>
        <nav style="background:linear-gradient(135deg,#2A1A06,#4A3210);padding:12px 20px;border-radius:10px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
          <a href="<?php echo esc_url(home_url('/portal/')); ?>" style="font-family:monospace;font-size:18px;font-weight:800;color:#D4AF37;text-decoration:none;letter-spacing:-1px;">ኢE</a>
          <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <a href="<?php echo esc_url($urls['apply']['url']); ?>" style="color:#F5E6A3;text-decoration:none;font-size:13px;padding:6px 14px;border-radius:6px;border:1px solid rgba(212,175,55,.3);">📋 Apply</a>
            <a href="<?php echo esc_url($urls['portal']['url']); ?>" style="color:#F5E6A3;text-decoration:none;font-size:13px;padding:6px 14px;border-radius:6px;border:1px solid rgba(212,175,55,.3);">🪪 Portal</a>
            <?php if ($member): ?>
            <a href="<?php echo esc_url($urls['profile']['url']); ?>" style="color:#F5E6A3;text-decoration:none;font-size:13px;padding:6px 14px;border-radius:6px;border:1px solid rgba(212,175,55,.3);">✏️ Edit Profile</a>
            <a href="<?php echo esc_url($urls['logout']['url']); ?>"  style="color:#fca5a5;text-decoration:none;font-size:13px;padding:6px 14px;border-radius:6px;border:1px solid rgba(239,68,68,.3);">🚪 Sign Out</a>
            <?php else: ?>
            <a href="<?php echo esc_url($urls['login']['url']); ?>" style="background:linear-gradient(135deg,#C8922A,#D4AF37);color:#1A0E00;text-decoration:none;font-size:13px;font-weight:800;padding:7px 16px;border-radius:6px;">🔑 Sign In</a>
            <?php endif; ?>
          </div>
        </nav>
        <?php
        return ob_get_clean();
    }

    /* ─── [etelid_services] ─── */
    public static function sc_services( $atts ) {
        $a = shortcode_atts( array( 'columns' => '4' ), $atts );
        $companies = class_exists('EtelID_Generator') ? EtelID_Generator::companies() : array();

        $colors = array('#C8922A','#3b82f6','#22c55e','#a855f7','#ef4444','#f59e0b','#06b6d4','#ec4899');
        $icons  = array('🛢','🛒','🚚','🚛','👕','💳','📱','💎');

        ob_start(); ?>
        <div style="display:grid;grid-template-columns:repeat(<?php echo (int)$a['columns']; ?>,1fr);gap:16px;margin:20px 0;">
          <?php $i=0; foreach ($companies as $slug => $co) :
            $c = $colors[$i % count($colors)];
            $ic = $icons[$i % count($icons)];
            $i++;
          ?>
          <div style="background:linear-gradient(135deg,#1A0E00,#2A1A06);border:1px solid rgba(212,175,55,.25);border-radius:12px;padding:20px 16px;text-align:center;transition:transform .2s;" onmouseover="this.style.transform='translateY(-4px)'" onmouseout="this.style.transform=''">
            <div style="width:48px;height:48px;border-radius:12px;background:<?php echo $c; ?>22;border:2px solid <?php echo $c; ?>;display:flex;align-items:center;justify-content:center;font-size:22px;margin:0 auto 10px;"><?php echo $ic; ?></div>
            <div style="font-family:'Syne',sans-serif;font-size:13px;font-weight:800;color:#F5E6A3;margin-bottom:4px;"><?php echo esc_html($co['name']); ?></div>
            <?php if (!empty($co['desc'])): ?>
            <div style="font-size:11px;color:#A07020;"><?php echo esc_html($co['desc']); ?></div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}
