<?php
/*
 * Quelora — quelora-wp-plugin
 * Copyright (C) 2026 Germán Zelaya — https://quelora.org
 * SPDX-License-Identifier: AGPL-3.0-only
 *
 * This file is part of Quelora. See the LICENSE file for terms.
 */

// filepath: includes/class-quelora-core.php

/**
 * Class Quelora_Core
 *
 * The core orchestrator class for the Quelora Integration plugin.
 * Responsible for defining all internationalization, public hooks,
 * admin hooks, and delegating execution to specific domain classes.
 * Features Event-Driven Sync and SPA backend routing.
 *
 * @package Quelora
 */
class Quelora_Core {

	/**
	 * @var Quelora_Admin The administrative dashboard and SPA settings manager.
	 */
	protected $admin;

	/**
	 * @var Quelora_Frontend The frontend asset injector and DOM manipulator.
	 */
	protected $frontend;

	/**
	 * @var Quelora_SSO The Single Sign-On and JWT token manager.
	 */
	protected $sso;

	/**
	 * @var Quelora_Sync The asynchronous and event-driven synchronization engine.
	 */
	protected $sync;

	/**
	 * Constructor.
	 *
	 * Instantiates all core dependencies required for the plugin's operation.
	 * Strict instantiation order is maintained for architectural consistency.
	 */
	public function __construct() {
		$this->admin    = new Quelora_Admin();
		$this->frontend = new Quelora_Frontend();
		$this->sso      = new Quelora_SSO();
		$this->sync     = new Quelora_Sync();
	}

	/**
	 * Executes the core plugin lifecycle by registering all WordPress hooks.
	 * Validates components availability before hook registration.
	 *
	 * @return void
	 */
	public function run() {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( $this, 'register_post_meta' ) );

		// Admin Hooks
		add_action( 'admin_menu',                             array( $this->admin, 'register_settings_page' ) );
		add_action( 'admin_enqueue_scripts',                  array( $this->admin, 'enqueue_admin_assets' ) );
		add_action( 'enqueue_block_editor_assets',            array( $this->admin, 'inject_editor_assets' ) );
		add_action( 'wp_ajax_quelora_save_settings',          array( $this->admin, 'ajax_save_settings' ) );
		add_action( 'wp_ajax_quelora_fetch_config',           array( $this->admin, 'ajax_fetch_config' ) );
		add_action( 'wp_ajax_quelora_health_check',           array( $this->admin, 'ajax_health_check' ) );
		add_action( 'wp_ajax_quelora_sync_config',            array( $this->admin, 'ajax_sync_config' ) );

		// Frontend Hooks
		add_action( 'wp_enqueue_scripts',                     array( $this->frontend, 'inject_frontend_assets' ) );
		add_action( 'wp_head',                                array( $this->frontend, 'inject_dynamic_configuration' ), 5 );
		add_action( 'wp_head',                                array( $this->frontend, 'inject_mount_script' ), 10 );
		add_action( 'wp_head',                                array( $this->frontend, 'inject_hide_comments_style' ), 20 );
		add_action( 'wp_head',                                array( $this->frontend, 'inject_custom_css' ), 25 );
		add_filter( 'the_content',                            array( $this->frontend, 'append_placement_anchor_to_content' ), 20 );
		add_action( 'comment_form_before',                    array( $this->frontend, 'inject_comment_form_anchor' ) );

		// SSO Hooks
		add_action( 'wp_head',                                array( $this->sso, 'inject_sso_token' ), 15 );
		add_action( 'wp_ajax_quelora_refresh_token',          array( $this->sso, 'ajax_refresh_token' ) );
		add_action( 'wp_ajax_nopriv_quelora_refresh_token',   array( $this->sso, 'ajax_refresh_token' ) );

		// Sync Hooks - Batch Process
		add_action( 'quelora_sync_posts_batch',               array( $this->sync, 'process_posts_sync_batch' ) );
		add_action( 'quelora_sync_users_batch',               array( $this->sync, 'process_users_sync_batch' ) );
		add_action( 'wp_ajax_quelora_trigger_posts_sync',     array( $this->sync, 'ajax_trigger_posts_sync' ) );
		add_action( 'wp_ajax_quelora_trigger_users_sync',     array( $this->sync, 'ajax_trigger_users_sync' ) );
		add_action( 'wp_ajax_quelora_sync_status',            array( $this->sync, 'ajax_get_sync_status' ) );
		add_action( 'wp_ajax_quelora_abort_sync',             array( $this->sync, 'ajax_abort_sync' ) );

		// Sync Hooks - Event Driven (Real-time)
		add_action( 'save_post',                              array( $this->sync, 'handle_post_saved' ), 10, 3 );
		add_action( 'user_register',                          array( $this->sync, 'handle_user_saved' ), 10, 1 );
		add_action( 'profile_update',                         array( $this->sync, 'handle_user_saved' ), 10, 2 );
	}

	/**
	 * Loads the plugin text domain for internationalization.
	 *
	 * Resolves the correct relative path ensuring that languages are properly 
	 * loaded regardless of directory naming or trailing slashes.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'quelora', false, basename( QUELORA_PLUGIN_DIR ) . '/languages' );
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
}