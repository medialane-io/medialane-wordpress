<?php

use TokenizeContent\Settings;

class Test_Settings extends WP_UnitTestCase {

	public function tear_down() {
		delete_option( Settings::OPTION_API_KEY );
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	public function test_get_api_key_reads_the_option_when_no_constant_is_defined() {
		update_option( Settings::OPTION_API_KEY, 'from-the-database' );
		$this->assertSame( 'from-the-database', Settings::get_api_key() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_api_key_prefers_the_constant_when_defined() {
		update_option( Settings::OPTION_API_KEY, 'from-the-database' );
		if ( ! defined( 'TOKENIZE_CONTENT_API_KEY' ) ) {
			define( 'TOKENIZE_CONTENT_API_KEY', 'from-wp-config' );
		}
		$this->assertSame( 'from-wp-config', Settings::get_api_key() );
	}

	public function test_grant_default_capability_adds_it_to_administrator() {
		$admin_role = get_role( 'administrator' );
		$admin_role->remove_cap( Settings::CAP_TOKENIZE );
		$this->assertFalse( $admin_role->has_cap( Settings::CAP_TOKENIZE ) );

		Settings::grant_default_capability();

		$this->assertTrue( get_role( 'administrator' )->has_cap( Settings::CAP_TOKENIZE ) );
	}

	public function test_grant_default_capability_is_idempotent() {
		Settings::grant_default_capability();
		Settings::grant_default_capability();
		$this->assertTrue( get_role( 'administrator' )->has_cap( Settings::CAP_TOKENIZE ) );
	}

	public function test_get_license_default_falls_back_to_all_rights_reserved() {
		delete_option( Settings::OPTION_LICENSE_DEFAULT );
		$this->assertSame( 'All Rights Reserved', Settings::get_license_default() );
	}

	public function test_get_license_default_reads_the_saved_option() {
		update_option( Settings::OPTION_LICENSE_DEFAULT, 'CC BY-SA' );
		$this->assertSame( 'CC BY-SA', Settings::get_license_default() );
		delete_option( Settings::OPTION_LICENSE_DEFAULT );
	}

	public function test_get_ai_policy_default_falls_back_to_allowed() {
		delete_option( Settings::OPTION_AI_POLICY_DEFAULT );
		$this->assertSame( 'Allowed', Settings::get_ai_policy_default() );
	}

	public function test_get_ai_policy_default_reads_the_saved_option() {
		update_option( Settings::OPTION_AI_POLICY_DEFAULT, 'Not Allowed' );
		$this->assertSame( 'Not Allowed', Settings::get_ai_policy_default() );
		delete_option( Settings::OPTION_AI_POLICY_DEFAULT );
	}

	public function test_save_collection_label_stores_it_and_makes_it_the_default_when_none_exists() {
		delete_option( Settings::OPTION_COLLECTION_LABELS );
		delete_option( Settings::OPTION_COLLECTION );

		Settings::save_collection_label( '0xaaa', 'General' );

		$this->assertSame( array( '0xaaa' => 'General' ), Settings::get_collection_labels() );
		$this->assertSame( '0xaaa', Settings::get_default_collection() );
	}

	public function test_save_collection_label_does_not_change_the_default_once_one_is_already_set() {
		delete_option( Settings::OPTION_COLLECTION_LABELS );
		delete_option( Settings::OPTION_COLLECTION );
		Settings::save_collection_label( '0xaaa', 'General' );
		Settings::save_collection_label( '0xbbb', 'News' );

		$this->assertSame( '0xaaa', Settings::get_default_collection() );
		$this->assertCount( 2, Settings::get_collection_labels() );
	}

	public function test_save_collection_label_overwrites_the_label_for_an_existing_contract() {
		delete_option( Settings::OPTION_COLLECTION_LABELS );
		Settings::save_collection_label( '0xaaa', 'General' );
		Settings::save_collection_label( '0xaaa', 'General (renamed)' );

		$this->assertSame( array( '0xaaa' => 'General (renamed)' ), Settings::get_collection_labels() );
	}

	public function test_fetch_live_collections_returns_nothing_without_an_api_key_or_wallet() {
		delete_option( Settings::OPTION_API_KEY );
		delete_option( Settings::OPTION_WALLET );
		$this->assertSame( array(), Settings::fetch_live_collections() );
	}

	public function test_fetch_live_collections_reads_the_backend_s_real_field_and_merges_the_stored_label() {
		update_option( Settings::OPTION_API_KEY, 'test-key' );
		Settings::save_wallet_address( '0xbeef01' );
		delete_option( Settings::OPTION_COLLECTION_LABELS );
		Settings::save_collection_label( '0xaaa', 'General' );

		add_filter( 'pre_http_request', function () {
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'data' => array(
					array( 'contractAddress' => '0xaaa' ),
					array( 'contractAddress' => '0xbbb' ),
				) ) ),
			);
		} );

		$collections = Settings::fetch_live_collections();

		$this->assertSame( array(
			array( 'contract' => '0xaaa', 'label' => 'General' ),
			array( 'contract' => '0xbbb', 'label' => '0xbbb' ),
		), $collections );
	}

	public function test_fetch_live_collections_returns_nothing_when_the_backend_call_fails() {
		update_option( Settings::OPTION_API_KEY, 'test-key' );
		Settings::save_wallet_address( '0xbeef01' );

		add_filter( 'pre_http_request', function () {
			return new WP_Error( 'http_request_failed', 'Could not resolve host' );
		} );

		$this->assertSame( array(), Settings::fetch_live_collections() );
	}

	public function test_resolve_collection_for_post_uses_the_category_map_when_the_mapped_collection_is_real() {
		delete_option( Settings::OPTION_CATEGORY_MAP );
		$live = array(
			array( 'contract' => '0xdefa01', 'label' => 'Default' ),
			array( 'contract' => '0xbbb22', 'label' => 'News' ),
		);

		$news_cat_id = $this->factory->category->create( array( 'name' => 'News' ) );
		$post_id     = $this->factory->post->create( array( 'post_category' => array( $news_cat_id ) ) );

		Settings::save_category_map( array( $news_cat_id => '0xbbb22' ) );

		$this->assertSame( '0xbbb22', Settings::resolve_collection_for_post( $post_id, $live ) );
	}

	public function test_resolve_collection_for_post_falls_back_to_the_default_collection() {
		delete_option( Settings::OPTION_COLLECTION );
		delete_option( Settings::OPTION_CATEGORY_MAP );
		Settings::save_collection_label( '0xdefa01', 'Default' );
		$live    = array( array( 'contract' => '0xdefa01', 'label' => 'Default' ) );
		$post_id = $this->factory->post->create();

		$this->assertSame( '0xdefa01', Settings::resolve_collection_for_post( $post_id, $live ) );
	}

	public function test_resolve_collection_for_post_ignores_a_stored_default_that_no_longer_exists() {
		delete_option( Settings::OPTION_CATEGORY_MAP );
		Settings::set_default_collection( '0xdeleted' );
		$live    = array( array( 'contract' => '0xreal01', 'label' => 'Real' ) );
		$post_id = $this->factory->post->create();

		$this->assertSame( '0xreal01', Settings::resolve_collection_for_post( $post_id, $live ) );
	}

	public function test_resolve_collection_for_post_returns_empty_string_when_no_real_collections_exist() {
		delete_option( Settings::OPTION_CATEGORY_MAP );
		delete_option( Settings::OPTION_COLLECTION );
		$post_id = $this->factory->post->create();

		$this->assertSame( '', Settings::resolve_collection_for_post( $post_id, array() ) );
	}
}
