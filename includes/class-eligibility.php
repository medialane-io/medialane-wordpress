<?php

namespace TokenizeContent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eligibility {

	public static function post_types(): array {
		return array_values( array_filter(
			get_post_types( array( 'public' => true ), 'names' ),
			function ( $type ) {
				return post_type_supports( $type, 'editor' );
			}
		) );
	}

	public static function supports_type( string $type ): bool {
		return in_array( $type, self::post_types(), true );
	}

	public static function is_tokenizable( \WP_Post $post ): bool {
		return self::supports_type( $post->post_type ) && 'publish' === $post->post_status && '' === (string) $post->post_password;
	}

	public static function message(): string {
		return __( 'Only published content without a password can be tokenized.', 'tokenize-content' );
	}

	public static function is_tokenized( int $post_id ): bool {
		return PostMeta::STATUS_MINTED === PostMeta::get_status( $post_id );
	}

	public static function tokenized_message(): string {
		return __( 'This post is already tokenized.', 'tokenize-content' );
	}
}
