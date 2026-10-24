<?php

namespace TokenizeContent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MintCheck {

	const TRANSFER_SELECTOR = '0x99cd8bde557814842a3121e8ddfd433a539b8c9f14bf31ebf108d12e6196e9';

	const FINAL_STATES = array( 'ACCEPTED_ON_L2', 'ACCEPTED_ON_L1' );

	const CACHE_SECONDS = 300;

	public static function token_ids( array $receipt, string $contract ): array {
		$ids = array();
		foreach ( isset( $receipt['events'] ) && is_array( $receipt['events'] ) ? $receipt['events'] : array() as $event ) {
			$keys = isset( $event['keys'] ) && is_array( $event['keys'] ) ? $event['keys'] : array();
			if ( ! isset( $event['from_address'] ) || ! Settings::same_address( (string) $event['from_address'], $contract ) ) {
				continue;
			}
			if ( 5 !== count( $keys ) || ! Settings::same_address( (string) $keys[0], self::TRANSFER_SELECTOR ) || ! Settings::same_address( (string) $keys[1], '0x0' ) ) {
				continue;
			}
			$low  = str_pad( ltrim( substr( strtolower( (string) $keys[3] ), 2 ), '0' ), 32, '0', STR_PAD_LEFT );
			$high = ltrim( substr( strtolower( (string) $keys[4] ), 2 ), '0' );
			$ids[] = self::hex_to_decimal( $high . $low );
		}
		return $ids;
	}

	public static function minted_token( string $tx_hash, string $contract, int $index ) {
		$receipt = self::receipt( $tx_hash );
		if ( is_wp_error( $receipt ) ) {
			return $receipt;
		}
		$ids = self::token_ids( $receipt, $contract );
		if ( ! isset( $ids[ $index ] ) ) {
			return new \WP_Error( 'tokenize_content_not_minted', __( 'This transaction did not mint this post.', 'tokenize-content' ), array( 'status' => 422 ) );
		}
		return $ids[ $index ];
	}

	private static function receipt( string $tx_hash ) {
		$cache_key = 'tokenize_content_receipt_' . md5( $tx_hash );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$key = Settings::get_api_key();
		if ( ! $key ) {
			return new \WP_Error( 'tokenize_content_no_api_key', __( 'No Medialane API key configured.', 'tokenize-content' ), array( 'status' => 400 ) );
		}
		$response = wp_remote_post( TOKENIZE_CONTENT_BACKEND_URL . '/v1/rpc', array(
			'headers' => array(
				'Content-Type' => 'application/json',
				'x-api-key'    => $key,
			),
			'body'    => wp_json_encode( array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'starknet_getTransactionReceipt',
				'params'  => array( 'transaction_hash' => $tx_hash ),
			) ),
			'timeout' => 15,
		) );
		$body    = is_wp_error( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );
		$receipt = is_array( $body ) && isset( $body['result'] ) && is_array( $body['result'] ) ? $body['result'] : null;
		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) >= 300 || null === $receipt ) {
			return new \WP_Error( 'tokenize_content_receipt_unavailable', __( 'The transaction could not be read from the chain right now. Try again.', 'tokenize-content' ), array( 'status' => 502 ) );
		}
		if ( ! isset( $receipt['execution_status'] ) || 'SUCCEEDED' !== $receipt['execution_status'] ) {
			return new \WP_Error( 'tokenize_content_not_minted', __( 'This transaction did not succeed on chain.', 'tokenize-content' ), array( 'status' => 422 ) );
		}
		if ( ! isset( $receipt['finality_status'] ) || ! in_array( $receipt['finality_status'], self::FINAL_STATES, true ) ) {
			return new \WP_Error( 'tokenize_content_not_confirmed', __( 'This transaction is not confirmed on chain yet. Try again in a moment.', 'tokenize-content' ), array( 'status' => 409 ) );
		}
		set_transient( $cache_key, $receipt, self::CACHE_SECONDS );
		return $receipt;
	}

	private static function hex_to_decimal( string $hex ): string {
		if ( '' === $hex ) {
			return '0';
		}
		$digits = array( 0 );
		foreach ( str_split( $hex ) as $char ) {
			$carry = hexdec( $char );
			for ( $i = count( $digits ) - 1; $i >= 0; $i-- ) {
				$value      = $digits[ $i ] * 16 + $carry;
				$digits[ $i ] = $value % 10;
				$carry      = intdiv( $value, 10 );
			}
			while ( $carry > 0 ) {
				array_unshift( $digits, $carry % 10 );
				$carry = intdiv( $carry, 10 );
			}
		}
		return implode( '', $digits );
	}
}
