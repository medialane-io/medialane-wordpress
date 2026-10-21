<?php

namespace TokenizeContent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Settings {
	const OPTION_API_KEY    = 'tokenize_content_api_key';
	const OPTION_WALLET     = 'tokenize_content_wallet_address';
	const OPTION_COLLECTION = 'tokenize_content_collection_contract';
	const OPTION_COLLECTION_LABELS = 'tokenize_content_collection_labels';
	const OPTION_CATEGORY_MAP = 'tokenize_content_category_collections';
	const OPTION_LICENSE_DEFAULT = 'tokenize_content_license_default';
	const OPTION_AI_POLICY_DEFAULT = 'tokenize_content_ai_policy_default';
	const OPTION_CONTENT_SCOPE   = 'tokenize_content_content_scope';
	const CAP_TOKENIZE = 'tokenize_content_tokenize_posts';
	const COLLECTION_SERVICE = 'mip-erc721';
	const GROUP_KEY      = 'tokenize-content-key';
	const GROUP_DEFAULTS = 'tokenize-content-defaults';

	const LICENSES = array(
		'CC BY-SA'            => array( 'commercialUse' => 'Yes', 'derivatives' => 'Share-Alike', 'attribution' => 'Required' ),
		'CC BY'               => array( 'commercialUse' => 'Yes', 'derivatives' => 'Allowed', 'attribution' => 'Required' ),
		'CC BY-NC'            => array( 'commercialUse' => 'No', 'derivatives' => 'Allowed', 'attribution' => 'Required' ),
		'CC BY-ND'            => array( 'commercialUse' => 'Yes', 'derivatives' => 'Not Allowed', 'attribution' => 'Required' ),
		'CC BY-NC-SA'         => array( 'commercialUse' => 'No', 'derivatives' => 'Share-Alike', 'attribution' => 'Required' ),
		'CC BY-NC-ND'         => array( 'commercialUse' => 'No', 'derivatives' => 'Not Allowed', 'attribution' => 'Required' ),
		'CC0'                 => array( 'commercialUse' => 'Yes', 'derivatives' => 'Allowed', 'attribution' => 'Not Required' ),
		'MIT'                 => array( 'commercialUse' => 'Yes', 'derivatives' => 'Allowed', 'attribution' => 'Required' ),
		'Apache 2.0'          => array( 'commercialUse' => 'Yes', 'derivatives' => 'Allowed', 'attribution' => 'Required' ),
		'All Rights Reserved' => array( 'commercialUse' => 'No', 'derivatives' => 'Not Allowed', 'attribution' => 'Required' ),
		'Custom'              => null,
	);
	const AI_POLICIES = array( 'Allowed', 'Training Only', 'Not Allowed' );

	public static function license_presets(): array {
		return array_keys( self::LICENSES );
	}

	public static function license_terms(): array {
		return array_filter( self::LICENSES );
	}

