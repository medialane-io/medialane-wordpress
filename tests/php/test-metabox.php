<?php

use TokenizeContent\Metabox;
use TokenizeContent\PostMeta;
use TokenizeContent\Settings;

class Test_Metabox extends Tokenize_Test_Case {

	public function tear_down() {
		wp_dequeue_script( 'tokenize-content-metabox' );
		wp_deregister_script( 'tokenize-content-metabox' );
		parent::tear_down();
	}

	public function test_enqueue_localizes_no_post_content_or_author_email() {
		$author  = $this->factory->user->create( array( 'role' => 'author', 'user_email' => 'writer@example.org' ) );
		$post_id = $this->factory->post->create( array( 'post_author' => $author, 'post_content' => 'SECRET BODY TEXT' ) );
		$GLOBALS['post'] = get_post( $post_id );

		Metabox::enqueue( 'post.php' );

		$data = (string) wp_scripts()->get_data( 'tokenize-content-metabox', 'data' );
		$this->assertStringNotContainsString( 'writer@example.org', $data );
		$this->assertStringNotContainsString( 'SECRET BODY TEXT', $data );
		unset( $GLOBALS['post'] );
	}

	private function render_for( int $post_id ): string {
		ob_start();
		Metabox::render( get_post( $post_id ) );
		return ob_get_clean();
	}

	public function test_a_minting_post_with_a_recorded_broadcast_offers_a_status_check_instead_of_tokenizing() {
		$post_id = $this->factory->post->create();
		PostMeta::set_minting( $post_id );
		PostMeta::set_broadcast( $post_id, array( 'tx_hash' => '0xabc', 'contract' => '0xdef', 'license' => 'CC BY', 'batch_index' => '2' ) );

		$html = $this->render_for( $post_id );

		$this->assertStringNotContainsString( 'tokenize-content-tokenize-btn', $html );
		$this->assertStringContainsString( 'tokenize-content-check-btn', $html );
		$this->assertStringContainsString( 'data-tx-hash="0xabc"', $html );
		$this->assertStringContainsString( 'data-contract="0xdef"', $html );
		$this->assertStringContainsString( 'data-license="CC BY"', $html );
		$this->assertStringContainsString( 'data-index="2"', $html );
	}

	public function test_a_minting_post_with_no_recorded_broadcast_still_offers_tokenizing() {
		$post_id = $this->factory->post->create();
		PostMeta::set_minting( $post_id );

		$html = $this->render_for( $post_id );

		$this->assertStringContainsString( 'tokenize-content-tokenize-btn', $html );
		$this->assertStringNotContainsString( 'tokenize-content-check-btn', $html );
	}

	public function test_the_editor_says_collections_could_not_be_loaded_when_the_lookup_fails() {
		\TokenizeContent\Settings::save_wallet_address( '0xbeef01' );
		update_option( \TokenizeContent\Settings::OPTION_API_KEY, 'test-key' );
		add_filter( 'pre_http_request', function () {
			return new WP_Error( 'http_request_failed', 'Could not resolve host' );
		} );

		$html = $this->render_for( $this->factory->post->create() );

		$this->assertStringContainsString( 'Collections could not be loaded right now', $html );
		remove_all_filters( 'pre_http_request' );
	}

	public function test_a_draft_shows_a_note_instead_of_the_tokenize_controls() {
		$draft = $this->factory->post->create( array( 'post_status' => 'draft' ) );

		$html = $this->render_for( $draft );

		$this->assertStringNotContainsString( 'tokenize-content-tokenize-btn', $html );
		$this->assertStringContainsString( 'Only published posts without a password can be tokenized.', $html );
	}

	public function test_enqueue_hands_the_client_the_license_terms() {
		$GLOBALS['post'] = get_post( $this->factory->post->create() );

		Metabox::enqueue( 'post.php' );

		$data = (string) wp_scripts()->get_data( 'tokenize-content-metabox', 'data' );
		$this->assertStringContainsString( '"licenses"', $data );
		$this->assertStringContainsString( 'Share-Alike', $data );
		unset( $GLOBALS['post'] );
	}

	public function test_enqueue_hands_the_client_the_default_license() {
		$GLOBALS['post'] = get_post( $this->factory->post->create() );
		update_option( Settings::OPTION_LICENSE_DEFAULT, 'CC BY' );

		Metabox::enqueue( 'post.php' );

		$data = (string) wp_scripts()->get_data( 'tokenize-content-metabox', 'data' );
		$this->assertStringContainsString( '"licenseDefault":"CC BY"', $data );
		unset( $GLOBALS['post'] );
	}
}
