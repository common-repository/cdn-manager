<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class CDN_FOR_WP_Upload {

    public static function init() {

        new self();
    }

    public function __construct() {

    }

    public static function uploading_request() {

        // check if tab is sync
        if ( is_admin() ) {

	        if ( empty( $_GET['tab'] ) || $_GET['tab'] !== 'synct' ) {
	            return;
	        }

	        // check user role
	        if ( ! current_user_can( 'manage_options' ) ) {
	            return;
	        }

        	$transient = get_transient( self::get_config_uploading_transient_name() );

			  if( ! empty( $transient ) ) {
			    return;
			  } else {
		        // set transient for uploading notice
		        	// check CDN upload
		        	$response = self::check_uploading();
		            set_transient( self::get_config_uploading_transient_name(), $response, 60 * 2 );
		        }
		}
        return;
    }

    public static function config_uploading_notice() {

        // check user role
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $response = get_transient( self::get_config_uploading_transient_name() );

        if ( isset($response['last_error']) && !empty($response['last_error']) ) {
            printf(
                $response['last_error']
            );

            delete_transient( self::get_config_uploading_transient_name() );
        }
    }

    public static function check_uploading() {

		$response = wp_remote_post( CDN_FOR_WP_API_URL, array(
					'method' => 'POST',
					'timeout' => 20,
					'sslverify'   => false,
					'redirection' => 5,
					'httpversion' => '1.0',
					'blocking' => true,
					'headers' => array(),
					'body' => array( 'sk'=> CDN_FOR_WP::get_site_keys(),
									 'action' => 'is_uploading'),
					'cookies' => array()
				    )
				);

	        if ( is_array( $response )) {
	            $json = json_decode($response['body'], true);

	            $rc = wp_remote_retrieve_response_code( $response );

	            // success
	            if ( $rc == 200
	                    and is_array($json)
	                    and array_key_exists('response', $json) )
	            {
	                if($json['response'] == 'No upload'){
	                	$response = $json['response'];
	                } else {
		                $response = array(
		                    'wrapper' => '<div class="notice notice-success is-dismissible"><p><strong>%s</strong></p></div>',
		                    'message' => $json['response'],
		                );
	                }
               		return $response;
	            }


	         }

		return;
    }

    private static function get_config_sync_transient_name() {

        $transient_name = 'cdn_for_wp_config_sync_' . get_current_user_id();

        return $transient_name;
    }

    private static function get_config_uploading_transient_name() {

        $transient_name = 'cdn_for_wp_config_uploading_' . get_current_user_id();

        return $transient_name;
    }

	public static function action_add_attachment ($postID) {

		if ( wp_attachment_is_image($postID) == false ) {

            $options = CDN_FOR_WP::get_options();
            $includes = explode( PHP_EOL, apply_filters( 'cdn4wp_backup_includes', $options['included_file_extensions'] ) );
            $file = get_attached_file($postID);

		    if ( $includes ){
		    	$inc = false;
	            foreach ( $includes as $included_string ) {
	                if ( strpos( $file , $included_string) !== false ) {
	                    $inc = true;
	                }
	            }

	            if($inc === false)
	            return;
		    }


		  $file_info = pathinfo($file);

          $paths = array();
          $paths['files'][] = $file_info['basename'];

		  $upload_dir = wp_upload_dir();

	      $paths['content'] = $upload_dir;
	      $paths['content']['site_url'] = get_site_url();
	      $paths['content']['subdir'] = substr($paths['content']['subdir'], 1);

		  self::file_upload($paths);
		}

		return true;

	}

  public static function action_delete_attachment ($postID) {

    $paths = array();
    $upload_dir = wp_upload_dir();

    if ( wp_attachment_is_image($postID) == false ) {

      $file = get_attached_file($postID);

      self::file_delete($file);

    } else {

      $metadata = wp_get_attachment_metadata($postID);

      // collect original file path
      if ( isset($metadata['file']) ) {

        $path = $upload_dir['basedir'] . DIRECTORY_SEPARATOR . $metadata['file'];

        if ( !in_array($path, $paths) ) {
          array_push($paths, $path);
        }

        // set basepath for other sizes
        $file_info = pathinfo($path);
        $basepath = isset($file_info['extension'])
            ? str_replace($file_info['filename'] . "." . $file_info['extension'], "", $path)
            : $path;

      }

      // collect size files path
      if ( isset($metadata['sizes']) ) {

        foreach ( $metadata['sizes'] as $size ) {

          if ( isset($size['file']) ) {

            $path = $basepath . $size['file'];
            array_push($paths, $path);

          }

        }

      }

      // process paths
      foreach ($paths as $filepath) {

        // upload file
        self::file_delete($filepath);

      }

    }

  }

  // FILTERS
  public static function filter_wp_generate_attachment_metadata ($metadata) {

    $paths = array();
    $paths['files'] = array();
    $upload_dir = wp_upload_dir();

    $options = CDN_FOR_WP::get_options();
    $includes = explode( PHP_EOL, apply_filters( 'cdn4wp_backup_includes', $options['included_file_extensions'] ) );

    if ( isset($metadata['file']) ) {

	    if ( $includes ){
	    	$inc = false;
	           foreach ( $includes as $included_string ) {
	               if ( strpos( $metadata['file'] , $included_string) !== false ) {
	                   $inc = true;
	               }
	           }

	           if($inc === false)
	           return;
	    }

		$file_info = pathinfo($metadata['file']);

		$ud = pathinfo($upload_dir['basedir']);
        $path = $file_info['basename'];
		$content_url = $upload_dir['baseurl'].'/'.$path;
		array_push($paths['files'], $path);

    }

    // collect size files path
    if ( isset($metadata['sizes']) ) {

      foreach ( $metadata['sizes'] as $size ) {

        if ( isset($size['file']) ) {

          $path = $size['file'];
          array_push($paths['files'], $path);

        }

      }

    }

    if ( isset($metadata['file']) ) {

      $paths['content'] = $upload_dir;

      $paths['content']['subdir'] = $file_info['dirname'];
      //2021/03

      $paths['content']['path'] = $paths['content']['basedir'].DIRECTORY_SEPARATOR.$file_info['dirname'];
      //D:\xampp\htdocs\cdnwp/wp-content/uploads/sites/2\2021/03

      $paths['content']['site_url'] = get_site_url();
      //http://localhost/cdnwp/deneme

      $paths['content']['baseurl'] = $upload_dir['baseurl'];
      //http://localhost/cdnwp/deneme/wp-content/2

      $paths['content']['url'] = $upload_dir['baseurl'].DIRECTORY_SEPARATOR.$file_info['dirname'];
      //http://localhost/cdnwp/deneme/wp-content/2/2021/03

      // upload file
      self::file_upload($paths, 0, true);
	}

    return $metadata;

  }

  public static function file_upload ($file) {
    $options = CDN_FOR_WP_Engine::$settings;

    try {


		$response = wp_remote_post( CDN_FOR_WP_API_URL, array(
			'method' => 'POST',
			'timeout' => 15,
			'sslverify'   => false,
			'redirection' => 5,
			'httpversion' => '1.0',
			'blocking' => true,
			'headers' => array(),
			'body' => array( 'sk'=> $options['cdn4wp_verify_key'],
							 'fupload' => maybe_serialize($file)),
			'cookies' => array()
		    )
		);

      return true;

    } catch (Exception $e) {

      return false;

    }

  }

  public static function file_delete ($file) {
      $options = CDN_FOR_WP_Engine::$settings;
      $file = str_replace(ABSPATH,'',$file);

      try {

			$response = wp_remote_post( CDN_FOR_WP_API_URL, array(
				'method' => 'POST',
				'timeout' => 15,
				'sslverify'   => false,
				'redirection' => 5,
				'httpversion' => '1.0',
				'blocking' => true,
				'headers' => array(),
				'body' => array( 'sk'=> $options['cdn4wp_verify_key'],
								 'fdelete' => serialize($file)),
				'cookies' => array()
			    )
			);

      } catch (Exception $e) {

        error_log( $e );

      }

    return $file;

  }

  public static function start_upload(){

  	$backup = get_transient( 'cdn_for_wp_last_backup_' . get_current_user_id() );
   	if(isset($backup) && !empty($backup) && isset($backup->url) ){
  		add_action( 'admin_footer', array( 'CDN_FOR_WP', 'check_upload_javascript' ) );
  	}

  }

    public static function upload_callback() {

    	$backup = get_transient( 'cdn_for_wp_last_backup_' . get_current_user_id() );

    	if(isset($backup) && !empty($backup) && isset($backup->url) ){

            delete_transient( self::get_config_uploading_transient_name() );

	        $response = wp_remote_post( CDN_FOR_WP_API_URL, array(
							'method' => 'POST',
							'timeout' => 20,
							'sslverify'   => false,
							'redirection' => 5,
							'httpversion' => '1.0',
							'blocking' => true,
							'headers' => array(),
							'body' => array( 'sk'=> CDN_FOR_WP::get_site_keys(),
											 'bk' => $backup->url),
							'cookies' => array()
						    )
						);

	        if ( is_wp_error( $response ) ) {
	           $response = array(
	                'wrapper' => '<div class="notice notice-error is-dismissible"><p><strong>%1$s</strong> %2$s</p></div>',
	                'subject' => esc_html__( 'Upload the CDN is failed:', 'cdn-for-wp' ),
	                'message' => $response->get_error_message(),
	            );
	            set_transient( self::get_config_sync_transient_name(), $response, 60 * 2);
	            return;
	        }


	        // check HTTP response
	        if ( is_array( $response )) {
	            $json = json_decode($response['body'], true);

	            $rc = wp_remote_retrieve_response_code( $response );

	            // success
	            if ( $rc == 200
	                    and is_array($json)
	                    and array_key_exists('description', $json) )
	            {
	                $response = array(
	                    'wrapper' => '<div class="notice notice-success is-dismissible"><p><strong>%s</strong></p></div>',
	                    'message' => $json['description'],
	                );

	                if (strpos($json['description'], 'succesfuly') !== false) {
			        		CDN_FOR_WP_Backups::get_instance()->cleanup_ziparchive( pathinfo($backup->path, PATHINFO_FILENAME ));
			        		delete_transient( 'cdn_for_wp_last_backup_' . get_current_user_id() );
	                }



	            } elseif ( $rc == 200 ) {
	                // return code 200 but no message
		           $response = array(
		                'wrapper' => '<div class="notice notice-error is-dismissible"><p><strong>%1$s</strong> %2$s</p></div>',
		                'subject' => esc_html__('HTTP returned 200 but no message received.'),
		                'message' => $response->get_error_message(),
		            );
	            }
	         	set_transient( self::get_config_sync_transient_name(), $response , 60 * 2);
	         }
		}
    	return;
	}

    public static function cache_synced_notice() {
        // check user role
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $response = get_transient( self::get_config_sync_transient_name() );

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

            delete_transient( self::get_config_sync_transient_name() );
        }
        return;
    }



}
