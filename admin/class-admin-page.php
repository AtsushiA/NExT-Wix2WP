<?php
/**
 * 管理画面ページ
 *
 * instance トークンを登録・管理する。
 * 保存したトークンは WP-CLI インポート時に --instance 省略で自動利用される。
 *
 * @package NExT_Wix2WP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Instance トークン管理用の管理画面ページ。
 */
class NExT_Wix2WP_Admin {

	/**
	 * Options キー (wp_options)。
	 *
	 * @var string
	 */
	const OPTION_KEY = 'wix2wp_token_map';

	/**
	 * Nonce アクション名。
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'wix2wp_token_action';

	/**
	 * フックを登録する。
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
	}

	/**
	 * 管理メニューを追加する。
	 */
	public function add_menu() {
		add_options_page(
			'Wix2WP 設定',
			'Wix2WP',
			'manage_options',
			'wix2wp',
			array( $this, 'render_page' )
		);
	}

	/**
	 * 管理ページを描画する。
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$notice = $this->handle_post();
		$map    = (array) get_option( self::OPTION_KEY, array() );

		?>
		<div class="wrap">
			<h1>Wix2WP — instance トークン管理</h1>

			<?php if ( 'saved' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p>トークンを保存しました。</p></div>
			<?php elseif ( 'deleted' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p>削除しました。</p></div>
			<?php elseif ( 'error' === $notice ) : ?>
				<div class="notice notice-error is-dismissible"><p>URL とトークンを両方入力してください。</p></div>
			<?php endif; ?>

			<h2>トークンを登録する</h2>
			<p>
				ブラウザの DevTools → Network タブ → <code>post-feed-page</code> のリクエストヘッダー
				<code>Authorization</code> の値をコピーして貼り付けてください。
			</p>

			<form method="post" action="">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<input type="hidden" name="wix2wp_action" value="save">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wix2wp_url">Wix ブログ URL</label></th>
						<td>
							<input
								type="url"
								id="wix2wp_url"
								name="wix2wp_url"
								class="regular-text"
								placeholder="https://example.com/blog-1"
								required
							>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wix2wp_token">instance トークン</label></th>
						<td>
							<input
								type="text"
								id="wix2wp_token"
								name="wix2wp_token"
								class="large-text code"
								placeholder="xxxxxxxx.eyJpbn..."
								required
							>
							<p class="description">
								DevTools の <code>Authorization</code> ヘッダー値をそのまま貼り付けてください。
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button( '保存' ); ?>
			</form>

			<?php if ( ! empty( $map ) ) : ?>
				<h2>登録済みトークン</h2>
				<table class="wp-list-table widefat striped">
					<thead>
						<tr>
							<th>Wix ブログ URL</th>
							<th>トークン（先頭 30 文字）</th>
							<th>WP-CLI コマンド</th>
							<th>操作</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $map as $stored_url => $stored_token ) : ?>
							<tr>
								<td><?php echo esc_html( $stored_url ); ?></td>
								<td>
									<code><?php echo esc_html( substr( $stored_token, 0, 30 ) ); ?>…</code>
								</td>
								<td>
									<code style="font-size:11px; word-break:break-all;">
										wp wix2wp import --wix-url=<?php echo esc_html( $stored_url ); ?>
									</code>
									<p class="description" style="margin-top:4px;">
										※ 保存済みのため <code>--instance</code> 省略可
									</p>
								</td>
								<td>
									<form method="post" action="" style="display:inline;">
										<?php wp_nonce_field( self::NONCE_ACTION ); ?>
										<input type="hidden" name="wix2wp_action" value="delete">
										<input type="hidden" name="wix2wp_delete_url" value="<?php echo esc_attr( $stored_url ); ?>">
										<button
											type="submit"
											class="button button-small button-link-delete"
											onclick="return confirm('このトークンを削除しますか？');"
										>削除</button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * POST 送信を処理する。
	 *
	 * @return string 'saved' | 'deleted' | 'error' | ''
	 */
	private function handle_post() {
		// sanitize_key は小文字化するため strtoupper で大文字に戻す.
		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		if ( 'POST' !== $request_method || empty( $_POST['wix2wp_action'] ) ) {
			return '';
		}

		check_admin_referer( self::NONCE_ACTION );

		$action = sanitize_key( wp_unslash( $_POST['wix2wp_action'] ) );

		if ( 'save' === $action ) {
			$url   = isset( $_POST['wix2wp_url'] ) ? esc_url_raw( trim( sanitize_text_field( wp_unslash( $_POST['wix2wp_url'] ) ) ) ) : '';
			$token = isset( $_POST['wix2wp_token'] ) ? sanitize_text_field( wp_unslash( $_POST['wix2wp_token'] ) ) : '';

			if ( ! $url || ! $token ) {
				return 'error';
			}

			// http / https のみ許可する.
			$parsed_scheme = wp_parse_url( $url, PHP_URL_SCHEME );
			if ( ! in_array( $parsed_scheme, array( 'http', 'https' ), true ) ) {
				return 'error';
			}

			$map         = (array) get_option( self::OPTION_KEY, array() );
			$map[ $url ] = $token;
			update_option( self::OPTION_KEY, $map );
			return 'saved';
		}

		if ( 'delete' === $action ) {
			$url = isset( $_POST['wix2wp_delete_url'] ) ? esc_url_raw( trim( sanitize_text_field( wp_unslash( $_POST['wix2wp_delete_url'] ) ) ) ) : '';
			if ( $url ) {
				$map = (array) get_option( self::OPTION_KEY, array() );
				unset( $map[ $url ] );
				update_option( self::OPTION_KEY, $map );
			}
			return 'deleted';
		}

		return '';
	}

	// -------------------------------------------------------------------------
	// 静的ユーティリティ（CLI からも利用可）
	// -------------------------------------------------------------------------

	/**
	 * 指定 URL に対応する保存済みトークンを返す。
	 *
	 * 完全一致 → 前方一致の順で検索する。
	 *
	 * @param string $url Wix ブログ URL.
	 * @return string トークン文字列。見つからない場合は空文字。
	 */
	public static function get_token( $url ) {
		$map = (array) get_option( self::OPTION_KEY, array() );

		// 完全一致.
		if ( isset( $map[ $url ] ) ) {
			return $map[ $url ];
		}

		// 前方一致（末尾スラッシュの有無などを吸収する）.
		$url_normalized = rtrim( $url, '/' );
		foreach ( $map as $stored_url => $token ) {
			if ( rtrim( $stored_url, '/' ) === $url_normalized ) {
				return $token;
			}
		}

		return '';
	}
}
