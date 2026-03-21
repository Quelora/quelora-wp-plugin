<?php
/**
 * Plugin Name: Quelora Integration
 * Plugin URI: https://www.quelora.org
 * Update URI: https://www.quelora.org/wp-plugin/quelora-wp-integration
 * Description: Advanced distributed community system integration for WordPress. Injects highly optimized ES Modules and CSS via a Sidebar toggle.
 * Version: 4.5.0
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
 * article — no post metadata is included. Post metadata (title, description, tags,
 * etc.) is only needed in the Gutenberg editor (sidebar.js / edit.js), where it
 * is read directly from the editor store without any PHP involvement.
 *
 * ## Meta default — source of truth for the sidebar toggle
 *
 * `_quelora_active` is registered with `default` set to the current value of the
 * `quelora_default_active` option at request time. This ensures the REST API and
 * Gutenberg editor store return the correct global default for posts that have
 * never been explicitly saved with this meta key.
 *
 * ## SSO
 *
 * SSO token generation uses a native PHP HS256 JWT implementation with no
 * external dependencies.
 */
class Quelora_Integration {

	/**
	 * Default base URL for the Quelora embedded dashboard.
	 *
	 * @var string
	 */
	const DEFAULT_DASHBOARD_URL = 'https://dashboard.quelora.local/embed/post/';

	/**
	 * Default CSS selector for the header widget mount point.
	 *
	 * @var string
	 */
	const DEFAULT_HEADER_SELECTOR = '.site-header-primary-section-right';

	/**
	 * Default SSO token time-to-live in seconds (1 hour).
	 *
	 * @var int
	 */
	const DEFAULT_TOKEN_TTL = 3600;

	/**
	 * Default state for the WordPress SSO token integration.
	 *
	 * @var bool
	 */
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

	/**
	 * Default value for the custom inline CSS field.
	 *
	 * @var string
	 */
	const DEFAULT_CUSTOM_CSS = '';

	/**
	 * Ordered cascade of CSS selectors used by the IIFE to locate the content
	 * container inside an article. The first match wins. Covers Astra, GeneratePress,
	 * Kadence, OceanWP, Neve, Twenty* themes, and block/FSE themes.
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

	/**
	 * Loads the plugin text domain for internationalization.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'quelora', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}

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

	/**
	 * Returns the plugin version string read from the plugin file header.
	 *
	 * Uses `get_file_data()` — always available in WordPress, no conditional
	 * require needed — and caches the result in a static variable so the header
	 * is parsed only once per request. This makes the plugin version the single
	 * source of truth: the Makefile updates `* Version:` in this file and all
	 * enqueued asset version strings follow automatically.
	 *
	 * @return string Plugin version string (e.g. `4.4.0`).
	 */
	private function get_plugin_version() {
		static $version = null;

		if ( null === $version ) {
			$data    = get_file_data( __FILE__, array( 'Version' => 'Version' ) );
			$version = ! empty( $data['Version'] ) ? $data['Version'] : '1.0.0';
		}

		return $version;
	}

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

