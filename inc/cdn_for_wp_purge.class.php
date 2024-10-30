<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class CDN_FOR_WP_Purge {

    public static function init() {

        new self();
    }

    public function __construct() {

    }

    private static function get_cache_purged_transient_name() {

        $transient_name = 'cdn_for_wp_cache_purged_' . get_current_user_id();

        return $transient_name;
    }

    public static function add_admin_bar_items( $wp_admin_bar ) {

        // check user role
        if ( ! self::user_can_purge_cache() ) {
            return;
        }

        if ( strlen( CDN_FOR_WP_Engine::$settings['cdn4wp_verify_key'] ) < 20 ) {
            return;
        }

        if ( CDN_FOR_WP_Engine::$settings['key_confirm'] == '' ) {
            return;
        }

        // add admin purge link
        if ( ! is_network_admin() ) {
            $wp_admin_bar->add_menu(
                array(
                    'id'     => 'cdn-for-wp-purge-cache',
                    'href'   => wp_nonce_url( add_query_arg( array(
                                    '_cache' => 'cdn',
                                    '_action' => 'purge',
                                ) ), 'cdn_for_wp_purge_cache_nonce' ),
                    'parent' => 'top-secondary',
                    'title'  => '<span class="ab-item">' . esc_html__( 'Purge CDN Cache', 'cdn-for-wp' ) . '</span>',
                    'meta'   => array( 'title' => esc_html__('Purge CDN Cache', 'cdn-for-wp') ),
                )
            );
        }
    }

    private static function user_can_purge_cache() {

        if ( apply_filters( 'cdn_for_wp_user_can_purge_cache', current_user_can( 'manage_options' ) ) ) {
            return true;
        }

        if ( apply_filters_deprecated( 'user_can_clear_cache', array( current_user_can( 'manage_options' ) ), '1.1.0', 'cdn_for_wp_user_can_purge_cache' ) ) {
            return true;
        }

        return false;
    }

    public static function process_purge_cache_request() {

        // check if purge cache request
        if ( empty( $_GET['_cache'] ) || empty( $_GET['_action'] ) || $_GET['_cache'] !== 'cdn' || ( $_GET['_action'] !== 'purge' ) ) {
            return;
        }

        // validate nonce
        if ( empty( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'cdn_for_wp_purge_cache_nonce' ) ) {
            return;
        }

        // check user role
        if ( ! self::user_can_purge_cache() ) {
            return;
        }

        // purge CDN cache
        $response = self::purge_cdn_cache();

        // redirect to same page
        wp_safe_redirect( wp_get_referer() );

        // set transient for purge notice
        if ( is_admin() ) {
            set_transient( self::get_cache_purged_transient_name(), $response );
        }

        // purge cache request completed
        exit;
    }

    public static function cache_purged_notice() {
        // check user role
        if ( ! self::user_can_purge_cache() ) {
            return;
        }

        $response = get_transient( self::get_cache_purged_transient_name() );

        if ( is_array( $response ) ) {
            if ( ! empty( $response['subject'] ) ) {
                printf(
                    $response['wrapper'],
                    $response['subject'],
                    $response['message']
                );
            } else {
                printf(
                    $response['wrapper'],
                    $response['message']
                );
            }

            delete_transient( self::get_cache_purged_transient_name() );
        }
    }

    public static function purge_cdn_cache() {

        $options = CDN_FOR_WP::get_options();

        // purge CDN cache API call
        $response = wp_remote_post( CDN_FOR_WP_API_URL, array(
						'method' => 'POST',
						'timeout' => 20,
						'sslverify'   => false,
						'redirection' => 5,
						'httpversion' => '1.0',
						'blocking' => true,
						'headers' => array(),
						'body' => array( 'sk'=> $options['cdn4wp_verify_key'],
										 'purge' => true),
						'cookies' => array()
					    )
		);

        // check if API call failed
        if ( is_wp_error( $response ) ) {
            $response = array(
                'wrapper' => '<div class="notice notice-error is-dismissible"><p><strong>%1$s</strong> %2$s</p></div>',
                'subject' => esc_html__( 'Purge CDN cache failed:', 'cdn-for-wp' ),
                'message' => $response->get_error_message(),
            );
        // check API call response otherwise
        } else {
            $response_status_code = wp_remote_retrieve_response_code( $response );

            if ( $response_status_code === 200 ) {
                $response = array(
                    'wrapper' => '<div class="notice notice-success is-dismissible"><p><strong>%s</strong></p></div>',
                    'message' => esc_html__( 'CDN cache purged.', 'cdn-for-wp' ),
                );
            } elseif ( $response_status_code >= 400 && $response_status_code <= 499 ) {
                $error_messages = array(
                    401 => esc_html__( 'Invalid API key.', 'cdn-for-wp' ),
                    403 => esc_html__( 'Invalid Zone ID.', 'cdn-for-wp' ),
                    429 => esc_html__( 'API rate limit exceeded.', 'cdn-for-wp' ),
                    451 => esc_html__( 'Too many failed attempts.', 'cdn-for-wp' ),
                );

                if ( array_key_exists( $response_status_code, $error_messages ) ) {
                    $message = $error_messages[ $response_status_code ];
                } else {
                    $message = sprintf(
                        // translators: %s: HTTP status code (e.g. 200)
                        esc_html__( '%s status code returned.', 'cdn-for-wp' ),
                        '<code>' . $response_status_code . '</code>'
                    );
                }

                $response = array(
                    'wrapper' => '<div class="notice notice-error is-dismissible"><p><strong>%s</strong> %s</p></div>',
                    'subject' => esc_html__( 'Purge CDN cache failed:', 'cdn-for-wp' ),
                    'message' => $message,
                );
            } elseif ( $response_status_code >= 500 && $response_status_code <= 599 ) {
                $response = array(
                    'wrapper' => '<div class="notice notice-error is-dismissible"><p><strong>%s</strong> %s</p></div>',
                    'subject' => esc_html__( 'Purge CDN cache failed:', 'cdn-for-wp' ),
                    'message' => esc_html__( 'API temporarily unavailable.', 'cdn-for-wp' ),
                );
            }
        }

        return $response;
    }

}
