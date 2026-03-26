<?php
// filepath: includes/class-quelora-admin.php

/**
 * Class Quelora_Admin
 *
 * Handles the administrative interface, settings registration, and script
 * enqueuing for the Gutenberg editor. It also provides the UI controls for
 * the background synchronization processes, including live polling and abort mechanisms.
 * Includes an advanced Quick Setup Wizard with an autonomous RegEx parser 
 * to extract and auto-configure settings from the Quelora system JS file.
 *
 * @package Quelora
 */
class Quelora_Admin {

	const DEFAULT_DASHBOARD_URL = 'https://dashboard.quelora.local/embed/post/';
	const DEFAULT_HEADER_SELECTOR = '.site-header-primary-section-right';
	const DEFAULT_TOKEN_TTL = 3600;
	const DEFAULT_SSO_ENABLED = false;
	const DEFAULT_PLACEMENT_STRATEGY = 'both';
	const DEFAULT_CUSTOM_CSS = '';
	const SYNC_BATCH_SIZE = 100;

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
	 * Registers plugin settings and handles the Quick Setup Wizard payload.
	 * Parses the uploaded JS file to autonomously configure SSO, API Endpoints, and CDN.
	 *
	 * @return void
	 */
	public function register_settings() {
		if ( isset( $_POST['quelora_quick_setup_submit'] ) && check_admin_referer( 'quelora_quick_setup', 'quelora_quick_setup_nonce' ) ) {
			if ( current_user_can( 'manage_options' ) ) {
				$secret = isset( $_POST['quelora_sso_secret_key'] ) ? sanitize_text_field( wp_unslash( $_POST['quelora_sso_secret_key'] ) ) : '';
				$dash   = isset( $_POST['quelora_dashboard_url'] ) ? esc_url_raw( wp_unslash( $_POST['quelora_dashboard_url'] ) ) : '';

				if ( ! empty( $secret ) ) {
					update_option( 'quelora_sso_secret_key', $secret );
				}

				if ( ! empty( $dash ) ) {
					update_option( 'quelora_dashboard_url', $dash );
				}

				// Valores forzados por defecto según directivas de despliegue
				update_option( 'quelora_default_active', 1 );
				update_option( 'quelora_hide_wp_comments', 1 );
				update_option( 'quelora_asset_source', 'local' );

				if ( isset( $_FILES['quelora_config_file'] ) && ! empty( $_FILES['quelora_config_file']['tmp_name'] ) ) {
					$file_content = file_get_contents( sanitize_text_field( wp_unslash( $_FILES['quelora_config_file']['tmp_name'] ) ) );
					
					if ( false !== $file_content ) {
						
						// Extract JSON configuration payload ignoring the IIFE wrapper
						if ( preg_match( '/window\.QUELORA_CONFIG\s*=\s*(\{.*?\});/s', $file_content, $matches ) ) {
							$json_string = $matches[1];
							$config_data = json_decode( $json_string, true );

							if ( is_array( $config_data ) ) {
								// Persist only the declarative configuration object to prevent double-enqueuing the JS
								update_option( 'quelora_config_script', 'window.QUELORA_CONFIG = ' . $json_string . ';' );

								if ( isset( $config_data['login']['queloraSession'] ) ) {
									$quelora_session = filter_var( $config_data['login']['queloraSession'], FILTER_VALIDATE_BOOLEAN );
									// Lógica invertida: Si queloraSession es false, habilitamos el SSO de WordPress
									update_option( 'quelora_sso_enabled', ! $quelora_session );
								}

								if ( ! empty( $config_data['apiUrl'] ) ) {
									$api_url = rtrim( $config_data['apiUrl'], '/' );
									update_option( 'quelora_sync_posts_endpoint', $api_url . '/v1/nodes/batch-upsert' );
									update_option( 'quelora_sync_users_endpoint', $api_url . '/v1/profiles/batch-upsert' );
								}
							}
						} else {
							update_option( 'quelora_config_script', wp_unslash( $file_content ) );
						}

						// Extract CDN Script URL from the appended IIFE
						if ( preg_match( '/script\.src\s*=\s*[\'"]([^\'"]+)[\'"]/', $file_content, $script_matches ) ) {
							$js_url = esc_url_raw( $script_matches[1] );
							update_option( 'quelora_cdn_js_url', $js_url );
							
							$css_url = str_replace( '/js/quelora.js', '/css/quelora.css', $js_url );
							update_option( 'quelora_cdn_css_url', esc_url_raw( $css_url ) );
						}
					}
				}

				update_option( 'quelora_is_configured', 1 );
				
				wp_safe_redirect( admin_url( 'admin.php?page=quelora-settings' ) );
				exit;
			}
		}

		register_setting( 'quelora_settings_group', 'quelora_dashboard_url' );
		register_setting( 'quelora_settings_group', 'quelora_asset_source' );
		register_setting( 'quelora_settings_group', 'quelora_cdn_js_url' );
		register_setting( 'quelora_settings_group', 'quelora_cdn_css_url' );
		register_setting( 'quelora_settings_group', 'quelora_header_selector' );

		register_setting(
			'quelora_settings_group',
			'quelora_config_script',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_config_script' ),
				'default'           => '',
			)
		);

