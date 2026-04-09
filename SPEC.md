# NExT-Wix2WP プラグイン仕様書

## 概要

WixブログをWordPressの投稿としてインポートするWP-CLIプラグイン。
Wix Thunderbolt（JavaScriptレンダリング）で構築されたサイト（例: https://example.com/blog-1）を対象とする。

---

## 対象サイト

- サイトURL: `https://example.com`
- ブログURL: `https://example.com/blog-1`
- プラットフォーム: Wix Thunderbolt（クライアントサイドレンダリング）

---

## WP-CLI コマンド仕様

### 基本コマンド

```bash
# 全記事インポート
wp wix2wp import --wix-url=https://example.com/blog-1

# 記事数を指定してインポート（テスト用）
wp wix2wp import --wix-url=https://example.com/blog-1 --limit=10

# ドライラン（実際にはインポートしない）
wp wix2wp import --wix-url=https://example.com/blog-1 --dry-run

# インポート済みの記事を再インポート（上書き）
wp wix2wp import --wix-url=https://example.com/blog-1 --force

# 画像のインポートをスキップ
wp wix2wp import --wix-url=https://example.com/blog-1 --skip-images
```

### オプション一覧

| オプション | 型 | デフォルト | 説明 |
|---|---|---|---|
| `--wix-url` | string | 必須 | WixブログのURL |
| `--limit` | int | 0（全件） | インポートする記事数の上限 |
| `--dry-run` | bool | false | 実行せず処理内容を表示のみ |
| `--force` | bool | false | 既存投稿を上書き |
| `--skip-images` | bool | false | 画像インポートをスキップ |
| `--post-status` | string | `publish` | インポート後の投稿ステータス |
| `--author` | int | 1 | 投稿者のユーザーID |

---

## 記事取得方式

### Wix Blog API 利用

WixはSPA（無限スクロール）でありHTMLスクレイピングが困難なため、**Wix Blog API** を使用して記事データをJSON形式で取得する。

#### APIエンドポイント

```
GET https://{domain}/_api/communities-blog-node-api/_api/posts
    ?offset=0
    &size=20
    &fieldsets=CONTENT_TEXT,RICH_CONTENT,COUNTERS,OWNER,CATEGORIES
```

| パラメータ | 説明 |
|---|---|
| `offset` | 取得開始位置（ページネーション用） |
| `size` | 1回の取得件数（最大100） |
| `fieldsets` | 取得フィールド指定（`CATEGORIES` を含めることでカテゴリー情報を取得） |

#### ページネーション処理

1. `offset=0` から取得開始
2. レスポンスの `total` と取得件数を比較
3. 全件取得するまで `offset` を加算してループ
4. `--limit` 指定時は上限に達したら停止

```
offset=0  → 20件取得
offset=20 → 20件取得
offset=40 → 残り件数分取得
...
```

#### レスポンス例

```json
{
  "posts": [
    {
      "id": "abc123",
      "title": "ブログタイトル",
      "slug": "blog-slug",
      "firstPublishedDate": "2023-08-15T10:00:00.000Z",
      "lastPublishedDate": "2023-08-15T10:00:00.000Z",
      "coverImage": "https://static.wixstatic.com/media/xxxx.jpg",
      "richContent": {
        "nodes": [ ... ]  // Wix Rich Content JSON
      },
      "excerpt": "記事の抜粋テキスト",
      "owner": {
        "name": "著者名"
      },
      "categories": [
        {
          "id": "cat-id-1",
          "label": "お知らせ",
          "slug": "oshirase"
        }
      ]
    }
  ],
  "total": 150
}
```

---

## インポート処理フロー

```
1. WP-CLI コマンド実行
        ↓
2. Wix Blog APIで記事一覧取得（ページネーション、fieldsets=CATEGORIES含む）
        ↓
3. 各記事をループ処理
   ├── 3a. 重複チェック（wix_post_id メタで確認）
   │       既存 & --force なし → スキップ
   │       既存 & --force あり → 更新
   │       未インポート → 新規作成
   ├── 3b. 画像処理（--skip-images なし）
   │       カバー画像・本文画像を取得
   │       WordPressメディアライブラリに登録
   │       年/月フォルダに分類（投稿日基準）
   ├── 3c. 本文変換（Wix Rich Content → Gutenberg ブロック）
   ├── 3d. カテゴリー処理
   │       WixカテゴリーをWPカテゴリーに変換・紐付け
   └── 3e. wp_insert_post() で投稿作成・更新
        ↓
4. 処理結果サマリー表示
```

