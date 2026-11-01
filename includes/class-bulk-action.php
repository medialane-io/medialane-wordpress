<?php

namespace TokenizeContent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BulkAction {

	public static function register() {
		foreach ( Eligibility::post_types() as $type ) {
			add_filter( "bulk_actions-edit-{$type}", array( __CLASS__, 'add_bulk_action' ) );
		}
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function add_bulk_action( array $actions ): array {
		$actions['tokenize_content_tokenize'] = __( 'Tokenize with Medialane', 'tokenize-content' );
		return $actions;
	}

	public static function enqueue( string $hook ) {
		if ( 'edit.php' !== $hook || ! current_user_can( Settings::CAP_TOKENIZE ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! Eligibility::supports_type( (string) $screen->post_type ) ) {
			return;
		}
		Scripts::enqueue( 'tokenize-content-bulk-action', 'bulk-action.js', Settings::script_data() );
	}
}
