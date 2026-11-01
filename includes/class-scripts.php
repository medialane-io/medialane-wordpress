<?php

namespace TokenizeContent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Scripts {

	public static function enqueue( string $handle, string $file, array $data ) {
		wp_enqueue_script( $handle, TOKENIZE_CONTENT_PLUGIN_URL . 'assets/dist/' . $file, array( 'wp-i18n' ), TOKENIZE_CONTENT_PLUGIN_VERSION, true );
		wp_set_script_translations( $handle, 'tokenize-content' );
		wp_localize_script( $handle, 'tokenizeContentData', $data );
	}
}
