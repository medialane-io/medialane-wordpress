<?php

use TokenizeContent\PostMeta;
use TokenizeContent\Settings;

class Test_Uninstall extends Tokenize_Test_Case {

	private function option_names(): array {
		$names = array();
		foreach ( ( new ReflectionClass( Settings::class ) )->getConstants() as $name => $value ) {
			if ( 0 === strpos( $name, 'OPTION_' ) ) {
				$names[ $name ] = $value;
			}
		}
		return $names;
	}

	private function fill_everything() {
		foreach ( $this->option_names() as $option ) {
			update_option( $option, 'value' );
		}
		update_option( Settings::LEGACY_COLLECTION_LABELS, array( '0xaaa' => 'General' ) );
		update_option( Settings::OPTION_CATEGORY_MAP, array( 3 => '0xaaa' ) );
	}

	public function test_uninstall_removes_every_option_the_plugin_stores() {
		$this->fill_everything();

		Settings::uninstall();

		foreach ( $this->option_names() as $name => $option ) {
			$this->assertFalse( get_option( $option ), $name );
		}
		$this->assertFalse( get_option( Settings::LEGACY_COLLECTION_LABELS ) );
	}

	public function test_stored_collection_labels_from_earlier_versions_are_removed_on_the_next_admin_load() {
		update_option( Settings::LEGACY_COLLECTION_LABELS, array( '0xaaa' => 'General' ) );

		Settings::register_settings();

		$this->assertFalse( get_option( Settings::LEGACY_COLLECTION_LABELS ) );
	}

	public function test_uninstall_removes_the_tokenize_capability_from_every_role() {
		get_role( 'editor' )->add_cap( Settings::CAP_TOKENIZE );

		Settings::uninstall();

		foreach ( wp_roles()->role_objects as $name => $role ) {
			$this->assertFalse( $role->has_cap( Settings::CAP_TOKENIZE ), $name );
		}
	}

	public function test_uninstall_removes_the_cached_collection_lists() {
		set_transient( 'tokenize_content_collections_abc123', array( 'x' ), HOUR_IN_SECONDS );

		Settings::uninstall();

		$this->assertFalse( get_transient( 'tokenize_content_collections_abc123' ) );
	}

	public function test_uninstall_keeps_the_per_post_records_of_what_was_tokenized() {
		$post_id = $this->factory->post->create();
		PostMeta::save_minted( $post_id, array( 'token_id' => '42', 'tx_hash' => '0xabc', 'contract' => '0xdef', 'license' => 'CC BY' ) );

		Settings::uninstall();

		$this->assertSame( PostMeta::STATUS_MINTED, PostMeta::get_status( $post_id ) );
		$this->assertSame( '42', PostMeta::get_token_id( $post_id ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_uninstall_file_performs_the_cleanup_when_wordpress_runs_it() {
		$this->fill_everything();
		define( 'WP_UNINSTALL_PLUGIN', 'tokenize-content/tokenize-content.php' );

		include dirname( dirname( __DIR__ ) ) . '/uninstall.php';

		$this->assertFalse( get_option( Settings::OPTION_API_KEY ) );
	}
}
