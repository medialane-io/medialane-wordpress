<?php

namespace TokenizeContent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PostsColumn {

	const COLUMN = 'tokenize_content_status';

	public static function register() {
		add_filter( 'manage_post_posts_columns', array( __CLASS__, 'add_column' ) );
		add_action( 'manage_post_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
	}

	public static function add_column( array $columns ): array {
		if ( current_user_can( Settings::CAP_TOKENIZE ) ) {
			$columns[ self::COLUMN ] = __( 'Tokenized', 'tokenize-content' );
		}
		return $columns;
	}

	public static function render_column( string $column, int $post_id ) {
		if ( self::COLUMN !== $column ) {
			return;
		}
		switch ( PostMeta::get_status( $post_id ) ) {
			case PostMeta::STATUS_MINTED:
				$url = AssetBadge::url_for_post( $post_id );
				if ( $url ) {
					printf( '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>', esc_url( $url ), esc_html__( 'View on Medialane', 'tokenize-content' ) );
				} else {
					esc_html_e( 'Tokenized', 'tokenize-content' );
				}
				break;
			case PostMeta::STATUS_MINTING:
				esc_html_e( 'In progress', 'tokenize-content' );
				break;
			case PostMeta::STATUS_ERROR:
				printf( '<span title="%s">%s</span>', esc_attr( PostMeta::get_error( $post_id ) ), esc_html__( 'Failed', 'tokenize-content' ) );
				break;
			default:
				echo '&mdash;';
		}
	}
}
