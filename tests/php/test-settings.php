<?php

use TokenizeContent\Settings;

class Test_Settings extends Tokenize_Test_Case {

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

	public function test_key_and_defaults_are_in_different_option_groups() {
		Settings::register_settings();
		$groups = array();
		foreach ( get_registered_settings() as $name => $config ) {
			if ( 0 === strpos( $name, 'tokenize_content_' ) ) {
				$groups[ $name ] = $config['group'];
			}
		}

		$this->assertNotSame( $groups[ Settings::OPTION_API_KEY ], $groups[ Settings::OPTION_LICENSE_DEFAULT ] );
		$this->assertSame( $groups[ Settings::OPTION_LICENSE_DEFAULT ], $groups[ Settings::OPTION_AI_POLICY_DEFAULT ] );
		$this->assertSame( $groups[ Settings::OPTION_LICENSE_DEFAULT ], $groups[ Settings::OPTION_CONTENT_SCOPE ] );
	}

	public function test_each_settings_form_posts_to_its_own_option_group() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		update_option( Settings::OPTION_API_KEY, 'test-key' );
		Settings::save_wallet_address( '0xbeef01' );

		ob_start();
		\TokenizeContent\SettingsPage::render();
		$html = ob_get_clean();

		$this->assertStringContainsString( "name='option_page' value='tokenize-content-key'", $html );
		$this->assertStringContainsString( "name='option_page' value='tokenize-content-defaults'", $html );
	}

	public function test_the_default_license_choices_all_carry_terms() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		update_option( Settings::OPTION_API_KEY, 'test-key' );
		Settings::save_wallet_address( '0xbeef01' );

		ob_start();
		\TokenizeContent\SettingsPage::render();
		$html = ob_get_clean();

		$this->assertStringContainsString( '<option value="CC BY-SA"', $html );
		$this->assertStringNotContainsString( '<option value="Custom"', $html );
	}

	public function test_settings_page_html_never_contains_the_saved_key() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		update_option( Settings::OPTION_API_KEY, 'SECRET-OPTION-KEY' );

		ob_start();
		\TokenizeContent\SettingsPage::render();
		$html = ob_get_clean();

		$this->assertStringNotContainsString( 'SECRET-OPTION-KEY', $html );
	}

	public function test_a_blank_api_key_submission_keeps_the_saved_key() {
		update_option( Settings::OPTION_API_KEY, 'keep-me' );

		$this->assertSame( 'keep-me', Settings::sanitize_api_key( '' ) );
		$this->assertSame( 'keep-me', Settings::sanitize_api_key( null ) );
	}

	public function test_a_new_api_key_submission_replaces_the_saved_key() {
		update_option( Settings::OPTION_API_KEY, 'old-key' );

		$this->assertSame( 'new-key', Settings::sanitize_api_key( ' new-key ' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_settings_page_shows_no_key_field_and_no_key_when_the_constant_is_defined() {
		define( 'TOKENIZE_CONTENT_API_KEY', 'SECRET-CONSTANT-KEY' );
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		ob_start();
		\TokenizeContent\SettingsPage::render();
		$html = ob_get_clean();

		$this->assertStringNotContainsString( 'SECRET-CONSTANT-KEY', $html );
		$this->assertStringNotContainsString( 'name="' . Settings::OPTION_API_KEY . '"', $html );
	}

	public function test_get_license_default_falls_back_to_all_rights_reserved() {
		delete_option( Settings::OPTION_LICENSE_DEFAULT );
		$this->assertSame( 'All Rights Reserved', Settings::get_license_default() );
	}

	public function test_get_license_default_ignores_a_stored_value_that_has_no_terms() {
		foreach ( array( 'Custom', 'Anything' ) as $stored ) {
			update_option( Settings::OPTION_LICENSE_DEFAULT, $stored );
			$this->assertSame( 'All Rights Reserved', Settings::get_license_default(), $stored );
		}
		delete_option( Settings::OPTION_LICENSE_DEFAULT );
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
					array( 'contractAddress' => '0xaaa', 'collectionId' => '1', 'service' => 'mip-erc721' ),
					array( 'contractAddress' => '0xbbb', 'collectionId' => '2', 'service' => 'mip-erc721' ),
				) ) ),
			);
		} );

		$collections = Settings::fetch_live_collections();

		$this->assertSame( array(
			array( 'contract' => '0xaaa', 'collectionId' => '1', 'label' => 'General' ),
			array( 'contract' => '0xbbb', 'collectionId' => '2', 'label' => '0xbbb' ),
		), $collections );
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

		$this->assertSame( '0xbbb22', Settings::resolve_collection_for_post( $post_id, $live )['contract'] );
	}

	public function test_resolve_collection_for_post_falls_back_to_the_default_collection() {
		delete_option( Settings::OPTION_COLLECTION );
		delete_option( Settings::OPTION_CATEGORY_MAP );
		Settings::save_collection_label( '0xdefa01', 'Default' );
		$live    = array( array( 'contract' => '0xdefa01', 'label' => 'Default' ) );
		$post_id = $this->factory->post->create();

		$this->assertSame( '0xdefa01', Settings::resolve_collection_for_post( $post_id, $live )['contract'] );
	}

	public function test_resolve_collection_for_post_ignores_a_stored_default_that_no_longer_exists() {
		delete_option( Settings::OPTION_CATEGORY_MAP );
		Settings::set_default_collection( '0xdeleted' );
		$live    = array( array( 'contract' => '0xreal01', 'label' => 'Real' ) );
		$post_id = $this->factory->post->create();

		$this->assertSame( '0xreal01', Settings::resolve_collection_for_post( $post_id, $live )['contract'] );
	}

	public function test_resolve_collection_for_post_returns_null_when_no_real_collections_exist() {
		delete_option( Settings::OPTION_CATEGORY_MAP );
		delete_option( Settings::OPTION_COLLECTION );
		$post_id = $this->factory->post->create();

		$this->assertNull( Settings::resolve_collection_for_post( $post_id, array() ) );
	}

	public function test_resolve_collection_matches_a_live_contract_that_differs_only_by_case_or_leading_zeros() {
		delete_option( Settings::OPTION_COLLECTION );
		$live = array( array( 'contract' => '0xdefa01', 'label' => 'Default' ), array( 'contract' => '0x000BBB22', 'label' => 'News' ) );
		$cat  = $this->factory->category->create();
		$post = $this->factory->post->create( array( 'post_category' => array( $cat ) ) );
		Settings::save_category_map( array( $cat => '0xbbb22' ) );

		$this->assertSame( '0x000BBB22', Settings::resolve_collection_for_post( $post, $live )['contract'] );
	}

	public function test_resolve_collection_matches_the_stored_default_across_address_formats() {
		delete_option( Settings::OPTION_CATEGORY_MAP );
		Settings::set_default_collection( '0xdefa01' );
		$live = array( array( 'contract' => '0xaaa11', 'label' => 'First' ), array( 'contract' => '0x0000DEFA01', 'label' => 'Default' ) );

		$this->assertSame( '0x0000DEFA01', Settings::resolve_collection_for_post( $this->factory->post->create(), $live )['contract'] );
	}

	public function test_fetch_live_collections_finds_the_label_across_address_formats() {
		update_option( Settings::OPTION_API_KEY, 'test-key' );
		Settings::save_wallet_address( '0xbeef01' );
		delete_option( Settings::OPTION_COLLECTION_LABELS );
		Settings::save_collection_label( '0xaaa', 'General' );

		add_filter( 'pre_http_request', function () {
			return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'data' => array( array( 'contractAddress' => '0x000AAA', 'collectionId' => '5', 'service' => 'mip-erc721' ) ) ) ) );
		} );

		$this->assertSame( array( array( 'contract' => '0x000AAA', 'collectionId' => '5', 'label' => 'General' ) ), Settings::fetch_live_collections() );
	}

	public function test_checked_fetch_returns_an_error_when_the_request_fails() {
		update_option( Settings::OPTION_API_KEY, 'test-key' );
		Settings::save_wallet_address( '0xbeef01' );
		add_filter( 'pre_http_request', function () {
			return new WP_Error( 'http_request_failed', 'Could not resolve host' );
		} );

		$this->assertInstanceOf( WP_Error::class, Settings::fetch_live_collections() );
	}

	public function test_checked_fetch_returns_an_error_on_a_non_success_status() {
		update_option( Settings::OPTION_API_KEY, 'test-key' );
		Settings::save_wallet_address( '0xbeef01' );
		add_filter( 'pre_http_request', function () {
			return array( 'response' => array( 'code' => 503 ), 'body' => '' );
		} );

		$this->assertInstanceOf( WP_Error::class, Settings::fetch_live_collections() );
	}

	public function test_checked_fetch_returns_an_empty_list_not_an_error_when_the_wallet_has_none() {
		update_option( Settings::OPTION_API_KEY, 'test-key' );
		Settings::save_wallet_address( '0xbeef01' );
		add_filter( 'pre_http_request', function () {
			return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'data' => array() ) ) );
		} );

		$this->assertSame( array(), Settings::fetch_live_collections() );
	}

	public function test_settings_page_says_collections_could_not_be_loaded_instead_of_none_found() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		update_option( Settings::OPTION_API_KEY, 'test-key' );
		Settings::save_wallet_address( '0xbeef01' );
		add_filter( 'pre_http_request', function () {
			return new WP_Error( 'http_request_failed', 'Could not resolve host' );
		} );

		ob_start();
		\TokenizeContent\SettingsPage::render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Collections could not be loaded right now', $html );
		$this->assertStringNotContainsString( 'No collections found for this wallet yet', $html );
	}

	private function backend_returns( array $items, ?int &$calls = null, ?string &$url = null ) {
		add_filter( 'pre_http_request', function ( $pre, $args, $request_url ) use ( $items, &$calls, &$url ) {
			$calls = (int) $calls + 1;
			$url   = $request_url;
			return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'data' => $items ) ) );
		}, 10, 3 );
	}

	private function configured() {
		update_option( Settings::OPTION_API_KEY, 'test-key' );
		Settings::save_wallet_address( '0xbeef01' );
	}

	public function test_only_mip_erc721_collections_that_have_a_collection_id_are_usable() {
		$this->configured();
		$this->backend_returns( array(
			array( 'contractAddress' => '0xaaa', 'collectionId' => '7', 'service' => 'mip-erc721' ),
			array( 'contractAddress' => '0xbbb', 'collectionId' => null, 'service' => 'mip-erc721' ),
			array( 'contractAddress' => '0xccc', 'collectionId' => '3', 'service' => 'drop-collection' ),
		), $calls, $url );

		$collections = Settings::fetch_live_collections();

		$this->assertSame( array( array( 'contract' => '0xaaa', 'collectionId' => '7', 'label' => '0xaaa' ) ), $collections );
		$this->assertStringContainsString( 'service=mip-erc721', $url );
		$this->assertStringContainsString( 'limit=100', $url );
	}

	public function test_a_successful_lookup_is_reused_instead_of_asking_again() {
		$this->configured();
		$this->backend_returns( array( array( 'contractAddress' => '0xaaa', 'collectionId' => '7', 'service' => 'mip-erc721' ) ), $calls );

		Settings::fetch_live_collections();
		Settings::fetch_live_collections();

		$this->assertSame( 1, $calls );
	}

	public function test_saving_a_collection_label_makes_the_next_lookup_fresh() {
		$this->configured();
		$this->backend_returns( array( array( 'contractAddress' => '0xaaa', 'collectionId' => '7', 'service' => 'mip-erc721' ) ), $calls );
		Settings::fetch_live_collections();

		Settings::save_collection_label( '0xaaa', 'General' );
		$collections = Settings::fetch_live_collections();

		$this->assertSame( 2, $calls );
		$this->assertSame( 'General', $collections[0]['label'] );
	}

	public function test_a_failed_lookup_is_not_remembered() {
		$this->configured();
		add_filter( 'pre_http_request', function () {
			return new WP_Error( 'http_request_failed', 'down' );
		} );
		$this->assertInstanceOf( WP_Error::class, Settings::fetch_live_collections() );
		remove_all_filters( 'pre_http_request' );

		$this->backend_returns( array( array( 'contractAddress' => '0xaaa', 'collectionId' => '7', 'service' => 'mip-erc721' ) ) );

		$this->assertCount( 1, Settings::fetch_live_collections() );
	}

	public function test_every_license_except_custom_defines_the_three_terms() {
		foreach ( Settings::LICENSES as $preset => $terms ) {
			if ( 'Custom' === $preset ) {
				$this->assertNull( $terms );
				continue;
			}
			$this->assertSame( array( 'commercialUse', 'derivatives', 'attribution' ), array_keys( $terms ), $preset );
			foreach ( $terms as $value ) {
				$this->assertNotSame( '', $value, $preset );
			}
		}
	}

	public function test_known_license_terms_match_the_platform_s_trait_values() {
		$this->assertSame( array( 'commercialUse' => 'Yes', 'derivatives' => 'Share-Alike', 'attribution' => 'Required' ), Settings::LICENSES['CC BY-SA'] );
		$this->assertSame( array( 'commercialUse' => 'No', 'derivatives' => 'Not Allowed', 'attribution' => 'Required' ), Settings::LICENSES['All Rights Reserved'] );
		$this->assertSame( 'Not Required', Settings::LICENSES['CC0']['attribution'] );
	}

	public function test_license_terms_for_the_client_leave_out_custom() {
		$terms = Settings::license_terms();

		$this->assertArrayHasKey( 'CC BY-SA', $terms );
		$this->assertArrayNotHasKey( 'Custom', $terms );
	}

	public function address_vectors() {
		$vectors = json_decode( file_get_contents( dirname( __DIR__ ) . '/fixtures/addresses.json' ), true );
		return array_map( function ( $vector ) {
			return array( $vector['a'], $vector['b'], $vector['same'] );
		}, $vectors );
	}

	/**
	 * @dataProvider address_vectors
	 */
	public function test_canonical_address_agrees_with_the_shared_vectors( $a, $b, $same ) {
		$this->assertSame( $same, Settings::canonical_address( $a ) === Settings::canonical_address( $b ) );
	}

	private function render_page_with_live( array $items ): string {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		update_option( Settings::OPTION_API_KEY, 'test-key' );
		Settings::save_wallet_address( '0xbeef01' );
		add_filter( 'pre_http_request', function () use ( $items ) {
			return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'data' => $items ) ) );
		} );
		ob_start();
		\TokenizeContent\SettingsPage::render();
		return ob_get_clean();
	}

	public function test_the_default_column_marks_the_default_collection_across_address_formats() {
		Settings::set_default_collection( '0xaaa' );

		$html = $this->render_page_with_live( array( array( 'contractAddress' => '0x000AAA', 'collectionId' => '1', 'service' => 'mip-erc721' ) ) );

		$this->assertStringContainsString( '<td>Yes</td>', $html );
	}

	public function test_the_category_map_preselects_the_mapped_collection_across_address_formats() {
		$cat = $this->factory->category->create( array( 'name' => 'News' ) );
		Settings::save_category_map( array( $cat => '0xbbb' ) );

		$html = $this->render_page_with_live( array(
			array( 'contractAddress' => '0x000AAA', 'collectionId' => '1', 'service' => 'mip-erc721' ),
			array( 'contractAddress' => '0x000BBB', 'collectionId' => '2', 'service' => 'mip-erc721' ),
		) );

		$this->assertMatchesRegularExpression( '/<option value="0x000BBB"\s+selected=\'selected\'/', $html );
	}
}
