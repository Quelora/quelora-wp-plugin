<?php
/**
 * Plugin Name: Quelora Integration
 * Plugin URI: https://www.quelora.org
 * Update URI: https://www.quelora.org/wp-plugin/quelora-wp-integration
 * Description: Advanced distributed community system integration for WordPress. Injects highly optimized ES Modules and CSS via a Sidebar toggle.
 * Version: 4.7.0
 * Author: Quelora Architecture Team
 * Text Domain: quelora
 *
 * @package Quelora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Quelora_Integration
 *
 * Main plugin class responsible for registering all WordPress hooks,
 * managing plugin settings, and injecting Quelora frontend and editor assets.
 *
 * ## Placement Architecture
 *
 * Quelora interaction-bar anchors (`<div data-entity-anchor="{nodeId}">`) are
 * injected through two complementary layers. Both layers emit the same attribute
 * so the Quelora JS widget always queries `[data-entity-anchor="{id}"]` globally,
 * regardless of which layer produced the element.
 *
 * ### Layer 1 — IIFE (primary, universal)
 *
 * The mount IIFE runs on DOMContentLoaded and iterates every article whose node ID
 * appears in `QueloraPostsIndex` (a flat set of active node IDs). For each active post:
 *  1. Skips if a `[data-entity-anchor]` already exists (idempotent).
 *  2. Walks `contentSelectorCascade` inside the article to find the content container.
 *  3. Inserts the anchor immediately AFTER the first matching container.
 *  4. Falls back to appending inside the article root when no selector matches.
 *
 * ### Layer 2 — PHP hooks (secondary, belt-and-suspenders)
 *
 *  - `the_content` filter (priority 20): fires when the theme calls `the_content()`.
 *  - `comment_form_before` action: fires when comments are open and the theme
 *    includes `comments_template()`.
 *
 * ## QueloraPostsIndex
 *
 * A flat object `{ nodeId: true }` published to `window.QueloraPostsIndex`.
 * Contains only the 24-char node IDs of Quelora-active posts on the current page.
 * The IIFE uses it exclusively to decide whether to inject an anchor for a given
 * article — no post metadata is included.
 *
 * ## Meta default — source of truth for the sidebar toggle
 *
 * `_quelora_active` is registered with `default` set to the current value of the
 * `quelora_default_active` option at request time. This ensures the REST API and
 * Gutenberg editor store return the correct global default for posts that have
 * never been explicitly saved with this meta key.
 *
 * ## SSO / External Session
 *
 * When `quelora_sso_enabled` is active, PHP generates a signed HS256 JWT from
 * the current WordPress user and writes it directly into browser storage via
 * {@link inject_sso_token} (priority 15). The Quelora SDK reads that token on
 * initialisation and treats its presence as proof of authentication — no
 * additional `isLoggedIn` flag or server-side config patch is needed.
 *
 * The integrator is responsible for setting all external-session config keys
 * (`login.queloraSession`, `login.loginUrl`, `login.logoutUrl`) as static
 * values in the admin config field. These never change between requests and
 * require no server-side generation.
 *
 * SSO token generation uses a native PHP HS256 JWT implementation with no
 * external dependencies.
 */
class Quelora_Integration {

	// =========================================================================
	// CONSTANTS
	// =========================================================================

	/** @var string Default base URL for the Quelora embedded dashboard. */
	const DEFAULT_DASHBOARD_URL = 'https://dashboard.quelora.local/embed/post/';

	/** @var string Default CSS selector for the header widget mount point. */
	const DEFAULT_HEADER_SELECTOR = '.site-header-primary-section-right';

	/** @var int Default SSO token time-to-live in seconds (1 hour). */
	const DEFAULT_TOKEN_TTL = 3600;

	/** @var bool Default state for the WordPress SSO token integration. */
	const DEFAULT_SSO_ENABLED = false;

	/**
	 * Default interaction bar placement strategy for PHP Layer 2 hooks.
	 *
	 * Available values:
	 *  - `content`      — `the_content` filter only.
	 *  - `comment_form` — `comment_form_before` action only.
	 *  - `both`         — both PHP hooks fire (recommended).
	 *  - `iife_only`    — PHP hooks disabled; IIFE is the sole injector.
	 *
	 * @var string
	 */
	const DEFAULT_PLACEMENT_STRATEGY = 'both';

	/** @var string Default value for the custom inline CSS field. */
	const DEFAULT_CUSTOM_CSS = '';

