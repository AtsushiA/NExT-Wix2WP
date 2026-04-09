<?php
/**
 * Wix Blog API / RSS クライアント
 *
 * @package NExT_Wix2WP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wix Blog API および RSS フィードから記事を取得するクライアントクラス。
 *
 * 記事取得の優先順位:
 *   1. Wix blog-frontend-adapter API (instance トークンが必要)
 *   2. RSS フィード (トークン不要、最新 ~20件のみ)
 *
 * instance トークンの取得方法:
 *   ブラウザの DevTools → Network タブ → Wix ブログページを開く →
 *   /_api/blog-frontend-adapter-public/v2/post-feed-page へのリクエストを探す →
 *   Request Headers の "Authorization: XXXXX" の XXXXX 部分をコピーする。
 */
class NExT_Wix2WP_API {

	/**
	 * ブログのベース URL (例: https://example.com).
	 *
	 * @var string
	 */
	private $base_url;

	/**
	 * ブログページのフル URL (例: https://example.com/blog-1).
	 *
	 * @var string
	 */
	private $blog_url;

	/**
	 * 1 リクエストあたりの取得件数.
	 *
	 * @var int
	 */
	private $page_size;

	/**
	 * Instance トークン（空文字の場合は RSS フォールバック）.
	 *
	 * @var string
	 */
	private $instance;

	/**
	 * カテゴリー ID → {id, label, slug} のマップ。
	 *
	 * @var array
	 */
	private $categories_map = array();

	/**
	 * コンストラクタ。
	 *
	 * @param string $blog_url  Wix ブログの URL (例: https://example.com/blog-1).
	 * @param int    $page_size ページサイズ (デフォルト 20、最大 100).
	 * @param string $instance  Instance トークン（省略時は RSS にフォールバック）.
	 */
	public function __construct( $blog_url, $page_size = 20, $instance = '' ) {
		$parsed          = wp_parse_url( $blog_url );
		$this->base_url  = $parsed['scheme'] . '://' . $parsed['host'];
		$this->blog_url  = $blog_url;
		$this->page_size = min( (int) $page_size, 100 );
		$this->instance  = $instance;
	}

	/**
	 * 全記事を取得する。
	 *
	 * Instance トークンがある場合は内部 API を使用する。
	 * ない場合は RSS フィードにフォールバックする。
	 *
	 * @param int $limit 取得上限 (0 = 全件).
	 * @return array[]|WP_Error 記事データの配列、またはエラー.
	 */
	public function get_all_posts( $limit = 0 ) {
		if ( $this->instance ) {
			return $this->get_posts_via_api( $limit );
		}

		return $this->get_posts_via_rss( $limit );
	}

	// -------------------------------------------------------------------------
	// 内部 API.
	// -------------------------------------------------------------------------

	/**
	 * Wix 内部 API で全記事を取得する。
	 *
	 * @param int $limit 取得上限 (0 = 全件).
	 * @return array[]|WP_Error
	 */
	private function get_posts_via_api( $limit ) {
		// まずブログ HTML からカテゴリーマップを構築する.
		$this->build_categories_map();

		$posts    = array();
		$page_num = 1;
		$total    = null;

		do {
			$result = $this->fetch_posts_api( $page_num );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			if ( null === $total ) {
				$total = isset( $result['total'] ) ? (int) $result['total'] : 0;
			}

			$batch   = isset( $result['posts'] ) ? $result['posts'] : array();
			$posts   = array_merge( $posts, $batch );
			$fetched = count( $posts );
			++$page_num;

			usleep( 200000 ); // 200ms インターバル.

			if ( $limit > 0 && $fetched >= $limit ) {
				$posts = array_slice( $posts, 0, $limit );
				break;
			}

			if ( count( $batch ) < $this->page_size ) {
				break;
			}
		} while ( $total > 0 && $fetched < $total );

		return $posts;
	}

