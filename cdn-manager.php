<?php
/**
 * Plugin Name: CDN Manager
 * Plugin URI: https://cdnforwp.com
 * Description: Simply integrate a Content Delivery Network (CDN) into your WordPress sites. Host and optimize image, CSS, and JavaScript files with Statically CDN.
 * Version: 1.1.1
 * Author: iRemoteWP
 * Author URI: https://cdnforwp.com/#contact
 * License: GPL2+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: cdn-for-wp

 */

/*
   Copyright (C)  2021 CDN Manager

   This program is free software; you can redistribute it and/or modify
   it under the terms of the GNU General Public License as published by
   the Free Software Foundation; either version 2 of the License, or
   (at your option) any later version.

   This program is distributed in the hope that it will be useful,
   but WITHOUT ANY WARRANTY; without even the implied warranty of
   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
   GNU General Public License for more details.

   You should have received a copy of the GNU General Public License along
   with this program; if not, write to the Free Software Foundation, Inc.,
   51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// constants
define( 'CDN_FOR_WP_VERSION', '1.1.1');
define( 'CDN_FOR_WP_MIN_PHP', '5.6' );
define( 'CDN_FOR_WP_MIN_WP', '5.1' );
define( 'CDN_FOR_WP_FILE', __FILE__ );
define( 'CDN_FOR_WP_BASE', plugin_basename( __FILE__ ) );
define( 'CDN_FOR_WP_DIR', __DIR__ );
define( 'CDN_FOR_WP_PLUGIN_SLUG', 'cdn_for_wp' );
define( 'CDN_FOR_WP_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'CDN_FOR_WP_URL', 'https://cdnforwp.com/' );
define( 'CDN_FOR_WP_API_URL', 'https://api.cdnforwp.com/' );
define('CDN_FOR_WP_GLOBAL_EXCLUDES', '.php,.zip,LICENSE,wp-admin,cgi-bin,.DS_Store,.github,.git' );

// hooks
add_action( 'plugins_loaded', array( 'CDN_FOR_WP', 'init' ) );
register_activation_hook( __FILE__, array( 'CDN_FOR_WP', 'on_activation' ) );
register_uninstall_hook( __FILE__, array( 'CDN_FOR_WP', 'on_uninstall' ) );

// register autoload
spl_autoload_register( 'cdn_manager_autoload' );

// load required classes
function cdn_manager_autoload( $class_name ) {
    if ( in_array( $class_name, array( 'CDN_FOR_WP', 'CDN_FOR_WP_Engine', 'CDN_FOR_WP_Settings', 'CDN_FOR_WP_Upload', 'CDN_FOR_WP_Purge', 'CDN_FOR_WP_HM_Backup', 'CDN_FOR_WP_Backups' ) ) ) {
        require_once sprintf(
            '%s/inc/%s.class.php',
            CDN_FOR_WP_DIR,
            strtolower( $class_name )
        );
    }
}