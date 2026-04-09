<?php
defined( 'ABSPATH' ) || exit;

/**
 * Wix Rich Content → Gutenberg ブロック変換
 *
 * Wix の richContent.nodes (DraftJS/ProseMirror ベースの JSON) を
 * WordPress のブロックシリアライズ形式に変換する。
 */
class NExT_Wix2WP_Converter {

	/** @var NExT_Wix2WP_Image */
	private $image_handler;

	/** @var int 紐付ける投稿 ID */
	private $post_id;

	/** @var string 投稿日 (Y-m-d H:i:s) */
	private $post_date;

	/**
	 * @param NExT_Wix2WP_Image $image_handler
	 */
	public function __construct( NExT_Wix2WP_Image $image_handler ) {
		$this->image_handler = $image_handler;
	}

	/**
	 * richContent を Gutenberg ブロック文字列に変換する。
	 *
	 * @param array  $rich_content Wix richContent 配列
	 * @param int    $post_id      紐付ける投稿 ID
	 * @param string $post_date    投稿日 (Y-m-d H:i:s)
	 * @return string Gutenberg シリアライズ済みブロック文字列
	 */
	public function convert( $rich_content, $post_id = 0, $post_date = '' ) {
		$this->post_id   = $post_id;
		$this->post_date = $post_date;

		$nodes = isset( $rich_content['nodes'] ) ? $rich_content['nodes'] : array();

		$blocks = array();
		foreach ( $nodes as $node ) {
			$block = $this->convert_node( $node );
			if ( $block !== '' ) {
				$blocks[] = $block;
			}
		}

		return implode( "\n\n", $blocks );
	}

	/**
	 * 単一ノードを変換する。
	 *
	 * @param array $node
	 * @return string
	 */
	private function convert_node( $node ) {
		$type = isset( $node['type'] ) ? $node['type'] : '';

		switch ( $type ) {
			case 'PARAGRAPH':
				return $this->convert_paragraph( $node );
			case 'HEADING':
				return $this->convert_heading( $node );
			case 'IMAGE':
				return $this->convert_image( $node );
			case 'GALLERY':
				return $this->convert_gallery( $node );
			case 'VIDEO':
				return $this->convert_video( $node );
			case 'DIVIDER':
				return $this->convert_divider();
			case 'BLOCKQUOTE':
				return $this->convert_blockquote( $node );
			case 'CODE_BLOCK':
				return $this->convert_code( $node );
			case 'ORDERED_LIST':
				return $this->convert_list( $node, true );
			case 'BULLETED_LIST':
				return $this->convert_list( $node, false );
			case 'LINK_PREVIEW':
				return $this->convert_link_preview( $node );
			default:
				// 未対応ノードはテキストを抽出して paragraph に
				$text = $this->extract_text( $node );
				if ( $text !== '' ) {
					return $this->wrap_block( 'core/paragraph', array(), '<p>' . $text . '</p>' );
				}
				return '';
		}
	}

	// -------------------------------------------------------------------------
	// ノード変換メソッド
	// -------------------------------------------------------------------------

	private function convert_paragraph( $node ) {
		$html = $this->nodes_to_html( $node );
		if ( trim( strip_tags( $html ) ) === '' ) {
			return '';
		}
		return $this->wrap_block( 'core/paragraph', array(), '<p>' . $html . '</p>' );
	}

	private function convert_heading( $node ) {
		$level = isset( $node['headingData']['level'] ) ? (int) $node['headingData']['level'] : 2;
		$level = max( 1, min( 6, $level ) );
		$html  = $this->nodes_to_html( $node );
		return $this->wrap_block(
			'core/heading',
			array( 'level' => $level ),
			'<h' . $level . ' class="wp-block-heading">' . $html . '</h' . $level . '>'
		);
	}

