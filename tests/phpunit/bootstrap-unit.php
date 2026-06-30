<?php
/**
 * ユニットテスト用ブートストラップ。
 *
 * WordPress 本体を読み込まず、Brain Monkey で WP 関数をモックする。
 * 純粋ロジック（リッチコンテンツ変換・URL 正規化など）を対象とする。
 *
 * @package NExT_Wix2WP
 */

$plugin_dir = dirname( __DIR__, 2 );

require_once $plugin_dir . '/vendor/autoload.php';

// プラグインのクラスファイルは ABSPATH 未定義時に exit するため、定義しておく。
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $plugin_dir . '/' );
}

// ユニットテスト用の最小限の WP_Error スタブ。
if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * 最小限の WP_Error スタブ。
	 */
	class WP_Error {

		/**
		 * エラーコード。
		 *
		 * @var string
		 */
		public $code;

		/**
		 * エラーメッセージ。
		 *
		 * @var string
		 */
		public $message;

		/**
		 * コンストラクタ。
		 *
		 * @param string $code    エラーコード。
		 * @param string $message エラーメッセージ。
		 */
		public function __construct( $code = '', $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}

		/**
		 * エラーメッセージを返す。
		 *
		 * @return string
		 */
		public function get_error_message() {
			return $this->message;
		}
	}
}

// テスト対象のクラスを読み込む。
require_once $plugin_dir . '/includes/class-image.php';
require_once $plugin_dir . '/includes/class-converter.php';
