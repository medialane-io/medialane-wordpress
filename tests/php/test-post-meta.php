<?php

use TokenizeContent\PostMeta;

class Test_Post_Meta extends Tokenize_Test_Case {

	public function test_default_status_is_none() {
		$post_id = $this->factory->post->create();
		$this->assertSame( PostMeta::STATUS_NONE, PostMeta::get_status( $post_id ) );
	}

	public function test_save_minted_sets_status_and_fields() {
		$post_id = $this->factory->post->create();
		PostMeta::save_minted( $post_id, array(
			'token_id' => '42',
			'tx_hash'  => '0xabc',
			'contract' => '0xdef',
			'license'  => 'All Rights Reserved',
		) );

		$this->assertSame( PostMeta::STATUS_MINTED, PostMeta::get_status( $post_id ) );
		$this->assertSame( '42', get_post_meta( $post_id, PostMeta::KEY_TOKEN_ID, true ) );
		$this->assertSame( '0xabc', get_post_meta( $post_id, PostMeta::KEY_TX_HASH, true ) );
	}

	public function test_accessors_read_back_the_saved_minted_fields() {
		$post_id = $this->factory->post->create();
		PostMeta::save_minted( $post_id, array(
			'token_id' => '42',
			'tx_hash'  => '0xabc',
			'contract' => '0xdef',
			'license'  => 'All Rights Reserved',
		) );

		$this->assertSame( '42', PostMeta::get_token_id( $post_id ) );
		$this->assertSame( '0xdef', PostMeta::get_contract( $post_id ) );
		$this->assertSame( 'All Rights Reserved', PostMeta::get_license( $post_id ) );
	}

	public function test_accessors_return_empty_string_before_minting() {
		$post_id = $this->factory->post->create();

		$this->assertSame( '', PostMeta::get_token_id( $post_id ) );
		$this->assertSame( '', PostMeta::get_contract( $post_id ) );
		$this->assertSame( '', PostMeta::get_license( $post_id ) );
	}

	public function test_save_error_sets_status_and_message() {
		$post_id = $this->factory->post->create();
		PostMeta::save_error( $post_id, 'Transaction reverted' );

		$this->assertSame( PostMeta::STATUS_ERROR, PostMeta::get_status( $post_id ) );
		$this->assertSame( 'Transaction reverted', PostMeta::get_error( $post_id ) );
	}

	public function test_set_minting_sets_status_and_clears_prior_error() {
		$post_id = $this->factory->post->create();
		PostMeta::save_error( $post_id, 'Previous attempt failed' );

		PostMeta::set_minting( $post_id );

		$this->assertSame( PostMeta::STATUS_MINTING, PostMeta::get_status( $post_id ) );
		$this->assertSame( '', PostMeta::get_error( $post_id ) );
	}

	public function test_set_broadcast_keeps_minting_and_stores_the_reference() {
		$post_id = $this->factory->post->create();
		PostMeta::set_minting( $post_id );

		PostMeta::set_broadcast( $post_id, array( 'tx_hash' => '0xabc', 'contract' => '0xdef', 'license' => 'CC BY', 'batch_index' => '1' ) );

		$this->assertSame( PostMeta::STATUS_MINTING, PostMeta::get_status( $post_id ) );
		$this->assertSame( '0xabc', PostMeta::get_tx_hash( $post_id ) );
		$this->assertSame( '0xdef', PostMeta::get_contract( $post_id ) );
		$this->assertSame( 'CC BY', PostMeta::get_license( $post_id ) );
		$this->assertSame( '1', PostMeta::get_batch_index( $post_id ) );
	}

	public function test_set_minting_clears_a_previous_broadcast_reference() {
		$post_id = $this->factory->post->create();
		PostMeta::set_broadcast( $post_id, array( 'tx_hash' => '0xabc', 'contract' => '0xdef', 'license' => 'CC BY', 'batch_index' => '1' ) );

		PostMeta::set_minting( $post_id );

		$this->assertSame( '', PostMeta::get_tx_hash( $post_id ) );
		$this->assertSame( '', PostMeta::get_batch_index( $post_id ) );
	}

