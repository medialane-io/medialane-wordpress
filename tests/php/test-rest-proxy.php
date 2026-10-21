<?php

use TokenizeContent\Settings;

class Test_Rest_Proxy extends WP_UnitTestCase {

	protected $server;

	public function set_up() {
		parent::set_up();
		global $wp_rest_server;
		$this->server = $wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init' );
		// Normally granted by register_activation_hook on plugin activation;
		// the test suite never activates the plugin, so grant it explicitly.
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

		// WP's own rest_authorization_required_code() returns 403 for a
		// logged-in-but-unauthorized user and reserves 401 for anonymous ones.
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

		$this->assertStringEndsWith( '/v1/intents/mint', $captured_url );
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

		// Subscribers have no edit_posts capability at all by default, so this
		// exercises the per-post ownership check independent of the tokenize
		// capability itself.
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

	public function test_deploy_build_and_provisioning_routes_forward_to_the_fixed_backend_path() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		update_option( Settings::OPTION_API_KEY, 'test-key' );

		$captured_urls = array();
		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( &$captured_urls ) {
			$captured_urls[] = $url;
			return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'ok' => true ) ) );
		}, 10, 3 );

		$this->server->dispatch( new WP_REST_Request( 'POST', '/tokenize-content/v1/paymaster/deploy/build' ) );
		$this->server->dispatch( new WP_REST_Request( 'POST', '/tokenize-content/v1/business/provisioning' ) );

		$this->assertStringEndsWith( '/v1/paymaster/deploy/build', $captured_urls[0] );
		$this->assertStringEndsWith( '/v1/business/provisioning', $captured_urls[1] );
	}

	public function test_settings_collections_route_saves_a_collection_s_label() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		$request = new WP_REST_Request( 'POST', '/tokenize-content/v1/settings/collections' );
		$request->set_body_params( array( 'contract' => '0xaaa11', 'label' => 'Politics' ) );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( '0xaaa11' => 'Politics' ), $response->get_data()['labels'] );
	}

	public function test_settings_collections_route_requires_admin_permission() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'subscriber' ) ) );

		$request = new WP_REST_Request( 'POST', '/tokenize-content/v1/settings/collections' );
		$request->set_body_params( array( 'contract' => '0xaaa11', 'label' => 'Politics' ) );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
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
		$post_id = $this->factory->post->create( array( 'post_author' => $author, 'post_title' => 'Hello', 'post_content' => 'Body text', 'post_excerpt' => 'Short' ) );

		$request = new WP_REST_Request( 'POST', '/tokenize-content/v1/tokenize-data' );
		$request->set_body_params( array( 'ids' => array( $post_id ) ) );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$post = $response->get_data()['posts'][ $post_id ];
		$this->assertSame( 'Hello', $post['title'] );
		$this->assertSame( 'Body text', $post['content'] );
		$this->assertSame( 'Short', $post['excerpt'] );
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
			return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'data' => array( array( 'contractAddress' => '0xaaa' ) ) ) ) );
		} );

		$request = new WP_REST_Request( 'POST', '/tokenize-content/v1/tokenize-data' );
		$request->set_body_params( array( 'ids' => $posts ) );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 1, $calls );
		$this->assertSame( '0xaaa', $response->get_data()['posts'][ $posts[0] ]['collectionContract'] );
		remove_all_filters( 'pre_http_request' );
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
}