	/**
	 * Ordered cascade of CSS selectors used by the IIFE to locate the content
	 * container inside an article. The first match wins.
	 *
	 * @var string[]
	 */
	const CONTENT_SELECTOR_CASCADE = array(
		'.entry-content',
		'.post-content',
		'.wp-block-post-content',
		'.post-body',
		'.article-content',
		'.content-area',
		'.post-entry',
	);

	// =========================================================================
	// BOOTSTRAP
	// =========================================================================

	/**
	 * Registers all WordPress action and filter hooks for the plugin lifecycle.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'init',                          array( $this, 'load_textdomain' ) );
		add_action( 'init',                          array( $this, 'register_post_meta' ) );
		add_action( 'admin_menu',                    array( $this, 'register_settings_page' ) );
		add_action( 'admin_init',                    array( $this, 'register_settings' ) );
		add_action( 'wp_enqueue_scripts',            array( $this, 'inject_frontend_assets' ) );
		add_action( 'wp_head',                       array( $this, 'inject_dynamic_configuration' ), 5 );
		add_action( 'wp_head',                       array( $this, 'inject_mount_script' ), 10 );
		add_action( 'wp_head',                       array( $this, 'inject_sso_token' ), 15 );
		add_action( 'wp_head',                       array( $this, 'inject_hide_comments_style' ), 20 );
		add_action( 'wp_head',                       array( $this, 'inject_custom_css' ), 25 );
		add_action( 'enqueue_block_editor_assets',   array( $this, 'inject_editor_assets' ) );
		add_action( 'wp_ajax_quelora_refresh_token', array( $this, 'ajax_refresh_token' ) );
		add_filter( 'the_content',                   array( $this, 'append_placement_anchor_to_content' ), 20 );
		add_action( 'comment_form_before',           array( $this, 'inject_comment_form_anchor' ) );
	}

	// =========================================================================
	// INTERNATIONALISATION
	// =========================================================================

	/**
	 * Loads the plugin text domain for internationalization.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'quelora', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}

	// =========================================================================
	// POST META
	// =========================================================================

	/**
	 * Registers the `_quelora_active` post meta field, exposed to the REST API.
	 *
	 * The `default` value is set dynamically to the current `quelora_default_active`
	 * option so the REST API and Gutenberg editor store return the correct global
	 * default for posts that have never been explicitly saved with this meta key.
	 *
	 * @return void
	 */
	public function register_post_meta() {
		register_post_meta(
			'post',
			'_quelora_active',
			array(
				'show_in_rest'  => true,
				'single'        => true,
				'type'          => 'boolean',
				'default'       => (bool) get_option( 'quelora_default_active', false ),
				'auth_callback' => function() {
					return current_user_can( 'edit_posts' );
				},
			)
		);
	}

	// =========================================================================
	// VERSIONING
	// =========================================================================

	/**
	 * Returns the plugin version string read from the plugin file header.
	 *
	 * Uses `get_file_data()` — always available in WordPress, no conditional
	 * require needed — and caches the result in a static variable so the header
	 * is parsed only once per request.
	 *
	 * @return string Plugin version string (e.g. `4.5.0`).
	 */
	private function get_plugin_version() {
		static $version = null;

		if ( null === $version ) {
			$data    = get_file_data( __FILE__, array( 'Version' => 'Version' ) );
			$version = ! empty( $data['Version'] ) ? $data['Version'] : '1.0.0';
		}

		return $version;
	}

	// =========================================================================
	// ADMIN — SETTINGS PAGE
	// =========================================================================

	/**
	 * Registers the Quelora admin settings page in the WordPress menu.
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
	 * Registers all plugin settings with the WordPress Settings API.
	 *
	 * @return void
	 */
	public function register_settings() {
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
	}

	// =========================================================================
	// ADMIN — SANITISATION CALLBACKS
	// =========================================================================

	/**
	 * Sanitizes the Configuration Script Payload option on save.
	 *
	 * @param  string $value Raw value submitted via the settings form.
	 * @return string Sanitized script content with submission slashes removed.
	 */
	public function sanitize_config_script( $value ) {
		return wp_unslash( (string) $value );
	}

	/**
	 * Sanitizes the Custom CSS option on save.
	 *
	 * @param  string $value Raw CSS value submitted via the settings form.
	 * @return string Sanitized CSS with submission slashes removed.
	 */
	public function sanitize_custom_css( $value ) {
		return wp_unslash( (string) $value );
	}

	/**
	 * Sanitizes the placement strategy setting, enforcing the allowed value set.
	 *
	 * @param  string $value Raw value submitted via the settings form.
	 * @return string One of `content`, `comment_form`, `both`, or `iife_only`.
	 */
	public function sanitize_placement_strategy( $value ) {
		$allowed = array( 'content', 'comment_form', 'both', 'iife_only' );
		$clean   = sanitize_text_field( wp_unslash( (string) $value ) );
		return in_array( $clean, $allowed, true ) ? $clean : self::DEFAULT_PLACEMENT_STRATEGY;
	}

