<?php

use TokenizeContent\BulkAction;
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

	public function test_the_bulk_action_is_offered_on_every_tokenizable_list() {
		BulkAction::register();

		$this->assertArrayHasKey( 'tokenize_content_tokenize', apply_filters( 'bulk_actions-edit-post', array() ) );
		$this->assertArrayHasKey( 'tokenize_content_tokenize', apply_filters( 'bulk_actions-edit-page', array() ) );
	}

	public function test_enqueue_loads_the_script_on_the_pages_list() {
		set_current_screen( 'edit-page' );

		BulkAction::enqueue( 'edit.php' );

		$this->assertTrue( wp_script_is( 'tokenize-content-bulk-action', 'enqueued' ) );
	}

	public function test_enqueue_adds_no_script_on_a_list_of_a_type_that_cannot_be_tokenized() {
		register_post_type( 'gallery', array( 'public' => true, 'show_ui' => true, 'supports' => array( 'title' ) ) );
		set_current_screen( 'edit-gallery' );

		BulkAction::enqueue( 'edit.php' );

		$this->assertFalse( wp_script_is( 'tokenize-content-bulk-action', 'enqueued' ) );
		unregister_post_type( 'gallery' );
	}

	public function test_enqueue_hands_the_client_the_license_terms() {
		BulkAction::enqueue( 'edit.php' );

		$data = (string) wp_scripts()->get_data( 'tokenize-content-bulk-action', 'data' );
		$this->assertStringContainsString( '"licenses"', $data );
		$this->assertStringContainsString( 'Share-Alike', $data );
	}
}
