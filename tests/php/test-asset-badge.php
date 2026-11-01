<?php

use TokenizeContent\AssetBadge;
use TokenizeContent\PostMeta;

class Test_Asset_Badge extends Tokenize_Test_Case {

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

	private function minted_post( array $args = array() ): int {
		$post_id = $this->factory->post->create( $args );
		PostMeta::save_minted( $post_id, array( 'token_id' => '42', 'tx_hash' => '0xabc', 'contract' => '0xdef', 'license' => 'CC BY-SA' ) );
		return $post_id;
	}

	private function the_content_on_the_page_of( int $post_id ): string {
		$this->go_to( get_permalink( $post_id ) );
		$output = '';
		while ( have_posts() ) {
			the_post();
			$output = apply_filters( 'the_content', get_the_content() );
		}
		return $output;
	}

	public function test_the_badge_brings_its_stylesheet_instead_of_printing_one() {
		$post_id = $this->minted_post();

		$html = AssetBadge::render( $post_id );

		$this->assertStringNotContainsString( '<style', $html );
		$this->assertTrue( wp_style_is( AssetBadge::STYLE, 'enqueued' ) );
	}

	public function test_a_page_that_is_tokenized_gets_the_badge_after_its_content() {
		$page = $this->minted_post( array( 'post_type' => 'page', 'post_content' => 'About us' ) );

		$output = $this->the_content_on_the_page_of( $page );

		$this->assertStringContainsString( 'About us', $output );
		$this->assertStringContainsString( 'https://medialane.io/asset/starknet/0xdef/42', $output );
	}

	public function test_the_shortcode_shows_the_badge_of_the_current_post() {
		$post_id = $this->minted_post( array( 'post_content' => 'Intro [tokenize_content_badge] Outro' ) );

		$output = $this->the_content_on_the_page_of( $post_id );

		$this->assertSame( 1, substr_count( $output, 'tokenize-content-asset-badge"' ) );
		$this->assertLessThan( strpos( $output, 'Outro' ), strpos( $output, 'tokenize-content-asset-badge"' ) );
	}

	public function test_the_shortcode_shows_nothing_for_a_post_that_is_not_tokenized() {
		$post_id = $this->factory->post->create( array( 'post_content' => 'Intro [tokenize_content_badge] Outro' ) );

		$this->assertStringNotContainsString( 'tokenize-content-asset-badge', $this->the_content_on_the_page_of( $post_id ) );
	}

	public function test_the_block_shows_the_badge_where_it_is_placed_and_only_once() {
		$post_id = $this->minted_post( array( 'post_content' => "<!-- wp:paragraph --><p>Intro</p><!-- /wp:paragraph -->\n<!-- wp:tokenize-content/badge /-->\n<!-- wp:paragraph --><p>Outro</p><!-- /wp:paragraph -->" ) );

		$output = $this->the_content_on_the_page_of( $post_id );

		$this->assertSame( 1, substr_count( $output, 'tokenize-content-asset-badge"' ) );
		$this->assertLessThan( strpos( $output, 'Outro' ), strpos( $output, 'tokenize-content-asset-badge"' ) );
	}

	public function test_the_block_is_registered_with_context_and_the_editor_script() {
		$type = WP_Block_Type_Registry::get_instance()->get_registered( AssetBadge::BLOCK );

		$this->assertNotNull( $type );
		$this->assertContains( 'postId', $type->uses_context );
		$this->assertSame( array( AssetBadge::BLOCK_SCRIPT ), $type->editor_script_handles );
	}

	public function test_the_block_shows_the_badge_of_the_post_in_its_context() {
		$post_id = $this->minted_post( array( 'post_title' => 'Context Post' ) );

		$block = new WP_Block( array( 'blockName' => AssetBadge::BLOCK ), array( 'postId' => $post_id ) );

		$this->assertStringContainsString( 'Context Post', $block->render() );
	}
}
