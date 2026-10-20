<?php
/**
 * Etel-ID — keep the WordPress REST index and user list private.
 *
 * An outside audit (Oct 2026, finding "Information Disclosure", /wp-json/) showed that anyone without signing in could
 * read the full list of REST routes and plugins, and the public user list (/wp-json/wp/v2/users). That is a map for an
 * attacker, not something a visitor needs. For visitors who are not signed in this:
 *   - empties the /wp-json/ index (no route list, no namespaces, no site details);
 *   - removes the WordPress user list endpoints (names and ids of staff accounts);
 *   - answers /?author=N user-name probing with the home page.
 * Signed-in users and every EtelID route (etelid/v1, called directly by the Delivery, Ride and EtelGroup servers) are not affected.
 *
 * This file stands alone: it can also be copied into wp-content/mu-plugins/ of any other WordPress on the domain.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'Etel_Rest_Hardening' ) ) {
    class Etel_Rest_Hardening {

        public static function init() {
            add_filter( 'rest_index',     array( __CLASS__, 'empty_index' ), 99 );
            add_filter( 'rest_endpoints', array( __CLASS__, 'drop_user_routes' ), 99 );
            add_action( 'template_redirect', array( __CLASS__, 'block_author_probe' ), 1 );
        }

        /* Visitors who are not signed in see an empty index. */
        public static function empty_index( $response ) {
            if ( is_user_logged_in() ) return $response;
            if ( is_object( $response ) && method_exists( $response, 'set_data' ) ) {
                $response->set_data( array(
                    'name' => '', 'description' => '', 'url' => '', 'home' => '', 'gmt_offset' => 0, 'timezone_string' => '',
                    'namespaces' => array(), 'authentication' => array(), 'routes' => array(),
                ) );
            }
            return $response;
        }

        /* Staff names and ids are not public. */
        public static function drop_user_routes( $endpoints ) {
            if ( is_user_logged_in() || ! is_array( $endpoints ) ) return $endpoints;
            foreach ( array_keys( $endpoints ) as $route ) {
                if ( strpos( $route, '/wp/v2/users' ) === 0 ) unset( $endpoints[ $route ] );
            }
            return $endpoints;
        }

        /* /?author=1 redirects to /author/<username>/ and leaks the login name. */
        public static function block_author_probe() {
            if ( is_user_logged_in() || is_admin() ) return;
            if ( isset( $_GET['author'] ) && ! is_array( $_GET['author'] ) && preg_match( '/^\d+$/', (string) $_GET['author'] ) ) {
                wp_safe_redirect( home_url( '/' ), 301 );
                exit;
            }
        }
    }
    Etel_Rest_Hardening::init();
}
