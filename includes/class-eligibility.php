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
}
