<?php
/**
 * Enqueue: registers jQuery + Font Awesome and inlines the MADEYA JS config
 * only on pages that use one of our shortcodes.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Logo URL — available to all shortcode views
define( 'MADEYA_LOGO_URL', MADEYA_PLUGIN_URL . 'assets/images/etel-madeya-logo-sm.webp' );

add_action( 'wp_enqueue_scripts', 'madeya_enqueue_assets' );

function madeya_enqueue_assets() {
    global $post;
    if ( ! is_a( $post, 'WP_Post' ) ) return;

    $has_shortcode = has_shortcode( $post->post_content, 'madeya_controller' )
                  || has_shortcode( $post->post_content, 'madeya_driver' )
                  || has_shortcode( $post->post_content, 'madeya_admin' );

    if ( ! $has_shortcode ) return;

    wp_enqueue_script( 'jquery' );
    wp_enqueue_style(
        'madeya-fa',
        'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css',
        array(),
        '6.5.0'
    );

    $cfg         = madeya_get_settings();
    $ctrl_vtype  = $_SESSION['madeya_ctrl_vtype']  ?? '';
    $ctrl_parity = $_SESSION['madeya_ctrl_parity'] ?? 'both';

    if ( $ctrl_vtype )  $cfg['allowed_types']  = array( $ctrl_vtype );
    if ( $ctrl_parity ) $cfg['allowed_parity'] = $ctrl_parity;

    wp_add_inline_script(
        'jquery',
        'var MADEYA=' . wp_json_encode( array(
            'ajaxurl'        => admin_url( 'admin-ajax.php' ),
            'nonce'          => wp_create_nonce( 'madeya_nonce' ),
            'allowed_parity' => $cfg['allowed_parity'],
            'allowed_types'  => $cfg['allowed_types'],
            'type_labels'    => $cfg['type_labels'],
            'station_name'   => $cfg['station_name'],
            'logo_url'       => MADEYA_LOGO_URL,
        ) ) . ';',
        'before'
    );
}