	/**
	 * 指定ページ番号で API から記事を取得し正規化して返す。
	 *
	 * @param int $page_num 取得ページ番号 (1始まり).
	 * @return array|WP_Error { 'posts' => array[], 'total' => int }
	 */
	private function fetch_posts_api( $page_num ) {
		$url = add_query_arg(
			array(
				'includeContent' => 'true',
				'languageCode'   => 'ja',
				'page'           => $page_num,
				'pageSize'       => $this->page_size,
				'type'           => 'ALL_POSTS',
			),
			$this->base_url . '/_api/blog-frontend-adapter-public/v2/post-feed-page'
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 30,
				'headers' => array(
					'Accept'          => 'application/json',
					'Accept-Language' => 'ja',
					'Authorization'   => $this->instance,
					'User-Agent'      => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
					'Referer'         => $this->blog_url,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error(
				'wix_api_error',
				sprintf( 'Wix API がステータスコード %d を返しました。', $code )
			);
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'wix_api_parse_error', 'API レスポンスの JSON パースに失敗しました。' );
		}

		// レスポンスの階層: postFeedPage > posts > posts (配列) と pagingMetaData > total.
		$feed        = isset( $data['postFeedPage'] ) ? $data['postFeedPage'] : array();
		$posts_wrap  = isset( $feed['posts'] ) ? $feed['posts'] : array();
		$raw_posts   = isset( $posts_wrap['posts'] ) ? $posts_wrap['posts'] : array();
		$paging_meta = isset( $posts_wrap['pagingMetaData'] ) ? $posts_wrap['pagingMetaData'] : array();
		$total       = isset( $paging_meta['total'] ) ? (int) $paging_meta['total'] : 0;

		$posts = array();
		foreach ( $raw_posts as $raw ) {
			$posts[] = $this->normalize_api_post( $raw );
		}

		return array(
			'posts' => $posts,
			'total' => $total,
		);
	}

	/**
	 * API レスポンスの生記事データをインポーター互換の形式に変換する。
	 *
	 * @param array $raw API からの生記事データ.
	 * @return array
	 */
	private function normalize_api_post( $raw ) {
		// カバー画像 URL を media フィールドから取得する.
		$cover_image = '';
		$media       = isset( $raw['media'] ) ? $raw['media'] : array();
		if ( ! empty( $media['wixMedia']['image']['url'] ) ) {
			$cover_image = $raw['media']['wixMedia']['image']['url'];
		}

		// categoryIds をカテゴリー情報に変換する.
		$categories   = array();
		$category_ids = isset( $raw['categoryIds'] ) ? $raw['categoryIds'] : array();
		foreach ( $category_ids as $cat_id ) {
			if ( isset( $this->categories_map[ $cat_id ] ) ) {
				$categories[] = $this->categories_map[ $cat_id ];
			} else {
				// マップにない場合は ID のみで登録する.
				$categories[] = array(
					'id'    => $cat_id,
					'label' => $cat_id,
					'slug'  => $cat_id,
				);
			}
		}

		return array(
			'id'                 => isset( $raw['id'] ) ? $raw['id'] : '',
			'title'              => isset( $raw['title'] ) ? $raw['title'] : '',
			'slug'               => isset( $raw['slug'] ) ? $raw['slug'] : '',
			'excerpt'            => isset( $raw['excerpt'] ) ? $raw['excerpt'] : '',
			'firstPublishedDate' => isset( $raw['firstPublishedDate'] ) ? $raw['firstPublishedDate'] : '',
			'lastPublishedDate'  => isset( $raw['lastPublishedDate'] ) ? $raw['lastPublishedDate'] : '',
			'coverImage'         => $cover_image,
			'richContent'        => isset( $raw['richContent'] ) ? $raw['richContent'] : array(),
			'htmlContent'        => '',
			'categories'         => $categories,
			'owner'              => isset( $raw['owner'] ) ? $raw['owner'] : array(),
		);
	}

	// -------------------------------------------------------------------------
	// カテゴリーマップ構築.
	// -------------------------------------------------------------------------

