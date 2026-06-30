<?php
/**
 * NExT_Wix2WP_Converter のユニットテスト。
 *
 * Brain Monkey で WP のエスケープ関数等をモックし、ノード→ブロック変換の出力を検証する。
 *
 * @package NExT_Wix2WP
 */

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * リッチコンテンツ → Gutenberg ブロック変換のテスト。
 */
class ConverterTest extends TestCase {

	/**
	 * 画像ハンドラのモック。
	 *
	 * @var \Mockery\MockInterface
	 */
	private $image;

	/**
	 * テスト対象。
	 *
	 * @var NExT_Wix2WP_Converter
	 */
	private $converter;

	/**
	 * Brain Monkey と WP 関数スタブを準備する。
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'esc_html' )->alias(
			static function ( $text ) {
				return htmlspecialchars( (string) $text, ENT_QUOTES );
			}
		);
		Functions\when( 'esc_attr' )->alias(
			static function ( $text ) {
				return htmlspecialchars( (string) $text, ENT_QUOTES );
			}
		);
		Functions\when( 'esc_url' )->alias(
			static function ( $url ) {
				return (string) $url;
			}
		);
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data ) {
				return wp_json_encode_stub( $data );
			}
		);
		Functions\when( 'wp_strip_all_tags' )->alias(
			static function ( $text ) {
				return trim( wp_strip_all_tags_stub( $text ) );
			}
		);
		Functions\when( 'is_wp_error' )->alias(
			static function ( $thing ) {
				return $thing instanceof WP_Error;
			}
		);
		Functions\when( 'wp_get_attachment_url' )->alias(
			static function ( $id ) {
				return 'https://example.com/wp-content/uploads/2024/01/image-' . $id . '.jpg';
			}
		);

		$this->image     = Mockery::mock( NExT_Wix2WP_Image::class )->shouldIgnoreMissing();
		$this->converter = new NExT_Wix2WP_Converter( $this->image );
	}

	/**
	 * Brain Monkey / Mockery を後始末する。
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * PARAGRAPH のインライン装飾（太字・リンク）が変換されること。
	 */
	public function test_paragraph_applies_bold_and_link_decorations(): void {
		$rich = array(
			'nodes' => array(
				array(
					'type'  => 'PARAGRAPH',
					'nodes' => array(
						array(
							'type'     => 'TEXT',
							'textData' => array(
								'text'        => 'Hello ',
								'decorations' => array( array( 'type' => 'BOLD' ) ),
							),
						),
						array(
							'type'     => 'TEXT',
							'textData' => array(
								'text'        => 'world',
								'decorations' => array(
									array(
										'type'     => 'LINK',
										'linkData' => array( 'link' => array( 'url' => 'https://example.com' ) ),
									),
								),
							),
						),
					),
				),
			),
		);

		$out = $this->converter->convert( $rich );

		$this->assertStringContainsString( '<!-- wp:core/paragraph -->', $out );
		$this->assertStringContainsString( '<strong>Hello </strong>', $out );
		$this->assertStringContainsString( '<a href="https://example.com">world</a>', $out );
	}

	/**
	 * HEADING のレベルが 1〜6 にクランプされること。
	 */
	public function test_heading_level_is_clamped(): void {
		$rich = array(
			'nodes' => array(
				array(
					'type'        => 'HEADING',
					'headingData' => array( 'level' => 9 ),
					'nodes'       => array(
						array(
							'type'     => 'TEXT',
							'textData' => array( 'text' => 'Title' ),
						),
					),
				),
			),
		);

		$out = $this->converter->convert( $rich );

		$this->assertStringContainsString( '<!-- wp:core/heading {"level":6} -->', $out );
		$this->assertStringContainsString( '<h6 class="wp-block-heading">Title</h6>', $out );
	}

	/**
	 * DIVIDER が core/separator になること。
	 */
	public function test_divider_becomes_separator(): void {
		$out = $this->converter->convert( array( 'nodes' => array( array( 'type' => 'DIVIDER' ) ) ) );

		$this->assertStringContainsString( '<!-- wp:core/separator -->', $out );
		$this->assertStringContainsString( 'wp-block-separator', $out );
	}

	/**
	 * CODE_BLOCK の内容が HTML エスケープされること。
	 */
	public function test_code_block_escapes_html(): void {
		$rich = array(
			'nodes' => array(
				array(
					'type'  => 'CODE_BLOCK',
					'nodes' => array(
						array(
							'type'     => 'TEXT',
							'textData' => array( 'text' => '<script>alert(1)</script>' ),
						),
					),
				),
			),
		);

		$out = $this->converter->convert( $rich );

		$this->assertStringContainsString( '<!-- wp:core/code -->', $out );
		$this->assertStringContainsString( '&lt;script&gt;', $out );
		$this->assertStringNotContainsString( '<script>', $out );
	}

