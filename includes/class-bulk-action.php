<?php

namespace TokenizeContent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BulkAction {

	public static function register() {
		add_filter( 'bulk_actions-edit-post', array( __CLASS__, 'add_bulk_action' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_pending_notice' ) );
	}

	public static function add_bulk_action( array $actions ): array {
		$actions['tokenize_content_tokenize'] = __( 'Tokenize with Medialane', 'tokenize-content' );
		return $actions;
	}

	private static function pending_count(): int {
		$query = new \WP_Query( array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'has_password'   => false,
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => array(
				'relation' => 'OR',
				array( 'key' => PostMeta::KEY_STATUS, 'compare' => 'NOT EXISTS' ),
				array( 'key' => PostMeta::KEY_STATUS, 'value' => PostMeta::STATUS_MINTED, 'compare' => '!=' ),
			),
		) );
		return (int) $query->found_posts;
	}

	public static function render_pending_notice() {
		$screen = get_current_screen();
		if ( ! $screen || 'edit-post' !== $screen->id ) {
			return;
		}
		if ( ! current_user_can( Settings::CAP_TOKENIZE ) ) {
			return;
		}
		$count = self::pending_count();
		if ( $count < 1 ) {
			return;
		}
		printf(
			'<div class="notice notice-info"><p>%s</p></div>',
			esc_html( sprintf(
				_n(
					'%d published post has not been tokenized yet with Medialane. Select it below and use the "Tokenize with Medialane" bulk action.',
					'%d published posts have not been tokenized yet with Medialane. Select them below and use the "Tokenize with Medialane" bulk action.',
					$count,
					'tokenize-content'
				),
				$count
			) )
		);
	}

	public static function enqueue( string $hook ) {
		if ( 'edit.php' !== $hook || ! current_user_can( Settings::CAP_TOKENIZE ) ) {
			return;
		}
		wp_enqueue_script( 'tokenize-content-bulk-action', TOKENIZE_CONTENT_PLUGIN_URL . 'assets/dist/bulk-action.js', array( 'wp-i18n' ), TOKENIZE_CONTENT_PLUGIN_VERSION, true );
		wp_set_script_translations( 'tokenize-content-bulk-action', 'tokenize-content' );
		wp_localize_script( 'tokenize-content-bulk-action', 'tokenizeContentData', array(
			'restUrl'            => esc_url_raw( rest_url( 'tokenize-content/v1' ) ),
			'nonce'              => wp_create_nonce( 'wp_rest' ),
			'walletAddress'      => Settings::get_wallet_address(),
			'licenseDefault'     => Settings::get_license_default(),
			'aiPolicyDefault'    => Settings::get_ai_policy_default(),
			'licenses'           => Settings::license_terms(),
		) );
	}
}
