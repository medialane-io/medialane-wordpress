<?php

use TokenizeContent\Metabox;
use TokenizeContent\BulkAction;
use TokenizeContent\SettingsPage;

class Test_Translations extends Tokenize_Test_Case {

	private function assert_script_translatable( string $handle ) {
		$script = wp_scripts()->registered[ $handle ];
		$this->assertContains( 'wp-i18n', $script->deps, $handle );
		$this->assertSame( 'tokenize-content', $script->textdomain, $handle );
	}

	public function test_the_editor_script_loads_translations() {
		$GLOBALS['post'] = get_post( $this->factory->post->create() );
		Metabox::enqueue( 'post.php' );
		$this->assert_script_translatable( 'tokenize-content-metabox' );
		unset( $GLOBALS['post'] );
	}

	public function test_the_bulk_script_loads_translations() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		BulkAction::enqueue( 'edit.php' );
		$this->assert_script_translatable( 'tokenize-content-bulk-action' );
	}

	public function test_the_settings_script_loads_translations() {
		SettingsPage::enqueue( SettingsPage::HOOK );
		$this->assert_script_translatable( 'tokenize-content-settings' );
	}

	public function test_no_error_message_is_written_as_an_untranslated_literal() {
		foreach ( glob( dirname( dirname( __DIR__ ) ) . '/includes/*.php' ) as $file ) {
			$this->assertSame( 0, preg_match( "/new \\\\\\\\?WP_Error\\(\\s*'[^']+',\\s*['\"]/", file_get_contents( $file ) ), basename( $file ) );
		}
	}
}