	/**
	 * ブログページの HTML からカテゴリー情報を抽出し $this->categories_map に格納する。
	 *
	 * Wix Thunderbolt は初期 HTML にカテゴリーデータを埋め込んでいる。
	 * 取得できない場合は空のまま続行する（カテゴリー未設定として扱う）。
	 */
	private function build_categories_map() {
		if ( ! empty( $this->categories_map ) ) {
			return;
		}

		$response = wp_remote_get(
			$this->blog_url,
			array(
				'timeout' => 30,
				'headers' => array(
					'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
					'Accept'     => 'text/html,application/xhtml+xml',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return;
		}

		$html = wp_remote_retrieve_body( $response );
		$cats = $this->parse_categories_from_html( $html );

		foreach ( $cats as $cat ) {
			$this->categories_map[ $cat['id'] ] = $cat;
		}
	}

	/**
	 * Wix Thunderbolt HTML の中に埋め込まれたカテゴリー JSON を抽出する。
	 *
	 * @param string $html ページの HTML 文字列.
	 * @return array[] { id, label, slug } の配列.
	 */
	private function parse_categories_from_html( $html ) {
		// Wix の初期データは二重エスケープされた JSON として埋め込まれている.
		// 検索パターンは二重エスケープ済みの categories 配列の先頭部分.
		$start_needle = '\\"categories\\":[{\\"id\\":\\"';
		$start_idx    = strpos( $html, $start_needle );

		if ( false === $start_idx ) {
			return array();
		}

		// カテゴリー配列の開始 '[' を見つける.
		$array_start = strpos( $html, '[', $start_idx + strlen( '\\"categories\\":' ) );
		if ( false === $array_start ) {
			return array();
		}

		// 対応する ']' を探す（ネストを考慮）.
		$depth   = 0;
		$pos     = $array_start;
		$end_idx = false;
		$len     = strlen( $html );

		while ( $pos < $len ) {
			$ch = $html[ $pos ];
			if ( '[' === $ch ) {
				++$depth;
			} elseif ( ']' === $ch ) {
				--$depth;
				if ( 0 === $depth ) {
					$end_idx = $pos;
					break;
				}
			}
			++$pos;
		}

		if ( false === $end_idx ) {
			return array();
		}

		// 二重エスケープを解除して JSON をパースする.
		$raw       = substr( $html, $array_start, $end_idx - $array_start + 1 );
		$unescaped = str_replace( array( '\\"', '\\/' ), array( '"', '/' ), $raw );
		$decoded   = json_decode( $unescaped, true );

		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$cats = array();
		foreach ( $decoded as $item ) {
			if ( empty( $item['id'] ) || empty( $item['label'] ) ) {
				continue;
			}
			$cats[] = array(
				'id'    => $item['id'],
				'label' => $item['label'],
				'slug'  => isset( $item['slug'] ) ? $item['slug'] : sanitize_title( $item['label'] ),
			);
		}

		return $cats;
	}

	// -------------------------------------------------------------------------
	// RSS フォールバック.
	// -------------------------------------------------------------------------

	/**
	 * RSS フィードから記事を取得する。
	 *
	 * Wix ブログの RSS は通常最新 20 件のみ返す。
	 * 全件が必要な場合は --instance オプションで内部 API を使用すること。
	 *
	 * @param int $limit 取得上限 (0 = 全件).
	 * @return array[]|WP_Error
	 */
	private function get_posts_via_rss( $limit ) {
		$feed_url = $this->resolve_rss_url();
		if ( is_wp_error( $feed_url ) ) {
			return $feed_url;
		}

		$response = wp_remote_get(
			$feed_url,
			array(
				'timeout' => 30,
				'headers' => array(
					'Accept'     => 'application/rss+xml, application/xml, text/xml',
					'User-Agent' => 'Mozilla/5.0 (compatible; RSS reader)',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error(
				'rss_fetch_error',
				sprintf(
					'RSS フィードの取得に失敗しました (HTTP %d)。--instance オプションで instance トークンを指定してください。',
					$code
				)
			);
		}

		$xml = simplexml_load_string( wp_remote_retrieve_body( $response ) );
		if ( false === $xml ) {
			return new WP_Error( 'rss_parse_error', 'RSS XML のパースに失敗しました。' );
		}

		$posts = $this->parse_rss( $xml );

		if ( $limit > 0 ) {
			$posts = array_slice( $posts, 0, $limit );
		}

		return $posts;
	}

	/**
	 * RSS フィード URL を返す。
	 *
	 * まずブログページの HTML から <link rel="alternate" type="application/rss+xml"> を探す。
	 * 見つからない場合は既知の URL パターンを順に試す。
	 *
	 * @return string|WP_Error
	 */
	private function resolve_rss_url() {
		// HTML の link タグから RSS URL を取得する.
		$from_html = $this->find_rss_url_in_html();
		if ( $from_html ) {
			return $from_html;
		}

		// フォールバック: よくある URL パターンを順に試す.
		$path       = wp_parse_url( $this->blog_url, PHP_URL_PATH );
		$path       = rtrim( $path, '/' );
		$candidates = array(
			$this->base_url . $path . '/feed',
			$this->base_url . $path . '/rss.xml',
			$this->base_url . '/blog/feed',
			$this->base_url . '/blog-feed.xml',
			$this->base_url . '/feed',
		);

		foreach ( $candidates as $url ) {
			$resp = wp_remote_head( $url, array( 'timeout' => 10 ) );
			if ( ! is_wp_error( $resp ) && 200 === wp_remote_retrieve_response_code( $resp ) ) {
				return $url;
			}
		}

		return new WP_Error(
			'rss_not_found',
			'RSS フィードが見つかりませんでした。--instance オプションで instance トークンを指定してください。'
		);
	}

	/**
	 * ブログページの HTML から RSS フィード URL を抽出する。
	 *
	 * @return string RSS フィード URL。見つからない場合は空文字.
	 */
	private function find_rss_url_in_html() {
		$response = wp_remote_get(
			$this->blog_url,
			array(
				'timeout' => 30,
				'headers' => array(
					'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return '';
		}

		$body = wp_remote_retrieve_body( $response );

		// <link rel="alternate" type="application/rss+xml" href="..."> を検索する.
		if ( preg_match(
			'/<link[^>]+type=["\']application\/rss\+xml["\'][^>]+href=["\']([^"\']+)["\'][^>]*>/i',
			$body,
			$m
		) ) {
			return $m[1];
		}

		if ( preg_match(
			'/<link[^>]+href=["\']([^"\']+)["\'][^>]+type=["\']application\/rss\+xml["\'][^>]*>/i',
			$body,
			$m
		) ) {
			return $m[1];
		}

		return '';
	}

	/**
	 * SimpleXMLElement を Wix API 互換の記事配列に変換する。
	 *
	 * @param SimpleXMLElement $xml RSS の SimpleXMLElement オブジェクト.
	 * @return array[]
	 */
	private function parse_rss( $xml ) {
		$posts = array();

		$channel = isset( $xml->channel ) ? $xml->channel : $xml;
		$items   = isset( $channel->item ) ? $channel->item : array();

		foreach ( $items as $item ) {
			$ns_content = $item->children( 'http://purl.org/rss/1.0/modules/content/' );

			$title    = (string) $item->title;
			$pub_date = (string) $item->pubDate; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$link     = (string) $item->link;
			$guid     = (string) $item->guid;
			$excerpt  = (string) $item->description;

			// 本文は content:encoded を優先し、なければ description を使う.
			$html_content = '';
			if ( isset( $ns_content->encoded ) ) {
				$html_content = (string) $ns_content->encoded;
			}
			if ( ! $html_content ) {
				$html_content = $excerpt;
			}

			// スラッグを URL から抽出する.
			$slug = basename( rtrim( wp_parse_url( $link, PHP_URL_PATH ), '/' ) );

			// カテゴリーを取得する.
			$categories = array();
			foreach ( $item->category as $cat ) {
				$label        = (string) $cat;
				$categories[] = array(
					'id'    => sanitize_title( $label ),
					'label' => $label,
					'slug'  => sanitize_title( $label ),
				);
			}

			// 公開日を ISO 8601 形式に変換する.
			$iso_date = $pub_date ? gmdate( 'Y-m-d\TH:i:s.000\Z', strtotime( $pub_date ) ) : '';

			$guid_value = $guid ? $guid : $link;

			$posts[] = array(
				'id'                 => $guid_value,
				'title'              => $title,
				'slug'               => $slug,
				'firstPublishedDate' => $iso_date,
				'lastPublishedDate'  => $iso_date,
				'excerpt'            => wp_strip_all_tags( $excerpt ),
				'coverImage'         => $this->extract_first_image( $html_content ),
				'richContent'        => array(),
				'htmlContent'        => $html_content,
				'categories'         => $categories,
				'owner'              => array( 'name' => '' ),
				'_source'            => 'rss',
			);
		}

		return $posts;
	}

	/**
	 * HTML 文字列から最初の img タグの src を抽出する。
	 *
	 * @param string $html HTML 文字列.
	 * @return string 画像 URL。見つからない場合は空文字.
	 */
	private function extract_first_image( $html ) {
		if ( preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/', $html, $m ) ) {
			return $m[1];
		}
		return '';
	}
}
