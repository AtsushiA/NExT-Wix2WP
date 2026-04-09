<?php
/**
 * WP-CLI コマンドクラス
 *
 * @package NExT_Wix2WP
 */

defined( 'ABSPATH' ) || exit;

/**
 * WP-CLI コマンド定義
 *
 * 使い方:
 *   wp wix2wp import --wix-url=https://example.com/blog-1
 *
 * 注意: WP-CLI のグローバルパラメータ --url と衝突するため --wix-url を使用する。
 */
class NExT_Wix2WP_CLI_Command {

	/**
	 * Wix ブログを WordPress にインポートする。
	 *
	 * ## OPTIONS
	 *
	 * --wix-url=<url>
	 * : Wix ブログの URL (例: https://example.com/blog-1)
	 *
	 * [--limit=<number>]
	 * : インポートする最大記事数 (0 = 全件)
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--dry-run]
	 * : 実際にはインポートせず、処理内容を表示のみ
	 *
	 * [--force]
	 * : インポート済みの記事を上書き更新する
	 *
	 * [--skip-images]
	 * : 画像のダウンロード・インポートをスキップする
	 *
	 * [--post-status=<status>]
	 * : インポート後の投稿ステータス
	 * ---
	 * default: publish
	 * options:
	 *   - publish
	 *   - draft
	 *   - private
	 * ---
	 *
	 * [--author=<user_id>]
	 * : 投稿者のユーザー ID
	 * ---
	 * default: 1
	 * ---
	 *
	 * [--instance=<token>]
	 * : Wix 内部 API の instance トークン。省略時は管理画面 [設定 → Wix2WP] の保存済みトークンを使用。
	 * どちらも未設定の場合は RSS フィードにフォールバック (最新 ~20 件のみ)。
	 * 取得方法: ブラウザの DevTools → Network → post-feed-page → Request Headers の "Authorization: XXXXX"。
	 * ---
	 * default:
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *   # RSS フィードで最新記事をインポート (instance 不要)
	 *   wp wix2wp import --wix-url=https://example.com/blog-1
	 *
	 *   # instance トークンを指定して全記事をインポート
	 *   wp wix2wp import --wix-url=https://example.com/blog-1 --instance=XXXXX
	 *
	 *   # 最初の 5 件だけドライラン
	 *   wp wix2wp import --wix-url=https://example.com/blog-1 --instance=XXXXX --limit=5 --dry-run
	 *
	 *   # 画像スキップして下書きとしてインポート
	 *   wp wix2wp import --wix-url=https://example.com/blog-1 --skip-images --post-status=draft
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       位置引数.
	 * @param array $assoc_args キー付き引数.
	 */
	public function import( $args, $assoc_args ) {
		$url         = WP_CLI\Utils\get_flag_value( $assoc_args, 'wix-url', '' );
		$limit       = (int) WP_CLI\Utils\get_flag_value( $assoc_args, 'limit', 0 );
		$dry_run     = WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$force       = WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false );
		$skip_images = WP_CLI\Utils\get_flag_value( $assoc_args, 'skip-images', false );
		$post_status = WP_CLI\Utils\get_flag_value( $assoc_args, 'post-status', 'publish' );
		$author      = (int) WP_CLI\Utils\get_flag_value( $assoc_args, 'author', 1 );
		$instance    = WP_CLI\Utils\get_flag_value( $assoc_args, 'instance', '' );

		if ( ! $url ) {
			WP_CLI::error( '--wix-url は必須です。例: --wix-url=https://www.example.com/blog-1' );
		}

		if ( $dry_run ) {
			WP_CLI::line( '[DRY RUN モード] 実際のインポートは行いません。' );
		}

		if ( ! $instance ) {
			$instance = NExT_Wix2WP_Admin::get_token( $url );
			if ( $instance ) {
				WP_CLI::line( '管理画面の保存済みトークンを使用します。' );
			}
		}

		if ( $instance ) {
			WP_CLI::line( '内部 API モード: instance トークンを使用します。' );
		} else {
			WP_CLI::warning( 'instance トークンが未指定のため RSS フィードにフォールバックします（最新 ~20 件のみ）。全件取得には --instance を指定するか、管理画面 [設定 → Wix2WP] でトークンを登録してください。' );
		}

		// タイムアウト無効化 (大量インポート対策).
		set_time_limit( 0 );

		// ---- 1. 記事一覧取得 ------------------------------------------------
		WP_CLI::line( '記事を取得しています...' );

		$api   = new NExT_Wix2WP_API( $url, 20, $instance );
		$posts = $api->get_all_posts( $limit );

		if ( is_wp_error( $posts ) ) {
			WP_CLI::error( '記事の取得に失敗しました: ' . $posts->get_error_message() );
		}

		$total = count( $posts );
		WP_CLI::line( sprintf( '%d 件の記事が見つかりました。', $total ) );

		if ( 0 === $total ) {
			WP_CLI::success( 'インポートする記事がありません。' );
			return;
		}

		// ---- 2. インポート処理 -----------------------------------------------
		$image_handler = new NExT_Wix2WP_Image();
		$converter     = new NExT_Wix2WP_Converter( $image_handler );
		$importer      = new NExT_Wix2WP_Importer( $image_handler, $converter );

		$options = array(
			'force'       => (bool) $force,
			'skip_images' => (bool) $skip_images,
			'post_status' => $post_status,
			'author'      => $author,
			'dry_run'     => (bool) $dry_run,
		);

		$progress = WP_CLI\Utils\make_progress_bar( 'インポート中', $total );

