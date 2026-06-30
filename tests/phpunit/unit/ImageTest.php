<?php
/**
 * NExT_Wix2WP_Image のユニットテスト。
 *
 * @package NExT_Wix2WP
 */

use PHPUnit\Framework\TestCase;

/**
 * Wix 画像 URL 正規化のテスト。
 */
class ImageTest extends TestCase {

	/**
	 * テスト対象。
	 *
	 * @var NExT_Wix2WP_Image
	 */
	private $image;

	/**
	 * 各テスト前にインスタンスを生成する。
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->image = new NExT_Wix2WP_Image();
	}

	/**
	 * /v1/fill/... 変換パラメータが除去されること。
	 */
	public function test_normalize_url_strips_fill_transform(): void {
		$this->assertSame(
			'https://static.wixstatic.com/media/abc123.jpg',
			$this->image->normalize_url(
				'https://static.wixstatic.com/media/abc123.jpg/v1/fill/w_800,h_600/abc123.jpg'
			)
		);
	}

	/**
	 * /v1/fit/... 変換パラメータが除去されること。
	 */
	public function test_normalize_url_strips_fit_transform(): void {
		$this->assertSame(
			'https://static.wixstatic.com/media/photo.png',
			$this->image->normalize_url(
				'https://static.wixstatic.com/media/photo.png/v1/fit/w_500,h_500,al_c/photo.png'
			)
		);
	}

	/**
	 * クエリパラメータが除去されること。
	 */
	public function test_normalize_url_strips_query_string(): void {
		$this->assertSame(
			'https://static.wixstatic.com/media/abc.jpg',
			$this->image->normalize_url( 'https://static.wixstatic.com/media/abc.jpg?token=xyz' )
		);
	}

	/**
	 * 変換のない URL はそのまま返ること。
	 */
	public function test_normalize_url_leaves_plain_url_untouched(): void {
		$url = 'https://static.wixstatic.com/media/plain.jpg';
		$this->assertSame( $url, $this->image->normalize_url( $url ) );
	}
}