---

## 投稿データのマッピング

| WordPress フィールド | Wix データソース | 備考 |
|---|---|---|
| `post_title` | `title` | Wixのタイトルをそのまま使用 |
| `post_date` | `firstPublishedDate` | UTC→JST変換して保存 |
| `post_date_gmt` | `firstPublishedDate` | UTC のまま保存 |
| `post_modified` | `lastPublishedDate` | |
| `post_status` | `--post-status` オプション | デフォルト `publish` |
| `post_author` | `--author` オプション | |
| `post_name` | `slug` | Wixスラッグをそのまま使用 |
| `post_content` | `richContent` | Gutenbergブロックに変換 |
| `post_excerpt` | `excerpt` | |
| `_thumbnail_id` | `coverImage` | メディアID |
| `_wix_post_id` | `id` | 重複チェック用カスタムフィールド |
| `_wix_source_url` | `slug` | 元のWix記事URL |

---

## カテゴリーインポート仕様

### 取得方法

カテゴリーはWix Blog APIの `fieldsets=CATEGORIES` で取得する（推奨）。

> **検討経緯**: ブラウザのDOMでCSSクラス `Ym42pV SkWvPq` が付与されたカテゴリー要素が確認されたが、以下の理由でAPIによる取得を採用する。
> - WixのCSSクラス名はビルド時に自動生成される難読化クラスであり、Wixプラットフォームのアップデートで変更される可能性が高い
> - サイトはJavaScriptレンダリング（Wix Thunderbolt）のためHTMLスクレイピングには別途ヘッドレスブラウザが必要
> - APIレスポンスにカテゴリー情報が含まれるため、スクレイピングより確実かつシンプルに取得可能

### API レスポンス構造

```json
"categories": [
  {
    "id":    "cat-id-1",
    "label": "お知らせ",
    "slug":  "oshirase"
  }
]
```

### WordPressへのマッピング

| Wix フィールド | WordPress | 処理 |
|---|---|---|
| `categories[].label` | `wp_insert_category()` の `cat_name` | 同名カテゴリーが存在すればそのIDを使用、なければ新規作成 |
| `categories[].slug` | `category_nicename` | Wixスラッグをそのまま使用 |
| `categories[].id` | `_wix_category_id`（タームメタ） | 重複防止・再インポート時の照合用 |

### 処理フロー

```php
foreach ($wix_post['categories'] as $cat) {
    // Wix category ID で既存タームを検索
    $term = get_terms(['meta_key' => '_wix_category_id', 'meta_value' => $cat['id']]);

    if (empty($term)) {
        // 新規カテゴリー作成
        $result = wp_insert_term($cat['label'], 'category', ['slug' => $cat['slug']]);
        add_term_meta($result['term_id'], '_wix_category_id', $cat['id']);
        $term_id = $result['term_id'];
    } else {
        $term_id = $term[0]->term_id;
    }

    $category_ids[] = $term_id;
}

wp_set_post_categories($post_id, $category_ids);
```

### フォールバック: DOMスクレイピング（API取得不可時）

APIが利用できない場合のみ、ヘッドレスブラウザ（Playwright）を使ったフォールバックを検討する。ただし以下の理由により**実装コストが高く、安定性も低い**ため原則使用しない。

- CSSクラス `Ym42pV SkWvPq` はWixのプラットフォーム更新で変更される可能性がある
- JavaScriptのフル実行が必要（Node.js環境でPlaywrightを別途起動する必要がある）

---

## Wix Rich Content → Gutenbergブロック 変換仕様

### 対応ノードタイプ

| Wix ノードタイプ | Gutenberg ブロック | 備考 |
|---|---|---|
| `PARAGRAPH` | `core/paragraph` | |
| `HEADING` | `core/heading` | level 1〜6 |
| `IMAGE` | `core/image` | メディアライブラリ登録後のIDを使用 |
| `GALLERY` | `core/gallery` | 複数画像 |
| `VIDEO` | `core/embed` または `core/video` | |
| `DIVIDER` | `core/separator` | |
| `BLOCKQUOTE` | `core/quote` | |
| `CODE_BLOCK` | `core/code` | |
| `ORDERED_LIST` | `core/list` | ordered=true |
| `BULLETED_LIST` | `core/list` | ordered=false |
| `LINK_PREVIEW` | `core/embed` | |

