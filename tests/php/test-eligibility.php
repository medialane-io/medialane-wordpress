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

	public function test_a_page_is_not_tokenizable() {
		$page = get_post( $this->factory->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) ) );
		$this->assertFalse( Eligibility::is_tokenizable( $page ) );
	}
}
