<?php

use TokenizeContent\PostMeta;
use TokenizeContent\Settings;
use TokenizeContent\TokenizePage;

class Test_Tokenize_Page extends Tokenize_Test_Case {

	public function set_up() {
		parent::set_up();
		Settings::grant_default_capability();
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down() {
		wp_dequeue_script( 'tokenize-content-tokenize-page' );
		wp_deregister_script( 'tokenize-content-tokenize-page' );
		parent::tear_down();
	}

	private function page_html( int $paged = 1 ): string {
		$_GET['paged'] = (string) $paged;
		ob_start();
		TokenizePage::render();
		unset( $_GET['paged'] );
		return (string) ob_get_clean();
	}

	private function minted( int $post_id ) {
		PostMeta::save_minted( $post_id, array( 'token_id' => '1', 'tx_hash' => '0xabc', 'contract' => '0xdef', 'license' => 'CC BY' ) );
	}

	public function test_the_page_is_added_under_the_posts_menu_for_users_who_can_tokenize() {
		global $submenu;
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		TokenizePage::add_page();

		$slugs = wp_list_pluck( $submenu['edit.php'], 2 );
		$this->assertContains( TokenizePage::SLUG, $slugs );
	}

	public function test_it_says_so_when_nothing_is_waiting() {
		$this->minted( $this->factory->post->create( array( 'post_status' => 'publish' ) ) );

		$this->assertStringContainsString( 'Nothing is waiting to be tokenized.', $this->page_html() );
		$this->assertStringNotContainsString( 'tokenize-content-page-run', $this->page_html() );
	}

	public function test_it_lists_published_posts_and_pages_that_are_not_tokenized() {
		$this->factory->post->create( array( 'post_status' => 'publish', 'post_title' => 'A Post Waiting' ) );
		$this->factory->post->create( array( 'post_status' => 'publish', 'post_type' => 'page', 'post_title' => 'A Page Waiting' ) );
		$done = $this->factory->post->create( array( 'post_status' => 'publish', 'post_title' => 'Already Done' ) );
		$this->minted( $done );
		$this->factory->post->create( array( 'post_status' => 'draft', 'post_title' => 'A Draft' ) );
		$this->factory->post->create( array( 'post_status' => 'publish', 'post_password' => 'secret', 'post_title' => 'Locked' ) );

		$html = $this->page_html();

		$this->assertStringContainsString( 'A Post Waiting', $html );
		$this->assertStringContainsString( 'A Page Waiting', $html );
		$this->assertStringNotContainsString( 'Already Done', $html );
		$this->assertStringNotContainsString( 'A Draft', $html );
		$this->assertStringNotContainsString( 'Locked', $html );
	}

	public function test_a_failed_post_shows_its_reason_and_can_be_tried_again() {
		$post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );
		PostMeta::save_error( $post_id, 'Transaction reverted' );

		$html = $this->page_html();

		$this->assertStringContainsString( 'Failed: Transaction reverted', $html );
		$this->assertDoesNotMatchRegularExpression( '/value="' . $post_id . '"[^>]*disabled/', $html );
	}

	public function test_a_post_in_progress_is_listed_but_cannot_be_selected() {
		$post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );
		PostMeta::set_minting( $post_id );

		$html = $this->page_html();

		$this->assertStringContainsString( 'In progress', $html );
		$this->assertMatchesRegularExpression( '/value="' . $post_id . '"[^>]*disabled/', $html );
	}

	public function test_a_post_the_user_cannot_edit_cannot_be_selected() {
		Settings::grant_default_capability();
		get_role( 'author' )->add_cap( Settings::CAP_TOKENIZE );
		$other = $this->factory->post->create( array( 'post_status' => 'publish', 'post_author' => $this->factory->user->create( array( 'role' => 'editor' ) ) ) );
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'author' ) ) );

		$this->assertMatchesRegularExpression( '/value="' . $other . '"[^>]*disabled/', $this->page_html() );
		get_role( 'author' )->remove_cap( Settings::CAP_TOKENIZE );
	}

	public function test_titles_are_escaped() {
		$this->factory->post->create( array( 'post_status' => 'publish', 'post_title' => '<script>alert(1)</script>' ) );

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $this->page_html() );
	}

	public function test_the_list_is_paged_without_leaving_anything_out() {
		$this->factory->post->create_many( TokenizePage::PER_PAGE + 3, array( 'post_status' => 'publish' ) );

		$this->assertSame( TokenizePage::PER_PAGE, substr_count( $this->page_html( 1 ), 'name="tokenize_content_ids[]"' ) );
		$this->assertSame( 3, substr_count( $this->page_html( 2 ), 'name="tokenize_content_ids[]"' ) );
	}

	public function test_a_user_without_the_capability_sees_nothing() {
		$this->factory->post->create( array( 'post_status' => 'publish' ) );
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertSame( '', $this->page_html() );
	}

	public function test_the_script_loads_only_on_its_own_page_for_users_who_can_tokenize() {
		TokenizePage::enqueue( 'edit.php' );
		$this->assertFalse( wp_script_is( 'tokenize-content-tokenize-page', 'enqueued' ) );

		TokenizePage::enqueue( TokenizePage::HOOK );
		$this->assertTrue( wp_script_is( 'tokenize-content-tokenize-page', 'enqueued' ) );
	}

	public function test_the_script_is_handed_no_post_content_or_author_email() {
		$author = $this->factory->user->create( array( 'role' => 'author', 'user_email' => 'writer@example.org' ) );
		$this->factory->post->create( array( 'post_status' => 'publish', 'post_author' => $author, 'post_content' => 'SECRET BODY TEXT' ) );

		TokenizePage::enqueue( TokenizePage::HOOK );

		$data = (string) wp_scripts()->get_data( 'tokenize-content-tokenize-page', 'data' );
		$this->assertStringNotContainsString( 'writer@example.org', $data );
		$this->assertStringNotContainsString( 'SECRET BODY TEXT', $data );
		$this->assertStringContainsString( '"licenses"', $data );
	}
}
