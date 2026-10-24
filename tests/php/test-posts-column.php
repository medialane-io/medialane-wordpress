<?php

use TokenizeContent\PostMeta;
use TokenizeContent\PostsColumn;
use TokenizeContent\Settings;

class Test_Posts_Column extends Tokenize_Test_Case {

	private function cell( int $post_id ): string {
		ob_start();
		PostsColumn::render_column( PostsColumn::COLUMN, $post_id );
		return ob_get_clean();
	}

	public function test_the_column_is_added_for_users_who_can_tokenize() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertArrayHasKey( PostsColumn::COLUMN, PostsColumn::add_column( array( 'title' => 'Title' ) ) );
	}

	public function test_the_column_is_not_added_for_users_who_cannot_tokenize() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'editor' ) ) );

		$this->assertArrayNotHasKey( PostsColumn::COLUMN, PostsColumn::add_column( array( 'title' => 'Title' ) ) );
	}

	public function test_a_tokenized_post_links_to_its_asset_page() {
		$post_id = $this->factory->post->create();
		PostMeta::save_minted( $post_id, array( 'token_id' => '42', 'tx_hash' => '0xabc', 'contract' => '0xdef', 'license' => 'CC BY' ) );

		$html = $this->cell( $post_id );

		$this->assertStringContainsString( 'href="https://medialane.io/asset/starknet/0xdef/42"', $html );
		$this->assertStringContainsString( 'View on Medialane', $html );
	}

	public function test_a_post_in_progress_says_so() {
		$post_id = $this->factory->post->create();
		PostMeta::set_minting( $post_id );

		$this->assertStringContainsString( 'In progress', $this->cell( $post_id ) );
	}

	public function test_a_failed_post_says_so_and_keeps_the_reason_in_the_tooltip() {
		$post_id = $this->factory->post->create();
		PostMeta::save_error( $post_id, 'Transaction reverted' );

		$html = $this->cell( $post_id );

		$this->assertStringContainsString( 'Failed', $html );
		$this->assertStringContainsString( 'title="Transaction reverted"', $html );
	}

	public function test_a_post_never_tokenized_shows_a_dash() {
		$this->assertStringContainsString( '&mdash;', $this->cell( $this->factory->post->create() ) );
	}

	public function test_other_columns_are_left_alone() {
		ob_start();
		PostsColumn::render_column( 'author', $this->factory->post->create() );

		$this->assertSame( '', ob_get_clean() );
	}
}
