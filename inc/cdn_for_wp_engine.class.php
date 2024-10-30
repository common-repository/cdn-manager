<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class CDN_FOR_WP_Engine {

    public static function start() {

        new self();
    }


    public static $settings;

    public function __construct() {

        // get settings from database
        self::$settings = CDN_FOR_WP_Settings::get_settings();
        if ( ! empty( self::$settings ) ) {
            self::start_buffering();
        }
    }


    private static function start_buffering() {

        ob_start( 'self::end_buffering' );
    }

    private static function end_buffering( $contents, $phase ) {

        if ( $phase & PHP_OUTPUT_HANDLER_FINAL || $phase & PHP_OUTPUT_HANDLER_END ) {
            if ( self::bypass_rewrite() ) {
                return $contents;
            }

            $rewritten_contents = self::rewriter( $contents );

            return $rewritten_contents;
        }
    }

    private static function is_excluded( $file_url ) {

        // if string excluded (case sensitive)
        if ( ! empty( self::$settings['excluded_strings'] ) ) {
            $excluded_strings = explode( PHP_EOL, self::$settings['excluded_strings'] );

            foreach ( $excluded_strings as $excluded_string ) {
                if ( strpos( $file_url, $excluded_string ) !== false ) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function is_admin() {

        if ( apply_filters( 'cdn_for_wp_exclude_admin', is_admin() ) ) {
            return true;
        }

        return false;
    }

    private static function bypass_rewrite() {

        // bypass rewrite hook
        if ( apply_filters( 'cdn_for_wp_bypass_rewrite', false ) ) {
            return true;
        }

        // check request method
        if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || $_SERVER['REQUEST_METHOD'] !== 'GET' ) {
            return true;
        }

        // check conditional tags
        if ( self::is_admin() || is_trackback() || is_robots() || is_preview() ) {
            return true;
        }

        return false;
    }

    private static function rewrite_url( $matches ) {

        $file_url       = $matches[0];
        $site_hostname  = ( ! empty( $_SERVER['HTTP_HOST'] ) ) ? $_SERVER['HTTP_HOST'] : parse_url( home_url(), PHP_URL_HOST );
        $site_hostnames = (array) apply_filters( 'cdn_for_wp_site_hostnames', array( $site_hostname ) );

        $cdn_hostname   = str_replace('https://','',self::$settings['endpoint']).self::$settings['cdn4wp_verify_key'];

        // if excluded or already using CDN hostname
        if ( self::is_excluded( $file_url ) || stripos( $file_url, $cdn_hostname ) !== false ) {
            return $file_url;
        }

        // rewrite full URL (e.g. https://www.example.com/wp..., https:\/\/www.example.com\/wp..., or //www.example.com/wp...)
        foreach ( $site_hostnames as $site_hostname ) {
            if ( stripos( $file_url, '//' . $site_hostname ) !== false || stripos( $file_url, '\/\/' . $site_hostname ) !== false ) {
                return substr_replace( $file_url, $cdn_hostname, stripos( $file_url, $site_hostname ), strlen( $site_hostname ) );
            }
        }

        // rewrite relative URLs hook
        if ( apply_filters( 'cdn_for_wp_rewrite_relative_urls', true ) ) {
            // rewrite relative URL (e.g. /wp-content/uploads/example.jpg)
            if ( strpos( $file_url, '//' ) !== 0 && strpos( $file_url, '/' ) === 0 ) {
                return '//' . $cdn_hostname . $file_url;
            }

            // rewrite escaped relative URL (e.g. \/wp-content\/uploads\/example.jpg)
            if ( strpos( $file_url, '\/\/' ) !== 0 && strpos( $file_url, '\/' ) === 0 ) {
                return '\/\/' . $cdn_hostname . $file_url;
            }
        }

        return $file_url;
    }

    public static function rewriter( $contents ) {

        // check rewrite requirements
        if ( ! is_string( $contents ) || empty(self::$settings['cdn4wp_verify_key']) || empty( self::$settings['endpoint'] ) || self::$settings['enabled'] == false || self::$settings['rewrite'] == false || empty( self::$settings['included_file_extensions'] ) ) {
            return $contents;
        }

        $contents = apply_filters( 'cdn_for_wp_contents_before_rewrite', $contents );

        $included_file_extensions_regex = quotemeta( implode( '|', explode( PHP_EOL, self::$settings['included_file_extensions'] ) ) );

        $urls_regex = '#(?:(?:[\"\'\s=>,]|url\()\K|^)[^\"\'\s(=>,]+(' . $included_file_extensions_regex . ')(\?[^\/?\\\"\'\s)>,]+)?(?:(?=\/?[?\\\"\'\s)>,])|$)#i';

        $rewritten_contents = apply_filters( 'cdn_for_wp_contents_after_rewrite', preg_replace_callback( $urls_regex, 'self::rewrite_url', $contents ) );

        return $rewritten_contents;
    }

	public static function remove_scripts() {
	    global $wp_scripts;
	    if (!is_a($wp_scripts, 'WP_Scripts'))
	        return;
	    foreach ($wp_scripts->registered as $handle => $script)
	        $wp_scripts->registered[$handle]->ver = null;
	}

	public static function remove_styles() {
	    global $wp_styles;
	    if (!is_a($wp_styles, 'WP_Styles'))
	        return;
	    foreach ($wp_styles->registered as $handle => $style)
	        $wp_styles->registered[$handle]->ver = null;
	}
}
