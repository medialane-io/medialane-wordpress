<?php

use TokenizeContent\Eligibility;

class Test_Eligibility extends Tokenize_Test_Case {

	public function test_a_published_post_without_a_password_is_tokenizable() {
		$post = get_post( $this->factory->post->create( array( 'post_status' => 'publish' ) ) );
		$this->assertTrue( Eligibility::is_tokenizable( $post ) );
	}

	public function test_a_draft_a_private_post_and_a_password_protected_post_are_not() {
		$draft    = get_post( $this->factory->post->create( array( 'post_status' => 'draft' ) ) );
		$private  = get_post( $this->factory->post->create( array( 'post_status' => 'private' ) ) );
		$password = get_post( $this->factory->post->create( array( 'post_status' => 'publish', 'post_password' => 'secret' ) ) );

		$this->assertFalse( Eligibility::is_tokenizable( $draft ) );
		$this->assertFalse( Eligibility::is_tokenizable( $private ) );
		$this->assertFalse( Eligibility::is_tokenizable( $password ) );
	}

	public function test_a_published_page_is_tokenizable() {
		$page = get_post( $this->factory->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) ) );
		$this->assertTrue( Eligibility::is_tokenizable( $page ) );
	}

	public function test_a_public_custom_type_with_the_editor_is_tokenizable() {
		register_post_type( 'story', array( 'public' => true, 'supports' => array( 'title', 'editor' ) ) );
		$story = get_post( $this->factory->post->create( array( 'post_type' => 'story', 'post_status' => 'publish' ) ) );

		$this->assertContains( 'story', Eligibility::post_types() );
		$this->assertTrue( Eligibility::is_tokenizable( $story ) );
		unregister_post_type( 'story' );
	}

	public function test_a_type_that_is_not_public_or_has_no_editor_is_not_tokenizable() {
		register_post_type( 'secret', array( 'public' => false, 'show_ui' => true, 'supports' => array( 'title', 'editor' ) ) );
		register_post_type( 'gallery', array( 'public' => true, 'supports' => array( 'title' ) ) );

		$this->assertNotContains( 'secret', Eligibility::post_types() );
		$this->assertNotContains( 'gallery', Eligibility::post_types() );
		$this->assertNotContains( 'attachment', Eligibility::post_types() );
		unregister_post_type( 'secret' );
		unregister_post_type( 'gallery' );
	}
}
