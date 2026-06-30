<?php
/**
 * プラグイン読み込み・基本要素の統合テスト。
 *
 * @package NExT_Wix2WP
 */

/**
 * プラグインのロードとユーティリティの統合テスト。
 */
class PluginTest extends WP_UnitTestCase {

	/**
	 * バージョン定数が定義されていること。
	 */
	public function test_plugin_constants_defined() {
		$this->assertTrue( defined( 'NEXT_WIX2WP_VERSION' ) );
		$this->assertSame( '1.0.1', NEXT_WIX2WP_VERSION );
	}

	/**
	 * 主要クラスが読み込まれていること。
	 */
	public function test_core_classes_exist() {
		$this->assertTrue( class_exists( 'NExT_Wix2WP_API' ) );
		$this->assertTrue( class_exists( 'NExT_Wix2WP_Image' ) );
		$this->assertTrue( class_exists( 'NExT_Wix2WP_Converter' ) );
		$this->assertTrue( class_exists( 'NExT_Wix2WP_Importer' ) );
		$this->assertTrue( class_exists( 'NExT_Wix2WP_Admin' ) );
	}

	/**
	 * 無効な URL では WP_Error が返ること。
	 */
	public function test_api_invalid_url_returns_wp_error() {
		$api    = new NExT_Wix2WP_API( 'not-a-valid-url' );
		$result = $api->get_all_posts();
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'invalid_url', $result->get_error_code() );
	}

	/**
	 * 保存済みトークンを完全一致・末尾スラッシュ差異の両方で取得できること。
	 */
	public function test_admin_get_token_matches_with_and_without_trailing_slash() {
		update_option(
			NExT_Wix2WP_Admin::OPTION_KEY,
			array( 'https://example.com/blog-1' => 'token-abc' )
		);

		$this->assertSame( 'token-abc', NExT_Wix2WP_Admin::get_token( 'https://example.com/blog-1' ) );
		$this->assertSame( 'token-abc', NExT_Wix2WP_Admin::get_token( 'https://example.com/blog-1/' ) );
		$this->assertSame( '', NExT_Wix2WP_Admin::get_token( 'https://other.example.com/blog' ) );
	}
}
