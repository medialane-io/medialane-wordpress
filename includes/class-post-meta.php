<?php

namespace TokenizeContent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PostMeta {
	const STATUS_NONE    = 'none';
	const STATUS_MINTING = 'minting';
	const STATUS_MINTED  = 'minted';
	const STATUS_ERROR   = 'error';

	const KEY_STATUS   = '_tokenize_content_status';
	const KEY_TOKEN_ID = '_tokenize_content_token_id';
	const KEY_TX_HASH  = '_tokenize_content_tx_hash';
	const KEY_CONTRACT = '_tokenize_content_contract';
	const KEY_LICENSE  = '_tokenize_content_license';
	const KEY_ERROR    = '_tokenize_content_error';
	const KEY_BATCH_INDEX = '_tokenize_content_batch_index';
	const KEY_AI_POLICY   = '_tokenize_content_ai_policy';
	const KEY_TOKENIZED_AT = '_tokenize_content_tokenized_at';

	public static function register() {
		$string_field = array(
			'show_in_rest' => false,
			'single'       => true,
			'type'         => 'string',
		);
		register_post_meta( '', self::KEY_STATUS, $string_field );
		register_post_meta( '', self::KEY_TOKEN_ID, $string_field );
		register_post_meta( '', self::KEY_TX_HASH, $string_field );
		register_post_meta( '', self::KEY_CONTRACT, $string_field );
		register_post_meta( '', self::KEY_LICENSE, $string_field );
		register_post_meta( '', self::KEY_ERROR, $string_field );
		register_post_meta( '', self::KEY_BATCH_INDEX, $string_field );
		register_post_meta( '', self::KEY_AI_POLICY, $string_field );
		register_post_meta( '', self::KEY_TOKENIZED_AT, $string_field );
	}

	public static function get_status( int $post_id ): string {
		$status = get_post_meta( $post_id, self::KEY_STATUS, true );
		return $status ? $status : self::STATUS_NONE;
	}

	public static function set_minting( int $post_id ) {
		update_post_meta( $post_id, self::KEY_STATUS, self::STATUS_MINTING );
		update_post_meta( $post_id, self::KEY_TOKENIZED_AT, (string) get_post_field( 'post_modified_gmt', $post_id ) );
		delete_post_meta( $post_id, self::KEY_ERROR );
		delete_post_meta( $post_id, self::KEY_TX_HASH );
		delete_post_meta( $post_id, self::KEY_BATCH_INDEX );
	}

	public static function set_broadcast( int $post_id, array $data ) {
		update_post_meta( $post_id, self::KEY_TX_HASH, sanitize_text_field( $data['tx_hash'] ) );
		update_post_meta( $post_id, self::KEY_CONTRACT, sanitize_text_field( $data['contract'] ) );
		update_post_meta( $post_id, self::KEY_LICENSE, sanitize_text_field( $data['license'] ) );
		update_post_meta( $post_id, self::KEY_BATCH_INDEX, sanitize_text_field( $data['batch_index'] ) );
		update_post_meta( $post_id, self::KEY_AI_POLICY, isset( $data['ai_policy'] ) ? sanitize_text_field( $data['ai_policy'] ) : '' );
	}

	public static function save_minted( int $post_id, array $data ) {
		update_post_meta( $post_id, self::KEY_STATUS, self::STATUS_MINTED );
		update_post_meta( $post_id, self::KEY_TOKEN_ID, sanitize_text_field( $data['token_id'] ) );
		update_post_meta( $post_id, self::KEY_TX_HASH, sanitize_text_field( $data['tx_hash'] ) );
		update_post_meta( $post_id, self::KEY_CONTRACT, sanitize_text_field( $data['contract'] ) );
		update_post_meta( $post_id, self::KEY_LICENSE, sanitize_text_field( $data['license'] ) );
		delete_post_meta( $post_id, self::KEY_ERROR );
	}

	public static function save_error( int $post_id, string $message ) {
		update_post_meta( $post_id, self::KEY_STATUS, self::STATUS_ERROR );
		update_post_meta( $post_id, self::KEY_ERROR, sanitize_text_field( $message ) );
	}

	public static function get_error( int $post_id ): string {
		return (string) get_post_meta( $post_id, self::KEY_ERROR, true );
	}

	public static function get_tx_hash( int $post_id ): string {
		return (string) get_post_meta( $post_id, self::KEY_TX_HASH, true );
	}

	public static function get_batch_index( int $post_id ): string {
		return (string) get_post_meta( $post_id, self::KEY_BATCH_INDEX, true );
	}

	public static function get_token_id( int $post_id ): string {
		return (string) get_post_meta( $post_id, self::KEY_TOKEN_ID, true );
	}

	public static function get_contract( int $post_id ): string {
		return (string) get_post_meta( $post_id, self::KEY_CONTRACT, true );
	}

	public static function get_license( int $post_id ): string {
		return (string) get_post_meta( $post_id, self::KEY_LICENSE, true );
	}

	public static function get_ai_policy( int $post_id ): string {
		return (string) get_post_meta( $post_id, self::KEY_AI_POLICY, true );
	}

	public static function is_edited_since_tokenized( int $post_id ): bool {
		if ( self::STATUS_MINTED !== self::get_status( $post_id ) ) {
			return false;
		}
		$tokenized_at = (string) get_post_meta( $post_id, self::KEY_TOKENIZED_AT, true );
		return '' !== $tokenized_at && (string) get_post_field( 'post_modified_gmt', $post_id ) > $tokenized_at;
	}
}
