<?php

namespace TokenizeContent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HeadMeta {

	const LICENSE_URLS = array(
		'CC BY-SA'    => 'https://creativecommons.org/licenses/by-sa/4.0/',
		'CC BY'       => 'https://creativecommons.org/licenses/by/4.0/',
		'CC BY-NC'    => 'https://creativecommons.org/licenses/by-nc/4.0/',
		'CC BY-ND'    => 'https://creativecommons.org/licenses/by-nd/4.0/',
		'CC BY-NC-SA' => 'https://creativecommons.org/licenses/by-nc-sa/4.0/',
		'CC BY-NC-ND' => 'https://creativecommons.org/licenses/by-nc-nd/4.0/',
		'CC0'         => 'https://creativecommons.org/publicdomain/zero/1.0/',
	);

	const AI_POLICY_META = 'tokenize-content-ai-policy';

	public static function register() {
		add_action( 'wp_head', array( __CLASS__, 'print_tags' ) );
	}

	public static function print_tags() {
		if ( ! is_singular( Eligibility::post_types() ) ) {
			return;
		}
		echo self::tags_for_post( (int) get_queried_object_id() );
	}

	public static function tags_for_post( int $post_id ): string {
		if ( ! Eligibility::is_tokenized( $post_id ) ) {
			return '';
		}
		$tags    = '';
		$license = PostMeta::get_license( $post_id );
		if ( isset( self::LICENSE_URLS[ $license ] ) ) {
			$tags .= sprintf( "<link rel=\"license\" href=\"%s\" />\n", esc_url( self::LICENSE_URLS[ $license ] ) );
		}
		$policy = PostMeta::get_ai_policy( $post_id );
		if ( in_array( $policy, Settings::AI_POLICIES, true ) ) {
			$tags .= sprintf( "<meta name=\"%s\" content=\"%s\" />\n", esc_attr( self::AI_POLICY_META ), esc_attr( $policy ) );
		}
		return $tags;
	}
}
