<?php
/**
 * Plugin Name: Tokenize, Protect & License Your Content
 * Description: Turn your posts into protected, licensed IP assets with Medialane. Prove authorship, set clear licensing terms, and keep control of how your content is used.
 * Version: 0.2.0
 * Requires PHP: 7.4
 * Requires at least: 6.0
 * Author: Medialane
 * License: GPL-2.0-or-later
 * Text Domain: tokenize-content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$tokenize_content_header = get_file_data( __FILE__, array( 'Version' => 'Version' ) );
define( 'TOKENIZE_CONTENT_PLUGIN_VERSION', $tokenize_content_header['Version'] );
define( 'TOKENIZE_CONTENT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TOKENIZE_CONTENT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'TOKENIZE_CONTENT_BACKEND_URL', 'https://api.medialane.io' );

require_once TOKENIZE_CONTENT_PLUGIN_DIR . 'includes/class-post-meta.php';
require_once TOKENIZE_CONTENT_PLUGIN_DIR . 'includes/class-eligibility.php';
require_once TOKENIZE_CONTENT_PLUGIN_DIR . 'includes/class-description.php';
require_once TOKENIZE_CONTENT_PLUGIN_DIR . 'includes/class-settings.php';
require_once TOKENIZE_CONTENT_PLUGIN_DIR . 'includes/class-settings-page.php';
require_once TOKENIZE_CONTENT_PLUGIN_DIR . 'includes/class-rest-proxy.php';
require_once TOKENIZE_CONTENT_PLUGIN_DIR . 'includes/class-metabox.php';
require_once TOKENIZE_CONTENT_PLUGIN_DIR . 'includes/class-bulk-action.php';
require_once TOKENIZE_CONTENT_PLUGIN_DIR . 'includes/class-asset-badge.php';

add_action( 'init', array( 'TokenizeContent\\PostMeta', 'register' ) );
add_action( 'init', array( 'TokenizeContent\\Settings', 'register' ) );
add_action( 'init', array( 'TokenizeContent\\SettingsPage', 'register' ) );
add_action( 'init', array( 'TokenizeContent\\Metabox', 'register' ) );
add_action( 'init', array( 'TokenizeContent\\BulkAction', 'register' ) );
add_action( 'init', array( 'TokenizeContent\\AssetBadge', 'register' ) );
add_action( 'rest_api_init', array( 'TokenizeContent\\RestProxy', 'register_routes' ) );

register_activation_hook( __FILE__, array( 'TokenizeContent\\Settings', 'grant_default_capability' ) );
