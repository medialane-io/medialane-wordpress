<?php

namespace TokenizeContent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Description {

	const EXCERPT_WORDS = 55;

	public static function plain_text( \WP_Post $post, string $scope ): string {
		if ( 'excerpt' === $scope && '' !== trim( $post->post_excerpt ) ) {
			return self::to_text( $post->post_excerpt );
		}
		$text = self::to_text( self::render( $post ) );
		return 'excerpt' === $scope ? wp_trim_words( $text, self::EXCERPT_WORDS, '' ) : $text;
	}

	private static function render( \WP_Post $post ): string {
		$previous = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
		setup_postdata( $post );
		$html = apply_filters( 'the_content', $post->post_content );
		if ( $previous ) {
			setup_postdata( $previous );
		} else {
			wp_reset_postdata();
		}
		return (string) $html;
	}

	private static function to_text( string $html ): string {
		$html = preg_replace( '#</(p|h[1-6]|li|blockquote|div|pre)>#i', "\n\n", $html );
		$html = preg_replace( '#<br\s*/?>#i', "\n", (string) $html );
		$text = html_entity_decode( wp_strip_all_tags( (string) $html, false ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( "/[ \t\r\f\v]+/u", ' ', $text );
		$text = preg_replace( "/ *\n */u", "\n", (string) $text );
		$text = preg_replace( "/\n{3,}/u", "\n\n", (string) $text );
		return trim( (string) $text );
	}
}
