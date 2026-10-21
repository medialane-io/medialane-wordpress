<?php

use TokenizeContent\Metabox;

class Test_Metabox extends WP_UnitTestCase {

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
}