		foreach ( $posts as $i => $wix_post ) {
			$title = isset( $wix_post['title'] ) ? $wix_post['title'] : '(無題)';
			$date  = isset( $wix_post['firstPublishedDate'] )
				? substr( $wix_post['firstPublishedDate'], 0, 10 )
				: '';

			$result = $importer->import_post( $wix_post, $options );

			switch ( $result['result'] ) {
				case 'imported':
					WP_CLI::debug( sprintf( '[%d/%d] インポート完了: "%s" (%s) → post_id=%d', $i + 1, $total, $title, $date, $result['post_id'] ) );
					break;
				case 'updated':
					WP_CLI::debug( sprintf( '[%d/%d] 更新完了: "%s" (%s) → post_id=%d', $i + 1, $total, $title, $date, $result['post_id'] ) );
					break;
				case 'skipped':
					WP_CLI::debug( sprintf( '[%d/%d] スキップ: "%s"', $i + 1, $total, $title ) );
					break;
				case 'error':
					WP_CLI::warning( sprintf( '[%d/%d] エラー: "%s" — %s', $i + 1, $total, $title, $result['message'] ) );
					break;
				case 'dry_run':
					WP_CLI::line( $result['message'] );
					break;
			}

			$progress->tick();
		}

		$progress->finish();

		// ---- 3. サマリー表示 ------------------------------------------------
		$stats = $importer->get_stats();

		WP_CLI::line( '' );
		WP_CLI::line( '===== インポート完了 =====' );
		WP_CLI::line( sprintf( '  インポート: %d', $stats['imported'] ) );
		WP_CLI::line( sprintf( '  更新:       %d', $stats['updated'] ) );
		WP_CLI::line( sprintf( '  スキップ:   %d', $stats['skipped'] ) );
		WP_CLI::line( sprintf( '  エラー:     %d', $stats['errors'] ) );

		if ( $stats['errors'] > 0 ) {
			WP_CLI::warning( 'エラーが発生した記事があります。--debug フラグで詳細を確認してください。' );
		} else {
			WP_CLI::success( 'すべての処理が完了しました。' );
		}
	}

	/**
	 * Wix ブログの instance トークンを取得して表示する。
	 *
	 * ページの HTML から自動抽出を試みる。
	 * 抽出できない場合はブラウザ DevTools を使った取得手順を表示する。
	 *
	 * ## OPTIONS
	 *
	 * --wix-url=<url>
	 * : Wix ブログの URL (例: https://example.com/blog-1)
	 *
	 * ## EXAMPLES
	 *
	 *   wp wix2wp token --wix-url=https://example.com/blog-1
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       位置引数.
	 * @param array $assoc_args キー付き引数.
	 */
	public function token( $args, $assoc_args ) {
		$url = WP_CLI\Utils\get_flag_value( $assoc_args, 'wix-url', '' );

		if ( ! $url ) {
			WP_CLI::error( '--wix-url は必須です。' );
		}

		WP_CLI::line( 'ページを取得しています: ' . $url );

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 30,
				'headers' => array(
					'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
					'Accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_CLI::error( 'ページ取得失敗: ' . $response->get_error_message() );
		}

		$body  = wp_remote_retrieve_body( $response );
		$token = $this->extract_instance_token( $body );

		if ( $token ) {
			WP_CLI::success( 'instance トークンが見つかりました。' );
			WP_CLI::line( '' );
			WP_CLI::line( $token );
			WP_CLI::line( '' );
			WP_CLI::line( '以下のコマンドでインポートできます:' );
			WP_CLI::line( "  wp wix2wp import --wix-url={$url} --instance={$token}" );
		} else {
			WP_CLI::warning( 'HTML からの自動抽出に失敗しました。' );
			WP_CLI::line( '' );
			WP_CLI::line( '【ブラウザから手動取得する手順】' );
			WP_CLI::line( '1. Chrome で ' . $url . ' を開く' );
			WP_CLI::line( '2. F12 キーで DevTools を開く → "Network" タブを選択' );
			WP_CLI::line( '3. ページをリロード (Ctrl+R / Cmd+R)' );
			WP_CLI::line( '4. Filter 欄に "post-feed-page" と入力' );
			WP_CLI::line( '5. 表示されたリクエストをクリック → "Headers" タブ' );
			WP_CLI::line( '6. "Request Headers" 内の "Authorization: XXXXX" の XXXXX 部分をコピー' );
			WP_CLI::line( '' );
			WP_CLI::line( '取得後、以下のコマンドを実行してください:' );
			WP_CLI::line( "  wp wix2wp import --wix-url={$url} --instance=<コピーしたトークン>" );
		}
	}

	/**
	 * HTML 文字列から instance トークンを複数パターンで検索する。
	 *
	 * @param string $html ページの HTML 文字列.
	 * @return string トークン文字列。見つからない場合は空文字.
	 */
	private function extract_instance_token( $html ) {
		// Wix JWT トークンは "eyJ" で始まる Base64 エンコードされた文字列.
		// Wix インスタンストークンは "<ランダム文字列>.<JWT>" の形式.
		// JWT 部分は "eyJ" で始まるが、トークン全体には先頭のランダム部分も含まれる.
		$token_re = '[A-Za-z0-9._~+\/=-]{10,}\.[A-Za-z0-9._~+\/=-]{10,}';
		$patterns = array(
			'/"instance"\s*:\s*"(' . $token_re . ')"/',
			'/[?&]instance=(' . $token_re . ')/',
			'/instance\s*=\s*["\x27](' . $token_re . ')["\x27]/',
			'/Authorization["\x27]?\s*:\s*["\x27]?(' . $token_re . ')/',
		);

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $html, $m ) ) {
				return $m[1];
			}
		}

		return '';
	}
}