	private function convert_image( $node ) {
		// imageData.image.src.id → Wix static CDN URL.
		$src = '';
		if ( ! empty( $node['imageData']['image']['src']['id'] ) ) {
			$src = 'https://static.wixstatic.com/media/' . $node['imageData']['image']['src']['id'];
		} elseif ( ! empty( $node['imageData']['image']['src']['url'] ) ) {
			$src = $node['imageData']['image']['src']['url'];
		}

		if ( ! $src ) {
			return '';
		}

		$alt = '';
		// キャプションは imageData.caption またはCAPTION 子ノードから取得する.
		$caption = isset( $node['imageData']['caption'] ) ? $node['imageData']['caption'] : '';
		if ( ! $caption ) {
			foreach ( isset( $node['nodes'] ) ? $node['nodes'] : array() as $child ) {
				if ( isset( $child['type'] ) && 'CAPTION' === $child['type'] ) {
					$caption = $this->extract_text( $child );
					break;
				}
			}
		}

		$attachment_id = $this->image_handler->import( $src, $this->post_id, $this->post_date );

		if ( is_wp_error( $attachment_id ) ) {
			// インポート失敗時は元 URL で img タグを生成
			$img = '<img src="' . esc_url( $src ) . '" alt="' . $alt . '"/>';
		} else {
			$img_url = wp_get_attachment_url( $attachment_id );
			$img     = '<img src="' . esc_url( $img_url ) . '" alt="' . $alt . '"'
				. ' class="wp-image-' . $attachment_id . '"/>';
		}

		$figure = '<figure class="wp-block-image">' . $img;
		if ( $caption ) {
			$figure .= '<figcaption class="wp-element-caption">' . esc_html( $caption ) . '</figcaption>';
		}
		$figure .= '</figure>';

		$attrs = is_wp_error( $attachment_id ) ? array() : array( 'id' => $attachment_id );
		return $this->wrap_block( 'core/image', $attrs, $figure );
	}

	private function convert_gallery( $node ) {
		$items = isset( $node['nodes'] ) ? $node['nodes'] : array();
		if ( empty( $items ) ) {
			return '';
		}

		$inner_blocks = array();
		$ids          = array();

		foreach ( $items as $item ) {
			$src = '';
			if ( ! empty( $item['imageData']['image']['src']['id'] ) ) {
				$src = 'https://static.wixstatic.com/media/' . $item['imageData']['image']['src']['id'];
			} elseif ( ! empty( $item['imageData']['image']['src']['url'] ) ) {
				$src = $item['imageData']['image']['src']['url'];
			}
			if ( ! $src ) {
				continue;
			}

			$alt           = '';
			$attachment_id = $this->image_handler->import( $src, $this->post_id, $this->post_date );

			if ( is_wp_error( $attachment_id ) ) {
				$img_url = $src;
				$img_tag = '<img src="' . esc_url( $img_url ) . '" alt="' . $alt . '"/>';
				$attrs   = array();
			} else {
				$img_url = wp_get_attachment_url( $attachment_id );
				$img_tag = '<img src="' . esc_url( $img_url ) . '" alt="' . $alt . '"'
					. ' class="wp-image-' . $attachment_id . '"/>';
				$attrs   = array( 'id' => $attachment_id );
				$ids[]   = $attachment_id;
			}

			$inner_blocks[] = $this->wrap_block(
				'core/image',
				$attrs,
				'<figure class="wp-block-gallery">' . $img_tag . '</figure>'
			);
		}

		if ( empty( $inner_blocks ) ) {
			return '';
		}

		$gallery_attrs = empty( $ids ) ? array() : array( 'ids' => $ids );
		$inner_html    = implode( "\n", $inner_blocks );

		return '<!-- wp:gallery ' . ( empty( $gallery_attrs ) ? '' : wp_json_encode( $gallery_attrs ) . ' ' )
			. "-->\n"
			. '<figure class="wp-block-gallery">' . "\n"
			. $inner_html . "\n"
			. '</figure>'
			. "\n<!-- /wp:gallery -->";
	}

	private function convert_video( $node ) {
		$url = isset( $node['data']['url'] ) ? $node['data']['url'] : '';
		if ( ! $url ) {
			return '';
		}
		$esc_url = esc_url( $url );
		return $this->wrap_block(
			'core/embed',
			array( 'url' => $url, 'type' => 'video' ),
			'<figure class="wp-block-embed is-type-video"><div class="wp-block-embed__wrapper">'
			. $esc_url
			. '</div></figure>'
		);
	}

	private function convert_divider() {
		return $this->wrap_block( 'core/separator', array(), '<hr class="wp-block-separator has-alpha-channel-opacity"/>' );
	}

	private function convert_blockquote( $node ) {
		$html = $this->nodes_to_html( $node );
		return $this->wrap_block(
			'core/quote',
			array(),
			'<blockquote class="wp-block-quote"><p>' . $html . '</p></blockquote>'
		);
	}

	private function convert_code( $node ) {
		$text = $this->extract_text( $node );
		return $this->wrap_block(
			'core/code',
			array(),
			'<pre class="wp-block-code"><code>' . esc_html( $text ) . '</code></pre>'
		);
	}

