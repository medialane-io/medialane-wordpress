<?php

namespace TokenizeContent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RestProxy {

	const NAMESPACE = 'tokenize-content/v1';

	const FORWARDS = array(
		'/metadata/upload'           => array( 'path' => '/v1/metadata/upload', 'fields' => array( 'name', 'description', 'image', 'external_url', 'attributes' ) ),
		'/intents/create-collection' => array( 'path' => '/v1/intents/create-collection', 'fields' => array( 'owner', 'name', 'symbol' ) ),
		'/intents/mint'              => array( 'path' => '/v1/intents/mint', 'fields' => array( 'owner', 'recipient', 'collectionId', 'tokenUri', 'royaltyBps' ) ),
		'/collections/sync-tx'       => array( 'path' => '/v1/tx/sync', 'fields' => array( 'txHash' ) ),
		'/business/provisioning'     => array( 'path' => '/v1/business/provisioning', 'fields' => array( 'email' ), 'fixed' => array( 'chain' => 'STARKNET' ) ),
		'/auth/siws/nonce'           => array( 'path' => '/v1/auth/siws/nonce', 'fields' => array( 'walletAddress' ) ),
		'/auth/siws/verify'          => array( 'path' => '/v1/auth/siws/verify', 'fields' => array( 'walletAddress', 'nonce', 'signature' ) ),
		'/paymaster/invoke/build'    => array( 'path' => '/v1/paymaster/invoke/build', 'fields' => array( 'intentIds' ), 'session' => true ),
		'/paymaster/invoke/execute'  => array( 'path' => '/v1/paymaster/invoke/execute', 'fields' => array( 'intentIds', 'typedData', 'signature' ), 'session' => true, 'record' => true ),
	);

	const RPC_METHODS = array(
		'starknet_call',
		'starknet_estimateFee',
		'starknet_getNonce',
		'starknet_getTransactionReceipt',
		'starknet_getTransactionStatus',
		'starknet_getClassAt',
		'starknet_getClassHashAt',
		'starknet_chainId',
		'starknet_specVersion',
		'starknet_blockNumber',
	);

	const RPC_MAX_BATCH = 20;

