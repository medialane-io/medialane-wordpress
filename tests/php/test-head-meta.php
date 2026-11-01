<?php

use TokenizeContent\HeadMeta;
use TokenizeContent\PostMeta;

class Test_Head_Meta extends Tokenize_Test_Case {

	private function tokenized( string $license, string $policy = '' ): int {
		$post_id = $this->factory->post->create();
		PostMeta::set_minting( $post_id );
		PostMeta::set_broadcast( $post_id, array( 'tx_hash' => '0xabc', 'contract' => '0xdef', 'license' => $license, 'ai_policy' => $policy, 'batch_index' => '0' ) );
		PostMeta::save_minted( $post_id, array( 'token_id' => '1', 'tx_hash' => '0xabc', 'contract' => '0xdef', 'license' => $license ) );
		return $post_id;
	}

	public function test_a_creative_commons_license_is_linked_to_its_page() {
		$tags = HeadMeta::tags_for_post( $this->tokenized( 'CC BY-NC' ) );

		$this->assertStringContainsString( '<link rel="license" href="https://creativecommons.org/licenses/by-nc/4.0/" />', $tags );
	}

	public function test_cc0_is_linked_to_the_public_domain_dedication() {
		$tags = HeadMeta::tags_for_post( $this->tokenized( 'CC0' ) );

		$this->assertStringContainsString( 'https://creativecommons.org/publicdomain/zero/1.0/', $tags );
	}

	public function test_reserved_rights_and_custom_terms_have_no_license_link() {
		$this->assertStringNotContainsString( 'rel="license"', HeadMeta::tags_for_post( $this->tokenized( 'All Rights Reserved' ) ) );
		$this->assertStringNotContainsString( 'rel="license"', HeadMeta::tags_for_post( $this->tokenized( 'My own <b>terms</b>' ) ) );
	}

	public function test_the_ai_policy_the_post_was_tokenized_with_is_published() {
		$tags = HeadMeta::tags_for_post( $this->tokenized( 'CC BY', 'Not Allowed' ) );

		$this->assertStringContainsString( '<meta name="tokenize-content-ai-policy" content="Not Allowed" />', $tags );
	}

	public function test_no_ai_policy_tag_when_the_post_has_none_recorded() {
		$this->assertStringNotContainsString( 'ai-policy', HeadMeta::tags_for_post( $this->tokenized( 'CC BY' ) ) );
	}

	public function test_nothing_for_a_post_that_is_not_tokenized() {
		$post_id = $this->factory->post->create();
		PostMeta::set_minting( $post_id );
		PostMeta::set_broadcast( $post_id, array( 'tx_hash' => '0xabc', 'contract' => '0xdef', 'license' => 'CC BY', 'ai_policy' => 'Allowed', 'batch_index' => '0' ) );

		$this->assertSame( '', HeadMeta::tags_for_post( $post_id ) );
	}

	public function test_the_head_carries_the_tags_of_the_tokenized_page_being_viewed() {
		$page = $this->tokenized( 'CC BY', 'Training Only' );
		wp_update_post( array( 'ID' => $page, 'post_type' => 'page' ) );
		$this->go_to( get_permalink( $page ) );

		ob_start();
		HeadMeta::print_tags();
		$head = ob_get_clean();

		$this->assertStringContainsString( 'creativecommons.org/licenses/by/4.0/', $head );
		$this->assertStringContainsString( 'content="Training Only"', $head );
	}

	public function test_the_head_is_untouched_outside_a_single_page() {
		$this->tokenized( 'CC BY', 'Allowed' );
		$this->go_to( home_url( '/' ) );

		ob_start();
		HeadMeta::print_tags();

		$this->assertSame( '', ob_get_clean() );
	}
}
