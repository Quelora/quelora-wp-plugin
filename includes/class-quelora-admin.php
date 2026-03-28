<?php
// filepath: includes/class-quelora-admin.php

/**
 * Class Quelora_Admin
 *
 * Handles the administrative interface setup, backend routing for the React SPA,
 * Secure JSON configuration fetching (JWT signed POST), and integration health checks.
 *
 * @package Quelora
 */
class Quelora_Admin {

	const DEFAULT_DASHBOARD_URL = 'https://dashboard.quelora.local';
	const DEFAULT_HEADER_SELECTOR = '.site-header-primary-section-right';
	const DEFAULT_TOKEN_TTL = 3600;
	const DEFAULT_SSO_ENABLED = false;
	const DEFAULT_PLACEMENT_STRATEGY = 'both';
	const DEFAULT_CUSTOM_CSS = '';

	/**
	 * Registers the Quelora settings page in the WordPress admin menu.
	 *
	 * @return void
	 */
	public function register_settings_page() {
		$svg      = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><rect width="100" height="100" rx="18" fill="#3b82c4"/><circle cx="45" cy="45" r="22" fill="none" stroke="#fff" stroke-width="16"/><line x1="56" y1="56" x2="76" y2="76" stroke="#fff" stroke-width="16" stroke-linecap="round"/></svg>';
		$icon_svg = 'data:image/svg+xml;base64,' . base64_encode( $svg );

		add_menu_page(
			esc_html__( 'Quelora Configuration', 'quelora' ),
			esc_html__( 'Quelora', 'quelora' ),
			'manage_options',
			'quelora-settings',
			array( $this, 'render_settings_page' ),
			$icon_svg,
			80
		);
	}

	/**
	 * Enqueues assets necessary for the React SPA admin interface.
	 *
	 * @param string $hook The current admin page hook.
	 * @return void
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( 'toplevel_page_quelora-settings' !== $hook ) {
			return;
		}

		$asset_file_path = plugin_dir_path( dirname( __FILE__ ) ) . 'build/admin.asset.php';

		if ( ! file_exists( $asset_file_path ) ) {
			return;
		}

		$asset_file = include $asset_file_path;

		wp_enqueue_script(
			'quelora-admin-spa',
			plugins_url( 'build/admin.js', dirname( __FILE__ ) ),
			$asset_file['dependencies'],
			$asset_file['version'],
			true
		);

		wp_set_script_translations( 'quelora-admin-spa', 'quelora', plugin_dir_path( dirname( __FILE__ ) ) . 'languages' );

		$settings = array(
			'isConfigured'      => (bool) get_option( 'quelora_is_configured', false ),
			'clientId'          => get_option( 'quelora_client_id', '' ),
			'dashboardUrl'      => get_option( 'quelora_dashboard_url', self::DEFAULT_DASHBOARD_URL ),
			'apiUrl'            => get_option( 'quelora_api_url', '' ),
			'dashboardApiUrl'   => get_option( 'quelora_dashboard_api_url', '' ),
			'assetSource'       => get_option( 'quelora_asset_source', 'local' ),
			'cdnJsUrl'          => get_option( 'quelora_cdn_js_url', '' ),
			'cdnCssUrl'         => get_option( 'quelora_cdn_css_url', '' ),
			'headerSelector'    => get_option( 'quelora_header_selector', self::DEFAULT_HEADER_SELECTOR ),
			'configScript'      => get_option( 'quelora_config_script', '' ),
			'customCss'         => get_option( 'quelora_custom_css', self::DEFAULT_CUSTOM_CSS ),
			'defaultActive'     => (bool) get_option( 'quelora_default_active', false ),
			'placementStrategy' => get_option( 'quelora_placement_strategy', self::DEFAULT_PLACEMENT_STRATEGY ),
			'hideWpComments'    => (bool) get_option( 'quelora_hide_wp_comments', false ),
			'ssoEnabled'        => (bool) get_option( 'quelora_sso_enabled', self::DEFAULT_SSO_ENABLED ),
			'ssoSecretKey'      => get_option( 'quelora_sso_secret_key', '' ),
			'ssoTokenTtl'       => (int) get_option( 'quelora_sso_token_ttl', self::DEFAULT_TOKEN_TTL ),
			'syncPostsEndpoint' => get_option( 'quelora_sync_posts_endpoint', '' ),
			'syncUsersEndpoint' => get_option( 'quelora_sync_users_endpoint', '' ),
		);

		wp_localize_script(
			'quelora-admin-spa',
			'QueloraAdminData',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'quelora_admin_nonce' ),
				'settings' => $settings,
			)
		);
	}

	/**
	 * Renders the DOM root mount point for the React SPA.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		echo '<div id="quelora-admin-root"></div>';
	}

	/**
	 * Injects the Gutenberg block editor assets (JS/CSS).
	 *
	 * @return void
	 */
	public function inject_editor_assets() {
		$asset_file_path = plugin_dir_path( dirname( __FILE__ ) ) . 'build/index.asset.php';

		if ( ! file_exists( $asset_file_path ) ) {
			return;
		}

		$asset_file = include $asset_file_path;

		wp_enqueue_script(
			'quelora-sidebar',
			plugins_url( 'build/index.js', dirname( __FILE__ ) ),
			$asset_file['dependencies'],
			$asset_file['version'],
			true
		);

		wp_set_script_translations( 'quelora-sidebar', 'quelora', plugin_dir_path( dirname( __FILE__ ) ) . 'languages' );

		$script = sprintf(
			'window.QueloraEditorConfig = { dashboardUrl: "%s", defaultActive: %s, language: "%s" };',
			esc_url( get_option( 'quelora_dashboard_url', self::DEFAULT_DASHBOARD_URL ) ),
			( (bool) get_option( 'quelora_default_active', false ) ) ? 'true' : 'false',
			esc_js( get_locale() )
		);

		wp_add_inline_script( 'quelora-sidebar', $script, 'before' );
	}