	/**
	 * Renders the HTML for the Quelora admin settings page.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		$current_strategy = get_option( 'quelora_placement_strategy', self::DEFAULT_PLACEMENT_STRATEGY );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Quelora Integration Settings', 'quelora' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'quelora_settings_group' ); ?>
				<?php do_settings_sections( 'quelora_settings_group' ); ?>
				<table class="form-table">

					<tr valign="top">
						<th scope="row"><?php esc_html_e( 'Enable Quelora by Default', 'quelora' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="quelora_default_active" value="1"
									<?php checked( get_option( 'quelora_default_active', false ) ); ?> />
								<?php esc_html_e( 'Activate Quelora on all posts unless explicitly disabled per-post.', 'quelora' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'When enabled, Quelora assets are injected on every post that has not been explicitly toggled off in the editor sidebar.', 'quelora' ); ?>
							</p>
						</td>
					</tr>

					<tr valign="top">
						<th scope="row"><?php esc_html_e( 'PHP Placement Hooks', 'quelora' ); ?></th>
						<td>
							<fieldset>
								<p class="description" style="margin-bottom:12px;">
									<?php esc_html_e( 'The IIFE script always injects anchors client-side (primary layer). These options control additional server-side injection via WordPress hooks (secondary layer).', 'quelora' ); ?>
								</p>
								<label style="display:block;margin-bottom:8px;">
									<input type="radio" name="quelora_placement_strategy" value="both"
										<?php checked( $current_strategy, 'both' ); ?> />
									<strong><?php esc_html_e( 'Both hooks (recommended)', 'quelora' ); ?></strong>
									&mdash;
									<?php esc_html_e( 'Fires both the_content filter and comment_form_before action.', 'quelora' ); ?>
								</label>
								<label style="display:block;margin-bottom:8px;">
									<input type="radio" name="quelora_placement_strategy" value="content"
										<?php checked( $current_strategy, 'content' ); ?> />
									<strong><?php esc_html_e( 'Content filter only', 'quelora' ); ?></strong>
									&mdash;
									<?php esc_html_e( 'Appends anchor at the end of post content via the_content filter.', 'quelora' ); ?>
								</label>
								<label style="display:block;margin-bottom:8px;">
									<input type="radio" name="quelora_placement_strategy" value="comment_form"
										<?php checked( $current_strategy, 'comment_form' ); ?> />
									<strong><?php esc_html_e( 'Comment form only', 'quelora' ); ?></strong>
									&mdash;
									<?php esc_html_e( 'Injects anchor before the WordPress comment form on single posts.', 'quelora' ); ?>
								</label>
								<label style="display:block;">
									<input type="radio" name="quelora_placement_strategy" value="iife_only"
										<?php checked( $current_strategy, 'iife_only' ); ?> />
									<strong><?php esc_html_e( 'IIFE only', 'quelora' ); ?></strong>
									&mdash;
									<?php esc_html_e( 'Disables PHP hooks entirely. The client-side IIFE is the sole injector.', 'quelora' ); ?>
								</label>
							</fieldset>
						</td>
					</tr>

					<tr valign="top">
						<th scope="row"><?php esc_html_e( 'Hide WordPress Comments', 'quelora' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="quelora_hide_wp_comments" value="1"
									<?php checked( get_option( 'quelora_hide_wp_comments', false ) ); ?> />
								<?php esc_html_e( 'Suppress native WordPress comment threads and reply form when Quelora is active.', 'quelora' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Hides comment lists, headings, navigation, and the reply form inside #comments. The container itself remains visible so Quelora anchors remain reachable. Only applies on single post pages.', 'quelora' ); ?>
							</p>
						</td>
					</tr>

					<tr valign="top">
						<th scope="row"><?php esc_html_e( 'Dashboard URL (Backend)', 'quelora' ); ?></th>
						<td>
							<input type="url" name="quelora_dashboard_url"
								value="<?php echo esc_attr( get_option( 'quelora_dashboard_url', self::DEFAULT_DASHBOARD_URL ) ); ?>"
								class="regular-text" />
							<p class="description">
								<?php
								printf(
									/* translators: %s: example composed URL */
									esc_html__( 'Base embed URL. The post identifier will be appended automatically (e.g. %s).', 'quelora' ),
									'<code>' . esc_html( rtrim( self::DEFAULT_DASHBOARD_URL, '/' ) . '/post-42' ) . '</code>'
								);
								?>
							</p>
						</td>
					</tr>

					<tr valign="top">
						<th scope="row"><?php esc_html_e( 'Header Widget Selector', 'quelora' ); ?></th>
						<td>
							<input type="text" name="quelora_header_selector"
								value="<?php echo esc_attr( get_option( 'quelora_header_selector', self::DEFAULT_HEADER_SELECTOR ) ); ?>"
								class="regular-text code" />
							<p class="description">
								<?php
								printf(
									/* translators: 1: default selector, 2: injected element */
									esc_html__( 'CSS selector for the header container. Default: %1$s. Injects: %2$s', 'quelora' ),
									'<code>' . esc_html( self::DEFAULT_HEADER_SELECTOR ) . '</code>',
									'<code>&lt;div id="quelora-header-widget"&gt;&lt;/div&gt;</code>'
								);
								?>
							</p>
						</td>
					</tr>

					<tr valign="top">
						<th scope="row"><?php esc_html_e( 'Asset Source', 'quelora' ); ?></th>
						<td>
							<select name="quelora_asset_source">
								<option value="local" <?php selected( get_option( 'quelora_asset_source', 'cdn' ), 'local' ); ?>>
									<?php esc_html_e( 'Local Package (Included)', 'quelora' ); ?>
								</option>
								<option value="cdn" <?php selected( get_option( 'quelora_asset_source', 'cdn' ), 'cdn' ); ?>>
									<?php esc_html_e( 'External CDN', 'quelora' ); ?>
								</option>
							</select>
						</td>
					</tr>

					<tr valign="top">
						<th scope="row"><?php esc_html_e( 'CDN JS URL', 'quelora' ); ?></th>
						<td>
							<input type="url" name="quelora_cdn_js_url"
								value="<?php echo esc_attr( get_option( 'quelora_cdn_js_url', 'https://cdn.quelora.org/quelora.min.js' ) ); ?>"
								class="regular-text" />
						</td>
					</tr>

					<tr valign="top">
						<th scope="row"><?php esc_html_e( 'CDN CSS URL', 'quelora' ); ?></th>
						<td>
							<input type="url" name="quelora_cdn_css_url"
								value="<?php echo esc_attr( get_option( 'quelora_cdn_css_url', 'https://cdn.quelora.org/css/quelora.css' ) ); ?>"
								class="regular-text" />
						</td>
					</tr>

					<tr valign="top">
						<th scope="row"><?php esc_html_e( 'Configuration Script Payload', 'quelora' ); ?></th>
						<td>
							<textarea name="quelora_config_script" rows="15" class="large-text code"><?php echo esc_textarea( get_option( 'quelora_config_script', '' ) ); ?></textarea>
							<p class="description">
								<?php esc_html_e( 'Raw JavaScript injected as an ES Module in the <head>. Only active on posts with Quelora enabled.', 'quelora' ); ?>
							</p>
						</td>
					</tr>

					<tr valign="top">
						<th scope="row"><?php esc_html_e( 'Custom CSS', 'quelora' ); ?></th>
						<td>
							<textarea name="quelora_custom_css" rows="10" class="large-text code"
								spellcheck="false"><?php echo esc_textarea( get_option( 'quelora_custom_css', self::DEFAULT_CUSTOM_CSS ) ); ?></textarea>
							<p class="description">
								<?php esc_html_e( 'Raw CSS injected in a <style> tag in the <head> on every page where Quelora is active. Use this to override Quelora widget styles without editing theme files.', 'quelora' ); ?>
							</p>
						</td>
					</tr>

					<tr valign="top">
						<th scope="row"><?php esc_html_e( 'Enable WordPress SSO Token', 'quelora' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="quelora_sso_enabled" value="1"
									<?php checked( get_option( 'quelora_sso_enabled', self::DEFAULT_SSO_ENABLED ) ); ?> />
								<?php esc_html_e( 'Generate a secure identity token and share it with Quelora for seamless, password-free login.', 'quelora' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'When enabled, Quelora receives only the authenticated user\'s display name and email address. Passwords and session cookies are never shared.', 'quelora' ); ?>
							</p>
						</td>
					</tr>

					<tr valign="top">
						<th scope="row"><?php esc_html_e( 'SSO Secret Key', 'quelora' ); ?></th>
						<td>
							<input type="password" name="quelora_sso_secret_key"
								value="<?php echo esc_attr( get_option( 'quelora_sso_secret_key', '' ) ); ?>"
								class="regular-text" autocomplete="new-password" />
							<p class="description">
								<?php esc_html_e( 'HMAC-SHA256 secret used to sign the SSO JWT. Must match the secret configured in the Quelora backend.', 'quelora' ); ?>
							</p>
						</td>
					</tr>

					<tr valign="top">
						<th scope="row"><?php esc_html_e( 'SSO Token TTL (seconds)', 'quelora' ); ?></th>
						<td>
							<input type="number" name="quelora_sso_token_ttl"
								value="<?php echo esc_attr( get_option( 'quelora_sso_token_ttl', self::DEFAULT_TOKEN_TTL ) ); ?>"
								class="small-text" min="60" step="60" />
							<p class="description">
								<?php
								printf(
									/* translators: %s: default TTL in seconds */
									esc_html__( 'How long the SSO token remains valid in seconds. Default: %s (1 hour).', 'quelora' ),
									'<code>' . esc_html( self::DEFAULT_TOKEN_TTL ) . '</code>'
								);
								?>
							</p>
						</td>
					</tr>

				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

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

	/**
	 * Injects the dynamic per-site configuration script into the page head at priority 5.
	 *
	 * @return void
	 */
	public function inject_dynamic_configuration() {
		if ( ! $this->is_quelora_active_for_context() ) {
			return;
		}

		$config_script = wp_unslash( (string) get_option( 'quelora_config_script', '' ) );

		if ( ! empty( $config_script ) ) {
			echo "<script type=\"module\" id=\"quelora-dynamic-config\">\n";
			echo $config_script . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo "</script>\n";
		}
	}

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
	 * Runs after all other Quelora styles. Only fires when Quelora is active
	 * for the current page and the field is non-empty.
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

	/**
	 * Injects the Quelora config globals and the mount IIFE into wp_head at priority 10.
	 *
	 * Outputs two inline scripts:
	 *
	 * **`window.QueloraConfig`** — CSS selectors for the header widget mount point
	 * and the `contentSelectorCascade` used by the IIFE to locate content containers.
	 *
	 * **`window.QueloraPostsIndex`** — A flat object `{ nodeId: true }` containing
	 * only the 24-char node IDs of Quelora-active posts on the current page. The
	 * IIFE uses this exclusively to decide whether to inject an anchor for a given
	 * article. No post metadata is included — that data is only needed in the
	 * Gutenberg editor and is read there directly from the editor store.
	 *
	 * **Mount IIFE** — Runs on DOMContentLoaded and performs two operations:
	 *
	 *  1. `mountHeader()` — Appends the header widget div. Idempotent.
	 *
	 *  2. `mountPlacementAnchors()` — PRIMARY placement layer (Layer 1).
	 *     For each article whose node ID is in QueloraPostsIndex, injects
	 *     `[data-entity-anchor]` immediately AFTER the first content container
	 *     found by the cascade. Skips articles where the anchor already exists
	 *     (PHP Layer 2 already handled it — idempotent guard).
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
}

$quelora_integration = new Quelora_Integration();
$quelora_integration->init();