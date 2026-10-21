<?php

use TokenizeContent\AssetBadge;
use TokenizeContent\PostMeta;

class Test_Asset_Badge extends WP_UnitTestCase {

	public function test_renders_nothing_for_a_post_that_was_never_minted() {
		$post_id = $this->factory->post->create();
		$this->assertSame( '', AssetBadge::render( $post_id ) );
	}

	public function test_renders_nothing_while_a_post_is_only_minting_or_errored() {
		$post_id = $this->factory->post->create();
		PostMeta::set_minting( $post_id );
		$this->assertSame( '', AssetBadge::render( $post_id ) );

		PostMeta::save_error( $post_id, 'Transaction reverted' );
		$this->assertSame( '', AssetBadge::render( $post_id ) );
	}

	public function test_renders_a_link_to_the_asset_page_for_a_minted_post() {
		$post_id = $this->factory->post->create( array( 'post_title' => 'A Tokenized Post' ) );
		PostMeta::save_minted( $post_id, array(
			'token_id' => '42',
			'tx_hash'  => '0xabc',
			'contract' => '0xdef',
			'license'  => 'CC BY-SA',
		) );

		$html = AssetBadge::render( $post_id );

		$this->assertStringContainsString( 'https://medialane.io/asset/starknet/0xdef/42', $html );
		$this->assertStringContainsString( 'A Tokenized Post', $html );
		$this->assertStringContainsString( 'CC BY-SA', $html );
	}

	public function test_escapes_the_license_and_title_in_the_rendered_markup() {
		$post_id = $this->factory->post->create( array( 'post_title' => '<script>alert(1)</script>' ) );
		PostMeta::save_minted( $post_id, array(
			'token_id' => '1',
			'tx_hash'  => '0xabc',
			'contract' => '0xdef',
			'license'  => '<script>alert(2)</script>',
		) );

		$html = AssetBadge::render( $post_id );

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
		$this->assertStringNotContainsString( '<script>alert(2)</script>', $html );
	}

	public function test_append_to_content_leaves_unrelated_content_untouched_outside_the_single_post_loop() {
		$post_id = $this->factory->post->create();
		PostMeta::save_minted( $post_id, array(
			'token_id' => '42', 'tx_hash' => '0xabc', 'contract' => '0xdef', 'license' => 'CC BY-SA',
		) );

		$this->assertSame( 'Original content', AssetBadge::append_to_content( 'Original content' ) );
	}

	public function test_append_to_content_adds_the_badge_on_a_minted_post_s_singular_page() {
		$post_id = $this->factory->post->create();
		PostMeta::save_minted( $post_id, array(
			'token_id' => '42', 'tx_hash' => '0xabc', 'contract' => '0xdef', 'license' => 'CC BY-SA',
		) );

		$this->go_to( get_permalink( $post_id ) );
		$output = '';
		if ( have_posts() ) {
			while ( have_posts() ) {
				the_post();
				$output = AssetBadge::append_to_content( 'Original content' );
			}
		}

		$this->assertStringContainsString( 'Original content', $output );
		$this->assertStringContainsString( 'https://medialane.io/asset/starknet/0xdef/42', $output );
	}
}
