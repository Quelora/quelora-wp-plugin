<?php
/**
 * Plugin Name: Quelora Integration
 * Plugin URI: https://www.quelora.org
 * Description: Advanced distributed community system integration for WordPress. Injects highly optimized ES Modules and CSS via a Sidebar toggle.
 * Version: 3.16.0
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
 * Mount point injection is handled entirely via a vanilla JS IIFE delivered
 * as an inline script, making the plugin theme-agnostic. Target containers
 * are resolved at runtime using CSS selectors configured in the settings page.
 *
 * SSO token generation uses a native PHP HS256 JWT implementation with no
 * external dependencies. The signed token is written to both sessionStorage
 * and localStorage on every authenticated page load using Quelora's native
 * wrapped format `{ value, expiry }`. An XHR-based renewal timer ensures the
 * token never expires while the user has the page open.
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
	 * Default CSS selector for the article meta widget insertion point.
	 *
	 * @var string
	 */
	const DEFAULT_META_SELECTOR = '.entry-header';

	/**
	 * Default SSO token time-to-live in seconds (1 hour).
	 *
	 * @var int
	 */
	const DEFAULT_TOKEN_TTL = 3600;

	/**
	 * Default state for the WordPress SSO token integration.
	 * Disabled by default; must be explicitly enabled in the settings page.
	 *
	 * @var bool
	 */
	const DEFAULT_SSO_ENABLED = false;

	/**
	 * Registers all WordPress action hooks for the plugin lifecycle.
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
		add_action( 'enqueue_block_editor_assets',   array( $this, 'inject_editor_assets' ) );
		add_action( 'wp_ajax_quelora_refresh_token', array( $this, 'ajax_refresh_token' ) );
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
				'default'       => false,
				'auth_callback' => function() {
					return current_user_can( 'edit_posts' );
				},
			)
		);
	}

	/**
	 * Registers the Quelora admin settings page in the WordPress menu.
	 *
	 * @return void
	 */
	public function register_settings_page() {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><rect width="100" height="100" rx="18" fill="#3b82c4"/><circle cx="45" cy="45" r="22" fill="none" stroke="#fff" stroke-width="16"/><line x1="56" y1="56" x2="76" y2="76" stroke="#fff" stroke-width="16" stroke-linecap="round"/></svg>';
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
		register_setting( 'quelora_settings_group', 'quelora_meta_selector' );

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
			'quelora_default_active',
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
	 * WordPress wraps all POST data with `addslashes()` before passing it to
	 * sanitize callbacks. This method strips those slashes without further
	 * encoding, preserving the raw JavaScript content as authored.
	 *
	 * @param  string $value Raw value submitted via the settings form.
	 * @return string Sanitized script content with submission slashes removed.
	 */
	public function sanitize_config_script( $value ) {
		return wp_unslash( (string) $value );
	}

	/**
	 * Renders the HTML for the Quelora admin settings page.
	 *
	 * @return void
	 */
	public function render_settings_page() {
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
								<input
									type="checkbox"
									name="quelora_default_active"
									value="1"
									<?php checked( get_option( 'quelora_default_active', false ) ); ?>
								/>
								<?php esc_html_e( 'Activate Quelora on all posts unless explicitly disabled per-post.', 'quelora' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'When enabled, Quelora assets are injected on every singular post that has not been explicitly toggled off in the editor sidebar.', 'quelora' ); ?>
							</p>
						</td>
					</tr>

					<tr valign="top">
						<th scope="row"><?php esc_html_e( 'Dashboard URL (Backend)', 'quelora' ); ?></th>
						<td>
							<input
								type="url"
								name="quelora_dashboard_url"
								value="<?php echo esc_attr( get_option( 'quelora_dashboard_url', self::DEFAULT_DASHBOARD_URL ) ); ?>"
								class="regular-text"
							/>
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
							<input
								type="text"
								name="quelora_header_selector"
								value="<?php echo esc_attr( get_option( 'quelora_header_selector', self::DEFAULT_HEADER_SELECTOR ) ); ?>"
								class="regular-text code"
							/>
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
						<th scope="row"><?php esc_html_e( 'Article Meta Selector', 'quelora' ); ?></th>
						<td>
							<input
								type="text"
								name="quelora_meta_selector"
								value="<?php echo esc_attr( get_option( 'quelora_meta_selector', self::DEFAULT_META_SELECTOR ) ); ?>"
								class="regular-text code"
							/>
							<p class="description">
								<?php
								printf(
									/* translators: 1: default selector, 2: injected element */
									esc_html__( 'CSS selector inside each &lt;article&gt;. Default: %1$s. Injects: %2$s', 'quelora' ),
									'<code>' . esc_html( self::DEFAULT_META_SELECTOR ) . '</code>',
									'<code>&lt;div class="quelora-meta" data-post-id="..."&gt;&lt;/div&gt;</code>'
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
							<input
								type="url"
								name="quelora_cdn_js_url"
								value="<?php echo esc_attr( get_option( 'quelora_cdn_js_url', 'https://cdn.quelora.org/quelora.min.js' ) ); ?>"
								class="regular-text"
							/>
						</td>
					</tr>

					<tr valign="top">
						<th scope="row"><?php esc_html_e( 'CDN CSS URL', 'quelora' ); ?></th>
						<td>
							<input
								type="url"
								name="quelora_cdn_css_url"
								value="<?php echo esc_attr( get_option( 'quelora_cdn_css_url', 'https://cdn.quelora.org/css/quelora.css' ) ); ?>"
								class="regular-text"
							/>
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
						<th scope="row"><?php esc_html_e( 'Enable WordPress SSO Token', 'quelora' ); ?></th>
						<td>
							<label>
								<input
									type="checkbox"
									name="quelora_sso_enabled"
									value="1"
									<?php checked( get_option( 'quelora_sso_enabled', self::DEFAULT_SSO_ENABLED ) ); ?>
								/>
								<?php esc_html_e( 'Generate a secure identity token and share it with Quelora for seamless, password-free login.', 'quelora' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'When enabled, Quelora receives only the authenticated user\'s display name and email address from your WordPress site to identify them. User passwords, session cookies, and WordPress credentials are never shared. A cryptographic signature is used to verify each user\'s identity — Quelora relies exclusively on this signature without accessing or inspecting WordPress sessions. Disable this option to allow Quelora to manage user registration and authentication independently.', 'quelora' ); ?>
							</p>
						</td>
					</tr>

					<tr valign="top">
						<th scope="row"><?php esc_html_e( 'SSO Secret Key', 'quelora' ); ?></th>
						<td>
							<input
								type="password"
								name="quelora_sso_secret_key"
								value="<?php echo esc_attr( get_option( 'quelora_sso_secret_key', '' ) ); ?>"
								class="regular-text"
								autocomplete="new-password"
							/>
							<p class="description">
								<?php esc_html_e( 'HMAC-SHA256 secret used to sign the SSO JWT. Must match the secret configured in the Quelora backend. Leave empty to disable SSO token injection.', 'quelora' ); ?>
							</p>
						</td>
					</tr>

					<tr valign="top">
						<th scope="row"><?php esc_html_e( 'SSO Token TTL (seconds)', 'quelora' ); ?></th>
						<td>
							<input
								type="number"
								name="quelora_sso_token_ttl"
								value="<?php echo esc_attr( get_option( 'quelora_sso_token_ttl', self::DEFAULT_TOKEN_TTL ) ); ?>"
								class="small-text"
								min="60"
								step="60"
							/>
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

		$dashboard_url  = get_option( 'quelora_dashboard_url', self::DEFAULT_DASHBOARD_URL );
		$default_active = (bool) get_option( 'quelora_default_active', false );
		$language       = get_locale();

		$script = sprintf(
			'window.QueloraEditorConfig = { dashboardUrl: "%s", defaultActive: %s, language: "%s" };',
			esc_url( $dashboard_url ),
			$default_active ? 'true' : 'false',
			esc_js( $language )
		);

		wp_add_inline_script( 'quelora-sidebar', $script, 'before' );
	}

	/**
	 * Determines whether Quelora assets should be active for the current context.
	 *
	 * Resolution order for singular posts:
	 *  1. Explicit `_quelora_active` meta on the post wins.
	 *  2. Falls back to the global `quelora_default_active` setting.
	 *
	 * Post listing pages (home, archives) are always considered active.
	 *
	 * @return bool True if Quelora assets should be enqueued.
	 */
	private function is_quelora_active_for_context() {
		if ( is_home() || is_archive() ) {
			return true;
		}

		if ( is_singular() ) {
			$post_id = get_the_ID();

			if ( metadata_exists( 'post', $post_id, '_quelora_active' ) ) {
				return (bool) get_post_meta( $post_id, '_quelora_active', true );
			}

			return (bool) get_option( 'quelora_default_active', false );
		}

		return false;
	}

	/**
	 * Enqueues Quelora CSS and the ES Module JS on the frontend.
	 *
	 * @return void
	 */
	public function inject_frontend_assets() {
		if ( ! $this->is_quelora_active_for_context() ) {
			return;
		}

		$source = get_option( 'quelora_asset_source', 'cdn' );

		if ( 'local' === $source ) {
			$js_url  = plugin_dir_url( __FILE__ ) . 'assets/quelora/js/quelora.js';
			$css_url = plugin_dir_url( __FILE__ ) . 'assets/css/quelora.css';
		} else {
			$js_url  = get_option( 'quelora_cdn_js_url', 'https://cdn.quelora.org/quelora.min.js' );
			$css_url = get_option( 'quelora_cdn_css_url', 'https://cdn.quelora.org/css/quelora.css' );
		}

		wp_enqueue_style( 'quelora-styles', $css_url, array(), '2.0.0', 'all' );
		wp_enqueue_script_module( 'quelora-core-module', $js_url, array(), '2.0.0' );
	}

	/**
	 * Injects the dynamic per-site configuration script into the page head.
	 *
	 * The configuration payload is a site-wide script, not post-specific.
	 * It fires on every page where Quelora is active — including listing pages
	 * (home, archives) — using the same context resolution as asset injection.
	 *
	 * `wp_unslash()` is applied at read time as a defensive measure against
	 * values stored before the sanitize_callback was deployed. For values already
	 * stored clean, `wp_unslash()` / `stripslashes_deep()` is a safe no-op.
	 *
	 * Runs at wp_head priority 5 — before mount (10) and SSO (15) scripts.
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
	 * Builds the post data payload for a single post used in QueloraPostsIndex.
	 *
	 * Resolves title, description (excerpt with fallback), tags, categories,
	 * language and permalink for the given post ID.
	 *
	 * Description resolution order:
	 *  1. Post excerpt if non-empty.
	 *  2. Title repeated twice (separated by a space), truncated to 1000 chars.
	 *     This guarantees the backend required field is never empty.
	 *
	 * @param  int $post_id WordPress post ID.
	 * @return array Associative array with post hydration data.
	 */
	private function build_post_data( $post_id ) {
		$post    = get_post( $post_id );
		$title   = get_the_title( $post_id );
		$excerpt = has_excerpt( $post_id )
			? wp_strip_all_tags( get_the_excerpt( $post ) )
			: '';

		if ( empty( $excerpt ) ) {
			$excerpt = mb_substr( $title . ' ' . $title, 0, 1000 );
		} else {
			$excerpt = mb_substr( $excerpt, 0, 1000 );
		}

		$tags = array_values(
			array_map(
				function( $tag ) { return $tag->name; },
				wp_get_post_tags( $post_id ) ?: array()
			)
		);

		$cat_ids    = wp_get_post_categories( $post_id ) ?: array();
		$categories = array_values(
			array_filter(
				array_map(
					function( $cat_id ) {
						$cat = get_category( $cat_id );
						return ( $cat && ! is_wp_error( $cat ) ) ? $cat->name : null;
					},
					$cat_ids
				)
			)
		);

		return array(
			'title'       => $title,
			'description' => $excerpt,
			'tags'        => $tags,
			'category'    => $categories,
			'language'    => get_locale(),
			'link'        => get_permalink( $post_id ),
		);
	}

	/**
	 * Injects the Quelora frontend config global and the mount point IIFE.
	 *
	 * Outputs three inline scripts into wp_head at priority 10:
	 *
	 * 1. `window.QueloraConfig` — CSS selectors for header and article meta mount points.
	 * 2. `window.QueloraPostsIndex` — A map of `nodeId → postData` for every Quelora-active
	 *    post on the current page, pre-computed by PHP. The IIFE reads from this index to
	 *    set `data-*` attributes on each `.quelora-meta` mount div, enabling the widget to
	 *    hydrate the post without additional HTTP requests.
	 * 3. Mount point IIFE — Vanilla JS that appends the header widget div once and,
	 *    on DOMContentLoaded, iterates every `article[id^="post-"]` to derive the node ID,
	 *    look up the post data from the index, create the `.quelora-meta` div with all
	 *    hydration data attributes, and append it to the configured selector within the article.
	 *    Falls back to the article root if the inner selector is not found.
	 *    Both mount operations are idempotent.
	 *
	 * @return void
	 */
	public function inject_mount_script() {
		if ( ! $this->is_quelora_active_for_context() ) {
			return;
		}

		$header_selector = get_option( 'quelora_header_selector', self::DEFAULT_HEADER_SELECTOR );
		$meta_selector   = get_option( 'quelora_meta_selector', self::DEFAULT_META_SELECTOR );

		global $wp_query;
		$posts_on_page = isset( $wp_query->posts ) ? $wp_query->posts : array();

		$posts_index = array();

		foreach ( $posts_on_page as $queried_post ) {
			$pid = (int) $queried_post->ID;

			$is_active = metadata_exists( 'post', $pid, '_quelora_active' )
				? (bool) get_post_meta( $pid, '_quelora_active', true )
				: (bool) get_option( 'quelora_default_active', false );

			if ( ! $is_active ) {
				continue;
			}

			$node_id                  = substr( hash( 'sha256', 'post-' . $pid ), 0, 24 );
			$posts_index[ $node_id ]  = $this->build_post_data( $pid );
		}

		$config = wp_json_encode(
			array(
				'headerSelector' => $header_selector,
				'metaSelector'   => $meta_selector,
			)
		);

		?>
		<script id="quelora-config">
		window.QueloraConfig     = <?php echo $config; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
		window.QueloraPostsIndex = <?php echo wp_json_encode( $posts_index ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
		</script>
		<script id="quelora-mount">
		(function () {
			var cfg   = window.QueloraConfig     || {};
			var index = window.QueloraPostsIndex || {};

			function mountHeader() {
				if ( ! cfg.headerSelector ) { return; }
				if ( document.getElementById( 'quelora-header-widget' ) ) { return; }
				var container = document.querySelector( cfg.headerSelector );
				if ( ! container ) { return; }
				var el = document.createElement( 'div' );
				el.id = 'quelora-header-widget';
				container.appendChild( el );
			}

			function toNodeId( input ) {
				var str = String( input );
				if ( /^[0-9a-f]{24}$/.test( str.toLowerCase() ) ) {
					return Promise.resolve( str.toLowerCase() );
				}
				var data = new TextEncoder().encode( str );
				return crypto.subtle.digest( 'SHA-256', data ).then( function ( hashBuffer ) {
					return Array.from( new Uint8Array( hashBuffer ) )
						.map( function ( b ) { return b.toString( 16 ).padStart( 2, '0' ); } )
						.join( '' )
						.substring( 0, 24 )
						.toLowerCase();
				} );
			}

			function mountArticleMeta() {
				var articles = document.querySelectorAll( 'article[id^="post-"]' );
				articles.forEach( function ( article ) {
					if ( article.querySelector( '.quelora-meta' ) ) { return; }
					var rawId = article.getAttribute( 'id' ) || '';
					if ( ! rawId ) { return; }
					toNodeId( rawId ).then( function ( nodeId ) {
						if ( article.querySelector( '.quelora-meta' ) ) { return; }
						var postData = index[ nodeId ] || {};
						var target   = cfg.metaSelector
							? ( article.querySelector( cfg.metaSelector ) || article )
							: article;
						var el = document.createElement( 'div' );
						el.className = 'quelora-meta';
						el.setAttribute( 'data-post-id',       nodeId );
						el.setAttribute( 'data-title',         postData.title       || '' );
						el.setAttribute( 'data-description',   postData.description || '' );
						el.setAttribute( 'data-tags',          JSON.stringify( postData.tags     || [] ) );
						el.setAttribute( 'data-category',      JSON.stringify( postData.category || [] ) );
						el.setAttribute( 'data-language',      postData.language    || '' );
						el.setAttribute( 'data-link',          postData.link        || '' );
						target.appendChild( el );
					} );
				} );
			}

			mountHeader();

			if ( document.readyState === 'loading' ) {
				document.addEventListener( 'DOMContentLoaded', mountArticleMeta );
			} else {
				mountArticleMeta();
			}
		}());
		</script>
		<?php
	}

	/**
	 * Encodes data to Base64URL format as required by the JWT specification.
	 *
	 * Base64URL differs from standard Base64 in three ways: `+` becomes `-`,
	 * `/` becomes `_`, and padding `=` characters are stripped.
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
	 * The token structure mirrors the ID token issued by Google OAuth2:
	 * - `iss`     — Issuer: the current site URL.
	 * - `aud`     — Audience: the Quelora dashboard URL.
	 * - `sub`     — Subject: the WordPress user ID (string, per JWT spec).
	 * - `email`   — User email address.
	 * - `name`    — User display name.
	 * - `picture` — Gravatar URL for the user avatar.
	 * - `author`  — Opaque, deterministic SHA-256 identifier equivalent to
	 *               Google's internal user identifier format (64-char hex string).
	 *               Derived from: sha256( user_id + wp_salt('auth') ).
	 *               Uses the WordPress AUTH_SALT (wp-config.php) as entropy source:
	 *               unique per installation, never exposed publicly, and fully
	 *               independent from the SSO secret key — which is reserved
	 *               exclusively for JWT signing. Stable across token refreshes
	 *               unless AUTH_SALT is manually rotated.
	 * - `iat`     — Issued-at Unix timestamp.
	 * - `exp`     — Expiration Unix timestamp (iat + TTL).
	 *
	 * The signature uses HMAC-SHA256 over `{header}.{payload}` with the
	 * configured secret key — no external JWT library required.
	 *
	 * Returns null when:
	 *  - The user is not logged in.
	 *  - The `quelora_sso_enabled` setting is disabled.
	 *  - No secret key is configured.
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

		// JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE are mandatory for
		// standards-compliant JWT generation. wp_json_encode() escapes forward
		// slashes (/ → \/) by default, producing a base64url payload that differs
		// from what Node.js and every other JWT library generate — causing signature
		// verification failures on the receiving end despite a structurally valid token.
		$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

		$header = $this->base64url_encode(
			json_encode(
				array(
					'alg' => 'HS256',
					'typ' => 'JWT',
				),
				$flags
			)
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
	 * Injects the SSO token into both sessionStorage and localStorage.
	 *
	 * Runs at wp_head priority 15 — after mount and config scripts.
	 *
	 * PHP generates a fresh HS256 JWT on every authenticated page load.
	 * The inline script checks whether a non-expired token already exists in
	 * either storage before overwriting, avoiding unnecessary churn on fast
	 * navigations within the same browser tab session.
	 *
	 * Token storage format:
	 *  - `ql_sso_token`         — JSON-wrapped object `{ value: JWT, expiry: ms }`,
	 *                             matching Quelora's native storage contract. Written
	 *                             to BOTH sessionStorage and localStorage so the widget
	 *                             finds the token regardless of its configured storage mode.
	 *  - `ql_sso_token_expires` — Expiration Unix timestamp in milliseconds (plain string),
	 *                             kept for backward compatibility.
	 *
	 * An XHR-based renewal timer (`scheduleRenewal`) fires 5 minutes before expiry,
	 * fetches a fresh token via `wp_ajax_quelora_refresh_token`, stores it, and
	 * re-schedules itself. This ensures the token never expires while the page is open.
	 *
	 * When the user is not logged in or SSO is disabled, any existing tokens in
	 * both storages are explicitly removed to prevent stale authenticated state
	 * across login/logout transitions.
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
					keys.forEach( function ( k ) {
						try { s.removeItem( k ); } catch (e) {}
					} );
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

			/**
			 * Writes the plain JWT string and its expiry timestamp to both
			 * sessionStorage and localStorage.
			 *
			 * Keys written:
			 *  - `ql_sso_token`         — Raw JWT string (plain text, no JSON wrapper).
			 *  - `ql_sso_token_expires` — Expiration Unix timestamp in milliseconds.
			 *
			 * @param {string} t   - Signed JWT string.
			 * @param {number} exp - Expiration timestamp in milliseconds.
			 */
			function storeToken( t, exp ) {
				var stores = [ sessionStorage, localStorage ];
				stores.forEach( function ( s ) {
					try {
						s.setItem( 'ql_sso_token',         t );
						s.setItem( 'ql_sso_token_expires', String( exp ) );
					} catch (e) {}
				} );
			}

			/**
			 * Reads the expiry timestamp from sessionStorage or localStorage,
			 * whichever has a valid (non-zero) value first.
			 *
			 * @return {number} Expiry timestamp in ms, or 0 if not found.
			 */
			function getStoredExpiry() {
				var stores = [ sessionStorage, localStorage ];
				for ( var i = 0; i < stores.length; i++ ) {
					try {
						var exp = parseInt( stores[i].getItem( 'ql_sso_token_expires' ), 10 );
						if ( exp > 0 ) { return exp; }
					} catch (e) {}
				}
				return 0;
			}

			var storedExpiry = getStoredExpiry();
			var stillValid   = storedExpiry > 0 && ( storedExpiry - Date.now() > 30000 );

			if ( ! stillValid ) {
				storeToken( token, expiresAt );
			}

			/**
			 * Schedules a token renewal 5 minutes before the current token expires.
			 * On success, stores the refreshed token and re-schedules the next renewal,
			 * keeping the token alive as long as the page remains open.
			 *
			 * The minimum interval is capped at 30 seconds to prevent tight loops
			 * when the configured TTL is very short.
			 *
			 * @param {number} expiryMs - Current token expiry timestamp in milliseconds.
			 */
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
					xhr.send(
						'action=quelora_refresh_token&nonce=' + encodeURIComponent( nonce )
					);
				}, Math.max( 30000, delay ) );
			}

			scheduleRenewal( stillValid ? storedExpiry : expiresAt );
		}());
		</script>
		<?php
	}


	/**
	 * WordPress AJAX handler for SSO token renewal on authenticated page sessions.
	 *
	 * Verifies the request nonce, delegates token generation to `generate_sso_token()`,
	 * and returns the signed JWT with its expiration timestamp in milliseconds.
	 *
	 * Bound to the `wp_ajax_quelora_refresh_token` action hook, which restricts
	 * access to logged-in users only. Unauthenticated requests are rejected by
	 * WordPress before this handler is invoked.
	 *
	 * Error conditions:
	 *  - Invalid nonce: `check_ajax_referer` terminates with a 403.
	 *  - SSO disabled or secret key empty: returns JSON error 403.
	 *  - User not logged in: WordPress rejects via `wp_ajax_*` (never reached).
	 *
	 * @return void Sends a JSON response and terminates execution.
	 */
	public function ajax_refresh_token() {
		check_ajax_referer( 'quelora_refresh_token', 'nonce' );

		$token = $this->generate_sso_token();

		if ( null === $token ) {
			wp_send_json_error(
				array( 'message' => 'SSO token generation is disabled or unavailable.' ),
				403
			);
			return;
		}

		$ttl        = (int) get_option( 'quelora_sso_token_ttl', self::DEFAULT_TOKEN_TTL );
		$expires_ms = ( time() + max( 60, $ttl ) ) * 1000;

		wp_send_json_success(
			array(
				'token'     => $token,
				'expiresAt' => $expires_ms,
			)
		);
	}
}

$quelora_integration = new Quelora_Integration();
$quelora_integration->init();