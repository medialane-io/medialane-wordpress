<?php

class Test_Plugin extends Tokenize_Test_Case {

	private function root( string $file ): string {
		return dirname( dirname( __DIR__ ) ) . '/' . $file;
	}

	public function test_the_version_is_written_once_in_the_plugin_header() {
		$source = file_get_contents( $this->root( 'tokenize-content.php' ) );

		$this->assertSame( 1, preg_match_all( '/\d+\.\d+\.\d+/', $source ) );
	}

	public function test_the_version_constant_matches_the_header() {
		$header = get_file_data( $this->root( 'tokenize-content.php' ), array( 'Version' => 'Version' ) );

		$this->assertSame( $header['Version'], TOKENIZE_CONTENT_PLUGIN_VERSION );
	}

	public function test_the_readme_stable_tag_matches_the_plugin_version() {
		$this->assertSame( 1, preg_match( '/^Stable tag:\s*(\S+)/m', file_get_contents( $this->root( 'readme.txt' ) ), $match ) );
		$this->assertSame( TOKENIZE_CONTENT_PLUGIN_VERSION, $match[1] );
	}
}