	// =========================================================================
	// ADMIN — SETTINGS PAGE RENDERER
	// =========================================================================

	/**
	 * Renders the HTML for the Quelora admin settings page.
	 *
	 * The page is organized into four focused tabs to avoid a flat, hard-to-scan
	 * layout. Tab state is managed with a small inline script using only native
	 * WordPress admin CSS classes — no external libraries required.
	 *
	 * Tabs:
	 *  - General      — Activation defaults and placement strategy.
	 *  - Integration  — SSO / external session configuration.
	 *  - Assets       — JS/CSS source selection and CDN URLs.
	 *  - Customisation — Config script payload and custom CSS overrides.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		$current_strategy = get_option( 'quelora_placement_strategy', self::DEFAULT_PLACEMENT_STRATEGY );
		$sso_enabled      = (bool) get_option( 'quelora_sso_enabled', self::DEFAULT_SSO_ENABLED );
		$asset_source     = get_option( 'quelora_asset_source', 'cdn' );
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
					<span style="font-size:13px;font-weight:400;color:#777;margin-left:4px;">v<?php echo esc_html( $this->get_plugin_version() ); ?></span>
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
			</nav>

			<form method="post" action="options.php" id="quelora-settings-form">
				<?php settings_fields( 'quelora_settings_group' ); ?>

				<?php /* ── TAB: GENERAL ─────────────────────────────────────────── */ ?>
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
										/* translators: %s: example composed URL */
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
										/* translators: 1: default selector, 2: injected element */
										esc_html__( 'CSS selector for the header container where Quelora injects %2$s. Default: %1$s.', 'quelora' ),
										'<code>' . esc_html( self::DEFAULT_HEADER_SELECTOR ) . '</code>',
										'<code>&lt;div id="quelora-header-widget"&gt;&lt;/div&gt;</code>'
									);
									?>
								</p>
							</div>
						</div>
					</div>

				</div><!-- /#tab-general -->

				<?php /* ── TAB: INTEGRATION ──────────────────────────────────────── */ ?>
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
						<h2 class="quelora-card__title"><?php esc_html_e( 'Token configuration', 'quelora' ); ?></h2>
						<p class="quelora-card__description">
							<?php esc_html_e( 'Configure the HMAC-SHA256 secret and token lifetime used to sign the identity JWT. The secret must match exactly what is set in the Quelora backend for the token to be accepted.', 'quelora' ); ?>
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
									<?php esc_html_e( 'HMAC-SHA256 secret used to sign and verify the SSO JWT. Treat this like a password — never commit it to version control.', 'quelora' ); ?>
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
										/* translators: %s: default TTL in seconds */
										esc_html__( 'How long each signed token remains valid. Default: %s (1 hour). The client-side script auto-renews the token 5 minutes before expiry.', 'quelora' ),
										'<code>' . esc_html( self::DEFAULT_TOKEN_TTL ) . '</code>'
									);
									?>
								</p>
							</div>
						</div>
					</div>

					<div class="quelora-infobox quelora-infobox--info">
						<span class="dashicons dashicons-info-outline"></span>
						<div>
							<strong><?php esc_html_e( 'How it works', 'quelora' ); ?></strong>
							<p>
								<?php esc_html_e( 'When a logged-in user loads a Quelora-active page, PHP generates a short-lived JWT and injects it directly into the browser\'s storage before the Quelora module initialises. The module detects the token immediately and authenticates without any redirect or user interaction. When SSO is active, the Quelora SDK\'s internal login modal and registration flows are suppressed — unauthenticated users are sent to the WordPress login page with a redirect parameter to return them to the exact same post and scroll position.', 'quelora' ); ?>
							</p>
						</div>
					</div>

				</div><!-- /#tab-integration -->

				<?php /* ── TAB: ASSETS ──────────────────────────────────────────── */ ?>
				<div id="tab-assets" class="quelora-tab-panel quelora-tab-panel--hidden">

					<div class="quelora-card">
						<h2 class="quelora-card__title"><?php esc_html_e( 'Asset source', 'quelora' ); ?></h2>
						<p class="quelora-card__description">
							<?php esc_html_e( 'Choose whether to load the Quelora JS and CSS from the bundled local copy or from an external CDN. The local option is recommended for air-gapped or staging environments; the CDN option ensures you always serve the latest optimised build.', 'quelora' ); ?>
						</p>

						<fieldset class="quelora-radio-group">
							<label class="quelora-radio-row">
								<input type="radio" id="asset_source_cdn" name="quelora_asset_source" value="cdn"
									<?php checked( $asset_source, 'cdn' ); ?> />
								<span class="quelora-radio-row__content">
									<strong><?php esc_html_e( 'External CDN', 'quelora' ); ?></strong>
									<span><?php esc_html_e( 'Load assets from the URLs specified below. Best for production — leverages CDN edge caching.', 'quelora' ); ?></span>
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
						<p class="quelora-card__description">
							<?php esc_html_e( 'Override the default CDN endpoints when using a self-hosted or custom CDN distribution.', 'quelora' ); ?>
						</p>

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

				</div><!-- /#tab-assets -->

				<?php /* ── TAB: CUSTOMISATION ─────────────────────────────────────── */ ?>
				<div id="tab-customisation" class="quelora-tab-panel quelora-tab-panel--hidden">

					<div class="quelora-card">
						<h2 class="quelora-card__title"><?php esc_html_e( 'Configuration script', 'quelora' ); ?></h2>
						<p class="quelora-card__description">
							<?php esc_html_e( 'Raw JavaScript injected as an ES Module in the <head> before the Quelora bundle loads. Use this to populate window.QUELORA_CONFIG with your client-specific settings (API URL, login providers, entity selectors, etc.).', 'quelora' ); ?>
						</p>

						<div class="quelora-field-row quelora-field-row--vertical">
							<textarea name="quelora_config_script" rows="16" class="large-text code quelora-code-editor"
								spellcheck="false"><?php echo esc_textarea( get_option( 'quelora_config_script', '' ) ); ?></textarea>
							<p class="description">
								<?php esc_html_e( 'This script runs on every page where Quelora is active. When WordPress SSO is enabled, the plugin automatically patches window.QUELORA_CONFIG.login with the external session parameters after this script runs — you do not need to set them manually here.', 'quelora' ); ?>
							</p>
						</div>
					</div>

					<div class="quelora-card">
						<h2 class="quelora-card__title"><?php esc_html_e( 'Custom CSS', 'quelora' ); ?></h2>
						<p class="quelora-card__description">
							<?php esc_html_e( 'Raw CSS injected in a <style> tag in the <head> on every page where Quelora is active. Use this to override widget styles without modifying theme files.', 'quelora' ); ?>
						</p>

						<div class="quelora-field-row quelora-field-row--vertical">
							<textarea name="quelora_custom_css" rows="12" class="large-text code quelora-code-editor"
								spellcheck="false"><?php echo esc_textarea( get_option( 'quelora_custom_css', self::DEFAULT_CUSTOM_CSS ) ); ?></textarea>
						</div>
					</div>

				</div><!-- /#tab-customisation -->

				<?php submit_button( __( 'Save settings', 'quelora' ) ); ?>
			</form>
		</div><!-- /.wrap -->

		<style>
		.quelora-settings-wrap { max-width: 900px; }
		.quelora-settings-title { display:flex; align-items:center; margin-bottom:16px; }
		.quelora-tab-nav { margin-bottom: 0; }
		.quelora-tab-nav .nav-tab { display: inline-flex; align-items: center; gap: 6px; }
		.quelora-badge { font-size: 10px; font-weight: 600; padding: 1px 6px; border-radius: 20px;
			background: #0a7440; color: #fff; text-transform: uppercase; letter-spacing: .04em; }
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
					activateTab(this.getAttribute('data-tab'));
					history.replaceState(null, '', '#' + this.getAttribute('data-tab'));
				});
			});

			if (document.getElementById(activeHash)) {
				activateTab(activeHash);
			}

			// Toggle dependent SSO fields opacity/interactivity.
			var ssoToggle    = document.getElementById('quelora_sso_enabled');
			var ssoFields    = document.getElementById('quelora-sso-fields');
			if (ssoToggle && ssoFields) {
				ssoToggle.addEventListener('change', function () {
					ssoFields.style.opacity        = this.checked ? '1'    : '.55';
					ssoFields.style.pointerEvents  = this.checked ? 'auto' : 'none';
				});
			}

			// Toggle dependent CDN fields.
			var cdnRadios = document.querySelectorAll('[name="quelora_asset_source"]');
			var cdnFields = document.getElementById('quelora-cdn-fields');
			if (cdnFields) {
				cdnRadios.forEach(function (r) {
					r.addEventListener('change', function () {
						var isCdn = document.querySelector('[name="quelora_asset_source"]:checked')?.value === 'cdn';
						cdnFields.style.opacity       = isCdn ? '1'    : '.55';
						cdnFields.style.pointerEvents = isCdn ? 'auto' : 'none';
					});
				});
			}
		}());
		</script>
		<?php
	}

	// =========================================================================
	// EDITOR ASSETS
	// =========================================================================

	/**
	 * Enqueues the compiled Gutenberg Sidebar plugin script in the block editor.
	 *
	 * @return void
	 */
	public function inject_editor_assets() {
		$asset_file = include plugin_dir_path( __FILE__ ) . 'build/index.asset.php';

		wp_enqueue_script(
			'quelora-sidebar',
			plugin_dir_url( __FILE__ ) . 'build/index.js',
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

	// =========================================================================
	// CONTEXT HELPERS
	// =========================================================================

	/**
	 * Determines whether Quelora assets should be active for the current page context.
	 *
	 * @return bool True if Quelora assets should be enqueued for this page.
	 */
	private function is_quelora_active_for_context() {
		if ( is_home() || is_archive() ) {
			return true;
		}

		if ( is_singular() ) {
			return $this->is_quelora_active_for_post( get_the_ID() );
		}

		return false;
	}

	/**
	 * Determines whether Quelora is active for a specific post ID.
	 *
	 * Resolution order:
	 *  1. Explicit `_quelora_active` meta written on the post.
	 *  2. `quelora_default_active` global option.
	 *
	 * @param  int $post_id WordPress post ID.
	 * @return bool True if Quelora is active for the given post.
	 */
	private function is_quelora_active_for_post( $post_id ) {
		if ( ! $post_id ) {
			return false;
		}

		if ( metadata_exists( 'post', $post_id, '_quelora_active' ) ) {
			return (bool) get_post_meta( $post_id, '_quelora_active', true );
		}

		return (bool) get_option( 'quelora_default_active', false );
	}

	/**
	 * Returns the currently configured PHP placement strategy.
	 *
	 * @return string One of `content`, `comment_form`, `both`, or `iife_only`.
	 */
	private function get_placement_strategy() {
		return get_option( 'quelora_placement_strategy', self::DEFAULT_PLACEMENT_STRATEGY );
	}

	/**
	 * Builds the HTML string for a Quelora placement anchor element.
	 *
	 * @param  int $post_id WordPress post ID.
	 * @return string HTML string for the placement anchor div.
	 */
	private function build_placement_anchor( $post_id ) {
		$node_id = substr( hash( 'sha256', 'post-' . (int) $post_id ), 0, 24 );
		return '<div class="quelora-placement-anchor" data-entity-anchor="' . esc_attr( $node_id ) . '"></div>';
	}

	// =========================================================================
	// FRONTEND ASSET INJECTION
	// =========================================================================

	/**
	 * Enqueues Quelora CSS and the ES Module JS on the frontend.
	 *
	 * Both assets are versioned with the plugin version string so browsers
	 * automatically bust their caches on every plugin update.
	 *
	 * @return void
	 */
	public function inject_frontend_assets() {
		if ( ! $this->is_quelora_active_for_context() ) {
			return;
		}

		$version = $this->get_plugin_version();
		$source  = get_option( 'quelora_asset_source', 'cdn' );

		if ( 'local' === $source ) {
			$js_url  = plugin_dir_url( __FILE__ ) . 'assets/quelora/js/quelora.js';
			$css_url = plugin_dir_url( __FILE__ ) . 'assets/css/quelora.css';
		} else {
			$js_url  = get_option( 'quelora_cdn_js_url', 'https://cdn.quelora.org/quelora.min.js' );
			$css_url = get_option( 'quelora_cdn_css_url', 'https://cdn.quelora.org/css/quelora.css' );
		}

		wp_enqueue_style( 'quelora-styles', $css_url, array(), $version, 'all' );
		wp_enqueue_script_module( 'quelora-core-module', $js_url, array(), $version );
	}

	// =========================================================================
	// HEAD SCRIPT INJECTION
	// =========================================================================

	/**
	 * Injects the integrator-supplied configuration script into the page head at priority 5.
	 *
	 * Outputs the raw JavaScript stored in the `quelora_config_script` admin field
	 * as a `<script type="module">` block. This is the single place where all
	 * `window.QUELORA_CONFIG` values are defined, including any external-session
	 * keys (`login.queloraSession`, `login.loginUrl`, `login.logoutUrl`).
	 *
	 * No dynamic server-side patching is performed here. Authentication state is
	 * determined entirely by the presence of the SSO token in browser storage,
	 * which is written by {@link inject_sso_token} at priority 15.
	 *
	 * Produces no output when the field is empty.
	 *
	 * @return void
	 */
	public function inject_dynamic_configuration() {
		if ( ! $this->is_quelora_active_for_context() ) {
			return;
		}

		$config_script = wp_unslash( (string) get_option( 'quelora_config_script', '' ) );

		if ( empty( $config_script ) ) {
			return;
		}

		echo "<script type=\"module\" id=\"quelora-dynamic-config\">\n";
		echo $config_script . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo "</script>\n";
	}

	/**
	 * Injects the Quelora config globals and the mount IIFE into wp_head at priority 10.
	 *
	 * @return void
	 */
	public function inject_mount_script() {
		if ( ! $this->is_quelora_active_for_context() ) {
			return;
		}

		$header_selector = get_option( 'quelora_header_selector', self::DEFAULT_HEADER_SELECTOR );

		global $wp_query;
		$posts_on_page = isset( $wp_query->posts ) ? $wp_query->posts : array();
		$posts_index   = array();

		foreach ( $posts_on_page as $queried_post ) {
			$pid = (int) $queried_post->ID;

			if ( ! $this->is_quelora_active_for_post( $pid ) ) {
				continue;
			}

			$node_id               = substr( hash( 'sha256', 'post-' . $pid ), 0, 24 );
			$posts_index[ $node_id ] = true;
		}

		$config = wp_json_encode(
			array(
				'headerSelector'         => $header_selector,
				'contentSelectorCascade' => self::CONTENT_SELECTOR_CASCADE,
			)
		);

		?>
		<script id="quelora-config">
		window.QueloraConfig     = <?php echo $config; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
		window.QueloraPostsIndex = <?php echo wp_json_encode( $posts_index ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
		</script>
		<script id="quelora-mount">
		(function () {
			var cfg     = window.QueloraConfig     || {};
			var index   = window.QueloraPostsIndex || {};
			var cascade = cfg.contentSelectorCascade || [
				'.entry-content', '.post-content', '.wp-block-post-content',
				'.post-body', '.article-content', '.content-area', '.post-entry'
			];

			function mountHeader() {
				if ( ! cfg.headerSelector ) { return; }
				if ( document.getElementById( 'quelora-header-widget' ) ) { return; }
				var container = document.querySelector( cfg.headerSelector );
				if ( ! container ) { return; }
				var el = document.createElement( 'div' );
				el.id  = 'quelora-header-widget';
				container.appendChild( el );
			}

			function toNodeId( input ) {
				var str = String( input );
				if ( /^[0-9a-f]{24}$/.test( str.toLowerCase() ) ) {
					return Promise.resolve( str.toLowerCase() );
				}
				var data = new TextEncoder().encode( str );
				return crypto.subtle.digest( 'SHA-256', data ).then( function ( buf ) {
					return Array.from( new Uint8Array( buf ) )
						.map( function ( b ) { return b.toString( 16 ).padStart( 2, '0' ); } )
						.join( '' )
						.substring( 0, 24 )
						.toLowerCase();
				} );
			}

			function mountPlacementAnchors() {
				var articles = document.querySelectorAll( 'article[id]' );
				articles.forEach( function ( article ) {
					var rawId = article.getAttribute( 'id' ) || '';
					if ( ! rawId ) { return; }
					toNodeId( rawId ).then( function ( nodeId ) {
						if ( ! index[ nodeId ] ) { return; }
						if ( document.querySelector( '[data-entity-anchor="' + nodeId + '"]' ) ) { return; }
						var anchor = document.createElement( 'div' );
						anchor.className = 'quelora-placement-anchor';
						anchor.setAttribute( 'data-entity-anchor', nodeId );
						var container = null;
						for ( var i = 0; i < cascade.length; i++ ) {
							container = article.querySelector( cascade[ i ] );
							if ( container ) { break; }
						}
						if ( container ) {
							container.insertAdjacentElement( 'afterend', anchor );
						} else {
							article.appendChild( anchor );
						}
					} );
				} );
			}

			mountHeader();

			if ( document.readyState === 'loading' ) {
				document.addEventListener( 'DOMContentLoaded', mountPlacementAnchors );
			} else {
				mountPlacementAnchors();
			}
		}());
		</script>
		<?php
	}

	/**
	 * Outputs scoped CSS that suppresses native WordPress comment UI elements.
	 *
	 * The `#comments` container itself is preserved so Quelora anchors remain
	 * accessible. Runs at wp_head priority 20.
	 *
	 * @return void
	 */
	public function inject_hide_comments_style() {
		if ( ! $this->is_quelora_active_for_context() ) {
			return;
		}

		if ( ! (bool) get_option( 'quelora_hide_wp_comments', false ) ) {
			return;
		}

		if ( ! is_singular() ) {
			return;
		}

		?>
		<style id="quelora-hide-comments">
		#comments .comment-list,
		#comments ol.commentlist,
		#comments .comments-title,
		#comments .wp-block-comments-title,
		#comments .comment-navigation,
		#comments .comments-pagination,
		#comments .comment-reply-title,
		#comments .wp-block-post-comments-form,
		#comments #commentform,
		#comments .must-log-in,
		#comments .logged-in-as { display: none !important; }
		</style>
		<?php
	}

	/**
	 * Outputs the custom inline CSS block in the page head at priority 25.
	 *
	 * @return void
	 */
	public function inject_custom_css() {
		if ( ! $this->is_quelora_active_for_context() ) {
			return;
		}

		$custom_css = wp_unslash( (string) get_option( 'quelora_custom_css', self::DEFAULT_CUSTOM_CSS ) );

		if ( empty( trim( $custom_css ) ) ) {
			return;
		}

		echo "<style id=\"quelora-custom-css\">\n";
		echo $custom_css . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo "</style>\n";
	}

	// =========================================================================
	// SSO TOKEN
	// =========================================================================

	/**
	 * Encodes data to Base64URL format as required by the JWT specification.
	 *
	 * @param  string $data Raw binary or string data to encode.
	 * @return string Base64URL-encoded string.
	 */
	private function base64url_encode( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/**
	 * Generates a signed HS256 JWT with a Google SSO-equivalent payload.
	 *
	 * @return string|null Signed JWT string, or null when preconditions are not met.
	 */
	private function generate_sso_token() {
		if ( ! is_user_logged_in() ) {
			return null;
		}

		if ( ! (bool) get_option( 'quelora_sso_enabled', self::DEFAULT_SSO_ENABLED ) ) {
			return null;
		}

		$secret_key = trim( wp_unslash( (string) get_option( 'quelora_sso_secret_key', '' ) ) );

		if ( empty( $secret_key ) ) {
			return null;
		}

		$user          = wp_get_current_user();
		$ttl           = (int) get_option( 'quelora_sso_token_ttl', self::DEFAULT_TOKEN_TTL );
		$issued_at     = time();
		$expires_at    = $issued_at + max( 60, $ttl );
		$gravatar_hash = md5( strtolower( trim( $user->user_email ) ) );
		$gravatar_url  = 'https://www.gravatar.com/avatar/' . $gravatar_hash . '?s=96&d=mp&r=g';
		$author_id     = hash( 'sha256', (string) $user->ID . wp_salt( 'auth' ) );
		$flags         = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

		$header = $this->base64url_encode(
			json_encode( array( 'alg' => 'HS256', 'typ' => 'JWT' ), $flags )
		);

		$payload = $this->base64url_encode(
			json_encode(
				array(
					'iss'     => get_site_url(),
					'aud'     => get_option( 'quelora_dashboard_url', self::DEFAULT_DASHBOARD_URL ),
					'sub'     => (string) $user->ID,
					'email'   => $user->user_email,
					'name'    => $user->display_name,
					'picture' => $gravatar_url,
					'author'  => $author_id,
					'iat'     => $issued_at,
					'exp'     => $expires_at,
				),
				$flags
			)
		);

		$signature = $this->base64url_encode(
			hash_hmac( 'sha256', $header . '.' . $payload, $secret_key, true )
		);

		return $header . '.' . $payload . '.' . $signature;
	}

	/**
	 * Injects the SSO token into both sessionStorage and localStorage at priority 15.
	 *
	 * When SSO is disabled or the user is not logged in, the script clears any
	 * previously stored Quelora session keys so stale tokens cannot persist.
	 *
	 * @return void
	 */
	public function inject_sso_token() {
		$token = $this->generate_sso_token();

		if ( null === $token ) {
			?>
			<script id="quelora-sso">
			(function () {
				var keys   = [ 'ql_sso_token', 'ql_sso_token_expires' ];
				var stores = [ sessionStorage, localStorage ];
				stores.forEach( function ( s ) {
					keys.forEach( function ( k ) { try { s.removeItem( k ); } catch (e) {} } );
				} );
			}());
			</script>
			<?php
			return;
		}

		$ttl        = (int) get_option( 'quelora_sso_token_ttl', self::DEFAULT_TOKEN_TTL );
		$expires_ms = ( time() + max( 60, $ttl ) ) * 1000;
		$ajax_url   = admin_url( 'admin-ajax.php' );
		$nonce      = wp_create_nonce( 'quelora_refresh_token' );

		?>
		<script id="quelora-sso">
		(function () {
			var token     = <?php echo wp_json_encode( $token ); ?>;
			var expiresAt = <?php echo (int) $expires_ms; ?>;
			var ajaxUrl   = <?php echo wp_json_encode( $ajax_url ); ?>;
			var nonce     = <?php echo wp_json_encode( $nonce ); ?>;

			function storeToken( t, exp ) {
				[ sessionStorage, localStorage ].forEach( function ( s ) {
					try {
						s.setItem( 'ql_sso_token',         t );
						s.setItem( 'ql_sso_token_expires', String( exp ) );
					} catch (e) {}
				} );
			}

			function getStoredExpiry() {
				var stores = [ sessionStorage, localStorage ];
				for ( var i = 0; i < stores.length; i++ ) {
					try {
						var exp = parseInt( stores[ i ].getItem( 'ql_sso_token_expires' ), 10 );
						if ( exp > 0 ) { return exp; }
					} catch (e) {}
				}
				return 0;
			}

			var storedExpiry = getStoredExpiry();
			var stillValid   = storedExpiry > 0 && ( storedExpiry - Date.now() > 30000 );

			if ( ! stillValid ) { storeToken( token, expiresAt ); }

			function scheduleRenewal( expiryMs ) {
				var delay = expiryMs - Date.now() - ( 5 * 60 * 1000 );
				setTimeout( function () {
					var xhr = new XMLHttpRequest();
					xhr.open( 'POST', ajaxUrl );
					xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
					xhr.onload = function () {
						if ( xhr.status !== 200 ) { return; }
						try {
							var resp = JSON.parse( xhr.responseText );
							if ( ! resp.success ) { return; }
							storeToken( resp.data.token, resp.data.expiresAt );
							scheduleRenewal( resp.data.expiresAt );
						} catch (e) {}
					};
					xhr.send( 'action=quelora_refresh_token&nonce=' + encodeURIComponent( nonce ) );
				}, Math.max( 30000, delay ) );
			}

			scheduleRenewal( stillValid ? storedExpiry : expiresAt );
		}());
		</script>
		<?php
	}

	/**
	 * WordPress AJAX handler for SSO token renewal.
	 *
	 * @return void Sends a JSON response and terminates execution.
	 */
	public function ajax_refresh_token() {
		check_ajax_referer( 'quelora_refresh_token', 'nonce' );

		$token = $this->generate_sso_token();

		if ( null === $token ) {
			wp_send_json_error( array( 'message' => 'SSO token generation is disabled or unavailable.' ), 403 );
			return;
		}

		$ttl        = (int) get_option( 'quelora_sso_token_ttl', self::DEFAULT_TOKEN_TTL );
		$expires_ms = ( time() + max( 60, $ttl ) ) * 1000;

		wp_send_json_success( array( 'token' => $token, 'expiresAt' => $expires_ms ) );
	}

	// =========================================================================
	// PLACEMENT HOOKS
	// =========================================================================

	/**
	 * Appends a placement anchor to post content via the `the_content` filter.
	 *
	 * Layer 2 (secondary). Skipped outside the main query loop, when strategy
	 * is `comment_form` or `iife_only`, when Quelora is not active for the post,
	 * or when an anchor already exists in the content (idempotent guard).
	 *
	 * @param  string $content The post content passed by WordPress.
	 * @return string Content with placement anchor appended, or unchanged.
	 */
	public function append_placement_anchor_to_content( $content ) {
		if ( ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$strategy = $this->get_placement_strategy();
		if ( 'comment_form' === $strategy || 'iife_only' === $strategy ) {
			return $content;
		}

		$post_id = get_the_ID();
		if ( ! $this->is_quelora_active_for_post( $post_id ) ) {
			return $content;
		}

		if ( strpos( $content, 'data-entity-anchor' ) !== false ) {
			return $content;
		}

		return $content . $this->build_placement_anchor( $post_id );
	}

	/**
	 * Injects a placement anchor via the `comment_form_before` action hook.
	 *
	 * Layer 2 (secondary). Fires only on singular posts when the theme calls
	 * `comments_template()` and comments are open.
	 *
	 * @return void
	 */
	public function inject_comment_form_anchor() {
		if ( ! is_singular() ) {
			return;
		}

		$strategy = $this->get_placement_strategy();
		if ( 'content' === $strategy || 'iife_only' === $strategy ) {
			return;
		}

		$post_id = get_the_ID();
		if ( ! $this->is_quelora_active_for_post( $post_id ) ) {
			return;
		}

		echo $this->build_placement_anchor( $post_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}

$quelora_integration = new Quelora_Integration();
$quelora_integration->init();