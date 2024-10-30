<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class CDN_FOR_WP_Settings {

    public static function init() {

        new self();
    }

    public function __construct() {

    }

    private static function get_config_validated_transient_name() {

        $transient_name = 'cdn_for_wp_config_validated_' . get_current_user_id();

        return $transient_name;
    }

    private static function validate_config( $validated_settings ) {

        if (!isset($validated_settings['cdn4wp_verify_key'])) {
            $validated_settings['cdn4wp_verify_key'] = "";
            $validated_settings['endpoint'] = "";
            $validated_settings['key_confirm'] = "";
            $validated_settings['enabled'] = 0;
            $validated_settings['last_error'] = "No key please add key";
        } else {
        	$check_key = self::confirm_key($validated_settings['cdn4wp_verify_key']);

        	if($check_key['endpoint'] != '' && $check_key['key'] == true){
	        	$validated_settings['endpoint'] = $check_key['endpoint'];
	        	$validated_settings['key_confirm'] = $check_key['key'];
	        	$validated_settings['last_error'] = "";
        	} else {
	        	$validated_settings['endpoint'] = "";
	        	$validated_settings['key_confirm'] = "";
	        	$validated_settings['last_error'] = $check_key['last_error'];
	        	$validated_settings['enabled'] = 0;
        	}
        }

        if ( empty( $validated_settings['endpoint'] ) ) {
            return $validated_settings;
        }

        // set transient for config validation notice
        set_transient( self::get_config_validated_transient_name(), $check_key );

        // validate config
        if ( !empty( $response['last_error']) ) {
            $validated_settings['endpoint'] = '';
        }

        return $validated_settings;
    }

    public static function config_validated_notice() {

        // check user role
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $response = get_transient( self::get_config_validated_transient_name() );

        if ( isset($response['last_error']) && !empty($response['last_error']) ) {
            printf(
                $response['last_error']
            );

            delete_transient( self::get_config_validated_transient_name() );
        }
    }

    public static function get_settings() {

        // get database option value
        $settings = get_option( 'cdn_for_wp' );

        // if database option does not exist or settings are outdated
        if ( $settings === false || ! isset( $settings['version'] ) || $settings['version'] !== CDN_FOR_WP_VERSION ) {
            $settings = CDN_FOR_WP::update_backend();
        }

        return $settings;
    }

    public static function get_default_settings( $settings_type = null ) {

        $system_default_settings = array(
        	'version' => (string) CDN_FOR_WP_VERSION,
        	'endpoint'             	   => '',
            'key_confirm'              => '',
            'cdn4wp_verify_key'        => '',
            'del_settings'  		   => '0',
            'rewrite'        		   => '1',
            'enabled'        		   => '0',
            'last_error'        	   => '',
        );

        if ( $settings_type === 'system' ) {
            return $system_default_settings;
        }

        $user_default_settings = array(
            'included_file_extensions' => implode( PHP_EOL, array(
                                              '.avif',
                                              '.css',
                                              '.gif',
                                              '.jpeg',
                                              '.jpg',
                                              '.json',
                                              '.js',
                                              '.mp3',
                                              '.mp4',
                                              '.pdf',
                                              '.png',
                                              '.svg',
                                              '.webp',
                                              '.woff',
                                              '.woff2',
                                              '.tff',
                                          ) ),
            'excluded_strings'         => implode( PHP_EOL, array(
                                              '.php',
                                          ) ),
        );

        // merge default settings
        $default_settings = wp_parse_args( $user_default_settings, $system_default_settings );

        return $default_settings;
    }


    private static function convert_settings( $settings ) {

        // check if there are any settings to convert
        if ( empty( $settings ) ) {
            return $settings;
        }

        // updated settings
        if ( isset( $settings['url'] ) && is_string( $settings['url'] ) && substr_count( $settings['url'], '/' ) > 2 ) {
            $settings['url'] = '';
        }

        // reformatted settings
        if ( isset( $settings['excludes'] ) && is_string( $settings['excludes'] ) ) {
            $settings['excludes'] = str_replace( ',', PHP_EOL, $settings['excludes'] );
            $settings['excludes'] = str_replace( '.php', '', $settings['excludes'] );
        }

        // renamed or removed settings
        $settings_names = array(
            'dirs'     => '', // deprecated
            'url'      => '', // deprecated
            'excludes' => 'excluded_strings',
            'relative' => '', // deprecated
            'https'    => '', // deprecated
        );

        foreach ( $settings_names as $old_name => $new_name ) {
            if ( array_key_exists( $old_name, $settings ) ) {
                if ( ! empty( $new_name ) ) {
                    $settings[ $new_name ] = $settings[ $old_name ];
                }

                unset( $settings[ $old_name ] );
            }
        }

        return $settings;
    }


    public static function confirm_key($key) {

        // validate nonce
        if ( empty($key)) {
            return;
        }

        // check user role
        if ( ! is_admin_bar_showing() ) {
            return;
        }

        // load if network
        if ( ! function_exists('is_plugin_active_for_network') ) {
            require_once( ABSPATH. 'wp-admin/includes/plugin.php' );
        }

        $response = wp_remote_post( CDN_FOR_WP_API_URL, array(
						'method' => 'POST',
						'timeout' => 20,
						'sslverify'   => false,
						'redirection' => 5,
						'httpversion' => '1.0',
						'blocking' => true,
						'headers' => array(),
						'body' => array( 'sk'=> $key,
										 'sitename'=> get_site_url(),
										 'verify' => true),
						'cookies' => array()
					    )
					);

        // check results - error connecting
        if ( is_wp_error( $response ) ) {
        	return array('last_error'=> esc_html__('Error connecting to API - '. $response->get_error_message(), 'cdn-for-wp') );
        }

        // check HTTP response
        if ( is_array( $response ) and is_admin_bar_showing()) {
            $json = json_decode($response['body'], true);

            $rc = wp_remote_retrieve_response_code( $response );

            // success
            if ( $rc == 200
                    and is_array($json)
                    and array_key_exists('endpoint', $json)
                    and array_key_exists('key', $json) )
            {
                return $json;
            } elseif ( is_array($json)
                    and array_key_exists('last_error', $json)
                    and $json['last_error'] != "" ) {
            	return array('last_error' => $json['last_error']);
            }elseif ( $rc == 200 ) {
                // return code 200 but no message
                return array('last_error'=>'HTTP returned 200 but no message received.');
            } else {
                // Something else went wrong - show HTTP error code
                return array('last_error' => esc_html__('HTTP returned '. $rc));
            }
        }
    }

    public static function validate_settings( $settings ) {

        update_option('upload_url_path', "");

        $validated_settings = array(
            'included_file_extensions'	=> self::validate_textarea( $settings['included_file_extensions'], true ),
            'excluded_strings'			=> self::validate_textarea( $settings['excluded_strings'] ),
            'cdn4wp_verify_key'			=> (string) sanitize_text_field( $settings['cdn4wp_verify_key'] ),
            'enabled'           		=> $settings['enabled'],
            'endpoint'           		=> $settings['endpoint'],
            'key_confirm'           	=> $settings['key_confirm'],
            'rewrite'           		=> isset($settings['rewrite'])? $settings['rewrite'] : 0,
            'del_settings'           	=> isset($settings['del_settings'])? $settings['del_settings'] : 0,
            'last_error'           		=> $settings['last_error'],
        );

        // add default system settings
        $validated_settings = wp_parse_args( $validated_settings, self::get_default_settings( 'system' ) );

        // check if configuration should be validated
        if ( ! empty( $settings['validate_config'] ) ) {
            $validated_settings = self::validate_config( $validated_settings );
        }

        return $validated_settings;
    }

    private static function validate_textarea( $textarea, $file_extension = false ) {

        $textarea = sanitize_textarea_field( $textarea );
        $lines = explode( PHP_EOL, trim( $textarea ) );
        $validated_textarea = array();

        foreach ( $lines as $line ) {
            $line = trim( $line );

            if ( $line !== '' ) {
                if ( ! $file_extension ) {
                    $validated_textarea[] = $line;
                } elseif ( preg_match( '/^\.\w{1,10}$/', $line ) ) {
                    $validated_textarea[] = $line;
                }
            }
        }

        $validated_textarea = implode( PHP_EOL, $validated_textarea );

        return $validated_textarea;
    }

    public static function add_settings_page() {

        add_options_page(
            'CDN For WP',
            'CDN For WP',
            'manage_options',
            'cdn-for-wp',
            array( __CLASS__, 'settings_page' )
        );
    }

	private static function page_tabs( $current = 'settings' ) {
	    $tabs = array(
	        'settings'   => __( 'Settings', 'cdn_for_wp' ),
	        'synct'   => __( 'Sync', 'cdn_for_wp' ),
	        'support'  => __( 'Support', 'cdn_for_wp' ),
	        'donate'  => __( 'Donate', 'cdn_for_wp' ),
	        'hire_expert'  => __( 'Hire an Expert', 'cdn_for_wp' ),
	    );

	    $html = '<div class="wrap"><h1></h1>';
	    $html .=  sprintf( '<a href="%s" target="_self">%s</a>', add_query_arg( ['page' => 'cdn-for-wp',],admin_url('options-general.php') ), '<img style="padding-top:20px" src="' . plugins_url( 'assets/logo.png' , dirname(__FILE__) ) . '" width="300" alt="">' );

	    $html .= '<h2 class="nav-tab-wrapper cdn4wp_settings">';
	    foreach( $tabs as $tab => $name ){
	        $class = ( $tab == $current ) ? 'nav-tab-active' : '';
	        $html .= sprintf( '<a href="%s" class="' . $class . ' nav-tab">%s</a>', add_query_arg( ['page' => 'cdn-for-wp', 'tab' => $tab], admin_url('options-general.php') ), $name );
	    }

	    $html .= '</h2>';
	    return $html;
	}


    public static function settings_page() {


		$options = CDN_FOR_WP::get_options();
		// Tabs
		$tab = ( ! empty( $_GET['tab'] ) ) ? esc_attr( $_GET['tab'] ) : 'settings';
        echo self::page_tabs( $tab );


        if ( $tab == 'settings' ) { ?>
            <h1><?php esc_html_e( 'CDN For WP Settings', 'cdn-for-wp' ); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields('cdn_for_wp') ?>
                <table class="form-table">
	                   <tr valign="top">
	                       <th scope="row">
	                           <?php _e("Enable CDN", "cdn-for-wp"); ?>
	                       </th>
	                       <td>
	                           <fieldset>
	                               <label for="cdn_for_wp_enabled">
	                                   <input type="checkbox" name="cdn_for_wp[enabled]" id="cdn_for_wp_enabled" value="1" <?php checked(1, $options['enabled']) ?> <?php
	                                   if(isset($options['endpoint']) && empty($options['endpoint']) && empty($options['key_confirm'])	){
	                                   		echo ' disabled';
	                                   }
	                                   ?>/>
	                                   <?php _e("Enable CDN", "cdn-for-wp"); ?>
	                               </label>
	                           </fieldset>
	                       </td>
	                   </tr>
	                   <tr valign="top">
	                       <th scope="row">
	                           <?php _e("Enable rewrite", "cdn-for-wp"); ?>
	                       </th>
	                       <td>
	                           <fieldset>
	                               <label for="cdn_for_wp_rewrite">
	                                   <input type="checkbox" name="cdn_for_wp[rewrite]" id="cdn_for_wp_rewrite" value="1" <?php checked(1, $options['rewrite']) ?> />
	                                   <?php _e("Enable rewrite for the uploaded files (default: enabled).", "cdn-for-wp"); ?>
	                               </label>
										<?php
											if(isset($options['endpoint']) && !empty($options['endpoint']) && !empty($options['key_confirm'])	){
												echo '<p>
														 CDN endpoint : <a href="javascript:void(0)" data-text="'.$options['endpoint'].$options['cdn4wp_verify_key'].'" onclick="copyClipboard(\'1\')" class="site-name-1">'.$options['endpoint'].$options['cdn4wp_verify_key'].'/</a>
														 <span style="display:none" class="copied-1">copied!!</span>
													  </p>';
											}
										?>
	                           </fieldset>
	                       </td>
	                   </tr>
                    <tr valign="top">
                        <th scope="row">
                            <?php esc_html_e( 'CDN Inclusions', 'cdn-for-wp' ); ?>
                        </th>
                        <td>
                            <fieldset>
                                <p class="subheading"><?php esc_html_e( 'File Extensions', 'cdn-for-wp' ); ?></p>
                                <label for="cdn_for_wp_included_file_extensions">
                                    <textarea name="cdn_for_wp[included_file_extensions]" type="text" id="cdn_for_wp_included_file_extensions" class="regular-text code" rows="5" cols="40"><?php echo esc_textarea( CDN_FOR_WP_Engine::$settings['included_file_extensions'] ) ?></textarea>
                                    <p class="description"><?php esc_html_e( 'Files with these extensions will be served by the CDN. One file extension per line.', 'cdn-for-wp' ); ?></p>
                                </label>
                            </fieldset>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">
                            <?php esc_html_e( 'CDN Exclusions', 'cdn-for-wp' ); ?>
                        </th>
                        <td>
                            <fieldset>
                                <p class="subheading"><?php esc_html_e( 'Strings', 'cdn-for-wp' ); ?></p>
                                <label for="cdn_for_wp_excluded_strings">
                                    <textarea name="cdn_for_wp[excluded_strings]" type="text" id="cdn_for_wp_excluded_strings" class="regular-text code" rows="5" cols="40"><?php echo esc_textarea( CDN_FOR_WP_Engine::$settings['excluded_strings'] ) ?></textarea>
                                    <p class="description"><?php esc_html_e( 'URLs containing these strings will not be served by the CDN. One string per line.', 'cdn-for-wp' ) ?></p>
                                </label>
                            </fieldset>
                        </td>
                    </tr>
                </table>
                <h2 class="title">Purge CDN Cache</h2>
                <p><?php esc_html_e( 'You can purge the CDN cache from the WordPress admin bar.', 'cdn-for-wp' ) ?></p>
                <table class="form-table">
	                   <tr valign="top">
	                       <th scope="row">
	                           <?php _e("CDN for WP API Key", "cdn-for-wp"); ?>
	                       </th>
	                       <td>
	                           <fieldset>
	                               <label for="cdn4wp_verify_key">
	                                   <input type="text" name="cdn_for_wp[cdn4wp_verify_key]" id="cdn4wp_verify_key" value="<?php echo $options['cdn4wp_verify_key']; ?>" size="64" class="regular-text code" />
											<?php
											if(isset($options['endpoint']) && empty($options['endpoint']) && empty($options['key_confirm'])	){

												echo '<a class="button button-primary" href="'.CDN_FOR_WP_URL.'register" target="_blank">
													'.__("GET NEW KEY", "cdn-for-wp").'
													 </a>';
											}

											if(!empty( $options['last_error'] )){
												echo '<div class="notice notice-error is-dismissible"><p>'.$options['last_error'].'</p></div>';
											}
											?>
										<p class="description">
											<?php _e('CDN for WP API key.', 'cdn-for-wp'); ?>
										</p>
	                               </label>
	                           </fieldset>
	                       </td>
	                   </tr>
	                   <tr valign="top">
	                       <th scope="row">
	                           <?php _e("Uninstall CDN for WP", "cdn-for-wp"); ?>
	                       </th>
	                       <td>
	                           <fieldset>
	                               <label for="cdn_for_wp_del_settings">
	                                   <input type="checkbox" name="cdn_for_wp[del_settings]" id="cdn_for_wp_del_settings" value="1" <?php checked(1, $options['del_settings']) ?> />
	                                   <?php _e("Remove ALL settings.", "cdn-for-wp"); ?>
		                               <p class="description">
		                                   <?php _e("Check this if you would like to remove ALL CDN for WP data upon plugin deletion. All settings will be unrecoverable.", "cdn-for-wp"); ?>
		                               </p>
	                               </label>
	                           </fieldset>
	                       </td>
	                   </tr>
                </table>
                <p class="submit">
                    <input name="cdn_for_wp[validate_config]" type="submit" class="button-primary" value="<?php esc_html_e( 'Save Changes and Validate Configuration', 'cdn-for-wp' ); ?>" />
                </p>
            </form>
		<?php
		}
		if ( $tab == 'synct' ) {

			if ( !empty($_GET['_cdn']) && esc_attr( $_GET['_cdn'] ) == 'stopt' ) {
				CDN_FOR_WP_Backups::get_instance()->cleanup();
				wp_safe_redirect( add_query_arg( ['page' => 'cdn-for-wp', 'tab' => 'synct',],admin_url('options-general.php') ) );
			}
			$backup = CDN_FOR_WP_Backups::get_instance()->get_status();
			$transient = get_transient( 'cdn_for_wp_config_uploading_' . get_current_user_id() );
		?>
					<h1><?php esc_html_e( 'CDN Sync Status', 'cdn-for-wp' ); ?></h1>
					<p class="cdn4wp_messages">
						<?php
							if(isset($options['endpoint']) && empty($options['endpoint']) && empty($options['key_confirm'])	){

						                echo '<div class="notice notice-error is-dismissible"><p class="response">';
						                    _e( 'CDN for WordPress is almost ready. you need to enter your ','cdn4wp' ); ?>
						                    <a href="<?php echo CDN_FOR_WP_URL; ?>" target="_blank">
						                    	<?php _e( 'CDN for WordPress','cdn4wp' ); ?>
						                    </a> <?php _e( 'site key below. ','cdn4wp' ); ?>
						                    <?php _e( 'Once you added your key, you can start to manage your site on <a href="'.CDN_FOR_WP_URL.'register" target="_blank">cdnforwp.com</a>','cdn4wp' ); ?>
						                    <br>
						                    <?php _e( 'If you didn\'t create any site key for this site ','cdn4wp' ); ?>
						                    <a href="<?php echo CDN_FOR_WP_URL; ?>register" target="_blank">
						                    	<?php _e( 'Click here and get your site key.','cdn4wp' ); ?>
						                    </a>
						                    <?php _e( 'If you are using multisite WordPress, you need to enter site key for only main site.','cdn4wp' );
						                    echo '</p></div>';
								exit;
							}
									if(isset($backup) && !empty($backup) ){
                                        //wp_safe_redirect( add_query_arg( ['page' => 'cdn-for-wp', 'tab' => 'synct',],admin_url('options-general.php') ) );
                                        add_action( 'admin_footer', array( 'CDN_FOR_WP', 'check_sync_javascript' ) );

										echo sprintf( '<a href="%s" disabled class="disabled button button-primary large sync-button">%s</a>', wp_nonce_url( add_query_arg( ['page' => 'cdn-for-wp', 'tab' => $tab], admin_url('options-general.php'), '_cdn__synct_nonce') ), __('SYNC MY FILES', 'cdn-for-wp') );

							               printf(
							                   '<div class="notice notice-error is-dismissible"><p class="response">%s</p></div>',
							                   $backup
							               );

									} else if(isset($transient) && !empty($transient && $transient != 'No upload') ){

										echo sprintf( '<a href="%s" disabled class="disabled button button-primary large sync-button">%s</a>', wp_nonce_url( add_query_arg( ['page' => 'cdn-for-wp', 'tab' => $tab], admin_url('options-general.php'), '_cdn__synct_nonce') ), __('SYNC MY FILES', 'cdn-for-wp') );

						                printf(
						                    $transient['wrapper'],
						                    $transient['message']
						                );

									} else {
										echo sprintf( '<a href="%s" class="button button-primary large sync-button">%s</a>', wp_nonce_url( add_query_arg( ['page' => 'cdn-for-wp', 'tab' => $tab, '_cdn' => 'synct'], admin_url('options-general.php'), '_cdn__synct_nonce') ), __('SYNC MY FILES', 'cdn-for-wp') );
									}

									if ( !empty($_GET['_cdn']) && esc_attr( $_GET['_cdn'] ) == 'upload' ) {

						                printf(
						                    '<div class="notice notice-error is-dismissible"><p class="response">%s</p></div>',
						                    esc_html__('Please wait...', 'cdn-for-wp')
						                );

										add_action( 'admin_footer', array( 'CDN_FOR_WP', 'check_upload_javascript' ) );

									}
						?>
						</p>
		<?php
		}

		if ( $tab == 'support' ) { ?>
			<div  class="wrap" id="cdn4wp_support">
						<p class="cdn4wp_messages">
							<h2 style="margin-bottom: 0;"><span class="dashicons dashicons-format-chat" style="font-size: 32px; display: inline-table;"></span> <?php _e( 'Questions Achive ? ','cdn4wp' ); ?></h2>
							<?php _e( 'Before asking your question you can look or search our questions archive!.. ','cdn4wp' ); ?><br>
							<a href="<?php echo esc_url( CDN_FOR_WP_URL ); ?>faq" target="_blank">
									<?php _e( 'Take a look at Question Archive now!','cdn4wp' ); ?>
							</a>
							<br>
							<br>
							<h2 style="margin-bottom: 0;"><span class="dashicons dashicons-admin-users" style="font-size: 32px; display: inline-table;"></span> <?php _e( 'Find an Answer ? ','cdn4wp' ); ?></h2>
							<?php _e( 'You are the right section! you can find all your answer about CDN gfor WordPress service. ','cdn4wp' ); ?><br>
							<a href="<?php echo esc_url( CDN_FOR_WP_URL ); ?>support" target="_blank">
									<?php _e( 'Ask your question now!','cdn4wp' ); ?>
							</a>
						</p>
		<?php
		}

		if ( $tab == 'donate' ) { ?>
			<h1><?php esc_html_e( 'Donate', 'cdn-for-wp' ); ?></h1>
					<p class="cdn4wp_messages">
						<h2 style="margin-bottom: 0;"><span class="dashicons dashicons-editor-help" style="font-size: 32px; display: inline-table;"></span> <?php _e( 'Do you like it? ','cdn4wp' ); ?></h2>
						<?php _e( 'Hi! I am Eric Simanko from the iRemoteWP developer team and one of the creators of this plugin. If you like this product that we spend a long time on, you can give us financial support. At least we can drink 1 coffee thanks to you :)','cdn4wp' ); ?><br>
						<br>
						<br>
						<form action="https://www.paypal.com/donate" method="post" target="_top">
							<input type="hidden" name="hosted_button_id" value="XRF2F4SN7FZSL" />
							<input type="image" src="https://www.paypalobjects.com/en_US/i/btn/btn_donateCC_LG.gif" border="0" name="submit" title="PayPal - The safer, easier way to pay online!" alt="Donate with PayPal button" />
							<img alt="" border="0" src="https://www.paypal.com/en_CY/i/scr/pixel.gif" width="1" height="1" />
						</form>
					</p>
		<?php
		}

		if ( $tab == 'hire_expert' ) { ?>
			<h1><?php esc_html_e( 'Hire an Expert', 'cdn-for-wp' ); ?></h1>
					<p class="cdn4wp_messages">
						<h2 style="margin-bottom: 0;"><span class="dashicons dashicons-editor-help" style="font-size: 32px; display: inline-table;"></span> <?php _e( 'Need help? ','cdn4wp' ); ?></h2>
						<?php _e( 'If you have speed problems on your website or want to get support with CDN setup, you may want to work with our expert support team. <br>Please send an e-mail to get information about CDN setups, speed increase solutions and prices. <br><br>Thank you.
						','cdn4wp' ); ?>
						<br>
						<?php _e( 'Eric Simanko', 'cdn4wp' ); ?>
						<bR>
						<br>
						<?php _e( 'Mail adress', 'cdn4wp' ); ?>
						<a href="mailto:<?php _e( 'info@cdnforwp.com', 'cdn4wp' ); ?>?subject=<?php _e( 'Hire an Expert','cdn4wp' ); ?>" target="_blank">
								<?php _e( 'info@cdnforwp.com', 'cdn4wp' ); ?>
						</a>
					</p>
		<?php }

		?>
		</div>
		<?php
    }

}