	public static function register_routes() {
		foreach ( self::FORWARDS as $route => $forward ) {
			register_rest_route( self::NAMESPACE, $route, array(
				'methods'             => 'POST',
				'callback'            => function ( \WP_REST_Request $request ) use ( $forward ) {
					return self::forward_json( $request, $forward );
				},
				'permission_callback' => array( __CLASS__, 'check_tokenize_permission' ),
			) );
		}
		register_rest_route( self::NAMESPACE, '/rpc', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'forward_rpc' ),
			'permission_callback' => array( __CLASS__, 'check_tokenize_permission' ),
		) );
		register_rest_route( self::NAMESPACE, '/posts/(?P<id>\d+)/featured-image', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'upload_featured_image' ),
			'permission_callback' => array( __CLASS__, 'check_tokenize_permission' ),
		) );
		register_rest_route( self::NAMESPACE, '/tokenize-data', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'tokenize_data' ),
			'permission_callback' => array( __CLASS__, 'check_tokenize_permission' ),
		) );
		register_rest_route( self::NAMESPACE, '/settings/wallet', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'save_wallet' ),
			'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
		) );
		register_rest_route( self::NAMESPACE, '/collections', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'forward_list_collections' ),
			'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
		) );
		register_rest_route( self::NAMESPACE, '/account', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'forward_account' ),
			'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
		) );
		register_rest_route( self::NAMESPACE, '/settings/default-collection', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'save_default_collection' ),
			'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
		) );
		register_rest_route( self::NAMESPACE, '/settings/category-map', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'save_category_map' ),
			'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
		) );
		register_rest_route( self::NAMESPACE, '/posts/(?P<id>\d+)/minting', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'save_minting' ),
			'permission_callback' => array( __CLASS__, 'check_tokenize_permission' ),
		) );
		register_rest_route( self::NAMESPACE, '/posts/(?P<id>\d+)/minted', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'save_minted' ),
			'permission_callback' => array( __CLASS__, 'check_tokenize_permission' ),
		) );
		register_rest_route( self::NAMESPACE, '/posts/(?P<id>\d+)/error', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'save_error' ),
			'permission_callback' => array( __CLASS__, 'check_tokenize_permission' ),
		) );
	}

	public static function tokenize_data( \WP_REST_Request $request ) {
		$ids = $request->get_param( 'ids' );
		$ids = is_array( $ids ) ? array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) ) : array();
		foreach ( $ids as $id ) {
			if ( ! current_user_can( 'edit_post', $id ) ) {
				return new \WP_Error( 'tokenize_content_forbidden', __( "You can't tokenize a post you don't have edit access to.", 'tokenize-content' ), array( 'status' => 403 ) );
			}
		}

		$live = Settings::fetch_live_collections();
		if ( is_wp_error( $live ) ) {
			return new \WP_Error( 'tokenize_content_collections_unavailable', __( 'Collections could not be loaded right now. Try again.', 'tokenize-content' ), array( 'status' => 502 ) );
		}
		$scope = Settings::get_content_scope();
		$posts = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post || 'post' !== $post->post_type ) {
				continue;
			}
			if ( ! Eligibility::is_tokenizable( $post ) ) {
				$posts[ $id ] = array( 'error' => Eligibility::message() );
				continue;
			}
			if ( Eligibility::is_tokenized( $id ) ) {
				$posts[ $id ] = array( 'error' => Eligibility::tokenized_message() );
				continue;
			}
			$content = 'full' === $scope ? Description::markdown( $post ) : '';
			$collection   = Settings::resolve_collection_for_post( $id, $live );
			$posts[ $id ] = array(
				'title'              => get_the_title( $post ),
				'description'        => Description::plain_text( $post, 'excerpt' ),
				'content'            => $content,
				'author'             => get_the_author_meta( 'display_name', $post->post_author ),
				'publisher'          => get_bloginfo( 'name' ),
				'url'                => get_permalink( $post ),
				'hasImage'           => (bool) has_post_thumbnail( $post ),
				'authorEmail'        => get_the_author_meta( 'user_email', $post->post_author ),
				'collectionContract' => $collection ? $collection['contract'] : '',
				'collectionId'       => $collection ? $collection['collectionId'] : '',
			);
		}
		return rest_ensure_response( array( 'posts' => $posts ) );
	}

	public static function save_minting( \WP_REST_Request $request ) {
		$post_id = self::post_id( $request );
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
				return new \WP_Error( 'tokenize_content_invalid_reference', __( 'The transaction reference is malformed.', 'tokenize-content' ), array( 'status' => 400 ) );
			}
		}
		return null;
	}

	public static function save_minted( \WP_REST_Request $request ) {
		$invalid = self::malformed_reference( $request, array( 'txHash', 'contract', 'tokenId' ) );
		if ( $invalid ) {
			return $invalid;
		}
		$post_id = self::post_id( $request );
		if ( Eligibility::is_tokenized( $post_id ) ) {
			return new \WP_Error( 'tokenize_content_already_tokenized', Eligibility::tokenized_message(), array( 'status' => 409 ) );
		}
		$tx_hash  = PostMeta::get_tx_hash( $post_id );
		$contract = PostMeta::get_contract( $post_id );
		if ( PostMeta::STATUS_MINTING !== PostMeta::get_status( $post_id ) || '' === $tx_hash || '' === $contract ) {
			return new \WP_Error( 'tokenize_content_not_minting', __( 'This post is not waiting for a token.', 'tokenize-content' ), array( 'status' => 409 ) );
		}
		if ( ! Settings::same_address( $tx_hash, (string) $request->get_param( 'txHash' ) ) || ! Settings::same_address( $contract, (string) $request->get_param( 'contract' ) ) ) {
			return new \WP_Error( 'tokenize_content_invalid_reference', __( 'The transaction reference is malformed.', 'tokenize-content' ), array( 'status' => 400 ) );
		}
		$live = Settings::fetch_live_collections();
		if ( is_wp_error( $live ) ) {
			return new \WP_Error( 'tokenize_content_collections_unavailable', __( 'Collections could not be loaded right now. Try again.', 'tokenize-content' ), array( 'status' => 502 ) );
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
		$post_id = self::post_id( $request );
		PostMeta::save_error( $post_id, (string) $request->get_param( 'message' ) );
		return rest_ensure_response( array( 'status' => PostMeta::get_status( $post_id ) ) );
	}

	public static function save_wallet( \WP_REST_Request $request ) {
		$address = (string) $request->get_param( 'address' );
		Settings::save_wallet_address( $address );
		return rest_ensure_response( array( 'address' => Settings::get_wallet_address() ) );
	}

	public static function save_default_collection( \WP_REST_Request $request ) {
		$live = Settings::fetch_live_collections();
		if ( is_wp_error( $live ) ) {
			return new \WP_Error( 'tokenize_content_collections_unavailable', __( 'Collections could not be loaded right now. Try again.', 'tokenize-content' ), array( 'status' => 502 ) );
		}
		$wanted = (string) $request->get_param( 'contract' );
		foreach ( $live as $collection ) {
			if ( Settings::same_address( $wanted, $collection['contract'] ) ) {
				Settings::set_default_collection( $collection['contract'] );
				return rest_ensure_response( array( 'default' => Settings::get_default_collection() ) );
			}
		}
		return new \WP_Error( 'tokenize_content_unknown_collection', __( 'That collection does not belong to the connected wallet.', 'tokenize-content' ), array( 'status' => 400 ) );
	}

	public static function forward_account() {
		$key = self::api_key_or_error();
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		$response = wp_remote_get( TOKENIZE_CONTENT_BACKEND_URL . '/v1/portal/me', array(
			'headers' => array( 'x-api-key' => $key ),
			'timeout' => 15,
		) );
		return self::relay( $response );
	}

	public static function save_category_map( \WP_REST_Request $request ) {
		$map = $request->get_param( 'map' );
		Settings::save_category_map( is_array( $map ) ? $map : array() );
		return rest_ensure_response( array( 'map' => Settings::get_category_map() ) );
	}

	public static function forward_list_collections( \WP_REST_Request $request ) {
		$key = self::api_key_or_error();
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		Settings::forget_collections();
		$owner = (string) $request->get_param( 'owner' );
		$response = wp_remote_get( Settings::collections_url( $owner ), array(
			'headers' => array( 'x-api-key' => $key ),
			'timeout' => 15,
		) );
		return self::relay( $response );
	}

	private static function post_id( \WP_REST_Request $request ): int {
		$params = $request->get_url_params();
		return isset( $params['id'] ) ? (int) $params['id'] : 0;
	}

	public static function check_admin_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	public static function check_tokenize_permission( \WP_REST_Request $request ) {
		if ( ! current_user_can( Settings::CAP_TOKENIZE ) ) {
			return false;
		}
		$post_id = self::post_id( $request );
		if ( $post_id > 0 && ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'tokenize_content_forbidden', __( "You can't tokenize a post you don't have edit access to.", 'tokenize-content' ), array( 'status' => 403 ) );
		}
		return true;
	}

	private static function api_key_or_error() {
		$key = Settings::get_api_key();
		if ( ! $key ) {
			return new \WP_Error( 'tokenize_content_no_api_key', __( 'No Medialane API key configured.', 'tokenize-content' ), array( 'status' => 400 ) );
		}
		return $key;
	}

	private static function session_header( \WP_REST_Request $request ): string {
		$token = (string) $request->get_header( 'X-Tokenize-Session' );
		return preg_match( '/^[A-Za-z0-9._~+\/=-]{1,4096}$/', $token ) ? $token : '';
	}

	public static function forward_json( \WP_REST_Request $request, array $forward ) {
		$key = self::api_key_or_error();
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		$params = array_merge( $request->get_params(), (array) $request->get_json_params() );
		$body   = array_intersect_key( $params, array_flip( $forward['fields'] ) );
		if ( ! empty( $forward['fixed'] ) ) {
			$body = array_merge( $body, $forward['fixed'] );
		}

		$plan = array();
		if ( ! empty( $forward['record'] ) ) {
			$plan = self::broadcast_plan( $params, isset( $body['intentIds'] ) ? $body['intentIds'] : array() );
			if ( is_wp_error( $plan ) ) {
				return $plan;
			}
		}

		$headers = array(
			'Content-Type' => 'application/json',
			'x-api-key'    => $key,
		);
		$session = ! empty( $forward['session'] ) ? self::session_header( $request ) : '';
		if ( '' !== $session ) {
			$headers['Authorization'] = 'Bearer ' . $session;
		}

		$response = wp_remote_post( TOKENIZE_CONTENT_BACKEND_URL . $forward['path'], array(
			'headers' => $headers,
			'body'    => wp_json_encode( $body ),
			'timeout' => 30,
		) );
		$result = self::relay( $response );
		if ( $plan && ! is_wp_error( $result ) ) {
			self::record_broadcast( $plan, $result->get_data() );
		}
		return $result;
	}

	private static function broadcast_plan( array $params, $intent_ids ) {
		if ( ! isset( $params['posts'] ) ) {
			return array();
		}
		$posts = is_array( $params['posts'] ) ? array_values( $params['posts'] ) : array();
		if ( ! is_array( $intent_ids ) || count( $posts ) !== count( $intent_ids ) ) {
			return new \WP_Error( 'tokenize_content_invalid_reference', __( 'The transaction reference is malformed.', 'tokenize-content' ), array( 'status' => 400 ) );
		}
		$plan = array();
		foreach ( $posts as $index => $entry ) {
			$id       = is_array( $entry ) && isset( $entry['id'] ) ? (int) $entry['id'] : 0;
			$contract = is_array( $entry ) && isset( $entry['contract'] ) ? (string) $entry['contract'] : '';
			$license  = is_array( $entry ) && isset( $entry['license'] ) ? (string) $entry['license'] : '';
			if ( $id < 1 || ! preg_match( '/^0x[0-9a-fA-F]+$/', $contract ) ) {
				return new \WP_Error( 'tokenize_content_invalid_reference', __( 'The transaction reference is malformed.', 'tokenize-content' ), array( 'status' => 400 ) );
			}
			if ( ! current_user_can( 'edit_post', $id ) ) {
				return new \WP_Error( 'tokenize_content_forbidden', __( "You can't tokenize a post you don't have edit access to.", 'tokenize-content' ), array( 'status' => 403 ) );
			}
			if ( PostMeta::STATUS_MINTING !== PostMeta::get_status( $id ) ) {
				return new \WP_Error( 'tokenize_content_not_minting', __( 'This post is not waiting for a token.', 'tokenize-content' ), array( 'status' => 409 ) );
			}
			$plan[] = array( 'id' => $id, 'contract' => $contract, 'license' => $license, 'index' => $index );
		}
		return $plan;
	}

	private static function record_broadcast( array $plan, $data ) {
		$tx_hash = is_array( $data ) && isset( $data['transactionHash'] ) ? (string) $data['transactionHash'] : '';
		if ( ! preg_match( '/^0x[0-9a-fA-F]+$/', $tx_hash ) ) {
			return;
		}
		foreach ( $plan as $entry ) {
			PostMeta::set_broadcast( $entry['id'], array(
				'tx_hash'     => $tx_hash,
				'contract'    => $entry['contract'],
				'license'     => $entry['license'],
				'batch_index' => (string) $entry['index'],
			) );
		}
	}

	private static function rpc_call_allowed( $call ): bool {
		return is_array( $call ) && isset( $call['method'] ) && is_string( $call['method'] ) && in_array( $call['method'], self::RPC_METHODS, true );
	}

	public static function forward_rpc( \WP_REST_Request $request ) {
		$key = self::api_key_or_error();
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		$body    = json_decode( $request->get_body(), true );
		$is_list = is_array( $body ) && array_keys( $body ) === range( 0, count( $body ) - 1 ) && count( $body ) > 0;
		$allowed = $is_list
			? count( $body ) <= self::RPC_MAX_BATCH && count( array_filter( $body, array( __CLASS__, 'rpc_call_allowed' ) ) ) === count( $body )
			: self::rpc_call_allowed( $body );
		if ( ! $allowed ) {
			return new \WP_Error( 'tokenize_content_rpc_refused', __( 'That request is not allowed.', 'tokenize-content' ), array( 'status' => 400 ) );
		}
		$response = wp_remote_post( TOKENIZE_CONTENT_BACKEND_URL . '/v1/rpc', array(
			'headers' => array(
				'Content-Type' => 'application/json',
				'x-api-key'    => $key,
			),
			'body'    => wp_json_encode( $body ),
			'timeout' => 30,
		) );
		return self::relay( $response );
	}

	private static function post_file( string $filename, string $content_type, string $contents ) {
		$key = self::api_key_or_error();
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		$filename     = sanitize_file_name( $filename );
		$content_type = preg_match( '~^[a-zA-Z0-9!#$&^_.+-]+/[a-zA-Z0-9!#$&^_.+-]+$~', $content_type ) ? $content_type : 'application/octet-stream';
		$boundary     = wp_generate_password( 24, false );
		$body         = "--{$boundary}\r\n"
			. "Content-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\n"
			. "Content-Type: {$content_type}\r\n\r\n"
			. $contents . "\r\n"
			. "--{$boundary}--\r\n";
		$response = wp_remote_post( TOKENIZE_CONTENT_BACKEND_URL . '/v1/metadata/upload-file', array(
			'headers' => array(
				'Content-Type' => "multipart/form-data; boundary={$boundary}",
				'x-api-key'    => $key,
			),
			'body'    => $body,
			'timeout' => 60,
		) );
		return self::relay( $response );
	}

	public static function upload_featured_image( \WP_REST_Request $request ) {
		$post = get_post( self::post_id( $request ) );
		if ( ! $post || ! Eligibility::is_tokenizable( $post ) ) {
			return new \WP_Error( 'tokenize_content_not_eligible', Eligibility::message(), array( 'status' => 422 ) );
		}
		$thumbnail = (int) get_post_thumbnail_id( $post->ID );
		if ( ! $thumbnail ) {
			return rest_ensure_response( array( 'data' => array( 'url' => null ) ) );
		}
		$path = get_attached_file( $thumbnail );
		if ( ! $path || ! is_readable( $path ) ) {
			return new \WP_Error( 'tokenize_content_image_unreadable', __( 'The featured image file could not be read.', 'tokenize-content' ), array( 'status' => 404 ) );
		}
		return self::post_file( basename( $path ), (string) get_post_mime_type( $thumbnail ), (string) file_get_contents( $path ) );
	}

	private static function relay( $response ) {
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'tokenize_content_upstream_error', $response->get_error_message(), array( 'status' => 502 ) );
		}
		$status = wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 ) {
			$message = is_array( $body ) && ! empty( $body['error'] ) ? $body['error'] : __( 'Medialane backend request failed.', 'tokenize-content' );
			$code    = is_array( $body ) && ! empty( $body['code'] ) ? (string) $body['code'] : 'tokenize_content_backend_error';
			return new \WP_Error( $code, $message, array( 'status' => $status ) );
		}
		return rest_ensure_response( $body );
	}
}