	private function convert_list( $node, $ordered ) {
		$tag   = $ordered ? 'ol' : 'ul';
		$items = isset( $node['nodes'] ) ? $node['nodes'] : array();
		$html  = '<' . $tag . '>';
		foreach ( $items as $item ) {
			$html .= '<li>' . $this->nodes_to_html( $item ) . '</li>';
		}
		$html .= '</' . $tag . '>';

		return $this->wrap_block(
			'core/list',
			array( 'ordered' => $ordered ),
			$html
		);
	}

	private function convert_link_preview( $node ) {
		$url = isset( $node['data']['url'] ) ? $node['data']['url'] : '';
		if ( ! $url ) {
			return '';
		}
		$esc_url = esc_url( $url );
		return $this->wrap_block(
			'core/embed',
			array( 'url' => $url ),
			'<figure class="wp-block-embed"><div class="wp-block-embed__wrapper">'
			. $esc_url
			. '</div></figure>'
		);
	}

	// -------------------------------------------------------------------------
	// ヘルパー
	// -------------------------------------------------------------------------

	/**
	 * ノードの子要素テキスト (インライン装飾を含む) を HTML に変換する。
	 *
	 * @param array $node
	 * @return string
	 */
	private function nodes_to_html( $node ) {
		$html  = '';
		$nodes = isset( $node['nodes'] ) ? $node['nodes'] : array();

		foreach ( $nodes as $child ) {
			$child_type = isset( $child['type'] ) ? $child['type'] : '';
			if ( 'TEXT' === $child_type && isset( $child['textData']['text'] ) ) {
				// TEXT ノード: textData.text にテキストが格納されている.
				$html .= $this->apply_decorations(
					$child['textData']['text'],
					$child['textData']
				);
			} elseif ( $child_type && 'TEXT' !== $child_type && 'CAPTION' !== $child_type ) {
				// ネストされたブロックノード (リストアイテムなど).
				$html .= $this->nodes_to_html( $child );
			}
		}

		return $html;
	}

	/**
	 * テキストにインライン装飾を適用する。
	 *
	 * @param string $text
	 * @param array  $text_data  Wix textData
	 * @return string
	 */
	private function apply_decorations( $text, $text_data ) {
		$text = esc_html( $text );
		$decorations = isset( $text_data['decorations'] ) ? $text_data['decorations'] : array();

		foreach ( $decorations as $dec ) {
			$type = isset( $dec['type'] ) ? $dec['type'] : '';
			switch ( $type ) {
				case 'BOLD':
					$text = '<strong>' . $text . '</strong>';
					break;
				case 'ITALIC':
					$text = '<em>' . $text . '</em>';
					break;
				case 'UNDERLINE':
					$text = '<u>' . $text . '</u>';
					break;
				case 'LINK':
					$href = isset( $dec['linkData']['link']['url'] ) ? $dec['linkData']['link']['url'] : '';
					if ( $href ) {
						$text = '<a href="' . esc_url( $href ) . '">' . $text . '</a>';
					}
					break;
				case 'COLOR':
					$color = isset( $dec['colorData']['foreground'] ) ? $dec['colorData']['foreground'] : '';
					if ( $color ) {
						$text = '<span style="color:' . esc_attr( $color ) . '">' . $text . '</span>';
					}
					break;
			}
		}

		return $text;
	}

	/**
	 * ノードからプレーンテキストを再帰的に抽出する。
	 *
	 * @param array $node
	 * @return string
	 */
	private function extract_text( $node ) {
		$text  = '';
		$nodes = isset( $node['nodes'] ) ? $node['nodes'] : array();
		foreach ( $nodes as $child ) {
			if ( isset( $child['textData']['text'] ) ) {
				$text .= $child['textData']['text'];
			} elseif ( isset( $child['nodes'] ) ) {
				$text .= $this->extract_text( $child );
			}
		}
		return $text;
	}

	/**
	 * ブロックコメントで囲む。
	 *
	 * @param string $block_name  例: 'core/paragraph'
	 * @param array  $attrs       ブロック属性
	 * @param string $inner_html  ブロック内 HTML
	 * @return string
	 */
	private function wrap_block( $block_name, $attrs, $inner_html ) {
		$attrs_str = empty( $attrs ) ? '' : ' ' . wp_json_encode( $attrs );
		return "<!-- wp:{$block_name}{$attrs_str} -->\n"
			. $inner_html
			. "\n<!-- /wp:{$block_name} -->";
	}
}