	public static function uninstall() {
		global $wpdb;
		foreach ( ( new \ReflectionClass( __CLASS__ ) )->getConstants() as $name => $option ) {
			if ( 0 === strpos( $name, 'OPTION_' ) ) {
				delete_option( $option );
			}
		}
		foreach ( wp_roles()->role_objects as $role ) {
			$role->remove_cap( self::CAP_TOKENIZE );
		}
		$cached = $wpdb->get_col( $wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( '_transient_tokenize_content_collections_' ) . '%'
		) );
		foreach ( $cached as $name ) {
			delete_transient( substr( $name, strlen( '_transient_' ) ) );
		}
	}

	public static function register() {
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	public static function register_settings() {
		register_setting( self::GROUP_KEY, self::OPTION_API_KEY, array( 'sanitize_callback' => array( __CLASS__, 'sanitize_api_key' ) ) );
		register_setting( self::GROUP_DEFAULTS, self::OPTION_LICENSE_DEFAULT, array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( self::GROUP_DEFAULTS, self::OPTION_AI_POLICY_DEFAULT, array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( self::GROUP_DEFAULTS, self::OPTION_CONTENT_SCOPE, array( 'sanitize_callback' => 'sanitize_text_field' ) );
	}

	public static function grant_default_capability() {
		$role = get_role( 'administrator' );
		if ( $role && ! $role->has_cap( self::CAP_TOKENIZE ) ) {
			$role->add_cap( self::CAP_TOKENIZE );
		}
	}

	public static function sanitize_api_key( $value ): string {
		$value = trim( sanitize_text_field( (string) $value ) );
		return '' === $value ? (string) get_option( self::OPTION_API_KEY, '' ) : $value;
	}

	public static function canonical_address( string $address ): string {
		$address = strtolower( trim( $address ) );
		if ( 0 !== strpos( $address, '0x' ) ) {
			return $address;
		}
		$digits = ltrim( substr( $address, 2 ), '0' );
		return '0x' . ( '' === $digits ? '0' : $digits );
	}

	public static function same_address( string $a, string $b ): bool {
		return '' !== $a && self::canonical_address( $a ) === self::canonical_address( $b );
	}

	public static function sanitize_address( $value ): string {
		$value = strtolower( trim( (string) $value ) );
		return preg_match( '/^0x[0-9a-f]+$/', $value ) ? $value : '';
	}

	public static function get_api_key(): string {
		if ( defined( 'TOKENIZE_CONTENT_API_KEY' ) && TOKENIZE_CONTENT_API_KEY ) {
			return (string) TOKENIZE_CONTENT_API_KEY;
		}
		return (string) get_option( self::OPTION_API_KEY, '' );
	}

	public static function get_wallet_address(): string {
		return (string) get_option( self::OPTION_WALLET, '' );
	}

	public static function save_wallet_address( string $address ) {
		self::forget_collections();
		update_option( self::OPTION_WALLET, self::sanitize_address( $address ) );
		self::forget_collections();
	}

	public static function get_default_collection(): string {
		return (string) get_option( self::OPTION_COLLECTION, '' );
	}

	public static function set_default_collection( string $address ) {
		update_option( self::OPTION_COLLECTION, self::sanitize_address( $address ) );
	}

	public static function get_collection_labels(): array {
		$labels = get_option( self::OPTION_COLLECTION_LABELS, array() );
		return is_array( $labels ) ? $labels : array();
	}

	public static function save_collection_label( string $contract, string $label ) {
		$contract = self::sanitize_address( $contract );
		$label    = sanitize_text_field( $label );
		$labels   = self::get_collection_labels();

		$labels[ $contract ] = $label;
		update_option( self::OPTION_COLLECTION_LABELS, $labels );
		self::forget_collections();

		if ( ! self::get_default_collection() ) {
			self::set_default_collection( $contract );
		}
	}

	public static function collections_url( string $owner ): string {
		return TOKENIZE_CONTENT_BACKEND_URL . '/v1/collections?owner=' . rawurlencode( $owner ) . '&service=' . self::COLLECTION_SERVICE . '&limit=100';
	}

	private static function collections_cache_key(): string {
		return 'tokenize_content_collections_' . md5( self::canonical_address( self::get_wallet_address() ) . '|' . self::get_api_key() );
	}

	public static function forget_collections() {
		delete_transient( self::collections_cache_key() );
	}

	public static function fetch_live_collections() {
		$key   = self::get_api_key();
		$owner = self::get_wallet_address();
		if ( ! $key || ! $owner ) {
			return array();
		}

		$cached = get_transient( self::collections_cache_key() );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_remote_get( self::collections_url( $owner ), array(
			'headers' => array( 'x-api-key' => $key ),
			'timeout' => 15,
		) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$status = wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			return new \WP_Error( 'tokenize_content_collections_unavailable', 'Collections could not be loaded.', array( 'status' => $status ) );
		}
		$body  = json_decode( wp_remote_retrieve_body( $response ), true );
		$items = ( is_array( $body ) && isset( $body['data'] ) && is_array( $body['data'] ) ) ? $body['data'] : array();

		$labels = array();
		foreach ( self::get_collection_labels() as $stored => $label ) {
			$labels[ self::canonical_address( (string) $stored ) ] = $label;
		}
		$collections = array();
		foreach ( $items as $item ) {
			if ( empty( $item['contractAddress'] ) || ! isset( $item['collectionId'] ) || '' === (string) $item['collectionId'] ) {
				continue;
			}
			if ( self::COLLECTION_SERVICE !== ( isset( $item['service'] ) ? $item['service'] : '' ) ) {
				continue;
			}
			$contract      = (string) $item['contractAddress'];
			$collections[] = array(
				'contract'     => $contract,
				'collectionId' => (string) $item['collectionId'],
				'label'        => isset( $labels[ self::canonical_address( $contract ) ] ) ? $labels[ self::canonical_address( $contract ) ] : $contract,
			);
		}
		set_transient( self::collections_cache_key(), $collections, MINUTE_IN_SECONDS );
		return $collections;
	}

	public static function get_category_map(): array {
		$map = get_option( self::OPTION_CATEGORY_MAP, array() );
		return is_array( $map ) ? $map : array();
	}

	public static function save_category_map( array $map ) {
		$sanitized = array();
		foreach ( $map as $category_id => $contract ) {
			$contract = self::sanitize_address( (string) $contract );
			if ( $contract ) {
				$sanitized[ (int) $category_id ] = $contract;
			}
		}
		update_option( self::OPTION_CATEGORY_MAP, $sanitized );
	}

	public static function resolve_collection_for_post( int $post_id, array $live_collections ): ?array {
		$find = function ( string $wanted ) use ( $live_collections ) {
			foreach ( $live_collections as $collection ) {
				if ( self::same_address( $wanted, $collection['contract'] ) ) {
					return $collection;
				}
			}
			return null;
		};

		$map = self::get_category_map();
		if ( $map ) {
			foreach ( get_the_category( $post_id ) as $category ) {
				if ( isset( $map[ $category->term_id ] ) ) {
					$mapped = $find( $map[ $category->term_id ] );
					if ( $mapped ) {
						return $mapped;
					}
				}
			}
		}

		$default = $find( self::get_default_collection() );
		if ( $default ) {
			return $default;
		}

		return $live_collections ? $live_collections[0] : null;
	}

	public static function get_content_scope(): string {
		$scope = get_option( self::OPTION_CONTENT_SCOPE, 'excerpt' );
		return in_array( $scope, array( 'excerpt', 'full' ), true ) ? $scope : 'excerpt';
	}

	public static function get_license_default(): string {
		$license = get_option( self::OPTION_LICENSE_DEFAULT, '' );
		return $license ? (string) $license : 'All Rights Reserved';
	}

	public static function get_ai_policy_default(): string {
		$policy = get_option( self::OPTION_AI_POLICY_DEFAULT, '' );
		return $policy ? (string) $policy : 'Allowed';
	}
}
