<?php

use TokenizeContent\Settings;

class Test_Rest_Proxy extends Tokenize_Test_Case {

	protected $server;

	public function set_up() {
		parent::set_up();
		global $wp_rest_server;
		$this->server = $wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init' );
		Settings::grant_default_capability();
	}

	public function test_returns_error_without_api_key() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		delete_option( Settings::OPTION_API_KEY );

		$request  = new WP_REST_Request( 'POST', '/tokenize-content/v1/intents/mint' );
		$request->set_body( wp_json_encode( array() ) );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_rejects_non_admin() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'subscriber' ) ) );

		$request  = new WP_REST_Request( 'POST', '/tokenize-content/v1/intents/mint' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_a_client_supplied_backend_path_cannot_redirect_the_forwarded_request() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		update_option( Settings::OPTION_API_KEY, 'test-key' );

		$captured_url = null;
		$intercept    = function ( $preempt, $args, $url ) use ( &$captured_url ) {
			$captured_url = $url;
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'ok' => true ) ),
			);
		};
		add_filter( 'pre_http_request', $intercept, 10, 3 );

		$request = new WP_REST_Request( 'POST', '/tokenize-content/v1/intents/mint' );
		$request->set_body( wp_json_encode( array( 'backend_path' => '/v1/paymaster/deploy/execute' ) ) );
		$this->server->dispatch( $request );

		remove_filter( 'pre_http_request', $intercept, 10 );

		$this->assertSame( 'https://backend.test/v1/intents/mint', $captured_url );
	}

	public function test_the_metadata_upload_forwards_the_standard_fields_and_nothing_else() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		update_option( Settings::OPTION_API_KEY, 'test-key' );

		$captured_body = null;
		$intercept     = function ( $preempt, $args ) use ( &$captured_body ) {
			$captured_body = json_decode( $args['body'], true );
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'ok' => true ) ),
			);
		};
		add_filter( 'pre_http_request', $intercept, 10, 2 );

		$request = new WP_REST_Request( 'POST', '/tokenize-content/v1/metadata/upload' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( array(
			'name'         => 'A Post',
			'description'  => 'Short',
			'image'        => 'ipfs://img',
			'external_url' => 'https://example.org/a-post',
			'attributes'   => array( array( 'trait_type' => 'Content', 'value' => '# Title' ) ),
			'extra'        => 'dropped',
		) ) );
		$this->server->dispatch( $request );

		remove_filter( 'pre_http_request', $intercept, 10 );

		$this->assertEqualsCanonicalizing( array( 'name', 'description', 'image', 'external_url', 'attributes' ), array_keys( $captured_body ) );
		$this->assertSame( 'https://example.org/a-post', $captured_body['external_url'] );
	}

	public function test_editor_without_the_capability_is_rejected() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'editor' ) ) );

		$request  = new WP_REST_Request( 'POST', '/tokenize-content/v1/intents/mint' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_editor_with_the_capability_can_reach_tokenize_routes() {
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		get_role( 'editor' )->add_cap( Settings::CAP_TOKENIZE );
		wp_set_current_user( $user_id );
		update_option( Settings::OPTION_API_KEY, 'test-key' );

		add_filter( 'pre_http_request', function () {
			return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'ok' => true ) ) );
		} );

		$request = new WP_REST_Request( 'POST', '/tokenize-content/v1/intents/mint' );
		$request->set_body( wp_json_encode( array() ) );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_a_user_with_the_capability_cannot_mark_a_post_they_cannot_edit() {
		$post_id = $this->factory->post->create();

		wp_set_current_user( $this->factory->user->create( array( 'role' => 'subscriber' ) ) );
		get_role( 'subscriber' )->add_cap( Settings::CAP_TOKENIZE );

		$request  = new WP_REST_Request( 'POST', "/tokenize-content/v1/posts/{$post_id}/minting" );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_paymaster_routes_forward_to_the_fixed_backend_path() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		update_option( Settings::OPTION_API_KEY, 'test-key' );

		$captured_urls = array();
		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( &$captured_urls ) {
			$captured_urls[] = $url;
			return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'ok' => true ) ) );
		}, 10, 3 );

		$this->server->dispatch( new WP_REST_Request( 'POST', '/tokenize-content/v1/paymaster/invoke/build' ) );
		$this->server->dispatch( new WP_REST_Request( 'POST', '/tokenize-content/v1/paymaster/invoke/execute' ) );

		$this->assertStringEndsWith( '/v1/paymaster/invoke/build', $captured_urls[0] );
		$this->assertStringEndsWith( '/v1/paymaster/invoke/execute', $captured_urls[1] );
	}

	public function test_settings_wallet_route_saves_the_address_immediately() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		$request = new WP_REST_Request( 'POST', '/tokenize-content/v1/settings/wallet' );
		$request->set_body_params( array( 'address' => '0xaaa11' ) );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '0xaaa11', Settings::get_wallet_address() );
	}

	public function test_settings_wallet_route_requires_admin_permission() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'subscriber' ) ) );

		$request = new WP_REST_Request( 'POST', '/tokenize-content/v1/settings/wallet' );
		$request->set_body_params( array( 'address' => '0xaaa11' ) );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_settings_category_map_route_saves_the_mapping() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$cat_id = $this->factory->category->create();

		$request = new WP_REST_Request( 'POST', '/tokenize-content/v1/settings/category-map' );
		$request->set_body_params( array( 'map' => array( (string) $cat_id => '0xbbb22' ) ) );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( $cat_id => '0xbbb22' ), Settings::get_category_map() );
	}

	public function test_tokenize_data_returns_what_the_client_needs_for_each_requested_post() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$author = $this->factory->user->create( array( 'role' => 'author', 'user_email' => 'writer@example.org' ) );
		$post_id = $this->factory->post->create( array( 'post_status' => 'publish', 'post_author' => $author, 'post_title' => 'Hello', 'post_content' => 'Body text', 'post_excerpt' => 'Short' ) );

		$request = new WP_REST_Request( 'POST', '/tokenize-content/v1/tokenize-data' );
		$request->set_body_params( array( 'ids' => array( $post_id ) ) );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$post = $response->get_data()['posts'][ $post_id ];
		$this->assertSame( 'Hello', $post['title'] );
		$this->assertSame( 'Short', $post['description'] );
		$this->assertSame( 'writer@example.org', $post['authorEmail'] );
		$this->assertArrayHasKey( 'collectionContract', $post );
	}

	public function test_tokenize_data_is_forbidden_when_any_requested_post_is_not_editable_by_the_user() {
		$other_post = $this->factory->post->create();
		$mine       = $this->factory->user->create( array( 'role' => 'author' ) );
		get_role( 'author' )->add_cap( Settings::CAP_TOKENIZE );
		wp_set_current_user( $mine );
		$own_post = $this->factory->post->create( array( 'post_author' => $mine ) );

		$request = new WP_REST_Request( 'POST', '/tokenize-content/v1/tokenize-data' );
		$request->set_body_params( array( 'ids' => array( $own_post, $other_post ) ) );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
		get_role( 'author' )->remove_cap( Settings::CAP_TOKENIZE );
	}

	public function test_tokenize_data_fetches_the_live_collections_once_for_many_posts() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		update_option( Settings::OPTION_API_KEY, 'test-key' );
		Settings::save_wallet_address( '0xbeef01' );
		$posts = $this->factory->post->create_many( 3 );

		$calls = 0;
		add_filter( 'pre_http_request', function () use ( &$calls ) {
			$calls++;
			return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'data' => array( array( 'contractAddress' => '0xaaa', 'collectionId' => '4', 'service' => 'mip-erc721' ) ) ) ) );
		} );

		$request = new WP_REST_Request( 'POST', '/tokenize-content/v1/tokenize-data' );
		$request->set_body_params( array( 'ids' => $posts ) );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 1, $calls );
		$this->assertSame( '0xaaa', $response->get_data()['posts'][ $posts[0] ]['collectionContract'] );
		$this->assertSame( '4', $response->get_data()['posts'][ $posts[0] ]['collectionId'] );
		remove_all_filters( 'pre_http_request' );
	}

	private function two_live_collections() {
		update_option( Settings::OPTION_API_KEY, 'test-key' );
		Settings::save_wallet_address( '0xbeef01' );
		add_filter( 'pre_http_request', function () {
			return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'data' => array(
				array( 'contractAddress' => '0x00aaa', 'collectionId' => '1', 'service' => 'mip-erc721' ),
				array( 'contractAddress' => '0xbbb', 'collectionId' => '2', 'service' => 'mip-erc721' ),
			) ) ) );
		} );
	}

	public function test_there_is_no_route_to_store_a_collection_label() {
		$this->assertArrayNotHasKey( '/tokenize-content/v1/settings/collections', $this->server->get_routes() );
	}

	public function test_listing_collections_drops_the_cached_list_so_the_next_page_load_is_fresh() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		update_option( Settings::OPTION_API_KEY, 'test-key' );
		Settings::save_wallet_address( '0xbeef01' );
		$calls = 0;
		add_filter( 'pre_http_request', function () use ( &$calls ) {
			$calls++;
			return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'data' => array( array( 'contractAddress' => '0xaaa', 'collectionId' => '1', 'service' => 'mip-erc721' ) ) ) ) );
		} );
		Settings::fetch_live_collections();
		Settings::fetch_live_collections();
		$this->assertSame( 1, $calls );

		$request = new WP_REST_Request( 'GET', '/tokenize-content/v1/collections' );
		$request->set_param( 'owner', '0xbeef01' );
		$this->server->dispatch( $request );
		Settings::fetch_live_collections();

		$this->assertSame( 3, $calls );
		remove_all_filters( 'pre_http_request' );
	}

	public function test_the_default_collection_can_be_changed_to_another_of_the_wallet_s_collections() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$this->two_live_collections();

		$request = new WP_REST_Request( 'POST', '/tokenize-content/v1/settings/default-collection' );
		$request->set_body_params( array( 'contract' => '0xBBB' ) );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '0xbbb', Settings::get_default_collection() );
		remove_all_filters( 'pre_http_request' );
	}

	public function test_a_contract_that_is_not_one_of_the_wallet_s_collections_cannot_become_the_default() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$this->two_live_collections();
		Settings::set_default_collection( '0x00aaa' );

		$request = new WP_REST_Request( 'POST', '/tokenize-content/v1/settings/default-collection' );
		$request->set_body_params( array( 'contract' => '0xccc' ) );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( '0x00aaa', Settings::get_default_collection() );
		remove_all_filters( 'pre_http_request' );
	}

	public function test_only_an_administrator_can_change_the_default_collection() {
		get_role( 'editor' )->add_cap( Settings::CAP_TOKENIZE );
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'editor' ) ) );

		$response = $this->server->dispatch( new WP_REST_Request( 'POST', '/tokenize-content/v1/settings/default-collection' ) );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_the_account_route_reads_the_balance_with_the_site_key() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		update_option( Settings::OPTION_API_KEY, 'test-key' );
		add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$captured ) {
			$captured = array( 'url' => $url, 'args' => $args );
			return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'data' => array( 'creditBalance' => 12 ) ) ) );
		}, 10, 3 );

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/tokenize-content/v1/account' ) );

		$this->assertSame( 12, $response->get_data()['data']['creditBalance'] );
		$this->assertSame( 'https://backend.test/v1/portal/me', $captured['url'] );
		$this->assertSame( 'test-key', $captured['args']['headers']['x-api-key'] );
		remove_all_filters( 'pre_http_request' );
	}

	public function test_the_account_route_passes_the_backend_status_through() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		update_option( Settings::OPTION_API_KEY, 'test-key' );
		add_filter( 'pre_http_request', function () {
			return array( 'response' => array( 'code' => 402 ), 'body' => wp_json_encode( array( 'code' => 'credits_exhausted' ) ) );
		} );

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/tokenize-content/v1/account' ) );

		$this->assertSame( 402, $response->get_status() );
		remove_all_filters( 'pre_http_request' );
	}

	public function test_the_account_route_needs_a_key_and_an_administrator() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		delete_option( Settings::OPTION_API_KEY );
		$this->assertSame( 400, $this->server->dispatch( new WP_REST_Request( 'GET', '/tokenize-content/v1/account' ) )->get_status() );

		get_role( 'editor' )->add_cap( Settings::CAP_TOKENIZE );
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 403, $this->server->dispatch( new WP_REST_Request( 'GET', '/tokenize-content/v1/account' ) )->get_status() );
	}

	public function test_broadcast_route_records_the_reference_and_keeps_the_post_minting() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$post_id = $this->factory->post->create();
		\TokenizeContent\PostMeta::set_minting( $post_id );

		$request = new WP_REST_Request( 'POST', "/tokenize-content/v1/posts/{$post_id}/broadcast" );
		$request->set_body_params( array( 'txHash' => '0xabc', 'contract' => '0xdef', 'license' => 'CC BY', 'index' => 3 ) );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'minting', $response->get_data()['status'] );
		$this->assertSame( '0xabc', \TokenizeContent\PostMeta::get_tx_hash( $post_id ) );
		$this->assertSame( '3', \TokenizeContent\PostMeta::get_batch_index( $post_id ) );
	}

	public function minted_payloads_with_a_malformed_value() {
		$good = array( 'tokenId' => '42', 'txHash' => '0xabc', 'contract' => '0xdef', 'license' => 'CC BY' );
		return array(
			'contract'  => array( array_merge( $good, array( 'contract' => 'javascript:alert(1)' ) ) ),
			'token id'  => array( array_merge( $good, array( 'tokenId' => 'abc' ) ) ),
			'empty token id' => array( array_merge( $good, array( 'tokenId' => '' ) ) ),
			'tx hash'   => array( array_merge( $good, array( 'txHash' => 'not-a-hash' ) ) ),
		);
	}

	/**
	 * @dataProvider minted_payloads_with_a_malformed_value
	 */
	public function test_minted_route_rejects_a_malformed_reference_and_leaves_the_post_alone( array $payload ) {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$post_id = $this->factory->post->create();
		\TokenizeContent\PostMeta::set_minting( $post_id );

		$request = new WP_REST_Request( 'POST', "/tokenize-content/v1/posts/{$post_id}/minted" );
		$request->set_body_params( $payload );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'minting', \TokenizeContent\PostMeta::get_status( $post_id ) );
	}

	public function test_minted_route_accepts_a_well_formed_reference() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$post_id = $this->factory->post->create();

		$request = new WP_REST_Request( 'POST', "/tokenize-content/v1/posts/{$post_id}/minted" );
		$request->set_body_params( array( 'tokenId' => '42', 'txHash' => '0xabc123', 'contract' => '0xdef456', 'license' => 'CC BY' ) );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'minted', $response->get_data()['status'] );
	}

	public function test_broadcast_route_rejects_a_malformed_transaction_hash() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$post_id = $this->factory->post->create();

		$request = new WP_REST_Request( 'POST', "/tokenize-content/v1/posts/{$post_id}/broadcast" );
		$request->set_body_params( array( 'txHash' => '<b>x</b>', 'contract' => '0xdef', 'license' => 'CC BY', 'index' => 0 ) );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_tokenize_data_reports_a_failed_collections_lookup_instead_of_blank_collections() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		update_option( Settings::OPTION_API_KEY, 'test-key' );
		Settings::save_wallet_address( '0xbeef01' );
		$post_id = $this->factory->post->create();
		add_filter( 'pre_http_request', function () {
			return new WP_Error( 'http_request_failed', 'Could not resolve host' );
		} );

		$request = new WP_REST_Request( 'POST', '/tokenize-content/v1/tokenize-data' );
		$request->set_body_params( array( 'ids' => array( $post_id ) ) );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 502, $response->get_status() );
		remove_all_filters( 'pre_http_request' );
	}

	public function test_routes_nothing_in_the_ui_uses_are_not_registered() {
		$routes = array_keys( $this->server->get_routes() );

		$this->assertNotContains( '/tokenize-content/v1/settings/collection', $routes );
		$this->assertNotContains( '/tokenize-content/v1/paymaster/deploy/build', $routes );
		foreach ( $routes as $route ) {
			$this->assertStringStartsNotWith( '/tokenize-content/v1/tokens/', $route );
		}
	}

	public function test_tokenize_data_describes_the_post_with_its_excerpt_whatever_the_content_scope() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$post_id = $this->factory->post->create( array( 'post_status' => 'publish', 'post_excerpt' => 'Short <b>summary</b>', 'post_content' => '<!-- wp:paragraph --><p>Body <em>text</em></p><!-- /wp:paragraph -->' ) );

		foreach ( array( 'full', 'excerpt' ) as $scope ) {
			update_option( Settings::OPTION_CONTENT_SCOPE, $scope );
			$request = new WP_REST_Request( 'POST', '/tokenize-content/v1/tokenize-data' );
			$request->set_body_params( array( 'ids' => array( $post_id ) ) );
			$post = $this->server->dispatch( $request )->get_data()['posts'][ $post_id ];

			$this->assertSame( 'Short summary', $post['description'] );
		}
	}

	public function test_tokenize_data_returns_the_full_post_as_markdown_by_default() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$post_id = $this->factory->post->create( array(
			'post_status'  => 'publish',
			'post_excerpt' => 'Short',
			'post_content' => '<!-- wp:heading --><h2>Title</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Body <em>text</em></p><!-- /wp:paragraph -->',
		) );

		$request = new WP_REST_Request( 'POST', '/tokenize-content/v1/tokenize-data' );
		$request->set_body_params( array( 'ids' => array( $post_id ) ) );
		$post = $this->server->dispatch( $request )->get_data()['posts'][ $post_id ];

		$this->assertSame( "## Title\n\nBody *text*", $post['content'] );
	}

	public function test_tokenize_data_returns_no_content_when_the_scope_is_excerpt() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		update_option( Settings::OPTION_CONTENT_SCOPE, 'excerpt' );
		$post_id = $this->factory->post->create( array( 'post_status' => 'publish', 'post_excerpt' => 'Short', 'post_content' => '<p>Body text</p>' ) );

		$request = new WP_REST_Request( 'POST', '/tokenize-content/v1/tokenize-data' );
		$request->set_body_params( array( 'ids' => array( $post_id ) ) );
		$post = $this->server->dispatch( $request )->get_data()['posts'][ $post_id ];

		$this->assertSame( '', $post['content'] );
	}

	public function test_tokenize_data_returns_the_byline_the_site_name_and_the_post_address() {
		$author_id = $this->factory->user->create( array( 'role' => 'author', 'display_name' => 'Ana Autora' ) );
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$post_id = $this->factory->post->create( array( 'post_status' => 'publish', 'post_author' => $author_id, 'post_content' => 'Body' ) );

		$request = new WP_REST_Request( 'POST', '/tokenize-content/v1/tokenize-data' );
		$request->set_body_params( array( 'ids' => array( $post_id ) ) );
		$post = $this->server->dispatch( $request )->get_data()['posts'][ $post_id ];

		$this->assertSame( 'Ana Autora', $post['author'] );
		$this->assertSame( get_bloginfo( 'name' ), $post['publisher'] );
		$this->assertSame( get_permalink( $post_id ), $post['url'] );
	}

	public function test_tokenize_data_marks_an_ineligible_post_with_an_error_and_no_content() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$draft = $this->factory->post->create( array( 'post_status' => 'draft', 'post_content' => 'Private draft text' ) );

		$request = new WP_REST_Request( 'POST', '/tokenize-content/v1/tokenize-data' );
		$request->set_body_params( array( 'ids' => array( $draft ) ) );
		$post = $this->server->dispatch( $request )->get_data()['posts'][ $draft ];

		$this->assertArrayHasKey( 'error', $post );
		$this->assertArrayNotHasKey( 'description', $post );
		$this->assertArrayNotHasKey( 'content', $post );
		$this->assertStringNotContainsString( 'Private draft text', wp_json_encode( $post ) );
	}

	public function test_tokenize_data_marks_an_already_tokenized_post_with_an_error_and_no_content() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$post_id = $this->factory->post->create( array( 'post_status' => 'publish', 'post_content' => 'Body' ) );
		\TokenizeContent\PostMeta::save_minted( $post_id, array( 'token_id' => '1', 'tx_hash' => '0xabc', 'contract' => '0xdef', 'license' => 'CC BY' ) );

		$request = new WP_REST_Request( 'POST', '/tokenize-content/v1/tokenize-data' );
		$request->set_body_params( array( 'ids' => array( $post_id ) ) );
		$post = $this->server->dispatch( $request )->get_data()['posts'][ $post_id ];

		$this->assertSame( 'This post is already tokenized.', $post['error'] );
		$this->assertArrayNotHasKey( 'content', $post );
	}

	public function test_starting_a_mint_on_an_already_tokenized_post_is_refused() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );
		\TokenizeContent\PostMeta::save_minted( $post_id, array( 'token_id' => '1', 'tx_hash' => '0xabc', 'contract' => '0xdef', 'license' => 'CC BY' ) );

		$response = $this->server->dispatch( new WP_REST_Request( 'POST', "/tokenize-content/v1/posts/{$post_id}/minting" ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'minted', \TokenizeContent\PostMeta::get_status( $post_id ) );
	}

	public function test_tokenize_data_refuses_a_post_too_long_to_tokenize_in_full() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$long = $this->factory->post->create( array( 'post_status' => 'publish', 'post_content' => '<p>' . str_repeat( 'word ', 120000 ) . '</p>' ) );
		$short = $this->factory->post->create( array( 'post_status' => 'publish', 'post_content' => '<p>' . str_repeat( 'word ', 20000 ) . '</p>' ) );

		$request = new WP_REST_Request( 'POST', '/tokenize-content/v1/tokenize-data' );
		$request->set_body_params( array( 'ids' => array( $long, $short ) ) );
		$posts = $this->server->dispatch( $request )->get_data()['posts'];

		$this->assertArrayHasKey( 'error', $posts[ $long ] );
		$this->assertArrayNotHasKey( 'content', $posts[ $long ] );
		$this->assertArrayNotHasKey( 'error', $posts[ $short ] );
	}

	public function test_a_long_post_is_tokenized_when_the_scope_is_excerpt() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		update_option( Settings::OPTION_CONTENT_SCOPE, 'excerpt' );
		$long = $this->factory->post->create( array( 'post_status' => 'publish', 'post_content' => '<p>' . str_repeat( 'word ', 120000 ) . '</p>' ) );

		$request = new WP_REST_Request( 'POST', '/tokenize-content/v1/tokenize-data' );
		$request->set_body_params( array( 'ids' => array( $long ) ) );
		$post = $this->server->dispatch( $request )->get_data()['posts'][ $long ];

		$this->assertArrayNotHasKey( 'error', $post );
	}

	public function test_starting_a_mint_on_an_ineligible_post_is_refused() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$draft = $this->factory->post->create( array( 'post_status' => 'draft' ) );

		$response = $this->server->dispatch( new WP_REST_Request( 'POST', "/tokenize-content/v1/posts/{$draft}/minting" ) );

		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'none', \TokenizeContent\PostMeta::get_status( $draft ) );
	}

	private function capture_forwarded( &$captured ) {
		add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$captured ) {
			$captured = array( 'url' => $url, 'args' => $args );
			return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'ok' => true ) ) );
		}, 10, 3 );
	}

	private function json_request( string $route, array $body, array $headers = array() ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/tokenize-content/v1' . $route );
		$request->set_header( 'content-type', 'application/json' );
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}
		$request->set_body( wp_json_encode( $body ) );
		return $request;
	}

	public function test_only_the_fields_a_route_needs_are_forwarded() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		update_option( Settings::OPTION_API_KEY, 'test-key' );
		$this->capture_forwarded( $captured );

		$this->server->dispatch( $this->json_request( '/intents/mint', array(
			'owner' => '0x1', 'recipient' => '0x2', 'collectionId' => '7', 'tokenUri' => 'ipfs://x', 'royaltyBps' => 0,
			'service' => 'pop-protocol', 'backend_path' => '/v1/anything',
		) ) );

		$this->assertSame( array( 'owner' => '0x1', 'recipient' => '0x2', 'collectionId' => '7', 'tokenUri' => 'ipfs://x', 'royaltyBps' => 0 ), json_decode( $captured['args']['body'], true ) );
	}

	public function test_provisioning_always_targets_starknet() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		update_option( Settings::OPTION_API_KEY, 'test-key' );
		$this->capture_forwarded( $captured );

		$this->server->dispatch( $this->json_request( '/business/provisioning', array( 'email' => 'a@example.org', 'chain' => 'ETHEREUM' ) ) );

		$this->assertSame( array( 'email' => 'a@example.org', 'chain' => 'STARKNET' ), json_decode( $captured['args']['body'], true ) );
	}

	public function test_the_session_header_becomes_a_bearer_token_on_paymaster_routes_only() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		update_option( Settings::OPTION_API_KEY, 'test-key' );
		$this->capture_forwarded( $captured );

		$this->server->dispatch( $this->json_request( '/paymaster/invoke/build', array( 'intentIds' => array( 'i1' ) ), array( 'X-Tokenize-Session' => 'tok.123_abc' ) ) );
		$this->assertSame( 'Bearer tok.123_abc', $captured['args']['headers']['Authorization'] );
		$this->assertSame( 'test-key', $captured['args']['headers']['x-api-key'] );

		$this->server->dispatch( $this->json_request( '/intents/mint', array( 'owner' => '0x1' ), array( 'X-Tokenize-Session' => 'tok.123_abc' ) ) );
		$this->assertArrayNotHasKey( 'Authorization', $captured['args']['headers'] );
	}

	public function test_a_malformed_session_header_is_not_forwarded() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		update_option( Settings::OPTION_API_KEY, 'test-key' );
		$this->capture_forwarded( $captured );

		$this->server->dispatch( $this->json_request( '/paymaster/invoke/build', array( 'intentIds' => array( 'i1' ) ), array( 'X-Tokenize-Session' => "bad token\r\nX-Evil: 1" ) ) );

		$this->assertArrayNotHasKey( 'Authorization', $captured['args']['headers'] );
	}

	public function test_sign_in_routes_forward_to_the_fixed_backend_paths() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		update_option( Settings::OPTION_API_KEY, 'test-key' );
		$this->capture_forwarded( $captured );

		$this->server->dispatch( $this->json_request( '/auth/siws/nonce', array( 'walletAddress' => '0x1' ) ) );
		$this->assertStringEndsWith( '/v1/auth/siws/nonce', $captured['url'] );

		$this->server->dispatch( $this->json_request( '/auth/siws/verify', array( 'walletAddress' => '0x1', 'nonce' => 'n', 'signature' => array( '0x1', '0x2' ) ) ) );
		$this->assertStringEndsWith( '/v1/auth/siws/verify', $captured['url'] );
	}

	public function test_the_backend_error_code_is_kept_so_the_client_can_react_to_it() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		update_option( Settings::OPTION_API_KEY, 'test-key' );
		add_filter( 'pre_http_request', function () {
			return array( 'response' => array( 'code' => 502 ), 'body' => wp_json_encode( array( 'error' => 'May have been submitted', 'code' => 'may_have_broadcast' ) ) );
		} );

		$response = $this->server->dispatch( $this->json_request( '/paymaster/invoke/execute', array( 'intentIds' => array( 'i1' ) ) ) );

		$this->assertSame( 502, $response->get_status() );
		$this->assertSame( 'may_have_broadcast', $response->get_data()['code'] );
	}

	public function test_listing_collections_asks_only_for_this_plugin_s_collections() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		update_option( Settings::OPTION_API_KEY, 'test-key' );
		$this->capture_forwarded( $captured );

		$request = new WP_REST_Request( 'GET', '/tokenize-content/v1/collections' );
		$request->set_query_params( array( 'owner' => '0xabc' ) );
		$this->server->dispatch( $request );

		$this->assertStringContainsString( 'owner=0xabc', $captured['url'] );
		$this->assertStringContainsString( 'service=mip-erc721', $captured['url'] );
		$this->assertStringContainsString( 'limit=100', $captured['url'] );
	}

	private function rpc_request( $body ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/tokenize-content/v1/rpc' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
		return $request;
	}

	public function test_rpc_forwards_a_read_only_call_to_the_backend_rpc_path() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		update_option( Settings::OPTION_API_KEY, 'test-key' );
		$this->capture_forwarded( $captured );

		$response = $this->server->dispatch( $this->rpc_request( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'starknet_getTransactionReceipt', 'params' => array( 'transaction_hash' => '0xabc' ) ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringEndsWith( '/v1/rpc', $captured['url'] );
		$this->assertSame( 'starknet_getTransactionReceipt', json_decode( $captured['args']['body'], true )['method'] );
	}

	public function rpc_bodies_that_must_be_refused() {
		return array(
			'broadcast'        => array( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'starknet_addInvokeTransaction', 'params' => array() ) ),
			'unknown method'   => array( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'admin_shutdown', 'params' => array() ) ),
			'no method'        => array( array( 'jsonrpc' => '2.0', 'id' => 1 ) ),
			'batch with a bad' => array( array( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'starknet_chainId' ), array( 'jsonrpc' => '2.0', 'id' => 2, 'method' => 'starknet_addInvokeTransaction' ) ) ),
			'not an object'    => array( 'starknet_chainId' ),
		);
	}

	/**
	 * @dataProvider rpc_bodies_that_must_be_refused
	 */
	public function test_rpc_refuses_anything_outside_the_read_only_allow_list( $body ) {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		update_option( Settings::OPTION_API_KEY, 'test-key' );
		$this->capture_forwarded( $captured );

		$response = $this->server->dispatch( $this->rpc_request( $body ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertNull( $captured );
	}

	public function test_rpc_requires_the_tokenize_capability() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'subscriber' ) ) );

		$response = $this->server->dispatch( $this->rpc_request( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'starknet_chainId' ) ) );

		$this->assertSame( 403, $response->get_status() );
	}

	private function post_with_featured_image(): int {
		$post_id    = $this->factory->post->create( array( 'post_status' => 'publish' ) );
		$attachment = $this->factory->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', $post_id );
		set_post_thumbnail( $post_id, $attachment );
		return $post_id;
	}

	public function test_featured_image_upload_sends_the_attachment_file_to_the_backend() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		update_option( Settings::OPTION_API_KEY, 'test-key' );
		$post_id = $this->post_with_featured_image();
		add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$captured ) {
			$captured = array( 'url' => $url, 'args' => $args );
			return array( 'response' => array( 'code' => 201 ), 'body' => wp_json_encode( array( 'data' => array( 'url' => 'ipfs://img' ) ) ) );
		}, 10, 3 );

		$response = $this->server->dispatch( new WP_REST_Request( 'POST', "/tokenize-content/v1/posts/{$post_id}/featured-image" ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'ipfs://img', $response->get_data()['data']['url'] );
		$this->assertStringEndsWith( '/v1/metadata/upload-file', $captured['url'] );
		$this->assertStringContainsString( 'filename="canola', $captured['args']['body'] );
		$this->assertStringContainsString( 'Content-Type: image/jpeg', $captured['args']['body'] );
		$this->assertStringContainsString( file_get_contents( DIR_TESTDATA . '/images/canola.jpg' ), $captured['args']['body'] );
	}

	public function test_featured_image_upload_is_empty_when_the_post_has_no_featured_image() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		update_option( Settings::OPTION_API_KEY, 'test-key' );
		$this->capture_forwarded( $captured );
		$post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );

		$response = $this->server->dispatch( new WP_REST_Request( 'POST', "/tokenize-content/v1/posts/{$post_id}/featured-image" ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNull( $response->get_data()['data']['url'] );
		$this->assertNull( $captured );
	}

	public function test_featured_image_upload_needs_edit_access_to_the_post() {
		$post_id = $this->post_with_featured_image();
		$author  = $this->factory->user->create( array( 'role' => 'author' ) );
		get_role( 'author' )->add_cap( Settings::CAP_TOKENIZE );
		wp_set_current_user( $author );

		$response = $this->server->dispatch( new WP_REST_Request( 'POST', "/tokenize-content/v1/posts/{$post_id}/featured-image" ) );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_tokenize_data_says_whether_the_post_has_a_featured_image_instead_of_a_url() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$with    = $this->post_with_featured_image();
		$without = $this->factory->post->create( array( 'post_status' => 'publish' ) );

		$request = new WP_REST_Request( 'POST', '/tokenize-content/v1/tokenize-data' );
		$request->set_body_params( array( 'ids' => array( $with, $without ) ) );
		$posts = $this->server->dispatch( $request )->get_data()['posts'];

		$this->assertTrue( $posts[ $with ]['hasImage'] );
		$this->assertFalse( $posts[ $without ]['hasImage'] );
		$this->assertArrayNotHasKey( 'image', $posts[ $with ] );
	}

	public function test_a_body_field_named_id_cannot_replace_the_post_in_the_url() {
		$author = $this->factory->user->create( array( 'role' => 'author' ) );
		get_role( 'author' )->add_cap( Settings::CAP_TOKENIZE );
		wp_set_current_user( $author );
		$mine  = $this->factory->post->create( array( 'post_status' => 'publish', 'post_author' => $author ) );
		$other = $this->factory->post->create( array( 'post_status' => 'publish' ) );

		$request = new WP_REST_Request( 'POST', "/tokenize-content/v1/posts/{$mine}/minting" );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'id' => $other ) ) );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'minting', \TokenizeContent\PostMeta::get_status( $mine ) );
		$this->assertSame( 'none', \TokenizeContent\PostMeta::get_status( $other ) );
	}

	public function test_featured_image_upload_is_refused_for_a_post_that_is_not_public() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		update_option( Settings::OPTION_API_KEY, 'test-key' );
		$this->capture_forwarded( $captured );
		$draft      = $this->factory->post->create( array( 'post_status' => 'draft' ) );
		$attachment = $this->factory->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', $draft );
		set_post_thumbnail( $draft, $attachment );

		$response = $this->server->dispatch( new WP_REST_Request( 'POST', "/tokenize-content/v1/posts/{$draft}/featured-image" ) );

		$this->assertSame( 422, $response->get_status() );
		$this->assertNull( $captured );
	}
}