	// =========================================================================
	// JWT AUTHENTICATION HELPER
	// =========================================================================

	/**
	 * Generates a short-lived JSON Web Token (JWT) using HMAC-SHA256.
	 * Native PHP implementation to avoid external dependencies.
	 *
	 * @param string $secret The JWT Secret Key.
	 * @return string The signed JWT.
	 */
	private function generate_temp_jwt( $secret ) {
		$header  = wp_json_encode( array( 'typ' => 'JWT', 'alg' => 'HS256' ) );
		$payload = wp_json_encode( array(
			'iss' => home_url(),
			'aud' => 'quelora-api',
			'iat' => time(),
			'exp' => time() + 60, 
		) );

		$base64_url_header  = str_replace( array( '+', '/', '=' ), array( '-', '_', '' ), base64_encode( $header ) );
		$base64_url_payload = str_replace( array( '+', '/', '=' ), array( '-', '_', '' ), base64_encode( $payload ) );

		$signature            = hash_hmac( 'sha256', $base64_url_header . '.' . $base64_url_payload, $secret, true );
		$base64_url_signature = str_replace( array( '+', '/', '=' ), array( '-', '_', '' ), base64_encode( $signature ) );

		return $base64_url_header . '.' . $base64_url_payload . '.' . $base64_url_signature;
	}

	// =========================================================================
	// SPA AJAX CONTROLLERS
	// =========================================================================