		register_setting(
			'quelora_settings_group',
			'quelora_custom_css',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_custom_css' ),
				'default'           => self::DEFAULT_CUSTOM_CSS,
			)
		);

		register_setting(
			'quelora_settings_group',
			'quelora_default_active',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => false,
			)
		);

		register_setting(
			'quelora_settings_group',
			'quelora_placement_strategy',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_placement_strategy' ),
				'default'           => self::DEFAULT_PLACEMENT_STRATEGY,
			)
		);

		register_setting(
			'quelora_settings_group',
			'quelora_hide_wp_comments',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => false,
			)
		);

		register_setting(
			'quelora_settings_group',
			'quelora_sso_enabled',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => self::DEFAULT_SSO_ENABLED,
			)
		);

		register_setting(
			'quelora_settings_group',
			'quelora_sso_secret_key',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);

		register_setting(
			'quelora_settings_group',
			'quelora_sso_token_ttl',
			array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => self::DEFAULT_TOKEN_TTL,
			)
		);

		register_setting(
			'quelora_settings_group',
			'quelora_sync_posts_endpoint',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'esc_url_raw',
				'default'           => '',
			)
		);

		register_setting(
			'quelora_settings_group',
			'quelora_sync_users_endpoint',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'esc_url_raw',
				'default'           => '',
			)
		);
	}

	/**
	 * Sanitizes the dynamic configuration script.
	 *
	 * @param string $value The raw script string.
	 * @return string Sanitized script string.
	 */
	public function sanitize_config_script( $value ) {
		return wp_unslash( (string) $value );
	}

	/**
	 * Sanitizes the custom CSS.
	 *
	 * @param string $value The raw CSS string.
	 * @return string Sanitized CSS string.
	 */
	public function sanitize_custom_css( $value ) {
		return wp_unslash( (string) $value );
	}

	/**
	 * Sanitizes the placement strategy option.
	 *
	 * @param string $value The raw strategy string.
	 * @return string A validated strategy string.
	 */
	public function sanitize_placement_strategy( $value ) {
		$allowed = array( 'content', 'comment_form', 'both', 'iife_only' );
		$clean   = sanitize_text_field( wp_unslash( (string) $value ) );
		return in_array( $clean, $allowed, true ) ? $clean : self::DEFAULT_PLACEMENT_STRATEGY;
	}

	/**
	 * Renders the Quick Setup Wizard.
	 *
	 * @return void
	 */
	private function render_quick_setup_wizard() {
		?>
		<div class="wrap quelora-settings-wrap">
			<h1 class="quelora-settings-title">
				<span style="display:inline-flex;align-items:center;gap:10px;">
					<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="28" height="28" style="flex-shrink:0">
						<rect width="100" height="100" rx="18" fill="#3b82c4"/>
						<circle cx="45" cy="45" r="22" fill="none" stroke="#fff" stroke-width="16"/>
						<line x1="56" y1="56" x2="76" y2="76" stroke="#fff" stroke-width="16" stroke-linecap="round"/>
					</svg>
					<?php esc_html_e( 'Quelora Integration - Quick Setup', 'quelora' ); ?>
				</span>
			</h1>

			<div class="quelora-card">
				<h2 class="quelora-card__title"><?php esc_html_e( 'Welcome to Quelora', 'quelora' ); ?></h2>
				<p class="quelora-card__description">
					<?php esc_html_e( 'Please provide the basic details below to initialize the plugin. You can upload the JS configuration file provided by the Quelora backend, and the system will automatically extract and apply the settings.', 'quelora' ); ?>
				</p>

				<form method="post" action="" enctype="multipart/form-data">
					<?php wp_nonce_field( 'quelora_quick_setup', 'quelora_quick_setup_nonce' ); ?>

					<div class="quelora-field-row">
						<label for="quelora_sso_secret_key" class="quelora-field-row__label">
							<?php esc_html_e( 'JWT Secret Key', 'quelora' ); ?>
						</label>
						<div class="quelora-field-row__control">
							<input type="password" id="quelora_sso_secret_key" name="quelora_sso_secret_key" class="regular-text" required autocomplete="new-password" />
							<p class="description">
								<?php esc_html_e( 'The HMAC-SHA256 secret used to sign the identity JWT and authenticate backend requests.', 'quelora' ); ?>
							</p>
						</div>
					</div>

					<div class="quelora-field-row">
						<label for="quelora_dashboard_url" class="quelora-field-row__label">
							<?php esc_html_e( 'Dashboard URL', 'quelora' ); ?>
						</label>
						<div class="quelora-field-row__control">
							<input type="url" id="quelora_dashboard_url" name="quelora_dashboard_url" class="regular-text" required placeholder="https://dashboard.quelora.example/embed/post/" />
							<p class="description">
								<?php esc_html_e( 'The base embed URL for the Quelora backend dashboard.', 'quelora' ); ?>
							</p>
						</div>
					</div>

					<div class="quelora-field-row">
						<label for="quelora_config_file" class="quelora-field-row__label">
							<?php esc_html_e( 'Configuration File', 'quelora' ); ?>
						</label>
						<div class="quelora-field-row__control">
							<input type="file" id="quelora_config_file" name="quelora_config_file" accept=".js" required />
							<p class="description">
								<?php esc_html_e( 'Upload the .js configuration file provided by the Quelora wizard. The script contents will be extracted and saved automatically.', 'quelora' ); ?>
							</p>
						</div>
					</div>

					<div style="margin-top: 20px;">
						<button type="submit" name="quelora_quick_setup_submit" class="button button-primary button-large">
							<?php esc_html_e( 'Complete Setup', 'quelora' ); ?>
						</button>
					</div>
				</form>
			</div>
		</div>
		<style>
			.quelora-settings-wrap { max-width: 900px; }
			.quelora-settings-title { display:flex; align-items:center; margin-bottom:16px; }
			.quelora-card { background: #fff; border: 1px solid #dcdcde; border-radius: 4px; padding: 20px 24px; margin: 20px 0; }
			.quelora-card__title { margin: 0 0 8px; font-size: 14px; font-weight: 600; color: #1d2327; }
			.quelora-card__description { margin: 0 0 20px; color: #646970; font-size: 13px; line-height: 1.6; }
			.quelora-field-row { display: flex; align-items: flex-start; gap: 16px; padding: 12px 0; border-bottom: 1px solid #f0f0f1; }
			.quelora-field-row:last-child { border-bottom: none; }
			.quelora-field-row__label { flex-shrink: 0; width: 160px; font-size: 13px; font-weight: 600; padding-top: 5px; }
			.quelora-field-row__control { flex: 1; }
			.quelora-field-row__control .description { margin: 6px 0 0; font-size: 12px; color: #646970; }
		</style>
		<?php
	}

	/**
	 * Renders the main settings page or intercepts it to show the Quick Setup Wizard.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! get_option( 'quelora_is_configured', false ) ) {
			$this->render_quick_setup_wizard();
			return;
		}

		$current_strategy = get_option( 'quelora_placement_strategy', self::DEFAULT_PLACEMENT_STRATEGY );
		$sso_enabled      = (bool) get_option( 'quelora_sso_enabled', self::DEFAULT_SSO_ENABLED );
		$asset_source     = get_option( 'quelora_asset_source', 'local' ); // fallback default

		// Sync tab initial state compute
		$sync_posts_raw    = (string) get_option( 'quelora_sync_posts_status', 'idle' );
		$sync_posts_synced = (int) get_option( 'quelora_sync_posts_synced', 0 );
		$sync_posts_total  = (int) get_option( 'quelora_sync_posts_total', 0 );
		$sync_posts_last   = (int) get_option( 'quelora_sync_posts_last_run', 0 );
		$sync_users_raw    = (string) get_option( 'quelora_sync_users_status', 'idle' );
		$sync_users_synced = (int) get_option( 'quelora_sync_users_synced', 0 );
		$sync_users_total  = (int) get_option( 'quelora_sync_users_total', 0 );
		$sync_users_last   = (int) get_option( 'quelora_sync_users_last_run', 0 );

		$status_map = array( 'idle' => 'Idle', 'running' => 'Running…', 'complete' => 'Complete', 'aborted' => 'Aborted' );

		$sync_posts_label = isset( $status_map[ $sync_posts_raw ] )
			? $status_map[ $sync_posts_raw ]
			: ( 0 === strpos( $sync_posts_raw, 'error:' ) ? 'Error: ' . substr( $sync_posts_raw, 6 ) : $sync_posts_raw );

		$sync_users_label = isset( $status_map[ $sync_users_raw ] )
			? $status_map[ $sync_users_raw ]
			: ( 0 === strpos( $sync_users_raw, 'error:' ) ? 'Error: ' . substr( $sync_users_raw, 6 ) : $sync_users_raw );

		$sync_posts_pct      = $sync_posts_total > 0 ? min( 100, (int) round( $sync_posts_synced / $sync_posts_total * 100 ) ) : 0;
		$sync_users_pct      = $sync_users_total > 0 ? min( 100, (int) round( $sync_users_synced / $sync_users_total * 100 ) ) : 0;
		$sync_posts_last_str = $sync_posts_last > 0 ? gmdate( 'Y-m-d H:i:s', $sync_posts_last ) . ' UTC' : '—';
		$sync_users_last_str = $sync_users_last > 0 ? gmdate( 'Y-m-d H:i:s', $sync_users_last ) . ' UTC' : '—';
		$sync_nonce          = wp_create_nonce( 'quelora_sync_nonce' );
		?>
		<div class="wrap quelora-settings-wrap">
			<h1 class="quelora-settings-title">
				<span style="display:inline-flex;align-items:center;gap:10px;">
					<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="28" height="28" style="flex-shrink:0">
						<rect width="100" height="100" rx="18" fill="#3b82c4"/>
						<circle cx="45" cy="45" r="22" fill="none" stroke="#fff" stroke-width="16"/>
						<line x1="56" y1="56" x2="76" y2="76" stroke="#fff" stroke-width="16" stroke-linecap="round"/>
					</svg>
					<?php esc_html_e( 'Quelora Integration', 'quelora' ); ?>
					<span style="font-size:13px;font-weight:400;color:#777;margin-left:4px;">v<?php echo esc_html( QUELORA_VERSION ); ?></span>
				</span>
			</h1>

			<?php settings_errors( 'quelora_settings_group' ); ?>

			<nav class="nav-tab-wrapper quelora-tab-nav" id="quelora-tabs">
				<a href="#tab-general"       class="nav-tab nav-tab-active" data-tab="tab-general">
					<?php esc_html_e( 'General', 'quelora' ); ?>
				</a>
				<a href="#tab-integration"   class="nav-tab" data-tab="tab-integration">
					<?php esc_html_e( 'Integration', 'quelora' ); ?>
					<?php if ( $sso_enabled ) : ?>
						<span class="quelora-badge quelora-badge--active"><?php esc_html_e( 'Active', 'quelora' ); ?></span>
					<?php endif; ?>
				</a>
				<a href="#tab-assets"        class="nav-tab" data-tab="tab-assets">
					<?php esc_html_e( 'Assets', 'quelora' ); ?>
				</a>
				<a href="#tab-customisation" class="nav-tab" data-tab="tab-customisation">
					<?php esc_html_e( 'Customisation', 'quelora' ); ?>
				</a>
				<a href="#tab-sync"          class="nav-tab" data-tab="tab-sync">
					<?php esc_html_e( 'Sync', 'quelora' ); ?>
					<span id="quelora-tab-sync-badge" class="quelora-badge quelora-badge--running" style="<?php echo ('running' === $sync_posts_raw || 'running' === $sync_users_raw) ? '' : 'display:none;'; ?>">
						<?php esc_html_e( 'Running', 'quelora' ); ?>
					</span>
				</a>
			</nav>

			<form method="post" action="options.php" id="quelora-settings-form">
				<?php settings_fields( 'quelora_settings_group' ); ?>

				<div id="tab-general" class="quelora-tab-panel">
					<div class="quelora-card">
						<h2 class="quelora-card__title"><?php esc_html_e( 'Activation', 'quelora' ); ?></h2>
						<p class="quelora-card__description">
							<?php esc_html_e( 'Control whether Quelora is enabled by default across all posts, or must be toggled on per-post from the block editor sidebar.', 'quelora' ); ?>
						</p>

						<label class="quelora-toggle-row">
							<div class="quelora-toggle-row__text">
								<span class="quelora-toggle-row__label"><?php esc_html_e( 'Enable Quelora by default', 'quelora' ); ?></span>
								<span class="quelora-toggle-row__help"><?php esc_html_e( 'Quelora assets are injected on every post unless explicitly disabled per-post in the editor sidebar.', 'quelora' ); ?></span>
							</div>
							<input type="checkbox" name="quelora_default_active" value="1"
								<?php checked( get_option( 'quelora_default_active', false ) ); ?> />
						</label>

						<label class="quelora-toggle-row">
							<div class="quelora-toggle-row__text">
								<span class="quelora-toggle-row__label"><?php esc_html_e( 'Hide WordPress comments', 'quelora' ); ?></span>
								<span class="quelora-toggle-row__help"><?php esc_html_e( 'Suppresses native WordPress comment threads and the reply form when Quelora is active on a post. The #comments container is preserved so Quelora anchors remain reachable.', 'quelora' ); ?></span>
							</div>
							<input type="checkbox" name="quelora_hide_wp_comments" value="1"
								<?php checked( get_option( 'quelora_hide_wp_comments', false ) ); ?> />
						</label>
					</div>

					<div class="quelora-card">
						<h2 class="quelora-card__title"><?php esc_html_e( 'Placement strategy', 'quelora' ); ?></h2>
						<p class="quelora-card__description">
							<?php esc_html_e( 'The IIFE script always injects interaction-bar anchors client-side (Layer 1). These options control the additional server-side injection via WordPress hooks (Layer 2).', 'quelora' ); ?>
						</p>

						<fieldset class="quelora-radio-group">
							<label class="quelora-radio-row">
								<input type="radio" name="quelora_placement_strategy" value="both"
									<?php checked( $current_strategy, 'both' ); ?> />
								<span class="quelora-radio-row__content">
									<strong><?php esc_html_e( 'Both hooks', 'quelora' ); ?></strong>
									<span><?php esc_html_e( 'Fires both the_content filter and comment_form_before action. Recommended for most themes.', 'quelora' ); ?></span>
								</span>
							</label>

							<label class="quelora-radio-row">
								<input type="radio" name="quelora_placement_strategy" value="content"
									<?php checked( $current_strategy, 'content' ); ?> />
								<span class="quelora-radio-row__content">
									<strong><?php esc_html_e( 'Content filter only', 'quelora' ); ?></strong>
									<span><?php esc_html_e( 'Appends the anchor at the end of post content via the_content filter.', 'quelora' ); ?></span>
								</span>
							</label>

							<label class="quelora-radio-row">
								<input type="radio" name="quelora_placement_strategy" value="comment_form"
									<?php checked( $current_strategy, 'comment_form' ); ?> />
								<span class="quelora-radio-row__content">
									<strong><?php esc_html_e( 'Comment form only', 'quelora' ); ?></strong>
									<span><?php esc_html_e( 'Injects the anchor before the WordPress comment form on single posts.', 'quelora' ); ?></span>
								</span>
							</label>

							<label class="quelora-radio-row">
								<input type="radio" name="quelora_placement_strategy" value="iife_only"
									<?php checked( $current_strategy, 'iife_only' ); ?> />
								<span class="quelora-radio-row__content">
									<strong><?php esc_html_e( 'IIFE only', 'quelora' ); ?></strong>
									<span><?php esc_html_e( 'Disables all PHP hooks. The client-side IIFE script is the sole injector.', 'quelora' ); ?></span>
								</span>
							</label>
						</fieldset>
					</div>

					<div class="quelora-card">
						<h2 class="quelora-card__title"><?php esc_html_e( 'Mount points', 'quelora' ); ?></h2>
						<p class="quelora-card__description">
							<?php esc_html_e( 'Configure where Quelora inserts its entry points into your theme\'s HTML structure.', 'quelora' ); ?>
						</p>

						<div class="quelora-field-row">
							<label for="quelora_dashboard_url" class="quelora-field-row__label">
								<?php esc_html_e( 'Dashboard URL', 'quelora' ); ?>
							</label>
							<div class="quelora-field-row__control">
								<input type="url" id="quelora_dashboard_url" name="quelora_dashboard_url"
									value="<?php echo esc_attr( get_option( 'quelora_dashboard_url', self::DEFAULT_DASHBOARD_URL ) ); ?>"
									class="regular-text" />
								<p class="description">
									<?php
									printf(
										esc_html__( 'Base embed URL for the Quelora backend dashboard. The post node ID is appended automatically (e.g. %s).', 'quelora' ),
										'<code>' . esc_html( rtrim( self::DEFAULT_DASHBOARD_URL, '/' ) . '/&lt;nodeId&gt;' ) . '</code>'
									);
									?>
								</p>
							</div>
						</div>

						<div class="quelora-field-row">
							<label for="quelora_header_selector" class="quelora-field-row__label">
								<?php esc_html_e( 'Header widget selector', 'quelora' ); ?>
							</label>
							<div class="quelora-field-row__control">
								<input type="text" id="quelora_header_selector" name="quelora_header_selector"
									value="<?php echo esc_attr( get_option( 'quelora_header_selector', self::DEFAULT_HEADER_SELECTOR ) ); ?>"
									class="regular-text code" />
								<p class="description">
									<?php
									printf(
										esc_html__( 'CSS selector for the header container where Quelora injects %2$s. Default: %1$s.', 'quelora' ),
										'<code>' . esc_html( self::DEFAULT_HEADER_SELECTOR ) . '</code>',
										'<code>&lt;div id="quelora-header-widget"&gt;&lt;/div&gt;</code>'
									);
									?>
								</p>
							</div>
						</div>
					</div>
				</div>

				<div id="tab-integration" class="quelora-tab-panel quelora-tab-panel--hidden">
					<div class="quelora-card">
						<h2 class="quelora-card__title"><?php esc_html_e( 'External session', 'quelora' ); ?></h2>
						<p class="quelora-card__description">
							<?php esc_html_e( 'When enabled, Quelora generates a signed identity token from the current WordPress user and uses it to authenticate with the Quelora backend seamlessly. The Quelora internal authentication circuit (login modal, registration, social providers) is disabled — users are redirected to WordPress\' native login page instead.', 'quelora' ); ?>
						</p>

						<label class="quelora-toggle-row quelora-toggle-row--prominent">
							<div class="quelora-toggle-row__text">
								<span class="quelora-toggle-row__label"><?php esc_html_e( 'Enable WordPress SSO', 'quelora' ); ?></span>
								<span class="quelora-toggle-row__help"><?php esc_html_e( 'Only the authenticated user\'s display name and email address are shared with Quelora. Passwords and session cookies are never transmitted.', 'quelora' ); ?></span>
							</div>
							<input type="checkbox" id="quelora_sso_enabled" name="quelora_sso_enabled" value="1"
								<?php checked( $sso_enabled ); ?> />
						</label>
					</div>

					<div class="quelora-card quelora-card--dependent" id="quelora-sso-fields" <?php echo $sso_enabled ? '' : 'style="opacity:.55;pointer-events:none;"'; ?>>
						<h2 class="quelora-card__title"><?php esc_html_e( 'Global Integration Secret', 'quelora' ); ?></h2>
						<p class="quelora-card__description">
							<?php esc_html_e( 'Configure the HMAC-SHA256 secret used to sign the identity JWT and authenticate backend synchronization requests. The secret must match exactly what is set in the Quelora backend.', 'quelora' ); ?>
						</p>

						<div class="quelora-field-row">
							<label for="quelora_sso_secret_key" class="quelora-field-row__label">
								<?php esc_html_e( 'Shared secret key', 'quelora' ); ?>
							</label>
							<div class="quelora-field-row__control">
								<input type="password" id="quelora_sso_secret_key" name="quelora_sso_secret_key"
									value="<?php echo esc_attr( get_option( 'quelora_sso_secret_key', '' ) ); ?>"
									class="regular-text" autocomplete="new-password" />
								<p class="description">
									<?php esc_html_e( 'This master secret is used for both SSO JWT signing and as a Bearer token for background sync operations. Treat this like a password.', 'quelora' ); ?>
								</p>
							</div>
						</div>

						<div class="quelora-field-row">
							<label for="quelora_sso_token_ttl" class="quelora-field-row__label">
								<?php esc_html_e( 'Token lifetime', 'quelora' ); ?>
							</label>
							<div class="quelora-field-row__control">
								<input type="number" id="quelora_sso_token_ttl" name="quelora_sso_token_ttl"
									value="<?php echo esc_attr( get_option( 'quelora_sso_token_ttl', self::DEFAULT_TOKEN_TTL ) ); ?>"
									class="small-text" min="60" step="60" />
								<span class="quelora-field-suffix"><?php esc_html_e( 'seconds', 'quelora' ); ?></span>
								<p class="description">
									<?php
									printf(
										esc_html__( 'How long each signed token remains valid. Default: %s (1 hour).', 'quelora' ),
										'<code>' . esc_html( self::DEFAULT_TOKEN_TTL ) . '</code>'
									);
									?>
								</p>
							</div>
						</div>
					</div>
				</div>

				<div id="tab-assets" class="quelora-tab-panel quelora-tab-panel--hidden">
					<div class="quelora-card">
						<h2 class="quelora-card__title"><?php esc_html_e( 'Asset source', 'quelora' ); ?></h2>
						<p class="quelora-card__description">
							<?php esc_html_e( 'Choose whether to load the Quelora JS and CSS from the bundled local copy or from an external CDN.', 'quelora' ); ?>
						</p>

						<fieldset class="quelora-radio-group">
							<label class="quelora-radio-row">
								<input type="radio" id="asset_source_cdn" name="quelora_asset_source" value="cdn"
									<?php checked( $asset_source, 'cdn' ); ?> />
								<span class="quelora-radio-row__content">
									<strong><?php esc_html_e( 'External CDN', 'quelora' ); ?></strong>
									<span><?php esc_html_e( 'Load assets from the URLs specified below. Best for production.', 'quelora' ); ?></span>
								</span>
							</label>

							<label class="quelora-radio-row">
								<input type="radio" id="asset_source_local" name="quelora_asset_source" value="local"
									<?php checked( $asset_source, 'local' ); ?> />
								<span class="quelora-radio-row__content">
									<strong><?php esc_html_e( 'Local package', 'quelora' ); ?></strong>
									<span><?php esc_html_e( 'Load assets bundled with this plugin. Best for offline or staging environments.', 'quelora' ); ?></span>
								</span>
							</label>
						</fieldset>
					</div>

					<div class="quelora-card quelora-card--dependent" id="quelora-cdn-fields" <?php echo 'cdn' === $asset_source ? '' : 'style="opacity:.55;pointer-events:none;"'; ?>>
						<h2 class="quelora-card__title"><?php esc_html_e( 'CDN URLs', 'quelora' ); ?></h2>

						<div class="quelora-field-row">
							<label for="quelora_cdn_js_url" class="quelora-field-row__label">
								<?php esc_html_e( 'JS bundle URL', 'quelora' ); ?>
							</label>
							<div class="quelora-field-row__control">
								<input type="url" id="quelora_cdn_js_url" name="quelora_cdn_js_url"
									value="<?php echo esc_attr( get_option( 'quelora_cdn_js_url', 'https://cdn.quelora.org/quelora.min.js' ) ); ?>"
									class="regular-text" />
							</div>
						</div>

						<div class="quelora-field-row">
							<label for="quelora_cdn_css_url" class="quelora-field-row__label">
								<?php esc_html_e( 'CSS bundle URL', 'quelora' ); ?>
							</label>
							<div class="quelora-field-row__control">
								<input type="url" id="quelora_cdn_css_url" name="quelora_cdn_css_url"
									value="<?php echo esc_attr( get_option( 'quelora_cdn_css_url', 'https://cdn.quelora.org/css/quelora.css' ) ); ?>"
									class="regular-text" />
							</div>
						</div>
					</div>
				</div>

				<div id="tab-customisation" class="quelora-tab-panel quelora-tab-panel--hidden">
					<div class="quelora-card">
						<h2 class="quelora-card__title"><?php esc_html_e( 'Configuration script', 'quelora' ); ?></h2>
						<div class="quelora-field-row quelora-field-row--vertical">
							<textarea name="quelora_config_script" rows="16" class="large-text code quelora-code-editor"
								spellcheck="false"><?php echo esc_textarea( get_option( 'quelora_config_script', '' ) ); ?></textarea>
						</div>
					</div>

					<div class="quelora-card">
						<h2 class="quelora-card__title"><?php esc_html_e( 'Custom CSS', 'quelora' ); ?></h2>
						<div class="quelora-field-row quelora-field-row--vertical">
							<textarea name="quelora_custom_css" rows="12" class="large-text code quelora-code-editor"
								spellcheck="false"><?php echo esc_textarea( get_option( 'quelora_custom_css', self::DEFAULT_CUSTOM_CSS ) ); ?></textarea>
						</div>
					</div>
				</div>

				<div id="tab-sync" class="quelora-tab-panel quelora-tab-panel--hidden">
					<input type="hidden" id="quelora-sync-nonce" value="<?php echo esc_attr( $sync_nonce ); ?>" />

					<div class="quelora-infobox quelora-infobox--info" style="margin-top:20px;">
						<span class="dashicons dashicons-info-outline"></span>
						<div>
							<strong><?php esc_html_e( 'Authentication Notice', 'quelora' ); ?></strong>
							<p>
								<?php esc_html_e( 'Sync operations now use the Global Integration Secret (Shared secret key) configured in the Integration tab as the Authorization Bearer token. Ensure it is set before starting.', 'quelora' ); ?>
							</p>
						</div>
					</div>

					<div class="quelora-card">
						<h2 class="quelora-card__title"><?php esc_html_e( 'Post Data Sync', 'quelora' ); ?></h2>
						<div class="quelora-field-row">
							<label for="quelora_sync_posts_endpoint" class="quelora-field-row__label">
								<?php esc_html_e( 'Endpoint URL', 'quelora' ); ?>
							</label>
							<div class="quelora-field-row__control">
								<input type="url" id="quelora_sync_posts_endpoint" name="quelora_sync_posts_endpoint"
									value="<?php echo esc_attr( get_option( 'quelora_sync_posts_endpoint', '' ) ); ?>"
									class="regular-text"
									placeholder="https://api.quelora.example/v1/nodes/batch-upsert" />
							</div>
						</div>

						<div class="quelora-sync-status-block" id="quelora-sync-posts-block">
							<div class="quelora-sync-status-grid">
								<div class="quelora-sync-stat">
									<span class="quelora-sync-stat__label"><?php esc_html_e( 'Status', 'quelora' ); ?></span>
									<span class="quelora-sync-stat__value" id="quelora-sync-posts-status-label"><?php echo esc_html( $sync_posts_label ); ?></span>
								</div>
								<div class="quelora-sync-stat">
									<span class="quelora-sync-stat__label"><?php esc_html_e( 'Progress', 'quelora' ); ?></span>
									<span class="quelora-sync-stat__value" id="quelora-sync-posts-count"><?php echo esc_html( $sync_posts_synced . ' / ' . $sync_posts_total ); ?></span>
								</div>
								<div class="quelora-sync-stat">
									<span class="quelora-sync-stat__label"><?php esc_html_e( 'Last run', 'quelora' ); ?></span>
									<span class="quelora-sync-stat__value" id="quelora-sync-posts-lastrun"><?php echo esc_html( $sync_posts_last_str ); ?></span>
								</div>
							</div>
							<div class="quelora-progress-bar">
								<div class="quelora-progress-bar__fill" id="quelora-sync-posts-bar-fill" style="width:<?php echo esc_attr( $sync_posts_pct ); ?>%"></div>
							</div>
						</div>

						<div style="margin-top:16px; display: flex; gap: 10px;">
							<button type="button" class="button button-primary" id="quelora-sync-posts-btn"
								onclick="queloraStartSync('posts')" <?php echo 'running' === $sync_posts_raw ? 'style="display:none;"' : ''; ?>>
								<?php esc_html_e( 'Start Post Sync', 'quelora' ); ?>
							</button>
							<button type="button" class="button button-secondary" id="quelora-sync-posts-abort-btn"
								onclick="queloraAbortSync('posts')" <?php echo 'running' !== $sync_posts_raw ? 'style="display:none;"' : ''; ?>>
								<?php esc_html_e( 'Abort Post Sync', 'quelora' ); ?>
							</button>
						</div>
					</div>

					<div class="quelora-card">
						<h2 class="quelora-card__title"><?php esc_html_e( 'User Data Sync', 'quelora' ); ?></h2>
						<div class="quelora-field-row">
							<label for="quelora_sync_users_endpoint" class="quelora-field-row__label">
								<?php esc_html_e( 'Endpoint URL', 'quelora' ); ?>
							</label>
							<div class="quelora-field-row__control">
								<input type="url" id="quelora_sync_users_endpoint" name="quelora_sync_users_endpoint"
									value="<?php echo esc_attr( get_option( 'quelora_sync_users_endpoint', '' ) ); ?>"
									class="regular-text"
									placeholder="https://api.quelora.example/v1/profiles/batch-upsert" />
							</div>
						</div>

						<div class="quelora-sync-status-block" id="quelora-sync-users-block">
							<div class="quelora-sync-status-grid">
								<div class="quelora-sync-stat">
									<span class="quelora-sync-stat__label"><?php esc_html_e( 'Status', 'quelora' ); ?></span>
									<span class="quelora-sync-stat__value" id="quelora-sync-users-status-label"><?php echo esc_html( $sync_users_label ); ?></span>
								</div>
								<div class="quelora-sync-stat">
									<span class="quelora-sync-stat__label"><?php esc_html_e( 'Progress', 'quelora' ); ?></span>
									<span class="quelora-sync-stat__value" id="quelora-sync-users-count"><?php echo esc_html( $sync_users_synced . ' / ' . $sync_users_total ); ?></span>
								</div>
								<div class="quelora-sync-stat">
									<span class="quelora-sync-stat__label"><?php esc_html_e( 'Last run', 'quelora' ); ?></span>
									<span class="quelora-sync-stat__value" id="quelora-sync-users-lastrun"><?php echo esc_html( $sync_users_last_str ); ?></span>
								</div>
							</div>
							<div class="quelora-progress-bar">
								<div class="quelora-progress-bar__fill" id="quelora-sync-users-bar-fill" style="width:<?php echo esc_attr( $sync_users_pct ); ?>%"></div>
							</div>
						</div>

						<div style="margin-top:16px; display: flex; gap: 10px;">
							<button type="button" class="button button-primary" id="quelora-sync-users-btn"
								onclick="queloraStartSync('users')" <?php echo 'running' === $sync_users_raw ? 'style="display:none;"' : ''; ?>>
								<?php esc_html_e( 'Start User Sync', 'quelora' ); ?>
							</button>
							<button type="button" class="button button-secondary" id="quelora-sync-users-abort-btn"
								onclick="queloraAbortSync('users')" <?php echo 'running' !== $sync_users_raw ? 'style="display:none;"' : ''; ?>>
								<?php esc_html_e( 'Abort User Sync', 'quelora' ); ?>
							</button>
						</div>
					</div>
				</div>

				<?php submit_button( __( 'Save settings', 'quelora' ) ); ?>
			</form>
		</div>

		<style>
		.quelora-settings-wrap { max-width: 900px; }
		.quelora-settings-title { display:flex; align-items:center; margin-bottom:16px; }
		.quelora-tab-nav { margin-bottom: 0; }
		.quelora-tab-nav .nav-tab { display: inline-flex; align-items: center; gap: 6px; }
		.quelora-badge { font-size: 10px; font-weight: 600; padding: 1px 6px; border-radius: 20px;
			background: #0a7440; color: #fff; text-transform: uppercase; letter-spacing: .04em; }
		.quelora-badge--running { background: #b45309; }
		.quelora-card { background: #fff; border: 1px solid #dcdcde; border-radius: 4px; padding: 20px 24px; margin: 20px 0; }
		.quelora-card__title { margin: 0 0 8px; font-size: 14px; font-weight: 600; color: #1d2327; }
		.quelora-card__description { margin: 0 0 20px; color: #646970; font-size: 13px; line-height: 1.6; }
		.quelora-tab-panel--hidden { display: none; }
		.quelora-toggle-row { display: flex; align-items: flex-start; justify-content: space-between;
			gap: 16px; padding: 12px 0; border-bottom: 1px solid #f0f0f1; cursor: pointer; }
		.quelora-toggle-row:last-child { border-bottom: none; }
		.quelora-toggle-row--prominent { background: #f6f7f7; margin: -20px -24px 0; padding: 16px 24px;
			border-radius: 4px 4px 0 0; border-bottom: 1px solid #dcdcde !important; }
		.quelora-toggle-row__text { flex: 1; }
		.quelora-toggle-row__label { display: block; font-weight: 600; font-size: 13px; margin-bottom: 3px; }
		.quelora-toggle-row__help { display: block; color: #646970; font-size: 12px; line-height: 1.5; }
		.quelora-radio-group { border: none; padding: 0; margin: 0; }
		.quelora-radio-row { display: flex; align-items: flex-start; gap: 10px; padding: 10px 0;
			border-bottom: 1px solid #f0f0f1; cursor: pointer; }
		.quelora-radio-row:last-child { border-bottom: none; }
		.quelora-radio-row input { margin-top: 3px; flex-shrink: 0; }
		.quelora-radio-row__content { display: flex; flex-direction: column; gap: 3px; }
		.quelora-radio-row__content strong { font-size: 13px; }
		.quelora-radio-row__content span { color: #646970; font-size: 12px; line-height: 1.5; }
		.quelora-field-row { display: flex; align-items: flex-start; gap: 16px; padding: 12px 0;
			border-bottom: 1px solid #f0f0f1; }
		.quelora-field-row:last-child { border-bottom: none; }
		.quelora-field-row--vertical { flex-direction: column; }
		.quelora-field-row__label { flex-shrink: 0; width: 160px; font-size: 13px; font-weight: 600;
			padding-top: 5px; }
		.quelora-field-row__control { flex: 1; }
		.quelora-field-row__control .description { margin: 6px 0 0; font-size: 12px; }
		.quelora-field-suffix { margin-left: 6px; color: #646970; font-size: 13px; line-height: 30px; }
		.quelora-code-editor { font-family: Consolas, Monaco, monospace; font-size: 12px;
			line-height: 1.6; border-radius: 3px; }
		.quelora-infobox { display: flex; gap: 12px; padding: 14px 18px; border-radius: 4px;
			border-left: 4px solid; margin: 20px 0; }
		.quelora-infobox--info { background: #f0f6fc; border-color: #2271b1; }
		.quelora-infobox .dashicons { color: #2271b1; margin-top: 2px; flex-shrink: 0; }
		.quelora-infobox p { margin: 6px 0 0; color: #646970; font-size: 12px; line-height: 1.6; }
		.quelora-sync-status-block { background: #f6f7f7; border: 1px solid #dcdcde; border-radius: 4px;
			padding: 14px 16px; margin-top: 16px; }
		.quelora-sync-status-grid { display: flex; gap: 32px; flex-wrap: wrap; margin-bottom: 10px; }
		.quelora-sync-stat { display: flex; flex-direction: column; gap: 3px; }
		.quelora-sync-stat__label { font-size: 11px; font-weight: 600; text-transform: uppercase;
			letter-spacing: .04em; color: #646970; }
		.quelora-sync-stat__value { font-size: 13px; font-weight: 500; color: #1d2327; }
		.quelora-progress-bar { height: 6px; background: #dcdcde; border-radius: 3px; overflow: hidden; }
		.quelora-progress-bar__fill { height: 100%; background: #2271b1; border-radius: 3px;
			transition: width .4s ease; }
		</style>

		<script>
		(function () {
			var tabLinks   = document.querySelectorAll('.quelora-tab-nav .nav-tab');
			var tabPanels  = document.querySelectorAll('.quelora-tab-panel');
			var activeHash = window.location.hash.replace('#', '') || 'tab-general';

			function activateTab(tabId) {
				tabLinks.forEach(function (a) {
					a.classList.toggle('nav-tab-active', a.getAttribute('data-tab') === tabId);
				});
				tabPanels.forEach(function (p) {
					p.classList.toggle('quelora-tab-panel--hidden', p.id !== tabId);
				});
			}

			tabLinks.forEach(function (a) {
				a.addEventListener('click', function (e) {
					e.preventDefault();
					var tabId = this.getAttribute('data-tab');
					activateTab(tabId);
					history.replaceState(null, '', '#' + tabId);
					if (tabId === 'tab-sync') {
						queloraLoadSyncStatus();
					} else {
						queloraStopSyncPoll();
					}
				});
			});

			if (document.getElementById(activeHash)) {
				activateTab(activeHash);
			}

			var ssoToggle   = document.getElementById('quelora_sso_enabled');
			var ssoFields   = document.getElementById('quelora-sso-fields');
			if (ssoToggle && ssoFields) {
				ssoToggle.addEventListener('change', function () {
					ssoFields.style.opacity       = this.checked ? '1'    : '.55';
					ssoFields.style.pointerEvents = this.checked ? 'auto' : 'none';
				});
			}

			var cdnRadios = document.querySelectorAll('[name="quelora_asset_source"]');
			var cdnFields = document.getElementById('quelora-cdn-fields');
			if (cdnFields) {
				cdnRadios.forEach(function (r) {
					r.addEventListener('change', function () {
						var isCdn = document.querySelector('[name="quelora_asset_source"]:checked') &&
							document.querySelector('[name="quelora_asset_source"]:checked').value === 'cdn';
						cdnFields.style.opacity       = isCdn ? '1'    : '.55';
						cdnFields.style.pointerEvents = isCdn ? 'auto' : 'none';
					});
				});
			}

			var queloraSyncPollInterval = null;

			function updateTabBadge(isRunning) {
				var badge = document.getElementById('quelora-tab-sync-badge');
				if (badge) {
					badge.style.display = isRunning ? 'inline-block' : 'none';
				}
			}

			function queloraStartSync(type) {
				var secret   = document.getElementById('quelora_sso_secret_key');
				var endpoint = document.getElementById('quelora_sync_' + type + '_endpoint');
				
				if (!secret || !secret.value.trim()) {
					alert('<?php esc_js( esc_html__( 'Please configure the Shared Secret Key in the Integration tab before starting a sync.', 'quelora' ) ); ?>');
					return;
				}

				if (!endpoint || !endpoint.value.trim()) {
					alert('<?php esc_js( esc_html__( 'Please configure the Endpoint URL before starting a sync.', 'quelora' ) ); ?>');
					return;
				}

				var nonce = document.getElementById('quelora-sync-nonce');
				if (!nonce) { return; }

				var btn = document.getElementById('quelora-sync-' + type + '-btn');
				if (btn) { btn.disabled = true; }

				var xhr = new XMLHttpRequest();
				xhr.open('POST', ajaxurl);
				xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
				xhr.onload = function () {
					if (btn) { btn.disabled = false; }
					queloraLoadSyncStatus();
					queloraStartSyncPoll();

					// Garantizar la ejecución de wp-cron invocándolo directamente desde el cliente
					fetch('<?php echo esc_url( site_url( "wp-cron.php?doing_wp_cron=" ) ); ?>' + Date.now(), { mode: 'no-cors' }).catch(function(){});
				};
				xhr.onerror = function () {
					if (btn) { btn.disabled = false; }
				};
				xhr.send(
					'action=quelora_trigger_' + type + '_sync' +
					'&nonce=' + encodeURIComponent(nonce.value)
				);
			}

			function queloraAbortSync(type) {
				var nonce = document.getElementById('quelora-sync-nonce');
				if (!nonce) { return; }

				var btn = document.getElementById('quelora-sync-' + type + '-abort-btn');
				if (btn) { btn.disabled = true; }

				var xhr = new XMLHttpRequest();
				xhr.open('POST', ajaxurl);
				xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
				xhr.onload = function () {
					if (btn) { btn.disabled = false; }
					queloraLoadSyncStatus();
				};
				xhr.onerror = function () {
					if (btn) { btn.disabled = false; }
				};
				xhr.send(
					'action=quelora_abort_sync' +
					'&type=' + encodeURIComponent(type) +
					'&nonce=' + encodeURIComponent(nonce.value)
				);
			}

			function queloraLoadSyncStatus() {
				var nonce = document.getElementById('quelora-sync-nonce');
				if (!nonce) { return; }

				var xhr = new XMLHttpRequest();
				xhr.open('POST', ajaxurl);
				xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
				xhr.onload = function () {
					try {
						var resp = JSON.parse(xhr.responseText);
						if (resp.success && resp.data) {
							queloraRenderSyncStatus('posts', resp.data.posts);
							queloraRenderSyncStatus('users', resp.data.users);
							var running = resp.data.posts.status === 'running' || resp.data.users.status === 'running';
							
							updateTabBadge(running);

							if (!running) { queloraStopSyncPoll(); }
						}
					} catch (e) {}
				};
				xhr.send(
					'action=quelora_sync_status' +
					'&nonce=' + encodeURIComponent(nonce.value)
				);
			}

			function queloraRenderSyncStatus(type, data) {
				var labelMap = { idle: 'Idle', running: 'Running\u2026', complete: 'Complete', aborted: 'Aborted' };
				var label    = labelMap[data.status] ||
					(data.status && data.status.indexOf('error:') === 0
						? 'Error: ' + data.status.slice(6)
						: data.status);
				var pct      = data.total > 0 ? Math.min(100, Math.round(data.synced / data.total * 100)) : 0;
				var lastRun  = data.lastRun > 0
					? new Date(data.lastRun * 1000).toLocaleString()
					: '\u2014';

				var statusEl = document.getElementById('quelora-sync-' + type + '-status-label');
				var countEl  = document.getElementById('quelora-sync-' + type + '-count');
				var fillEl   = document.getElementById('quelora-sync-' + type + '-bar-fill');
				var runEl    = document.getElementById('quelora-sync-' + type + '-lastrun');
				
				var btnStart = document.getElementById('quelora-sync-' + type + '-btn');
				var btnAbort = document.getElementById('quelora-sync-' + type + '-abort-btn');

				if (statusEl) { statusEl.textContent = label; }
				if (countEl)  { countEl.textContent  = data.synced + ' / ' + data.total; }
				if (fillEl)   { fillEl.style.width   = pct + '%'; }
				if (runEl)    { runEl.textContent    = lastRun; }

				if (btnStart && btnAbort) {
					if (data.status === 'running') {
						btnStart.style.display = 'none';
						btnAbort.style.display = 'inline-flex';
					} else {
						btnStart.style.display = 'inline-flex';
						btnAbort.style.display = 'none';
					}
				}
			}

			function queloraStartSyncPoll() {
				queloraStopSyncPoll();
				queloraSyncPollInterval = setInterval(queloraLoadSyncStatus, 3000);
			}

			function queloraStopSyncPoll() {
				if (queloraSyncPollInterval) {
					clearInterval(queloraSyncPollInterval);
					queloraSyncPollInterval = null;
				}
			}

			window.queloraStartSync = queloraStartSync;
			window.queloraAbortSync = queloraAbortSync;

			if (activeHash === 'tab-sync') {
				queloraLoadSyncStatus();
			}
		}());
		</script>
		<?php
	}

	/**
	 * Injects the Gutenberg block editor assets (JS/CSS).
	 *
	 * @return void
	 */
	public function inject_editor_assets() {
		$asset_file_path = QUELORA_PLUGIN_DIR . 'build/index.asset.php';

		if ( ! file_exists( $asset_file_path ) ) {
			return;
		}

		$asset_file = include $asset_file_path;

		wp_enqueue_script(
			'quelora-sidebar',
			QUELORA_PLUGIN_URL . 'build/index.js',
			$asset_file['dependencies'],
			$asset_file['version'],
			true
		);

		$script = sprintf(
			'window.QueloraEditorConfig = { dashboardUrl: "%s", defaultActive: %s, language: "%s" };',
			esc_url( get_option( 'quelora_dashboard_url', self::DEFAULT_DASHBOARD_URL ) ),
			( (bool) get_option( 'quelora_default_active', false ) ) ? 'true' : 'false',
			esc_js( get_locale() )
		);

		wp_add_inline_script( 'quelora-sidebar', $script, 'before' );
	}
}