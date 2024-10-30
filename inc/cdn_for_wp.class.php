<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class CDN_FOR_WP {

    public static function init() {

        new self();
    }

    public function __construct() {

        // engine hook
        add_action( 'setup_theme', array( 'CDN_FOR_WP_Engine', 'start' ) );

        // init hooks
        add_action( 'init', array( 'CDN_FOR_WP_Purge', 'process_purge_cache_request' ) );
        add_action( 'init', array( 'CDN_FOR_WP_Upload', 'uploading_request' ) );
        add_action( 'init', array( 'CDN_FOR_WP_Backups', 'backup_request' ) );
        add_action( 'init', array( __CLASS__, 'register_textdomain' ) );

        // multisite hook
        add_action( 'wp_initialize_site', array( __CLASS__, 'install_later' ) );

        // admin bar hook
        add_action( 'admin_bar_menu', array( 'CDN_FOR_WP_Purge', 'add_admin_bar_items' ), 90 );

		add_action( 'wp_ajax_do_backup', array( __CLASS__, 'backup_callback' ) );
		add_action( 'wp_ajax_do_upload', array( 'CDN_FOR_WP_Upload', 'upload_callback' ) );
		add_action( 'wp_ajax_check_sync', array( __CLASS__, 'sync_callback' ) );

		add_action('wp_print_scripts', array( 'CDN_FOR_WP_Engine', 'remove_scripts' ), 999);
		add_action('wp_print_footer_scripts', array( 'CDN_FOR_WP_Engine', 'remove_scripts' ), 999);

		add_action('admin_print_styles', array( 'CDN_FOR_WP_Engine', 'remove_styles' ), 999);
		add_action('wp_print_styles', array( 'CDN_FOR_WP_Engine', 'remove_styles' ), 999);


        // admin interface hooks
        if ( is_admin() ) {
            // settings
            add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
            add_action( 'admin_init', array( 'CDN_FOR_WP_Upload', 'start_upload' ) );
            add_action( 'admin_menu', array( 'CDN_FOR_WP_Settings', 'add_settings_page' ) );
            add_action( 'admin_enqueue_scripts', array( __CLASS__, 'add_admin_resources' ) );
            // dashboard
            add_filter( 'plugin_action_links_' . CDN_FOR_WP_BASE, array( __CLASS__, 'add_plugin_action_links' ) );
            add_filter( 'plugin_row_meta', array( __CLASS__, 'add_plugin_row_meta' ), 10, 2 );
            // notices
            add_action( 'admin_notices', array( __CLASS__, 'requirements_check' ) );
            add_action( 'admin_notices', array( 'CDN_FOR_WP_Purge', 'cache_purged_notice' ) );
            add_action( 'admin_notices', array( 'CDN_FOR_WP_Upload', 'cache_synced_notice' ) );
            add_action( 'admin_notices', array( 'CDN_FOR_WP_Settings', 'config_validated_notice' ) );
            add_action( 'admin_notices', array( 'CDN_FOR_WP_Upload', 'config_uploading_notice' ) );



	        $options = self::get_options();

			if(isset($options['endpoint']) && !empty($options['endpoint']) && !empty($options['key_confirm']) && $options['enabled']  == true){
			    add_action('add_attachment', array( 'CDN_FOR_WP_Upload', 'action_add_attachment' ), 10, 1);
			    add_action('delete_attachment', array( 'CDN_FOR_WP_Upload', 'action_delete_attachment' ), 10, 1);
			    add_filter('wp_generate_attachment_metadata', array( 'CDN_FOR_WP_Upload', 'filter_wp_generate_attachment_metadata' ), 20, 1);
		    }

	    }
    }

    public static function on_activation( $network_wide ) {
        global $wp_version;

        // add backend requirements
        self::each_site( $network_wide, 'self::update_backend' );

        $act_info = array( 'php_ver'=> phpversion(),
										 'wp_ver'=> $wp_version,
										 'sitename'=> get_option('blogname'),
										 'email'=> get_option('admin_email'),
										 'site_url'=> get_site_url(),
										 'home_url'	=> get_home_url(),
										 'admin_url'	=> get_admin_url(),
										 'plugin_ver'	=> CDN_FOR_WP_VERSION,
										 'site_size' => self::_humanreadablesize(self::_scan(ABSPATH, ABSPATH))
										 );

        $response = wp_remote_post( CDN_FOR_WP_API_URL, array(
						'method' => 'POST',
						'timeout' => 20,
						'sslverify'   => false,
						'redirection' => 5,
						'httpversion' => '1.0',
						'blocking' => true,
						'headers' => array(),
						'body' => array( 'info'=> maybe_serialize($act_info),
										 'activation' => true),
						'cookies' => array()
					    )
					);

    }

    public static function on_uninstall() {

        // uninstall backend requirements
        self::each_site( is_multisite(), 'self::uninstall_backend' );
    }

    public static function install_later( $new_site ) {

        // check if network activated
        if ( ! is_plugin_active_for_network( CDN_FOR_WP_BASE ) ) {
            return;
        }

        // switch to new site
        switch_to_blog( (int) $new_site->blog_id );

        // add backend requirements
        self::update_backend();

        // restore current blog from before new site
        restore_current_blog();
    }

    public static function update_backend() {

        // get defined settings, fall back to empty array if not found
        $old_option_value = get_option( 'cdn_for_wp', array() );

        // merge defined settings into default settings
        $new_option_value = wp_parse_args( $old_option_value, CDN_FOR_WP_Settings::get_default_settings() );

        // validate settings
        $new_option_value = CDN_FOR_WP_Settings::validate_settings( $new_option_value );

        // add or update database option
        update_option( 'cdn_for_wp', $new_option_value );

        return $new_option_value;
    }


    private static function uninstall_backend() {

        // delete database option
    	$options = self::get_options();

    	if($options['del_settings'] == true)
        delete_option( 'cdn_for_wp' );
    }

    private static function each_site( $network, $callback, $callback_params = array() ) {

        $callback_return = array();

        if ( $network ) {
            $blog_ids = self::get_blog_ids();
            // switch to each site in network
            foreach ( $blog_ids as $blog_id ) {
                switch_to_blog( $blog_id );
                $callback_return[] = (int) call_user_func_array( $callback, $callback_params );
                restore_current_blog();
            }
        } else {
            $callback_return[] = (int) call_user_func_array( $callback, $callback_params );
        }

        return $callback_return;
    }

    public static function get_options() {

        return CDN_FOR_WP_Settings::get_settings();
    }

    private static function get_blog_ids() {

        $blog_ids = array( '1' );

        if ( is_multisite() ) {
            global $wpdb;
            $blog_ids = $wpdb->get_col( "SELECT blog_id FROM $wpdb->blogs" );
        }

        return $blog_ids;
    }

    public static function add_plugin_action_links( $action_links ) {

        // check user role
        if ( ! current_user_can( 'manage_options' ) ) {
            return $action_links;
        }

        // prepend action link
        array_unshift( $action_links, sprintf(
            '<a href="%s">%s</a>',
            admin_url( 'options-general.php?page=cdn-for-wp' ),
            esc_html__( 'Settings', 'cdn-for-wp' )
        ) );

        return $action_links;
    }


    public static function add_plugin_row_meta( $plugin_meta, $plugin_file ) {

        if ( $plugin_file !== CDN_FOR_WP_BASE ) {
            return $plugin_meta;
        }

        // append metadata
        $plugin_meta = wp_parse_args(
            array(
                '<a href="https://cdnforwp.com/faq/" target="_blank" rel="nofollow noopener">FAQ</a>',
            ),
            $plugin_meta
        );

        return $plugin_meta;
    }


    public static function add_admin_resources( $hook ) {

        // settings page
        if ( $hook === 'settings_page_cdn-for-wp' ) {
            wp_enqueue_script( 'cdn-for-wp-settings', plugins_url( 'assets/cdn-for-wp.js', CDN_FOR_WP_FILE ), array('jquery'), CDN_FOR_WP_VERSION );
        }
    }


    public static function requirements_check() {

        // check user role
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // check PHP version
        if ( version_compare( PHP_VERSION, CDN_FOR_WP_MIN_PHP, '<' ) ) {
            printf(
                '<div class="notice notice-error"><p>%s</p></div>',
                sprintf(
                    // translators: 1. CDN For WordPress 2. PHP version (e.g. 5.6)
                    esc_html__( '%1$s requires PHP %2$s or higher to function properly. Please update PHP or disable the plugin.', 'cdn-for-wp' ),
                    '<strong>CDN For WordPress</strong>',
                    CDN_FOR_WP_MIN_PHP
                )
            );
        }

        // check WordPress version
        if ( version_compare( $GLOBALS['wp_version'], CDN_FOR_WP_MIN_WP . 'alpha', '<' ) ) {
            printf(
                '<div class="notice notice-error"><p>%s</p></div>',
                sprintf(
                    // translators: 1. CDN For WordPress 2. WordPress version (e.g. 5.1)
                    esc_html__( '%1$s requires WordPress %2$s or higher to function properly. Please update WordPress or disable the plugin.', 'cdn-for-wp' ),
                    '<strong>CDN For WordPress</strong>',
                    CDN_FOR_WP_MIN_WP
                )
            );
        }
    }

    public static function register_textdomain() {

        // load translated strings
        load_plugin_textdomain( 'cdn-for-wp', false, 'cdn-for-wp/lang' );
    }


    public static function register_settings() {

        register_setting( 'cdn_for_wp', 'cdn_for_wp', array( 'CDN_FOR_WP_Settings', 'validate_settings', ) );
    }


	  // ACTIONS
    public static function backup_callback() {

		CDN_FOR_WP_Backups::get_instance()->set_type( 'file' );
		CDN_FOR_WP_Backups::get_instance()->set_is_using_file_manifest( true );
		CDN_FOR_WP_Backups::get_instance()->do_backup();

		$backup = CDN_FOR_WP_Backups::get_instance()->get_backup();
		set_transient( 'cdn_for_wp_last_backup_' . get_current_user_id() , $backup, 60 * 60);
	    wp_die();
	}


	public static function sync_callback() {
		echo CDN_FOR_WP_Backups::get_instance()->get_status();
		wp_die();
	}

	function do_backup_javascript() { ?>
		<script type="text/javascript" >
			jQuery(document).ready(function($) {
						jQuery('.sync-button').attr('disabled',true);
					    jQuery.post(ajaxurl, {'action': 'do_backup'}, function(response) {});
			});
		</script> <?php
	}

	public static function check_sync_javascript() { ?>
		<script type="text/javascript" >
			jQuery(document).ready(function($) {
					setInterval(function(){
					    jQuery.post(ajaxurl, {'action': 'check_sync'}, function(response) {
					    	if(response == ''){
					    		window.location.href = '<?php echo add_query_arg( ['page' => 'cdn-for-wp', 'tab' => 'synct',],admin_url('options-general.php') ); ?>';
					    	} else {
					        	$("p.response").html(response);
					        }
					    });
					}, 5000);
			});
		</script> <?php
	}

	public static function check_upload_javascript() { ?>
		<script type="text/javascript" >
		jQuery(document).ready(function($) {
					jQuery('.sync-button').attr('disabled',true);
				    jQuery.post(ajaxurl, {'action': 'do_upload'}, function(response) {
				    	$("p.response").html(response);
				    });
		});
		</script>
		<?php
		exit;
	}

	public static function get_site_keys() {
	    $options = self::get_options();
	    $keys = $options['cdn4wp_verify_key'];
	    if ( ! empty( $keys ) )
	        return $keys;
	    else
	        return false;
	}


	public static function _humanreadablesize($bytes, $decimals = 2) {
	    $size = array('B','kB','MB','GB','TB','PB','EB','ZB','YB');
	    $factor = floor((strlen($bytes) - 1) / 3);
	    return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . ' ' . @$size[$factor];
	}

	public static function _scan($path){
	    $bytestotal = 0;
	    $path = realpath($path);
	    if($path!==false && $path!='' && file_exists($path)){
	        foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $object){
	            $bytestotal += $object->getSize();
	        }
	    }
	    return $bytestotal;
	}

}
