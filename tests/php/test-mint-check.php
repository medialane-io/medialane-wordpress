<?php

use TokenizeContent\MintCheck;
use TokenizeContent\Settings;

class Test_Mint_Check extends Tokenize_Test_Case {

	const TRANSFER = '0x99cd8bde557814842a3121e8ddfd433a539b8c9f14bf31ebf108d12e6196e9';
	const CONTRACT = '0x0abc';
	const TX       = '0xfeed';

	public function set_up() {
		parent::set_up();
		update_option( Settings::OPTION_API_KEY, 'test-key' );
	}

	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		delete_transient( 'tokenize_content_receipt_' . md5( self::TX ) );
		parent::tear_down();
	}

	private function mint_event( string $token_low, string $token_high = '0x0', string $contract = self::CONTRACT, string $from = '0x0' ): array {
		return array(
			'from_address' => $contract,
			'keys'         => array( self::TRANSFER, $from, '0x999', $token_low, $token_high ),
			'data'         => array(),
		);
	}

	private function receipt( array $events, string $execution = 'SUCCEEDED', string $finality = 'ACCEPTED_ON_L2' ): array {
		return array(
			'transaction_hash' => self::TX,
			'execution_status' => $execution,
			'finality_status'  => $finality,
			'events'           => $events,
		);
	}

	private function serve( $result, &$calls = null, int $status = 200 ) {
		add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( $result, &$calls, $status ) {
			$calls = (int) $calls + 1;
			return array( 'response' => array( 'code' => $status ), 'body' => wp_json_encode( $result ) );
		}, 10, 3 );
	}

	public function test_the_transfer_selector_is_the_one_the_browser_matches() {
		$this->assertSame( self::TRANSFER, MintCheck::TRANSFER_SELECTOR );
	}

	public function test_token_ids_lists_the_tokens_minted_by_the_contract_in_order() {
		$receipt = $this->receipt( array(
			$this->mint_event( '0x65' ),
			$this->mint_event( '0x66' ),
		) );

		$this->assertSame( array( '101', '102' ), MintCheck::token_ids( $receipt, self::CONTRACT ) );
	}

	public function test_token_ids_ignores_events_that_are_not_mints_by_that_contract() {
		$receipt = $this->receipt( array(
			$this->mint_event( '0x1', '0x0', '0x0def' ),
			$this->mint_event( '0x2', '0x0', self::CONTRACT, '0x5' ),
			array( 'from_address' => self::CONTRACT, 'keys' => array( '0x1234', '0x0', '0x999', '0x3', '0x0' ), 'data' => array() ),
			array( 'from_address' => self::CONTRACT, 'keys' => array( self::TRANSFER, '0x0', '0x999', '0x4' ), 'data' => array() ),
			$this->mint_event( '0x7' ),
		) );

		$this->assertSame( array( '7' ), MintCheck::token_ids( $receipt, '0x00000abc' ) );
	}

	public function test_token_ids_combines_the_low_and_high_halves_of_the_token_id() {
		$receipt = $this->receipt( array( $this->mint_event( '0x1', '0x1' ) ) );

		$this->assertSame( '340282366920938463463374607431768211457', MintCheck::token_ids( $receipt, self::CONTRACT )[0] );
	}

	public function test_token_ids_is_empty_for_a_receipt_with_no_events() {
		$this->assertSame( array(), MintCheck::token_ids( array(), self::CONTRACT ) );
	}

	public function test_minted_token_returns_the_token_at_the_batch_index() {
		$this->serve( array( 'jsonrpc' => '2.0', 'id' => 1, 'result' => $this->receipt( array( $this->mint_event( '0x65' ), $this->mint_event( '0x66' ) ) ) ) );

		$this->assertSame( '102', MintCheck::minted_token( self::TX, self::CONTRACT, 1 ) );
	}

	public function test_minted_token_asks_the_backend_rpc_for_the_receipt_with_the_site_key() {
		$captured = null;
		add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$captured ) {
			$captured = array( 'url' => $url, 'args' => $args );
			return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'result' => $this->receipt( array( $this->mint_event( '0x65' ) ) ) ) ) );
		}, 10, 3 );

		MintCheck::minted_token( self::TX, self::CONTRACT, 0 );

		$this->assertSame( 'https://backend.test/v1/rpc', $captured['url'] );
		$this->assertSame( 'test-key', $captured['args']['headers']['x-api-key'] );
		$body = json_decode( $captured['args']['body'], true );
		$this->assertSame( 'starknet_getTransactionReceipt', $body['method'] );
		$this->assertSame( array( 'transaction_hash' => self::TX ), $body['params'] );
	}

	public function test_a_reverted_transaction_mints_nothing() {
		$this->serve( array( 'result' => $this->receipt( array( $this->mint_event( '0x65' ) ), 'REVERTED' ) ) );

		$result = MintCheck::minted_token( self::TX, self::CONTRACT, 0 );

		$this->assertWPError( $result );
		$this->assertSame( 422, $result->get_error_data()['status'] );
	}

	public function test_a_transaction_that_is_not_accepted_yet_is_not_final() {
		$this->serve( array( 'result' => $this->receipt( array( $this->mint_event( '0x65' ) ), 'SUCCEEDED', 'PRE_CONFIRMED' ) ) );

		$result = MintCheck::minted_token( self::TX, self::CONTRACT, 0 );

		$this->assertWPError( $result );
		$this->assertSame( 409, $result->get_error_data()['status'] );
	}

	public function test_a_transaction_with_no_token_at_the_index_mints_nothing_for_that_post() {
		$this->serve( array( 'result' => $this->receipt( array( $this->mint_event( '0x65' ) ) ) ) );

		$result = MintCheck::minted_token( self::TX, self::CONTRACT, 1 );

		$this->assertWPError( $result );
		$this->assertSame( 422, $result->get_error_data()['status'] );
	}

	public function test_a_receipt_the_node_does_not_have_yet_is_unavailable() {
		$this->serve( array( 'jsonrpc' => '2.0', 'id' => 1, 'error' => array( 'code' => 29, 'message' => 'Transaction hash not found' ) ) );

		$result = MintCheck::minted_token( self::TX, self::CONTRACT, 0 );

		$this->assertWPError( $result );
		$this->assertSame( 502, $result->get_error_data()['status'] );
	}

	public function test_a_failing_backend_makes_the_receipt_unavailable() {
		$this->serve( array( 'error' => 'down' ), $calls, 503 );

		$result = MintCheck::minted_token( self::TX, self::CONTRACT, 0 );

		$this->assertWPError( $result );
		$this->assertSame( 502, $result->get_error_data()['status'] );
	}

	public function test_an_unreachable_backend_makes_the_receipt_unavailable() {
		add_filter( 'pre_http_request', function () {
			return new WP_Error( 'http_request_failed', 'Could not resolve host' );
		} );

		$this->assertSame( 502, MintCheck::minted_token( self::TX, self::CONTRACT, 0 )->get_error_data()['status'] );
	}

	public function test_a_confirmed_receipt_is_fetched_once_for_a_whole_batch() {
		$calls = 0;
		$this->serve( array( 'result' => $this->receipt( array( $this->mint_event( '0x65' ), $this->mint_event( '0x66' ) ) ) ), $calls );

		MintCheck::minted_token( self::TX, self::CONTRACT, 0 );
		MintCheck::minted_token( self::TX, self::CONTRACT, 1 );

		$this->assertSame( 1, $calls );
	}

	public function test_a_receipt_that_is_not_final_is_not_kept() {
		$calls = 0;
		$this->serve( array( 'result' => $this->receipt( array( $this->mint_event( '0x65' ) ), 'SUCCEEDED', 'PRE_CONFIRMED' ) ), $calls );

		MintCheck::minted_token( self::TX, self::CONTRACT, 0 );
		MintCheck::minted_token( self::TX, self::CONTRACT, 0 );

		$this->assertSame( 2, $calls );
	}

	public function test_without_an_api_key_the_receipt_is_unavailable() {
		delete_option( Settings::OPTION_API_KEY );

		$this->assertSame( 400, MintCheck::minted_token( self::TX, self::CONTRACT, 0 )->get_error_data()['status'] );
	}
}
