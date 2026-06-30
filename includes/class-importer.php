<?php
/**
 * 投稿インポート処理クラスを定義するファイル。
 *
 * @package NExT_Wix2WP
 */

defined( 'ABSPATH' ) || exit;

/**
 * 投稿インポート処理
 *
 * Wix 記事データを受け取り、WordPress 投稿として保存する。
 * カテゴリー、アイキャッチ画像、本文ブロック変換を含む。
 */
class NExT_Wix2WP_Importer {

	/** @var NExT_Wix2WP_Image */
	private $image_handler;

	/** @var NExT_Wix2WP_Converter */
	private $converter;

	/** @var array インポート結果集計 */
	private $stats = array(
		'imported' => 0,
		'updated'  => 0,
		'skipped'  => 0,
		'errors'   => 0,
	);

	/**
	 * @param NExT_Wix2WP_Image     $image_handler
	 * @param NExT_Wix2WP_Converter $converter
	 */
	public function __construct(
		NExT_Wix2WP_Image $image_handler,
		NExT_Wix2WP_Converter $converter
	) {
		$this->image_handler = $image_handler;
		$this->converter     = $converter;
	}

	/**
	 * 1件の Wix 記事を WordPress にインポートする。
	 *
	 * @param array $wix_post  Wix API レスポンスの記事データ
	 * @param array $options   インポートオプション
	 *   - force       bool   既存投稿を上書きするか
	 *   - skip_images bool   画像インポートをスキップするか
	 *   - post_status string 投稿ステータス
	 *   - author      int    投稿者 ID
	 *   - dry_run     bool   ドライランかどうか
	 * @return array { 'result' => 'imported'|'updated'|'skipped'|'error', 'post_id' => int, 'message' => string }
	 */
	public function import_post( $wix_post, $options = array() ) {
		$options = wp_parse_args(
			$options,
			array(
				'force'       => false,
				'skip_images' => false,
				'post_status' => 'publish',
				'author'      => 1,
				'dry_run'     => false,
			)
		);

		$wix_id     = isset( $wix_post['id'] ) ? $wix_post['id'] : '';
		$title      = isset( $wix_post['title'] ) ? $wix_post['title'] : '(無題)';
		$slug       = isset( $wix_post['slug'] ) ? $wix_post['slug'] : '';
		$excerpt    = isset( $wix_post['excerpt'] ) ? $wix_post['excerpt'] : '';
		$pub_date   = isset( $wix_post['firstPublishedDate'] ) ? $wix_post['firstPublishedDate'] : '';
		$mod_date   = isset( $wix_post['lastPublishedDate'] ) ? $wix_post['lastPublishedDate'] : $pub_date;
		$cover_img  = isset( $wix_post['coverImage'] ) ? $wix_post['coverImage'] : '';
		$rich       = isset( $wix_post['richContent'] ) ? $wix_post['richContent'] : array();
		$html       = isset( $wix_post['htmlContent'] ) ? $wix_post['htmlContent'] : '';
		$is_rss     = isset( $wix_post['_source'] ) && 'rss' === $wix_post['_source'];
		$categories = isset( $wix_post['categories'] ) ? $wix_post['categories'] : array();

		// 日付を WP 形式 (Y-m-d H:i:s / JST) に変換
		$post_date     = $this->convert_date( $pub_date );
		$post_date_gmt = $this->to_gmt( $pub_date );
		$post_modified = $this->convert_date( $mod_date );
		$post_mod_gmt  = $this->to_gmt( $mod_date );

		// 重複チェック
		$existing_id = $this->find_existing( $wix_id, $slug );
		if ( $existing_id && ! $options['force'] ) {
			++$this->stats['skipped'];
			return array(
				'result'  => 'skipped',
				'post_id' => $existing_id,
				'message' => 'スキップ（既存）',
			);
		}

		// ドライランは処理内容を返すだけ
		if ( $options['dry_run'] ) {
			$action = $existing_id ? '更新予定' : 'インポート予定';
			return array(
				'result'  => 'dry_run',
				'post_id' => $existing_id ? $existing_id : 0,
				'message' => "[DRY RUN] {$action}: {$title} ({$post_date})",
			);
		}

		// 本文変換
		// RSS ソースは HTML をそのまま core/html ブロックに格納する.
		$post_content = '';
		if ( $is_rss && $html ) {
			$post_content = "<!-- wp:html -->\n" . $html . "\n<!-- /wp:html -->";
		} elseif ( ! empty( $rich ) ) {
			$post_content = $this->converter->convert( $rich, (int) $existing_id, $post_date );
		}

		// 投稿データ組み立て
		$post_data = array(
			'post_title'        => $title,
			'post_name'         => $slug,
			'post_content'      => $post_content,
			'post_excerpt'      => $excerpt,
			'post_status'       => $options['post_status'],
			'post_author'       => (int) $options['author'],
			'post_date'         => $post_date,
			'post_date_gmt'     => $post_date_gmt,
			'post_modified'     => $post_modified,
			'post_modified_gmt' => $post_mod_gmt,
			'post_type'         => 'post',
		);

		if ( $existing_id ) {
			$post_data['ID'] = $existing_id;
			$post_id         = wp_update_post( $post_data, true );
		} else {
			$post_id = wp_insert_post( $post_data, true );
		}

		if ( is_wp_error( $post_id ) ) {
			++$this->stats['errors'];
			return array(
				'result'  => 'error',
				'post_id' => 0,
				'message' => $post_id->get_error_message(),
			);
		}

		// カスタムフィールド保存
		update_post_meta( $post_id, '_wix_post_id', $wix_id );
		update_post_meta( $post_id, '_wix_source_url', $slug );

		// カテゴリー紐付け
		if ( ! empty( $categories ) ) {
			$this->assign_categories( $post_id, $categories );
		}

		// アイキャッチ画像
		if ( ! $options['skip_images'] && $cover_img ) {
			$thumbnail_id = $this->image_handler->import( $cover_img, $post_id, $post_date );
			if ( ! is_wp_error( $thumbnail_id ) ) {
				set_post_thumbnail( $post_id, $thumbnail_id );
			}
		}

		// 本文内の画像は converter で処理済みだが、post_id が確定してから再保存が必要な場合はここで対応
		if ( ! $options['skip_images'] && ! empty( $rich ) ) {
			$post_content = $this->converter->convert( $rich, $post_id, $post_date );
			wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => $post_content,
				)
			);
		}

		if ( $existing_id ) {
			++$this->stats['updated'];
			$result = 'updated';
		} else {
			++$this->stats['imported'];
			$result = 'imported';
		}

		return array(
			'result'  => $result,
			'post_id' => $post_id,
			'message' => '',
		);
	}

	/**
	 * 集計結果を返す。
	 *
	 * @return array
	 */
	public function get_stats() {
		return $this->stats;
	}

	// -------------------------------------------------------------------------
	// カテゴリー処理
	// -------------------------------------------------------------------------

	/**
	 * Wix カテゴリーを WordPress カテゴリーに変換して投稿に紐付ける。
	 *
	 * @param int   $post_id
	 * @param array $categories Wix categories 配列
	 */
	private function assign_categories( $post_id, $categories ) {
		$term_ids = array();

		foreach ( $categories as $cat ) {
			$wix_cat_id = isset( $cat['id'] ) ? $cat['id'] : '';
			$label      = isset( $cat['label'] ) ? $cat['label'] : '';
			$cat_slug   = isset( $cat['slug'] ) ? $cat['slug'] : '';

			if ( ! $label ) {
				continue;
			}

			$term_id = $this->get_or_create_category( $wix_cat_id, $label, $cat_slug );
			if ( $term_id ) {
				$term_ids[] = $term_id;
			}
		}

		if ( ! empty( $term_ids ) ) {
			wp_set_post_categories( $post_id, $term_ids );
		}
	}

	/**
	 * _wix_category_id メタで既存カテゴリーを検索し、なければ作成して term_id を返す。
	 *
	 * @param string $wix_cat_id
	 * @param string $label
	 * @param string $slug
	 * @return int|false
	 */
	private function get_or_create_category( $wix_cat_id, $label, $slug ) {
		// 既存カテゴリーを Wix ID で検索
		if ( $wix_cat_id ) {
			$terms = get_terms(
				array(
					'taxonomy'   => 'category',
					'hide_empty' => false,
					'meta_query' => array(
						array(
							'key'   => '_wix_category_id',
							'value' => $wix_cat_id,
						),
					),
				)
			);

			if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
				return (int) $terms[0]->term_id;
			}
		}

		// 同名カテゴリーが存在すれば流用
		$existing = get_term_by( 'name', $label, 'category' );
		if ( $existing ) {
			if ( $wix_cat_id ) {
				add_term_meta( $existing->term_id, '_wix_category_id', $wix_cat_id, true );
			}
			return (int) $existing->term_id;
		}

		// 新規カテゴリー作成
		$args   = $slug ? array( 'slug' => $slug ) : array();
		$result = wp_insert_term( $label, 'category', $args );

		if ( is_wp_error( $result ) ) {
			return false;
		}

		$term_id = (int) $result['term_id'];
		if ( $wix_cat_id ) {
			add_term_meta( $term_id, '_wix_category_id', $wix_cat_id, true );
		}

		return $term_id;
	}

	// -------------------------------------------------------------------------
	// 日付変換
	// -------------------------------------------------------------------------

	/**
	 * ISO 8601 (UTC) を WordPress ローカル時間 (Y-m-d H:i:s) に変換する。
	 *
	 * @param string $iso_date
	 * @return string
	 */
	private function convert_date( $iso_date ) {
		if ( ! $iso_date ) {
			return current_time( 'mysql' );
		}
		$timestamp = strtotime( $iso_date );
		if ( false === $timestamp ) {
			return current_time( 'mysql' );
		}
		return get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $timestamp ) );
	}

	/**
	 * ISO 8601 を UTC の Y-m-d H:i:s に変換する。
	 *
	 * @param string $iso_date
	 * @return string
	 */
	private function to_gmt( $iso_date ) {
		if ( ! $iso_date ) {
			return current_time( 'mysql', true );
		}
		$timestamp = strtotime( $iso_date );
		if ( false === $timestamp ) {
			return current_time( 'mysql', true );
		}
		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	// -------------------------------------------------------------------------
	// 重複チェック
	// -------------------------------------------------------------------------

	/**
	 * 既存投稿を検索する。_wix_post_id メタで検索し、見つからなければスラッグで検索する。
	 *
	 * @param string $wix_id
	 * @param string $slug
	 * @return int|false
	 */
	private function find_existing( $wix_id, $slug = '' ) {
		if ( $wix_id ) {
			$posts = get_posts(
				array(
					'post_type'      => 'post',
					'post_status'    => 'any',
					'meta_key'       => '_wix_post_id',
					'meta_value'     => $wix_id,
					'posts_per_page' => 1,
					'fields'         => 'ids',
				)
			);

			if ( ! empty( $posts ) ) {
				return (int) $posts[0];
			}
		}

		// RSS インポート済み投稿との重複を防ぐためスラッグでも検索する。
		if ( $slug ) {
			$posts = get_posts(
				array(
					'name'           => $slug,
					'post_type'      => 'post',
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'fields'         => 'ids',
				)
			);

			if ( ! empty( $posts ) ) {
				return (int) $posts[0];
			}
		}

		return false;
	}
}