	public function test_an_author_cannot_write_the_plugin_s_meta_through_the_meta_capability() {
		$author_id = $this->factory->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author_id );
		$post_id = $this->factory->post->create( array( 'post_author' => $author_id ) );

		foreach ( array( PostMeta::KEY_STATUS, PostMeta::KEY_TOKEN_ID, PostMeta::KEY_TX_HASH, PostMeta::KEY_CONTRACT, PostMeta::KEY_LICENSE, PostMeta::KEY_ERROR, PostMeta::KEY_BATCH_INDEX ) as $key ) {
			$this->assertFalse( current_user_can( 'edit_post_meta', $post_id, $key ), $key );
		}
	}

	public function test_set_broadcast_keeps_the_ai_policy_the_post_was_tokenized_with() {
		$post_id = $this->factory->post->create();
		PostMeta::set_minting( $post_id );

		PostMeta::set_broadcast( $post_id, array( 'tx_hash' => '0xabc', 'contract' => '0xdef', 'license' => 'CC BY', 'ai_policy' => 'Not Allowed', 'batch_index' => '0' ) );

		$this->assertSame( 'Not Allowed', PostMeta::get_ai_policy( $post_id ) );
	}

	public function test_the_ai_policy_is_empty_for_a_post_tokenized_without_one() {
		$post_id = $this->factory->post->create();
		PostMeta::save_minted( $post_id, array( 'token_id' => '1', 'tx_hash' => '0xabc', 'contract' => '0xdef', 'license' => 'CC BY' ) );

		$this->assertSame( '', PostMeta::get_ai_policy( $post_id ) );
	}

	private function edit_later( int $post_id ) {
		$later = gmdate( 'Y-m-d H:i:s', time() + 3600 );
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_modified' => $later, 'post_modified_gmt' => $later ), array( 'ID' => $post_id ) );
		clean_post_cache( $post_id );
	}

	public function test_a_post_is_not_edited_since_it_was_tokenized_until_it_changes() {
		$post_id = $this->factory->post->create();
		PostMeta::set_minting( $post_id );
		PostMeta::save_minted( $post_id, array( 'token_id' => '1', 'tx_hash' => '0xabc', 'contract' => '0xdef', 'license' => 'CC BY' ) );

		$this->assertFalse( PostMeta::is_edited_since_tokenized( $post_id ) );

		$this->edit_later( $post_id );

		$this->assertTrue( PostMeta::is_edited_since_tokenized( $post_id ) );
	}

	public function test_a_post_tokenized_before_the_time_was_recorded_is_never_reported_as_edited() {
		$post_id = $this->factory->post->create();
		PostMeta::save_minted( $post_id, array( 'token_id' => '1', 'tx_hash' => '0xabc', 'contract' => '0xdef', 'license' => 'CC BY' ) );
		$this->edit_later( $post_id );

		$this->assertFalse( PostMeta::is_edited_since_tokenized( $post_id ) );
	}

	public function test_a_post_that_is_not_tokenized_is_never_reported_as_edited() {
		$post_id = $this->factory->post->create();
		PostMeta::set_minting( $post_id );
		$this->edit_later( $post_id );

		$this->assertFalse( PostMeta::is_edited_since_tokenized( $post_id ) );
	}

	public function test_a_new_attempt_takes_the_time_again() {
		$post_id = $this->factory->post->create();
		PostMeta::set_minting( $post_id );
		$this->edit_later( $post_id );
		PostMeta::save_error( $post_id, 'failed' );
		PostMeta::set_minting( $post_id );
		PostMeta::save_minted( $post_id, array( 'token_id' => '1', 'tx_hash' => '0xabc', 'contract' => '0xdef', 'license' => 'CC BY' ) );

		$this->assertFalse( PostMeta::is_edited_since_tokenized( $post_id ) );
	}
}