### テキスト装飾（インライン）

| Wix 装飾 | HTML 変換 |
|---|---|
| `BOLD` | `<strong>` |
| `ITALIC` | `<em>` |
| `UNDERLINE` | `<u>` |
| `LINK` | `<a href="...">` |
| `COLOR` | `<span style="color:...">` |

---

## 画像インポート仕様

### 処理手順

1. Wixの画像URL（`https://static.wixstatic.com/media/...`）を取得
2. `wp_upload_bits()` または `media_handle_sideload()` でダウンロード
3. 投稿日（`firstPublishedDate`）の年/月ディレクトリに保存
   - 例: `wp-content/uploads/2023/08/image.jpg`
4. `wp_insert_attachment()` でメディアライブラリに登録
5. `wp_generate_attachment_metadata()` でサムネイル生成
6. 元のWix URLと新しいメディアIDをマッピング（本文内URL差し替え用）

### 画像URL変換

- 本文内の `wixstatic.com` URL → WordPressメディアURL に置換
- カバー画像 → `_thumbnail_id` にメディアIDをセット

### 注意事項

- 同一画像の重複インポート防止: `_wix_original_url` メタで照合
- Wix画像URLには画像変換パラメータ（`/v1/fill/...`）が含まれる場合があるため、オリジナル画像URLを抽出する

---

## 重複チェック

```php
// インポート済みチェック
$existing = get_posts([
    'post_type'  => 'post',
    'meta_key'   => '_wix_post_id',
    'meta_value' => $wix_post_id,
    'fields'     => 'ids',
]);
```

- `_wix_post_id` カスタムフィールドにWixの記事IDを保存
- インポート前に上記で既存投稿を検索
- `--force` オプション時は既存投稿を更新

---

## プラグイン構成

```
NExT-Wix2WP/
├── NExT-Wix2WP.php          # プラグインエントリーポイント
├── includes/
│   ├── class-wix-api.php    # Wix Blog API クライアント
│   ├── class-converter.php  # Rich Content → Gutenbergブロック変換
│   ├── class-importer.php   # 投稿インポート処理
│   └── class-image.php      # 画像ダウンロード・メディア登録
├── cli/
│   └── class-cli-command.php # WP-CLI コマンド定義
└── SPEC.md
```

---

## エラーハンドリング

| エラーケース | 対応 |
|---|---|
| Wix API 接続失敗 | WP_Errorを返し処理中断 |
| 画像ダウンロード失敗 | ログに記録してスキップ（記事インポートは継続） |
| 本文変換失敗 | プレーンテキストとして`core/paragraph`に格納 |
| DB書き込み失敗 | WP_Errorを返し次の記事へ |

---

## 進捗表示（WP-CLI出力）

```
$ wp wix2wp import --wix-url=https://example.com/blog-1

Fetching posts from Wix API...
Found 150 posts.

Importing post 1/150: "ブログタイトル1" (2023-08-15)... done
Importing post 2/150: "ブログタイトル2" (2023-07-20)... done
...
Importing post 150/150: "最初の記事" (2019-01-10)... done

Import complete!
  Imported: 148
  Skipped:  2
  Errors:   0
```

---

## 開発上の注意事項

1. **Wix API の不安定性**: Wixの内部APIは公式サポートがなく変更される可能性がある。APIが取得できない場合のフォールバック処理を検討する。
2. **レート制限**: APIへのリクエストを連続して行わないよう、適切なインターバル（100〜500ms）を設ける。
3. **タイムゾーン**: WixのAPIはUTCで返すため、`get_option('timezone_string')` を参照してJST変換する。
4. **大量インポート**: `--limit` で小量テストしてから全件実行を推奨。`set_time_limit(0)` でタイムアウトを回避する。
5. **Wix Rich Content**: Wixの`richContent`はDraftJS/ProseMirrorベースのJSON形式。ネストされたノード構造に注意。
