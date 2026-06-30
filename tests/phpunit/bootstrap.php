<?php
/**
 * 統合テスト用ブートストラップ。
 *
 * WordPress テストスイート（install-wp-tests.sh で用意）を読み込み、
 * 本プラグインを muplugins_loaded で手動ロードする。
 *
 * @package NExT_Wix2WP
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

// yoast/phpunit-polyfills の場所を WP テストスイートに伝える。
if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	$_polyfills_path = dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills';
	if ( is_dir( $_polyfills_path ) ) {
		define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $_polyfills_path );
	}
}

$_functions = $_tests_dir . '/includes/functions.php';
if ( ! file_exists( $_functions ) ) {
	echo "WP テストスイートが見つかりません: {$_functions}" . PHP_EOL;
	echo 'bin/install-wp-tests.sh を実行してください。' . PHP_EOL;
	exit( 1 );
}

require_once $_functions;

/**
 * テスト実行時にプラグインを手動で読み込む。
 */
function _next_wix2wp_manually_load_plugin() {
	require dirname( __DIR__, 2 ) . '/NExT-Wix2WP.php';
}
tests_add_filter( 'muplugins_loaded', '_next_wix2wp_manually_load_plugin' );

require $_tests_dir . '/includes/bootstrap.php';