	/**
	 * AJAX Handler: Re-fetches the integration configuration from the Quelora
	 * Dashboard API and updates all related wp_options in place.
	 *
	 * @return void
	 */
	public function ajax_sync_config() {
		check_ajax_referer( 'quelora_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}

		$dashboard_api_url = trim( (string) get_option( 'quelora_dashboard_api_url', '' ) );
		$cid               = trim( (string) get_option( 'quelora_client_id', '' ) );
		$secret            = trim( (string) get_option( 'quelora_sso_secret_key', '' ) );

		if ( empty( $dashboard_api_url ) || empty( $cid ) || empty( $secret ) ) {
			wp_send_json_error( array( 'message' => 'Missing connection settings. Please complete the initial setup first.' ), 400 );
		}

		$fetch_endpoint = rtrim( $dashboard_api_url, '/' ) . '/client/' . $cid . '/v1/integration/config';
		$jwt  = $this->generate_temp_jwt( $secret );
		$args = array(
			'method'  => 'POST',
			'timeout' => 15,
			'headers' => array(
				'Content-Type'  => 'application/json',
				'X-Client-ID'   => $cid,
				'Authorization' => 'Bearer ' . $jwt,
			),
			'body' => wp_json_encode( array( 'siteUrl' => get_site_url() ) ),
		);

		$response = wp_remote_post( $fetch_endpoint, $args );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => 'Network error: ' . $response->get_error_message() ), 502 );
		}

		$status = wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			wp_send_json_error( array( 'message' => 'API error (HTTP ' . $status . '). Check your connection settings.' ), 502 );
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) || ! isset( $data['config'] ) ) {
			wp_send_json_error( array( 'message' => 'Invalid response received from Quelora API.' ), 502 );
		}

		update_option( 'quelora_config_script', 'window.QUELORA_CONFIG = ' . wp_json_encode( $data['config'] ) . ';' );

		if ( ! empty( $data['apiUrl'] ) ) {
			update_option( 'quelora_api_url', esc_url_raw( $data['apiUrl'] ) );
		}
		if ( ! empty( $data['dashboardUrl'] ) ) {
			update_option( 'quelora_dashboard_url', esc_url_raw( $data['dashboardUrl'] ) );
		}

		wp_send_json_success( array( 'message' => 'Configuration synced successfully.' ) );
	}

	/**
	 * AJAX Handler: Securely fetches the JSON configuration via POST using a signed JWT.
	 * Builds the API endpoint using the separated parts of the Connection String.
	 *
	 * @return void
	 */
	public function ajax_fetch_config() {
		check_ajax_referer( 'quelora_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}

		$dashboard_api_url = isset( $_POST['dashboardApiUrl'] ) ? esc_url_raw( wp_unslash( $_POST['dashboardApiUrl'] ) ) : '';
		$cid               = isset( $_POST['cid'] ) ? sanitize_text_field( wp_unslash( $_POST['cid'] ) ) : '';
		$secret            = isset( $_POST['secret'] ) ? sanitize_text_field( wp_unslash( $_POST['secret'] ) ) : '';

		if ( empty( $dashboard_api_url ) || empty( $cid ) || empty( $secret ) ) {
			wp_send_json_error( array( 'message' => 'Dashboard API URL, Client ID, and Secret Key are required.' ), 400 );
		}

		// Dynamically build the config fetch endpoint
		$fetch_endpoint = rtrim( $dashboard_api_url, '/' ) . '/client/' . $cid . '/v1/integration/config';

		$jwt  = $this->generate_temp_jwt( $secret );
		$args = array(
			'method'  => 'POST',
			'timeout' => 15,
			'headers' => array(
				'Content-Type'  => 'application/json',
				'X-Client-ID'   => $cid,
				'Authorization' => 'Bearer ' . $jwt,
			),
			'body'    => wp_json_encode( array( 'siteUrl' => get_site_url() ) ),
		);

		$response = wp_remote_post( $fetch_endpoint, $args );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => 'Network error: ' . $response->get_error_message() ), 502 );
		}

		$status = wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			wp_send_json_error( array( 'message' => 'Server rejected credentials at ' . $fetch_endpoint . ' (HTTP ' . $status . '). Check your Connection String.' ), 401 );
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) || ! isset( $data['config'] ) ) {
			wp_send_json_error( array( 'message' => 'Invalid JSON structure received from Quelora API.' ), 502 );
		}

		wp_send_json_success( $data );
	}

	/**
	 * AJAX Handler: Performs a diagnostic test against the Main Quelora API.
	 *
	 * @return void
	 */
	public function ajax_health_check() {
		check_ajax_referer( 'quelora_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}

		$secret  = trim( (string) get_option( 'quelora_sso_secret_key', '' ) );
		$api_url = trim( (string) get_option( 'quelora_api_url', '' ) );
		$cid     = trim( (string) get_option( 'quelora_client_id', '' ) );

		if ( empty( $secret ) || empty( $api_url ) || empty( $cid ) ) {
			wp_send_json_error( array( 'message' => 'Missing Secret Key, API URL or CID.' ), 400 );
		}

		$parsed   = wp_parse_url( $api_url );
		$ping_url = $parsed['scheme'] . '://' . $parsed['host'] . ( isset( $parsed['port'] ) ? ':' . $parsed['port'] : '' ) . '/health';
		
		$args = array(
			'timeout' => 10,
			'headers' => array(
				'Authorization' => 'Bearer ' . $secret,
				'X-Client-ID'   => $cid,
			),
		);

		$response = wp_remote_get( $ping_url, $args );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => 'Network Error: ' . $response->get_error_message() ), 502 );
		}

		$status = wp_remote_retrieve_response_code( $response );
		
		if ( $status >= 200 && $status < 300 ) {
			wp_send_json_success( array( 'message' => 'Connection successful. Secret Key & CID verified.' ) );
		} else {
			wp_send_json_error( array( 'message' => 'API Error (HTTP ' . $status . '). Verification failed.' ), 401 );
		}
	}

	/**
	 * AJAX Handler: Processes and saves SPA settings, handling both Structured JSON and Regex fallback.
	 *
	 * @return void
	 */
	public function ajax_save_settings() {
		check_ajax_referer( 'quelora_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}

		$payload = isset( $_POST['payload'] ) ? json_decode( wp_unslash( $_POST['payload'] ), true ) : array();
		
		if ( ! is_array( $payload ) ) {
			wp_send_json_error( array( 'message' => 'Invalid payload format.' ), 400 );
		}

		if ( isset( $payload['wizard'] ) && true === $payload['wizard'] ) {
			$this->process_wizard_submission( $payload );
		} else {
			$this->process_standard_submission( $payload );
		}

		wp_send_json_success( array( 'message' => 'Settings saved successfully.' ) );
	}

	/**
	 * Processes the Quick Setup Wizard payload.
	 *
	 * @param array $payload Request body.
	 * @return void
	 */
	private function process_wizard_submission( $payload ) {
		// Universally save provided secrets and client IDs
		if ( ! empty( $payload['ssoSecretKey'] ) ) {
			update_option( 'quelora_sso_secret_key', sanitize_text_field( $payload['ssoSecretKey'] ) );
		}
		if ( ! empty( $payload['cid'] ) ) {
			update_option( 'quelora_client_id', sanitize_text_field( $payload['cid'] ) );
		}

		update_option( 'quelora_default_active', 1 );
		update_option( 'quelora_hide_wp_comments', 1 );
		update_option( 'quelora_asset_source', 'local' );

		// SCENARIO A: Secure JSON API Fetch
		if ( isset( $payload['configObject'] ) && is_array( $payload['configObject'] ) ) {
			
			$config_data = $payload['configObject'];
			update_option( 'quelora_config_script', 'window.QUELORA_CONFIG = ' . wp_json_encode( $config_data ) . ';' );

			if ( isset( $payload['dashboardApiUrl'] ) ) {
				$dashboard_api_url = rtrim( esc_url_raw( $payload['dashboardApiUrl'] ), '/' );
				update_option( 'quelora_dashboard_api_url', $dashboard_api_url );
			}

			if ( isset( $config_data['login']['queloraSession'] ) ) {
				$quelora_session = filter_var( $config_data['login']['queloraSession'], FILTER_VALIDATE_BOOLEAN );
				update_option( 'quelora_sso_enabled', ! $quelora_session );
			}

			if ( ! empty( $payload['dashboardUrl'] ) ) {
				update_option( 'quelora_dashboard_url', esc_url_raw( $payload['dashboardUrl'] ) );
			} elseif ( ! empty( $config_data['dashboardUrl'] ) ) {
				update_option( 'quelora_dashboard_url', esc_url_raw( $config_data['dashboardUrl'] ) );
			}

			if ( ! empty( $payload['apiUrl'] ) ) {
				update_option( 'quelora_api_url', esc_url_raw( rtrim( $payload['apiUrl'], '/' ) ) );
			}

			// Build sync endpoints from the dashboard API URL and CID (always available from the connection string).
			$cid = isset( $payload['cid'] ) ? sanitize_text_field( $payload['cid'] ) : '';
			if ( ! empty( $dashboard_api_url ) && ! empty( $cid ) ) {
				update_option( 'quelora_sync_posts_endpoint', $dashboard_api_url . '/client/' . $cid . '/v1/nodes/batch-upsert' );
				update_option( 'quelora_sync_users_endpoint', $dashboard_api_url . '/client/' . $cid . '/v1/profiles/batch-upsert' );
			}

			update_option( 'quelora_cdn_js_url', 'https://cdn.quelora.org/js/quelora.js' );
			update_option( 'quelora_cdn_css_url', 'https://cdn.quelora.org/css/quelora.css' );

		} 
		// SCENARIO B: Manual File Upload Fallback
		else {
			$file_content = isset( $payload['configScriptRaw'] ) ? $payload['configScriptRaw'] : '';
			
			if ( preg_match( '/window\.QUELORA_CONFIG\s*=\s*(\{.*?\});/s', $file_content, $matches ) ) {
				$json_string = $matches[1];
				$config_data = json_decode( $json_string, true );

				if ( is_array( $config_data ) ) {
					update_option( 'quelora_config_script', 'window.QUELORA_CONFIG = ' . $json_string . ';' );

					// If the config object contains a CID, it must match the manually entered one (or use it if missing)
					if ( isset( $config_data['cid'] ) && empty( $payload['cid'] ) ) {
						update_option( 'quelora_client_id', sanitize_text_field( $config_data['cid'] ) );
					}

					if ( isset( $config_data['login']['queloraSession'] ) ) {
						$quelora_session = filter_var( $config_data['login']['queloraSession'], FILTER_VALIDATE_BOOLEAN );
						update_option( 'quelora_sso_enabled', ! $quelora_session );
					}

					$api_url = isset( $config_data['apiUrl'] ) ? rtrim( $config_data['apiUrl'], '/' ) : '';
					if ( ! empty( $api_url ) ) {
						update_option( 'quelora_api_url', esc_url_raw( $api_url ) );
						update_option( 'quelora_dashboard_api_url', esc_url_raw( $api_url ) ); // Fallback
						
						$cid = sanitize_text_field( get_option('quelora_client_id') );
						update_option( 'quelora_sync_posts_endpoint', $api_url . '/client/' . $cid . '/v1/nodes/batch-upsert' );
						update_option( 'quelora_sync_users_endpoint', $api_url . '/client/' . $cid . '/v1/profiles/batch-upsert' );
					}
				}
			} else {
				update_option( 'quelora_config_script', $file_content );
			}

			if ( preg_match( '/script\.src\s*=\s*[\'"]([^\'"]+)[\'"]/', $file_content, $script_matches ) ) {
				$js_url = esc_url_raw( $script_matches[1] );
				update_option( 'quelora_cdn_js_url', $js_url );
				update_option( 'quelora_cdn_css_url', esc_url_raw( str_replace( '/js/quelora.js', '/css/quelora.css', $js_url ) ) );
			}
		}

		update_option( 'quelora_is_configured', 1 );
	}

	/**
	 * Processes standard key-value option updates from the SPA.
	 *
	 * @param array $payload Request body.
	 * @return void
	 */
	private function process_standard_submission( $payload ) {
		$key_map = array(
			'clientId'          => 'quelora_client_id',
			'apiUrl'            => 'quelora_api_url',
			'dashboardApiUrl'   => 'quelora_dashboard_api_url',
			'dashboardUrl'      => 'quelora_dashboard_url',
			'assetSource'       => 'quelora_asset_source',
			'cdnJsUrl'          => 'quelora_cdn_js_url',
			'cdnCssUrl'         => 'quelora_cdn_css_url',
			'headerSelector'    => 'quelora_header_selector',
			'configScript'      => 'quelora_config_script',
			'customCss'         => 'quelora_custom_css',
			'defaultActive'     => 'quelora_default_active',
			'placementStrategy' => 'quelora_placement_strategy',
			'hideWpComments'    => 'quelora_hide_wp_comments',
			'ssoEnabled'        => 'quelora_sso_enabled',
			'ssoSecretKey'      => 'quelora_sso_secret_key',
			'ssoTokenTtl'       => 'quelora_sso_token_ttl',
			'syncPostsEndpoint' => 'quelora_sync_posts_endpoint',
			'syncUsersEndpoint' => 'quelora_sync_users_endpoint',
		);

		foreach ( $key_map as $react_key => $db_key ) {
			if ( isset( $payload[ $react_key ] ) ) {
				$val = $payload[ $react_key ];

				if ( is_bool( $val ) ) {
					update_option( $db_key, (bool) $val );
				} elseif ( is_numeric( $val ) ) {
					update_option( $db_key, absint( $val ) );
				} elseif ( in_array( $react_key, array( 'configScript', 'customCss' ), true ) ) {
					update_option( $db_key, wp_unslash( (string) $val ) );
				} elseif ( strpos( strtolower( $react_key ), 'url' ) !== false || strpos( strtolower( $react_key ), 'endpoint' ) !== false ) {
					update_option( $db_key, esc_url_raw( (string) $val ) );
				} else {
					update_option( $db_key, sanitize_text_field( (string) $val ) );
				}
			}
		}
	}
}