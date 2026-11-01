<?php

namespace TokenizeContent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PostStatusRoutes {

	public static function register_routes() {
		foreach ( array( 'minting' => 'save_minting', 'minted' => 'save_minted', 'error' => 'save_error' ) as $route => $callback ) {
			register_rest_route( RestProxy::NAMESPACE, '/posts/(?P<id>\\d+)/' . $route, array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, $callback ),
				'permission_callback' => array( RestProxy::class, 'check_tokenize_permission' ),
			) );
		}
	}

	public static function save_minting( \WP_REST_Request $request ) {
		$post_id = RestProxy::post_id( $request );
		$post    = get_post( $post_id );
		if ( ! $post || ! Eligibility::is_tokenizable( $post ) ) {
			return new \WP_Error( 'tokenize_content_not_eligible', Eligibility::message(), array( 'status' => 422 ) );
		}
		if ( Eligibility::is_tokenized( $post_id ) ) {
			return new \WP_Error( 'tokenize_content_already_tokenized', Eligibility::tokenized_message(), array( 'status' => 409 ) );
		}
		PostMeta::set_minting( $post_id );
		return rest_ensure_response( array( 'status' => PostMeta::get_status( $post_id ) ) );
	}

	private static function malformed_reference( \WP_REST_Request $request, array $fields ) {
		$patterns = array(
			'txHash'   => '/^0x[0-9a-fA-F]+$/',
			'contract' => '/^0x[0-9a-fA-F]+$/',
			'tokenId'  => '/^\d+$/',
		);
		foreach ( $fields as $field ) {
			if ( ! preg_match( $patterns[ $field ], (string) $request->get_param( $field ) ) ) {
				return RestProxy::invalid_reference();
			}
		}
		return null;
	}

	public static function save_minted( \WP_REST_Request $request ) {
		$invalid = self::malformed_reference( $request, array( 'txHash', 'contract', 'tokenId' ) );
		if ( $invalid ) {
			return $invalid;
		}
		$post_id = RestProxy::post_id( $request );
		if ( Eligibility::is_tokenized( $post_id ) ) {
			return new \WP_Error( 'tokenize_content_already_tokenized', Eligibility::tokenized_message(), array( 'status' => 409 ) );
		}
		$tx_hash  = PostMeta::get_tx_hash( $post_id );
		$contract = PostMeta::get_contract( $post_id );
		if ( PostMeta::STATUS_MINTING !== PostMeta::get_status( $post_id ) || '' === $tx_hash || '' === $contract ) {
			return new \WP_Error( 'tokenize_content_not_minting', __( 'This post is not waiting for a token.', 'tokenize-content' ), array( 'status' => 409 ) );
		}
		if ( ! Settings::same_address( $tx_hash, (string) $request->get_param( 'txHash' ) ) || ! Settings::same_address( $contract, (string) $request->get_param( 'contract' ) ) ) {
			return RestProxy::invalid_reference();
		}
		$live = Settings::fetch_live_collections();
		if ( is_wp_error( $live ) ) {
			return RestProxy::collections_unavailable();
		}
		$owned = array_filter( $live, function ( $collection ) use ( $contract ) {
			return Settings::same_address( $contract, $collection['contract'] );
		} );
		if ( ! $owned ) {
			return new \WP_Error( 'tokenize_content_unknown_collection', __( 'That collection does not belong to the connected wallet.', 'tokenize-content' ), array( 'status' => 422 ) );
		}
		$token_id = MintCheck::minted_token( $tx_hash, $contract, (int) PostMeta::get_batch_index( $post_id ) );
		if ( is_wp_error( $token_id ) ) {
			return $token_id;
		}
		if ( (string) $request->get_param( 'tokenId' ) !== $token_id ) {
			return new \WP_Error( 'tokenize_content_not_minted', __( 'This transaction did not mint this post.', 'tokenize-content' ), array( 'status' => 422 ) );
		}
		PostMeta::save_minted( $post_id, array(
			'token_id' => $token_id,
			'tx_hash'  => $tx_hash,
			'contract' => $contract,
			'license'  => PostMeta::get_license( $post_id ),
		) );
		return rest_ensure_response( array( 'status' => PostMeta::get_status( $post_id ) ) );
	}

	public static function save_error( \WP_REST_Request $request ) {
		$post_id = RestProxy::post_id( $request );
		if ( Eligibility::is_tokenized( $post_id ) ) {
			return new \WP_Error( 'tokenize_content_already_tokenized', Eligibility::tokenized_message(), array( 'status' => 409 ) );
		}
		PostMeta::save_error( $post_id, (string) $request->get_param( 'message' ) );
		return rest_ensure_response( array( 'status' => PostMeta::get_status( $post_id ) ) );
	}
}
