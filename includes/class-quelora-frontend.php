<?php
/*
 * Quelora — quelora-wp-plugin
 * Copyright (C) 2026 Germán Zelaya — https://quelora.org
 * SPDX-License-Identifier: AGPL-3.0-only
 *
 * This file is part of Quelora. See the LICENSE file for terms.
 */

// filepath: includes/class-quelora-frontend.php

/**
 * Class Quelora_Frontend
 *
 * Handles the frontend injection of Quelora assets (JS/CSS), dynamic
 * configuration payloads, and placement anchors. Features strict CSP
 * (Content Security Policy) compliance utilizing the WordPress dependency API.
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
	 * @param int $post_id The WordPress Post ID.
	 * @return bool True if active.
	 */
	private function is_quelora_active_for_post( $post_id ) {
		$is_active = get_post_meta( $post_id, '_quelora_active', true );

		if ( '' === $is_active ) {
			return (bool) get_option( 'quelora_default_active', false );
		}

		return (bool) $is_active;
	}

	/**
	 * Retrieves the configured placement strategy for the interaction anchors.
	 *
	 * @return string The placement strategy identifier.
	 */
	private function get_placement_strategy() {
		return get_option( 'quelora_placement_strategy', 'both' );
	}

	/**
	 * Builds the HTML anchor markup required by the Quelora IIFE.
	 *
	 * @param int $post_id The WordPress Post ID.
	 * @return string HTML div element.
	 */
	private function build_placement_anchor( $post_id ) {
		$node_id = 'post-' . $post_id;
		return '<div data-entity-anchor="' . esc_attr( $node_id ) . '" class="quelora-interaction-node"></div>';
	}

	/**
	 * Enqueues the primary Quelora JavaScript and CSS bundles.
	 *
	 * @return void
	 */
	public function inject_frontend_assets() {
		if ( ! $this->is_quelora_active_for_context() ) {
			return;
		}

		$source = get_option( 'quelora_asset_source', 'local' );

		if ( 'cdn' === $source ) {
			$js_url  = get_option( 'quelora_cdn_js_url', 'https://cdn.quelora.org/quelora.min.js' );
			$css_url = get_option( 'quelora_cdn_css_url', 'https://cdn.quelora.org/css/quelora.css' );
		} else {
			$js_url  = QUELORA_PLUGIN_URL . 'assets/js/quelora.js';
			$css_url = QUELORA_PLUGIN_URL . 'assets/css/quelora.css';
		}

		wp_enqueue_style( 'quelora-frontend-css', $css_url, array(), QUELORA_VERSION );
		wp_enqueue_script( 'quelora-frontend-js', $js_url, array(), QUELORA_VERSION, true );
		add_filter( 'script_loader_tag', array( $this, 'add_module_type_to_script' ), 10, 2 );
	}

	/**
	 * Adds type="module" to the Quelora JS script tag.
	 *
	 * @param string $tag    The rendered <script> tag.
	 * @param string $handle The script handle.
	 * @return string Modified tag.
	 */
	public function add_module_type_to_script( $tag, $handle ) {
		if ( 'quelora-frontend-js' === $handle ) {
			$tag = str_replace( '<script ', '<script type="module" ', $tag );
		}
		return $tag;
	}

	/**
	 * Injects the global window.QUELORA_CONFIG object.
	 * Utilizes the WP Dependency API to maintain CSP compliance.
	 *
	 * @return void
	 */
	public function inject_dynamic_configuration() {
		if ( ! $this->is_quelora_active_for_context() ) {
			return;
		}

		$config_script = get_option( 'quelora_config_script', '' );
		
		if ( empty( $config_script ) ) {
			return;
		}

		echo '<script>' . wp_unslash( $config_script ) . '</script>' . "\n";
	}

	/**
	 * Injects the DOM mounting script for the header widget.
	 * Utilizes the WP Dependency API for secure execution.
	 *
	 * @return void
	 */
	public function inject_mount_script() {
		if ( ! $this->is_quelora_active_for_context() ) {
			return;
		}

		$header_selector = get_option( 'quelora_header_selector', '.site-header-primary-section-right' );
		
		$script = '
			document.addEventListener("DOMContentLoaded", function() {
				var header = document.querySelector("' . esc_js( $header_selector ) . '");
				if (header && !document.getElementById("quelora-header-widget")) {
					var widget = document.createElement("div");
					widget.id = "quelora-header-widget";
					header.appendChild(widget);
				}
			});
		';

		wp_register_script( 'quelora-mount-script', false );
		wp_enqueue_script( 'quelora-mount-script' );
		wp_add_inline_script( 'quelora-mount-script', $script );
		wp_print_scripts( 'quelora-mount-script' );
	}

	/**
	 * Injects CSS to suppress native WordPress comments when active.
	 * Utilizes WP native functions to support CSP nonce generation.
	 *
	 * @return void
	 */
	public function inject_hide_comments_style() {
		if ( ! (bool) get_option( 'quelora_hide_wp_comments', false ) ) {
			return;
		}

		if ( ! is_singular() || ! $this->is_quelora_active_for_post( get_the_ID() ) ) {
			return;
		}

		$css = '#comments, .comment-respond { display: none !important; }';

		wp_register_style( 'quelora-hide-comments', false );
		wp_enqueue_style( 'quelora-hide-comments' );
		wp_add_inline_style( 'quelora-hide-comments', $css );
		wp_print_styles( 'quelora-hide-comments' );
	}

	/**
	 * Injects administrator-defined custom CSS safely.
	 *
	 * @return void
	 */
	public function inject_custom_css() {
		$custom_css = get_option( 'quelora_custom_css', '' );
		
		if ( empty( $custom_css ) ) {
			return;
		}

		wp_register_style( 'quelora-custom-css', false );
		wp_enqueue_style( 'quelora-custom-css' );
		wp_add_inline_style( 'quelora-custom-css', wp_strip_all_tags( wp_unslash( $custom_css ) ) );
		wp_print_styles( 'quelora-custom-css' );
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