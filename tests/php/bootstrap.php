<?php
$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = '/tmp/wordpress-tests-lib';
}
require_once $_tests_dir . '/includes/functions.php';

function _tokenize_content_manually_load_plugin() {
	define( 'TOKENIZE_CONTENT_BACKEND_URL', 'https://backend.test' );
	require dirname( dirname( __DIR__ ) ) . '/tokenize-content.php';
}
tests_add_filter( 'muplugins_loaded', '_tokenize_content_manually_load_plugin' );

require $_tests_dir . '/includes/bootstrap.php';

class Tokenize_Test_Case extends WP_UnitTestCase {

	public function tear_down() {
		foreach ( wp_roles()->role_objects as $role ) {
			$role->remove_cap( \TokenizeContent\Settings::CAP_TOKENIZE );
		}
		\TokenizeContent\Settings::grant_default_capability();
		parent::tear_down();
	}
}
