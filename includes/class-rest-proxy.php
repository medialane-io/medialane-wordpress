<?php

namespace TokenizeContent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RestProxy {

	const NAMESPACE = 'tokenize-content/v1';

	public static function register_routes() {
		register_rest_route( self::NAMESPACE, '/metadata/upload', array(
			'methods'             => 'POST',
			'callback'            => function ( \WP_REST_Request $request ) {
				return self::forward_json( $request, '/v1/metadata/upload' );
			},
			'permission_callback' => array( __CLASS__, 'check_tokenize_permission' ),
		) );
		register_rest_route( self::NAMESPACE, '/metadata/upload-file', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'forward_file' ),
			'permission_callback' => array( __CLASS__, 'check_tokenize_permission' ),
		) );
		register_rest_route( self::NAMESPACE, '/intents/create-collection', array(
			'methods'             => 'POST',
			'callback'            => function ( \WP_REST_Request $request ) {
				return self::forward_json( $request, '/v1/intents/create-collection' );
			},
			'permission_callback' => array( __CLASS__, 'check_tokenize_permission' ),
		) );
		register_rest_route( self::NAMESPACE, '/intents/mint', array(
			'methods'             => 'POST',
			'callback'            => function ( \WP_REST_Request $request ) {
				return self::forward_json( $request, '/v1/intents/mint' );
			},
			'permission_callback' => array( __CLASS__, 'check_tokenize_permission' ),
		) );
		register_rest_route( self::NAMESPACE, '/collections/sync-tx', array(
			'methods'             => 'POST',
			'callback'            => function ( \WP_REST_Request $request ) {
				return self::forward_json( $request, '/v1/tx/sync' );
			},
			'permission_callback' => array( __CLASS__, 'check_tokenize_permission' ),
		) );
		register_rest_route( self::NAMESPACE, '/paymaster/invoke/build', array(
			'methods'             => 'POST',
			'callback'            => function ( \WP_REST_Request $request ) {
				return self::forward_json( $request, '/v1/paymaster/invoke/build' );
			},
			'permission_callback' => array( __CLASS__, 'check_tokenize_permission' ),
		) );
		register_rest_route( self::NAMESPACE, '/paymaster/invoke/execute', array(
			'methods'             => 'POST',
			'callback'            => function ( \WP_REST_Request $request ) {
				return self::forward_json( $request, '/v1/paymaster/invoke/execute' );
			},
			'permission_callback' => array( __CLASS__, 'check_tokenize_permission' ),
		) );
		register_rest_route( self::NAMESPACE, '/paymaster/deploy/build', array(
			'methods'             => 'POST',
			'callback'            => function ( \WP_REST_Request $request ) {
				return self::forward_json( $request, '/v1/paymaster/deploy/build' );
			},
			'permission_callback' => array( __CLASS__, 'check_tokenize_permission' ),
		) );
		register_rest_route( self::NAMESPACE, '/business/provisioning', array(
			'methods'             => 'POST',
			'callback'            => function ( \WP_REST_Request $request ) {
				return self::forward_json( $request, '/v1/business/provisioning' );
			},
			'permission_callback' => array( __CLASS__, 'check_tokenize_permission' ),
		) );
		register_rest_route( self::NAMESPACE, '/tokens/(?P<contract>[a-zA-Z0-9x]+)/(?P<tokenId>[a-zA-Z0-9]+)', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'forward_get_token' ),
			'permission_callback' => array( __CLASS__, 'check_tokenize_permission' ),
		) );
		register_rest_route( self::NAMESPACE, '/tokenize-data', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'tokenize_data' ),
			'permission_callback' => array( __CLASS__, 'check_tokenize_permission' ),
		) );
		register_rest_route( self::NAMESPACE, '/settings/collection', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'save_collection' ),
			'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
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
		register_rest_route( self::NAMESPACE, '/settings/collections', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'save_collections' ),
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
		register_rest_route( self::NAMESPACE, '/posts/(?P<id>\d+)/broadcast', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'save_broadcast' ),
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

		$live  = Settings::fetch_live_collections();
		$posts = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post || 'post' !== $post->post_type ) {
				continue;
			}
			$posts[ $id ] = array(
				'title'              => get_the_title( $post ),
				'excerpt'            => get_the_excerpt( $post ),
				'content'            => $post->post_content,
				'image'              => has_post_thumbnail( $post ) ? get_the_post_thumbnail_url( $post, 'large' ) : '',
				'authorEmail'        => get_the_author_meta( 'user_email', $post->post_author ),
				'collectionContract' => Settings::resolve_collection_for_post( $id, $live ),
			);
		}
		return rest_ensure_response( array( 'posts' => $posts ) );
	}

	public static function save_minting( \WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'id' );
		PostMeta::set_minting( $post_id );
		return rest_ensure_response( array( 'status' => PostMeta::get_status( $post_id ) ) );
	}

	private static function malformed_reference( \WP_REST_Request $request, array $fields ) {
		$patterns = array(
			'txHash'   => '/^0x[0-9a-fA-F]+$/',
			'contract' => '/^0x[0-9a-fA-F]+$/',
			'tokenId'  => '/^\d*$/',
		);
		foreach ( $fields as $field ) {
			if ( ! preg_match( $patterns[ $field ], (string) $request->get_param( $field ) ) ) {
				return new \WP_Error( 'tokenize_content_invalid_reference', __( 'The transaction reference is malformed.', 'tokenize-content' ), array( 'status' => 400 ) );
			}
		}
		return null;
	}

	public static function save_broadcast( \WP_REST_Request $request ) {
		$invalid = self::malformed_reference( $request, array( 'txHash', 'contract' ) );
		if ( $invalid ) {
			return $invalid;
		}
		$post_id = (int) $request->get_param( 'id' );
		PostMeta::set_broadcast( $post_id, array(
			'tx_hash'     => (string) $request->get_param( 'txHash' ),
			'contract'    => (string) $request->get_param( 'contract' ),
			'license'     => (string) $request->get_param( 'license' ),
			'batch_index' => (string) (int) $request->get_param( 'index' ),
		) );
		return rest_ensure_response( array( 'status' => PostMeta::get_status( $post_id ) ) );
	}

	public static function save_minted( \WP_REST_Request $request ) {
		$invalid = self::malformed_reference( $request, array( 'txHash', 'contract', 'tokenId' ) );
		if ( $invalid ) {
			return $invalid;
		}
		$post_id = (int) $request->get_param( 'id' );
		PostMeta::save_minted( $post_id, array(
			'token_id' => (string) $request->get_param( 'tokenId' ),
			'tx_hash'  => (string) $request->get_param( 'txHash' ),
			'contract' => (string) $request->get_param( 'contract' ),
			'license'  => (string) $request->get_param( 'license' ),
		) );
		return rest_ensure_response( array( 'status' => PostMeta::get_status( $post_id ) ) );
	}

	public static function save_error( \WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'id' );
		PostMeta::save_error( $post_id, (string) $request->get_param( 'message' ) );
		return rest_ensure_response( array( 'status' => PostMeta::get_status( $post_id ) ) );
	}

	public static function save_collection( \WP_REST_Request $request ) {
		$address = (string) $request->get_param( 'contract' );
		Settings::save_collection_contract( $address );
		return rest_ensure_response( array( 'contract' => Settings::get_collection_contract() ) );
	}

	public static function save_wallet( \WP_REST_Request $request ) {
		$address = (string) $request->get_param( 'address' );
		Settings::save_wallet_address( $address );
		return rest_ensure_response( array( 'address' => Settings::get_wallet_address() ) );
	}

	public static function save_collections( \WP_REST_Request $request ) {
		$contract = (string) $request->get_param( 'contract' );
		$label    = (string) $request->get_param( 'label' );
		Settings::save_collection_label( $contract, $label );
		return rest_ensure_response( array( 'labels' => Settings::get_collection_labels() ) );
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
		$owner = (string) $request->get_param( 'owner' );
		$response = wp_remote_get( TOKENIZE_CONTENT_BACKEND_URL . '/v1/collections?owner=' . rawurlencode( $owner ), array(
			'headers' => array( 'x-api-key' => $key ),
			'timeout' => 15,
		) );
		return self::relay( $response );
	}

	public static function check_admin_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	public static function check_tokenize_permission( \WP_REST_Request $request ) {
		if ( ! current_user_can( Settings::CAP_TOKENIZE ) ) {
			return false;
		}
		$post_id = (int) $request->get_param( 'id' );
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

	public static function forward_json( \WP_REST_Request $request, string $backend_path ) {
		$key = self::api_key_or_error();
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		$response = wp_remote_post( TOKENIZE_CONTENT_BACKEND_URL . $backend_path, array(
			'headers' => array(
				'Content-Type' => 'application/json',
				'x-api-key'    => $key,
			),
			'body'    => $request->get_body(),
			'timeout' => 30,
		) );
		return self::relay( $response );
	}

	public static function forward_file( \WP_REST_Request $request ) {
		$key = self::api_key_or_error();
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		$files = $request->get_file_params();
		if ( empty( $files['file'] ) ) {
			return new \WP_Error( 'tokenize_content_no_file', __( 'No file provided.', 'tokenize-content' ), array( 'status' => 400 ) );
		}
		$file = $files['file'];
		// Sanitize the filename before it goes into a hand-built multipart header —
		// it originates from the browser's File.name and could otherwise carry
		// quotes/CRLF into the request we send upstream.
		$filename = sanitize_file_name( $file['name'] );
		$content_type = preg_match( '#^[a-zA-Z0-9!#$&^_.+-]+/[a-zA-Z0-9!#$&^_.+-]+$#', $file['type'] ) ? $file['type'] : 'application/octet-stream';
		$boundary = wp_generate_password( 24, false );
		$body = "--{$boundary}\r\n"
			. "Content-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\n"
			. "Content-Type: {$content_type}\r\n\r\n"
			. file_get_contents( $file['tmp_name'] ) . "\r\n"
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

	public static function forward_get_token( \WP_REST_Request $request ) {
		$key = self::api_key_or_error();
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		$contract = $request->get_param( 'contract' );
		$token_id = $request->get_param( 'tokenId' );
		$response = wp_remote_get( TOKENIZE_CONTENT_BACKEND_URL . "/v1/tokens/{$contract}/{$token_id}", array(
			'headers' => array( 'x-api-key' => $key ),
			'timeout' => 15,
		) );
		return self::relay( $response );
	}

	private static function relay( $response ) {
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'tokenize_content_upstream_error', $response->get_error_message(), array( 'status' => 502 ) );
		}
		$status = wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 ) {
			$message = is_array( $body ) && ! empty( $body['error'] ) ? $body['error'] : __( 'Medialane backend request failed.', 'tokenize-content' );
			return new \WP_Error( 'tokenize_content_backend_error', $message, array( 'status' => $status ) );
		}
		return rest_ensure_response( $body );
	}
}
