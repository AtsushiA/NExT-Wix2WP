<?php
/**
 * 投稿インポート処理の統合テスト。
 *
 * @package NExT_Wix2WP
 */

/**
 * NExT_Wix2WP_Importer の統合テスト（実 WordPress を使用）。
 */
class ImporterTest extends WP_UnitTestCase {

	/**
	 * インポーターを生成する。
	 *
	 * @return NExT_Wix2WP_Importer
	 */
	private function make_importer() {
		$image     = new NExT_Wix2WP_Image();
		$converter = new NExT_Wix2WP_Converter( $image );
		return new NExT_Wix2WP_Importer( $image, $converter );
	}

	/**
	 * RSS ソースの記事を公開投稿として作成すること。
	 */
	public function test_import_rss_post_creates_published_post() {
		$wix_post = array(
			'id'                 => 'wix-1',
			'title'              => 'Test Title',
			'slug'               => 'test-title',
			'excerpt'            => 'An excerpt',
			'firstPublishedDate' => '2023-05-01T10:00:00.000Z',
			'lastPublishedDate'  => '2023-05-02T12:00:00.000Z',
			'coverImage'         => '',
			'richContent'        => array(),
			'htmlContent'        => '<p>Body content</p>',
			'categories'         => array(),
			'owner'              => array(),
			'_source'            => 'rss',
		);

		$result = $this->make_importer()->import_post( $wix_post, array( 'skip_images' => true ) );

		$this->assertSame( 'imported', $result['result'] );

		$post = get_post( $result['post_id'] );
		$this->assertSame( 'Test Title', $post->post_title );
		$this->assertSame( 'test-title', $post->post_name );
		$this->assertSame( 'publish', $post->post_status );
		$this->assertStringContainsString( '<!-- wp:html -->', $post->post_content );
		$this->assertStringContainsString( 'Body content', $post->post_content );
		$this->assertSame( 'wix-1', get_post_meta( $result['post_id'], '_wix_post_id', true ) );
	}

	/**
	 * 既存記事は --force なしでスキップされること。
	 */
	public function test_duplicate_import_is_skipped() {
		$importer = $this->make_importer();
		$wix_post = array(
			'id'                 => 'wix-dup',
			'title'              => 'Dup',
			'slug'               => 'dup',
			'firstPublishedDate' => '2023-01-01T00:00:00.000Z',
			'htmlContent'        => '<p>x</p>',
			'_source'            => 'rss',
		);

		$first = $importer->import_post( $wix_post, array( 'skip_images' => true ) );
		$this->assertSame( 'imported', $first['result'] );

		$second = $importer->import_post( $wix_post, array( 'skip_images' => true ) );
		$this->assertSame( 'skipped', $second['result'] );
		$this->assertSame( $first['post_id'], $second['post_id'] );
	}

	/**
	 * --force 指定で既存記事が更新されること。
	 */
	public function test_force_updates_existing_post() {
		$importer = $this->make_importer();
		$wix_post = array(
			'id'                 => 'wix-upd',
			'title'              => 'Original',
			'slug'               => 'upd',
			'firstPublishedDate' => '2023-01-01T00:00:00.000Z',
			'htmlContent'        => '<p>x</p>',
			'_source'            => 'rss',
		);

		$first = $importer->import_post( $wix_post, array( 'skip_images' => true ) );

		$wix_post['title'] = 'Updated';
		$second            = $importer->import_post( $wix_post, array( 'skip_images' => true, 'force' => true ) );

		$this->assertSame( 'updated', $second['result'] );
		$this->assertSame( $first['post_id'], $second['post_id'] );
		$this->assertSame( 'Updated', get_post( $second['post_id'] )->post_title );
	}

	/**
	 * カテゴリーが作成・紐付けされること。
	 */
	public function test_categories_are_assigned() {
		$wix_post = array(
			'id'                 => 'wix-cat',
			'title'              => 'Cat',
			'slug'               => 'cat-post',
			'firstPublishedDate' => '2023-01-01T00:00:00.000Z',
			'htmlContent'        => '<p>x</p>',
			'_source'            => 'rss',
			'categories'         => array(
				array(
					'id'    => 'c1',
					'label' => 'News',
					'slug'  => 'news',
				),
			),
		);

		$result = $this->make_importer()->import_post( $wix_post, array( 'skip_images' => true ) );
		$names  = wp_get_post_categories( $result['post_id'], array( 'fields' => 'names' ) );

		$this->assertContains( 'News', $names );
	}

	/**
	 * dry_run では投稿が作成されないこと。
	 */
	public function test_dry_run_does_not_create_post() {
		$wix_post = array(
			'id'                 => 'wix-dry',
			'title'              => 'Dry',
			'slug'               => 'dry-run-post',
			'firstPublishedDate' => '2023-01-01T00:00:00.000Z',
			'htmlContent'        => '<p>x</p>',
			'_source'            => 'rss',
		);

		$result = $this->make_importer()->import_post( $wix_post, array( 'dry_run' => true, 'skip_images' => true ) );

		$this->assertSame( 'dry_run', $result['result'] );
		$this->assertSame( 0, $result['post_id'] );
		$this->assertNull( get_page_by_path( 'dry-run-post', OBJECT, 'post' ) );
	}
}