	/**
	 * ORDERED_LIST がネストした PARAGRAPH/TEXT を含めて変換されること。
	 */
	public function test_ordered_list_conversion(): void {
		$item = static function ( $text ) {
			return array(
				'type'  => 'LIST_ITEM',
				'nodes' => array(
					array(
						'type'  => 'PARAGRAPH',
						'nodes' => array(
							array(
								'type'     => 'TEXT',
								'textData' => array( 'text' => $text ),
							),
						),
					),
				),
			);
		};

		$rich = array(
			'nodes' => array(
				array(
					'type'  => 'ORDERED_LIST',
					'nodes' => array( $item( 'First' ), $item( 'Second' ) ),
				),
			),
		);

		$out = $this->converter->convert( $rich );

		$this->assertStringContainsString( '<!-- wp:core/list {"ordered":true} -->', $out );
		$this->assertStringContainsString( '<ol>', $out );
		$this->assertStringContainsString( '<li>First</li>', $out );
		$this->assertStringContainsString( '<li>Second</li>', $out );
	}

	/**
	 * IMAGE のインポート失敗時に元 URL の img タグへフォールバックすること。
	 */
	public function test_image_falls_back_to_original_url_on_error(): void {
		$this->image->shouldReceive( 'import' )->once()->andReturn( new WP_Error( 'fail', 'download failed' ) );

		$rich = array(
			'nodes' => array(
				array(
					'type'      => 'IMAGE',
					'imageData' => array( 'image' => array( 'src' => array( 'id' => 'abc123.jpg' ) ) ),
				),
			),
		);

		$out = $this->converter->convert( $rich );

		$this->assertStringContainsString( '<!-- wp:core/image -->', $out );
		$this->assertStringContainsString( '<img src="https://static.wixstatic.com/media/abc123.jpg" alt=""/>', $out );
		$this->assertStringNotContainsString( 'wp-image-', $out );
	}

	/**
	 * IMAGE のインポート成功時にアタッチメント ID が付与されること。
	 */
	public function test_image_uses_attachment_id_on_success(): void {
		$this->image->shouldReceive( 'import' )->once()->andReturn( 42 );

		$rich = array(
			'nodes' => array(
				array(
					'type'      => 'IMAGE',
					'imageData' => array( 'image' => array( 'src' => array( 'id' => 'abc123.jpg' ) ) ),
				),
			),
		);

		$out = $this->converter->convert( $rich );

		$this->assertStringContainsString( '<!-- wp:core/image {"id":42} -->', $out );
		$this->assertStringContainsString( 'class="wp-image-42"', $out );
	}

	/**
	 * COLOR 装飾は安全な値のみ許可し、危険な値は無視すること（セキュリティ）。
	 */
	public function test_color_decoration_allows_safe_value_only(): void {
		$make = function ( $color ) {
			return array(
				'nodes' => array(
					array(
						'type'  => 'PARAGRAPH',
						'nodes' => array(
							array(
								'type'     => 'TEXT',
								'textData' => array(
									'text'        => 'tinted',
									'decorations' => array(
										array(
											'type'      => 'COLOR',
											'colorData' => array( 'foreground' => $color ),
										),
									),
								),
							),
						),
					),
				),
			);
		};

		$safe = $this->converter->convert( $make( '#ff0000' ) );
		$this->assertStringContainsString( '<span style="color:#ff0000">tinted</span>', $safe );

		$unsafe = $this->converter->convert( $make( 'red;background:url(javascript:alert(1))' ) );
		$this->assertStringNotContainsString( '<span style', $unsafe );
		$this->assertStringContainsString( 'tinted', $unsafe );
	}

	/**
	 * 未対応ノードはテキストを抽出して段落にフォールバックすること。
	 */
	public function test_unknown_node_falls_back_to_paragraph(): void {
		$rich = array(
			'nodes' => array(
				array(
					'type'  => 'SOME_FUTURE_TYPE',
					'nodes' => array(
						array(
							'type'     => 'TEXT',
							'textData' => array( 'text' => 'fallback text' ),
						),
					),
				),
			),
		);

		$out = $this->converter->convert( $rich );

		$this->assertStringContainsString( '<!-- wp:core/paragraph -->', $out );
		$this->assertStringContainsString( 'fallback text', $out );
	}
}

/**
 * wp_json_encode スタブ実体（JSON_UNESCAPED_* で WP 既定に近づける）。
 *
 * @param mixed $data エンコード対象。
 * @return string
 */
function wp_json_encode_stub( $data ) {
	return (string) json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
}

/**
 * wp_strip_all_tags スタブ実体。
 *
 * @param string $text 入力文字列。
 * @return string
 */
function wp_strip_all_tags_stub( $text ) {
	return preg_replace( '/<[^>]*>/', '', (string) $text );
}
