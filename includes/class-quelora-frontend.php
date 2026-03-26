<?php
// filepath: includes/class-quelora-frontend.php

/**
 * Class Quelora_Frontend
 *
 * Handles the frontend injection of Quelora assets (JS/CSS), dynamic
 * configuration payloads, and placement anchors.
 *
 * @package Quelora
 */
class Quelora_Frontend {

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
	 * 1. Explicit `_quelora_active` meta written on the post.
	 * 2. `quelora_default_active` global option.
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
		return get_option( 'quelora_placement_strategy', Quelora_Admin::DEFAULT_PLACEMENT_STRATEGY );
	}

	/**
	 * Builds the HTML string for a Quelora placement anchor element.
	 *
	 * The anchor carries two data attributes consumed by the Quelora JS widget:
	 * - `data-entity-anchor` — 24-char SHA-256 node ID derived from `post-{id}`.
	 * - `data-href`          — Post permalink, used by the widget for sharing.
	 *
	 * @param  int $post_id WordPress post ID.
	 * @return string HTML string for the placement anchor div.
	 */
	private function build_placement_anchor( $post_id ) {
		$node_id   = substr( hash( 'sha256', 'post-' . (int) $post_id ), 0, 24 );
		$permalink = (string) get_permalink( (int) $post_id );

		return sprintf(
			'<div class="quelora-placement-anchor" data-entity-anchor="%s" data-href="%s"></div>',
			esc_attr( $node_id ),
			esc_url( $permalink )
		);
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
			$js_url  = QUELORA_PLUGIN_URL . 'assets/quelora/js/quelora.js';
			$css_url = QUELORA_PLUGIN_URL . 'assets/css/quelora.css';
		} else {
			$js_url  = get_option( 'quelora_cdn_js_url', 'https://cdn.quelora.org/quelora.min.js' );
			$css_url = get_option( 'quelora_cdn_css_url', 'https://cdn.quelora.org/css/quelora.css' );
		}

		wp_enqueue_style( 'quelora-styles', $css_url, array(), QUELORA_VERSION, 'all' );
		wp_enqueue_script_module( 'quelora-core-module', $js_url, array(), QUELORA_VERSION );
	}

	/**
	 * Injects the integrator-supplied configuration script into the page head at priority 5.
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

		$header_selector = get_option( 'quelora_header_selector', Quelora_Admin::DEFAULT_HEADER_SELECTOR );

		global $wp_query;
		$posts_on_page = isset( $wp_query->posts ) ? $wp_query->posts : array();
		$posts_index   = array();

		foreach ( $posts_on_page as $queried_post ) {
			$pid = (int) $queried_post->ID;

			if ( ! $this->is_quelora_active_for_post( $pid ) ) {
				continue;
			}

			$node_id                 = substr( hash( 'sha256', 'post-' . $pid ), 0, 24 );
			$posts_index[ $node_id ] = (string) get_permalink( $pid );
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
						if ( ! index.hasOwnProperty( nodeId ) ) { return; }
						if ( document.querySelector( '[data-entity-anchor="' + nodeId + '"]' ) ) { return; }
						var anchor = document.createElement( 'div' );
						anchor.className = 'quelora-placement-anchor';
						anchor.setAttribute( 'data-entity-anchor', nodeId );
						anchor.setAttribute( 'data-href', index[ nodeId ] || '' );
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

		$custom_css = wp_unslash( (string) get_option( 'quelora_custom_css', Quelora_Admin::DEFAULT_CUSTOM_CSS ) );

		if ( empty( trim( $custom_css ) ) ) {
			return;
		}

		echo "<style id=\"quelora-custom-css\">\n";
		echo $custom_css . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo "</style>\n";
	}

	/**
	 * Appends a placement anchor to post content via the `the_content` filter.
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