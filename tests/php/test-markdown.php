<?php

use TokenizeContent\Markdown;

class Test_Markdown extends Tokenize_Test_Case {

	public function test_blocks_become_paragraphs_with_inline_emphasis() {
		$html = '<!-- wp:paragraph --><p>Hello <strong>world</strong> and <em>soft</em></p><!-- /wp:paragraph --><p>Second &amp; last</p>';

		$this->assertSame( "Hello **world** and *soft*\n\nSecond & last", Markdown::from_html( $html ) );
	}

	public function test_headings_and_nested_lists_keep_their_structure() {
		$html = '<h2>Title</h2><ul><li>one</li><li>two <a href="https://e.com/a">link</a></li></ul><ol><li>a</li><li>b<ul><li>nested</li></ul></li></ol>';

		$this->assertSame( "## Title\n\n- one\n- two [link](https://e.com/a)\n\n1. a\n2. b\n   - nested", Markdown::from_html( $html ) );
	}

	public function test_line_breaks_inside_a_paragraph_are_kept() {
		$this->assertSame( "Line one  \nLine two", Markdown::from_html( '<p>Line one<br>Line two</p>' ) );
	}

	public function test_quotes_images_and_captions() {
		$this->assertSame( '> Quoted', Markdown::from_html( '<blockquote><p>Quoted</p></blockquote>' ) );
		$this->assertSame(
			"![A cat](https://e.com/c.png)\n\nCaption",
			Markdown::from_html( '<figure class="wp-block-image"><img src="https://e.com/c.png" alt="A cat"/><figcaption>Caption</figcaption></figure>' )
		);
	}

	public function test_images_stay_as_the_author_linked_them() {
		$this->assertSame( '![](https://e.com/c.png)', Markdown::from_html( '<p><img src="https://e.com/c.png"></p>' ) );
	}

	public function test_code_blocks_keep_their_lines_and_inline_code_is_wrapped() {
		$html = '<pre class="wp-block-code"><code>a *b*' . "\n" . '  c</code></pre><p>use <code>x</code></p>';

		$this->assertSame( "```\na *b*\n  c\n```\n\nuse `x`", Markdown::from_html( $html ) );
	}

	public function test_characters_that_would_read_as_markdown_are_escaped() {
		$html = '<p>2*3*4 and [brackets]</p><p># not heading</p><p>- not list</p><p>1. not ordered</p>';

		$this->assertSame( "2\\*3\\*4 and \\[brackets\\]\n\n\\# not heading\n\n\\- not list\n\n1\\. not ordered", Markdown::from_html( $html ) );
	}

	public function test_unsafe_links_images_and_scripts_are_dropped() {
		$html = '<p><a href="javascript:alert(1)">click</a> <img src="javascript:x"> <script>alert(1)</script>ok</p>';

		$this->assertSame( 'click ok', Markdown::from_html( $html ) );
	}

	public function test_an_embed_becomes_a_link_to_its_source() {
		$html = '<figure><iframe src="https://www.youtube.com/embed/abc" title="Video"></iframe></figure>';

		$this->assertSame( '[Video](https://www.youtube.com/embed/abc)', Markdown::from_html( $html ) );
	}

	public function test_accents_and_typographic_quotes_survive() {
		$text = 'Final Fantasy Resonance resgata a essência clássica dos JRPGs — “aspas”';

		$this->assertSame( $text, Markdown::from_html( '<p>' . $text . '</p>' ) );
	}

	public function test_empty_input_gives_nothing() {
		$this->assertSame( '', Markdown::from_html( '' ) );
	}
}
