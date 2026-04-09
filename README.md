# NExT Wix2WP

Wix ブログを WordPress の投稿として WP-CLI 経由でインポートするプラグインです。

---

## 機能

- Wix Blog API からブログ記事を全件取得（ページネーション対応）
- `--instance` 省略時は RSS フィードにフォールバック（最新 ~20 件）
- RSS フィード URL をブログページの HTML から自動検出
- 記事タイトル・公開日・スラッグを Wix の値そのままで保存
- 本文を Gutenberg ブロック（`core/paragraph`、`core/heading`、`core/image` など）に変換
- 画像を WordPress メディアライブラリに登録（投稿日の年/月フォルダ）
- カテゴリーをブログページの HTML から自動抽出して WordPress カテゴリーに変換・紐付け
- 重複インポート防止（`_wix_post_id` メタで照合）
- ドライラン・強制上書き・画像スキップなどのオプション

---

## 動作環境

| 項目 | 要件 |
|---|---|
| WordPress | 6.0 以上 |
| PHP | 7.4 以上 |
| WP-CLI | 2.0 以上 |

---

## インストール

1. プラグインフォルダを `wp-content/plugins/NExT-Wix2WP/` に配置
2. WordPress 管理画面または WP-CLI でプラグインを有効化

```bash
wp plugin activate NExT-Wix2WP
```

---

## 使い方

### instance トークンの取得

全記事を取得するには Wix の instance トークンが必要です。

**自動取得を試みる（HTML から抽出）:**

```bash
wp wix2wp token --wix-url=https://example.com/blog-1
```

自動抽出できない場合は、ブラウザの DevTools から手動取得します。

**手動取得手順:**

1. Chrome で Wix ブログページを開く
2. F12 で DevTools → **Network** タブを開く
3. ページをリロード（Ctrl+R / Cmd+R）
4. Filter に `post-feed-page` と入力
5. 表示されたリクエストをクリック → **Headers** タブ
6. **Request Headers** 内の `Authorization: XXXXX` の `XXXXX` 部分をコピー

---

### import コマンド

```bash
wp wix2wp import --wix-url=<WixブログURL> [オプション]
```

#### オプション

| オプション | デフォルト | 説明 |
|---|---|---|
| `--wix-url=<url>` | （必須） | Wix ブログの URL |
| `--instance=<token>` | （空） | instance トークン。省略時は RSS フィードにフォールバック（最新 ~20 件のみ） |
| `--limit=<number>` | `0`（全件） | 最大インポート件数 |
| `--dry-run` | false | 実行せず処理内容を表示のみ |
| `--force` | false | インポート済み記事を上書き更新 |
| `--skip-images` | false | 画像インポートをスキップ |
| `--post-status=<status>` | `publish` | 投稿ステータス（`publish` / `draft` / `private`） |
| `--author=<user_id>` | `1` | 投稿者のユーザー ID |

#### 実行例

```bash
# RSS フィードで最新記事をインポート（instance 不要）
wp wix2wp import --wix-url=https://example.com/blog-1

# instance トークンを指定して全記事をインポート
wp wix2wp import --wix-url=https://example.com/blog-1 --instance=XXXXX

# 最初の 5 件だけドライランで確認
wp wix2wp import --wix-url=https://example.com/blog-1 --instance=XXXXX --limit=5 --dry-run

# 画像をスキップして下書きでインポート
wp wix2wp import --wix-url=https://example.com/blog-1 --instance=XXXXX --skip-images --post-status=draft

# インポート済みの記事を強制上書き
wp wix2wp import --wix-url=https://example.com/blog-1 --instance=XXXXX --force

# 詳細ログを表示しながら実行
wp wix2wp import --wix-url=https://example.com/blog-1 --instance=XXXXX --debug
```

#### 実行例（出力）

```
内部 API モード: instance トークンを使用します。
記事を取得しています...
275 件の記事が見つかりました。
インポート中: 100% (275/275) [============================] 0:04:30

===== インポート完了 =====
  インポート: 273
  更新:         0
  スキップ:     2
  エラー:       0
Success: すべての処理が完了しました。
```

---

### token コマンド

Wix ブログページの HTML から instance トークンの自動抽出を試みます。

```bash
wp wix2wp token --wix-url=https://example.com/blog-1
```

抽出に成功すると、そのまま使えるインポートコマンドを表示します。
失敗した場合は DevTools を使った手動取得手順を表示します。

---

## 保存されるデータ

### 投稿メタ

| メタキー | 内容 |
|---|---|
| `_wix_post_id` | Wix の記事 ID（重複チェック・再インポート用） |
| `_wix_source_url` | Wix の記事スラッグ（元 URL 確認用） |

### カテゴリーメタ（タームメタ）

| メタキー | 内容 |
|---|---|
| `_wix_category_id` | Wix のカテゴリー ID（重複チェック用） |

### メディアメタ

| メタキー | 内容 |
|---|---|
| `_wix_original_url` | Wix の元画像 URL（重複インポート防止用） |

---

## ファイル構成

```
NExT-Wix2WP/
├── NExT-Wix2WP.php              # プラグインエントリーポイント
├── includes/
│   ├── class-wix-api.php        # Wix Blog API / RSS クライアント
│   ├── class-image.php          # 画像ダウンロード・メディア登録
│   ├── class-converter.php      # Rich Content → Gutenberg ブロック変換
│   └── class-importer.php       # 投稿インポート処理（カテゴリー・日付含む）
├── cli/
│   └── class-cli-command.php    # WP-CLI コマンド定義
├── README.md
└── SPEC.md                      # 仕様書
```

---

## 技術仕様

### 使用 API

| モード | エンドポイント | 認証 |
|---|---|---|
| 内部 API | `/_api/blog-frontend-adapter-public/v2/post-feed-page` | `Authorization: <instance_token>` |
| RSS フォールバック | `<link rel="alternate" type="application/rss+xml">` で自動検出 | 不要 |

### instance トークンの形式

```
<ランダム文字列>.<JWT>
```

例: `712eKAML...yAA.eyJpbnN0...`

ブラウザの DevTools → Network タブで `post-feed-page` のリクエストヘッダー `Authorization` の値をそのままコピーします。

---

## 注意事項

- **Wix 内部 API**: `/_api/blog-frontend-adapter-public/v2/post-feed-page` は Wix の非公式 API です。Wix のプラットフォーム更新により仕様が変わる可能性があります。
- **大量インポート**: 記事数が多い場合は `--limit=10` などで少量テストしてから全件実行することを推奨します。
- **レート制限**: 各ページリクエスト間に 200ms のインターバルを設けています。
- **再実行**: 一度インポートした記事は `_wix_post_id` メタで管理されるため、再実行しても重複しません。上書きが必要な場合は `--force` を使用してください。
- **RSS フォールバック**: `--instance` を省略した場合は RSS フィードから最新 ~20 件のみ取得します。全件インポートには `--instance` が必要です。

---

## ライセンス

GPL-2.0-or-later
