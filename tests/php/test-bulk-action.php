<?php

use TokenizeContent\BulkAction;
use TokenizeContent\PostMeta;
use TokenizeContent\Settings;

class Test_Bulk_Action extends Tokenize_Test_Case {

	public function set_up() {
		parent::set_up();
		Settings::grant_default_capability();
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'edit-post' );
	}

	public function tear_down() {
		wp_dequeue_script( 'tokenize-content-bulk-action' );
		wp_deregister_script( 'tokenize-content-bulk-action' );
		set_current_screen( 'front' );
		parent::tear_down();
	}

	public function test_no_notice_when_every_published_post_is_already_minted() {
		$post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );
		PostMeta::save_minted( $post_id, array( 'token_id' => '1', 'tx_hash' => '0xabc', 'contract' => '0xdef', 'license' => 'CC BY-SA' ) );

		$output = $this->capture_notice();

		$this->assertSame( '', $output );
	}

	public function test_notice_counts_published_posts_that_are_not_yet_minted() {
		$this->factory->post->create( array( 'post_status' => 'publish' ) );
		$this->factory->post->create( array( 'post_status' => 'publish' ) );
		$minted = $this->factory->post->create( array( 'post_status' => 'publish' ) );
		PostMeta::save_minted( $minted, array( 'token_id' => '1', 'tx_hash' => '0xabc', 'contract' => '0xdef', 'license' => 'CC BY-SA' ) );
		$this->factory->post->create( array( 'post_status' => 'draft' ) );

		$output = $this->capture_notice();

		$this->assertStringContainsString( '2', $output );
		$this->assertStringContainsString( 'Tokenize with Medialane', $output );
	}

	public function test_notice_ignores_password_protected_posts() {
		$this->factory->post->create( array( 'post_status' => 'publish', 'post_password' => 'secret' ) );

		$this->assertSame( '', $this->capture_notice() );
	}

	public function test_no_notice_outside_the_posts_list_screen() {
		$this->factory->post->create( array( 'post_status' => 'publish' ) );
		set_current_screen( 'dashboard' );

		$output = $this->capture_notice();

		$this->assertSame( '', $output );
	}

	public function test_no_notice_for_a_user_without_the_tokenize_capability() {
		$this->factory->post->create( array( 'post_status' => 'publish' ) );
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'subscriber' ) ) );

		$output = $this->capture_notice();

		$this->assertSame( '', $output );
	}

	public function test_enqueue_adds_no_script_for_a_user_without_the_tokenize_capability() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'contributor' ) ) );

		BulkAction::enqueue( 'edit.php' );

		$this->assertFalse( wp_script_is( 'tokenize-content-bulk-action', 'enqueued' ) );
	}

	public function test_enqueue_localizes_no_post_content_or_author_email() {
		$author = $this->factory->user->create( array( 'role' => 'author', 'user_email' => 'writer@example.org' ) );
		$this->factory->post->create( array( 'post_status' => 'publish', 'post_author' => $author, 'post_content' => 'SECRET BODY TEXT' ) );

		BulkAction::enqueue( 'edit.php' );

		$data = (string) wp_scripts()->get_data( 'tokenize-content-bulk-action', 'data' );
		$this->assertStringNotContainsString( 'writer@example.org', $data );
		$this->assertStringNotContainsString( 'SECRET BODY TEXT', $data );
	}

	private function capture_notice(): string {
		ob_start();
		BulkAction::render_pending_notice();
		return trim( (string) ob_get_clean() );
	}
}
