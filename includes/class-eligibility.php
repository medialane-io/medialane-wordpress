<?php

namespace TokenizeContent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eligibility {

	public static function is_tokenizable( \WP_Post $post ): bool {
		return 'post' === $post->post_type && 'publish' === $post->post_status && '' === (string) $post->post_password;
	}

	public static function message(): string {
		return __( 'Only published posts without a password can be tokenized.', 'tokenize-content' );
	}

	public static function is_tokenized( int $post_id ): bool {
		return PostMeta::STATUS_MINTED === PostMeta::get_status( $post_id );
	}

	public static function tokenized_message(): string {
		return __( 'This post is already tokenized.', 'tokenize-content' );
	}
}
