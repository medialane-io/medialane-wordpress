<?php

use TokenizeContent\Description;

class Test_Description extends Tokenize_Test_Case {

	private function post( array $args ): WP_Post {
		return get_post( $this->factory->post->create( array_merge( array( 'post_status' => 'publish' ), $args ) ) );
	}

	public function test_full_scope_renders_blocks_to_plain_paragraphs() {
		$post = $this->post( array(
			'post_content' => '<!-- wp:paragraph --><p>Hello <strong>world</strong></p><!-- /wp:paragraph --><!-- wp:paragraph --><p>Second &amp; last</p><!-- /wp:paragraph -->',
		) );

		$this->assertSame( "Hello world\n\nSecond & last", Description::plain_text( $post, 'full' ) );
	}

	public function test_full_scope_contains_no_markup_or_block_comments() {
		$post = $this->post( array( 'post_content' => '<!-- wp:heading --><h2>Title</h2><!-- /wp:heading --><p>Body<br>line</p>' ) );

		$text = Description::plain_text( $post, 'full' );

		$this->assertStringNotContainsString( '<', $text );
		$this->assertStringNotContainsString( 'wp:', $text );
	}

	public function test_excerpt_scope_uses_the_manual_excerpt_as_plain_text() {
		$post = $this->post( array( 'post_excerpt' => 'A <b>short</b> summary', 'post_content' => '<p>Long body text</p>' ) );

		$this->assertSame( 'A short summary', Description::plain_text( $post, 'excerpt' ) );
	}

	public function test_excerpt_scope_without_a_manual_excerpt_trims_the_body_without_a_more_marker() {
		$words = implode( ' ', array_map( function ( $i ) {
			return 'word' . $i;
		}, range( 1, 80 ) ) );
		$post  = $this->post( array( 'post_excerpt' => '', 'post_content' => '<p>' . $words . '</p>' ) );

		$text = Description::plain_text( $post, 'excerpt' );

		$this->assertSame( 55, count( explode( ' ', $text ) ) );
		$this->assertStringNotContainsString( '[', $text );
	}
}
