<?php
/*
 * Quelora — quelora-wp-plugin
 * Copyright (C) 2026 Germán Zelaya — https://quelora.org
 * SPDX-License-Identifier: AGPL-3.0-only
 *
 * This file is part of Quelora. See the LICENSE file for terms.
 */

/**
 * Plugin Name: Quelora Integration
 * Plugin URI: https://www.quelora.org
 * Update URI: https://www.quelora.org/wp-plugin/quelora-wp-integration
 * Description: Advanced distributed community system integration for WordPress. Injects highly optimized ES Modules and CSS via a Sidebar toggle.
 * Version: 14.0.0
 * Author: Quelora Architecture Team
 * Text Domain: quelora
 *
 * @package Quelora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Define global constants for the Quelora Integration plugin.
 * These constants are immutable and serve as the single source of truth
 * for directory paths, URLs, and the current plugin version.
 */
define( 'QUELORA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'QUELORA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'QUELORA_VERSION', '14.0.0' );

/**
 * Require all modular architectural components.
 * Loading order is strictly maintained to ensure base classes and dependencies
 * are available before instantiation.
 */
require_once QUELORA_PLUGIN_DIR . 'includes/class-quelora-core.php';
require_once QUELORA_PLUGIN_DIR . 'includes/class-quelora-admin.php';
require_once QUELORA_PLUGIN_DIR . 'includes/class-quelora-frontend.php';
require_once QUELORA_PLUGIN_DIR . 'includes/class-quelora-sso.php';
require_once QUELORA_PLUGIN_DIR . 'includes/class-quelora-sync.php';

/**
 * Bootstraps the Quelora plugin.
 * * Instantiates the core orchestrator and begins the execution lifecycle.
 * Encapsulated within a functional scope to prevent global namespace pollution.
 *
 * @return void
 */
function run_quelora() {
	$plugin = new Quelora_Core();
	$plugin->run();
}

run_quelora();