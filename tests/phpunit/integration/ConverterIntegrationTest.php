<?php
/**
 * 変換結果が WordPress のブロックパーサーで解釈できることの統合テスト。
 *
 * @package NExT_Wix2WP
 */

/**
 * 生成ブロックが parse_blocks で正しく解釈されることを検証する。
 */
class ConverterIntegrationTest extends WP_UnitTestCase {

	/**
	 * 段落ブロックが core/paragraph として解釈されること。
	 */
	public function test_paragraph_block_parses_in_wordpress() {
		$image     = new NExT_Wix2WP_Image();
		$converter = new NExT_Wix2WP_Converter( $image );

		$rich = array(
			'nodes' => array(
				array(
					'type'  => 'PARAGRAPH',
					'nodes' => array(
						array(
							'type'     => 'TEXT',
							'textData' => array( 'text' => 'Hello world' ),
						),
					),
				),
			),
		);

		$serialized = $converter->convert( $rich );
		$blocks     = parse_blocks( $serialized );

		$named = array_values(
			array_filter(
				$blocks,
				static function ( $block ) {
					return ! empty( $block['blockName'] );
				}
			)
		);

		$this->assertNotEmpty( $named, 'ブロックが 1 つ以上解釈されること' );
		$this->assertSame( 'core/paragraph', $named[0]['blockName'] );
		$this->assertStringContainsString( 'Hello world', $named[0]['innerHTML'] );
	}
}
