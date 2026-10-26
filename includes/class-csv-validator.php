<?php
namespace AAA;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class CsvValidator {
	public static function duplicate( string $key, string $type, string $slug, int $except = 0 ): bool {
		$ids = get_posts( array( 'post_type' => 'any', 'post_status' => array_keys( get_post_stati() ), 'posts_per_page' => 2, 'fields' => 'ids', 'meta_key' => '_aaa_article_key', 'meta_value' => $key, 'suppress_filters' => true ) );
		$path = get_page_by_path( $slug, OBJECT, array( $type ) );
		return (bool) array_diff( array_map( 'intval', $ids ), array( $except ) ) || ( $path && (int) $path->ID !== $except );
	}

	private static function decode( string $raw, string $name ) {
		try { return json_decode( $raw, false, 32, JSON_THROW_ON_ERROR ); }
		catch ( \JsonException $error ) {
			throw new \InvalidArgumentException( $name . '：JSONの形式が正しくありません。引用符・カンマ・括弧を確認してください。CSVセル内の引用符は2つに重ねる必要があります。ChatGPTには、この項目を直し、他の内容を維持してCSVを再出力するよう依頼してください。' );
		}
	}

	private static function object( string $raw, string $name ): \stdClass {
		$value = self::decode( $raw, $name );
		if ( ! $value instanceof \stdClass ) { throw new \InvalidArgumentException( $name . 'はJSONオブジェクトにしてください。空の場合も{}です。' ); }
		return $value;
	}

	public static function check( array $rows, int $target = 0, array $bindings = array(), array $image_options = array() ): array {
		$items = array(); $errors = array(); $keys = array(); $slugs = array();
		$before = $target ? DraftUpdate::target( $target ) : array();
		if ( is_wp_error( $before ) || ( $target && 1 !== count( $rows ) ) ) { return array( 'items' => array(), 'errors' => array( '更新は利用可能な本人の下書き1件だけを明示的に選んでください。' ), 'fingerprint' => '' ); }
		$site = Store::site();
		foreach ( $rows as $row ) {
			$line = $row['_line']; $warnings = array();
			try {
				if ( ! in_array( $row['schema_version'], array( Csv::VERSION, '2' ), true ) || ( $target && Csv::VERSION !== $row['schema_version'] ) ) { throw new \InvalidArgumentException( '未対応のCSVバージョンです。下書き更新は従来のv1を使用してください。' ); }
				$key = strtolower( $row['article_key'] );
				if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $key ) ) { throw new \InvalidArgumentException( 'article_keyはUUID v4で指定してください。' ); }
				if ( 'draft' !== $row['post_status'] ) { throw new \InvalidArgumentException( '投稿ステータスはdraftだけ利用できます。公開・予約はできません。' ); }
				$type = $row['post_type'];
				$source_article = Store::article();
				$source_snapshot = ( $source_article['article_key'] ?? '' ) === $key ? ProfileRuntime::snapshot( $source_article ) : array();
				if ( $source_snapshot && $type !== ( $source_article['post_type'] ?? '' ) ) { throw new \InvalidArgumentException( '設定を保持している記事条件とCSVの投稿先が一致しません。CSVを出力し直してください。' ); }
				if ( $target && ( $key !== $before['article_key'] || $type !== $before['post_type'] || $row['post_name'] !== $before['post_name'] ) ) { throw new \InvalidArgumentException( 'CSVの管理ID・投稿先・スラッグが選んだ下書きと一致しません。自動で別記事へ振り替えません。' ); }
				if ( ! current_user_can( 'read' ) || ! Store::can_create( $type ) || ! post_type_supports( $type, 'editor' ) ) { throw new \InvalidArgumentException( 'この投稿先の本文編集・下書き作成を利用できません。' ); }
				$title = sanitize_text_field( $row['post_title'] ); $excerpt = sanitize_textarea_field( $row['post_excerpt'] );
				if ( '' === $title || Blocks::length( $title ) > 200 || Blocks::length( $excerpt ) > 2000 ) { throw new \InvalidArgumentException( 'タイトルは1〜200文字、抜粋は2000文字以下にしてください。' ); }
				$slug = $row['post_name'];
				if ( strlen( $slug ) > 180 || ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug ) ) { throw new \InvalidArgumentException( 'スラッグは英小文字・数字をハイフンでつなぎ、180文字以下で指定してください。' ); }
				if ( isset( $keys[ $key ] ) || isset( $slugs[ $type . ':' . $slug ] ) || self::duplicate( $key, $type, $slug, $target ) ) { throw new \InvalidArgumentException( '管理IDまたは投稿先・スラッグが他の記事と重複しています。' ); }
				$keys[ $key ] = true; $slugs[ $type . ':' . $slug ] = true;
				$taxonomies = self::object( $row['taxonomies_json'], 'taxonomies_json' ); $terms = array(); $objects = Store::taxonomies( $type );
				foreach ( $taxonomies as $taxonomy => $ids ) {
					if ( ! isset( $objects[ $taxonomy ] ) || ! current_user_can( $objects[ $taxonomy ]->cap->assign_terms ) || ! is_array( $ids ) || count( $ids ) > 200 ) { throw new \InvalidArgumentException( '投稿先に対応しない分類、権限のない分類、または不正な分類形式です。' ); }
					foreach ( $ids as $id ) { if ( ! is_int( $id ) || $id < 1 || ! term_exists( $id, $taxonomy ) ) { throw new \InvalidArgumentException( '分類は現存するterm IDの整数配列で指定してください。新規作成は行いません。' ); } }
					$terms[ $taxonomy ] = array_values( array_unique( $ids ) );
				}
				if ( ! $target && 'post' === $type && ! property_exists( $taxonomies, 'category' ) ) { $warnings[] = 'カテゴリー未指定です。WordPressの既定カテゴリーが適用されます。'; }
				if ( $target ) {
					foreach ( array( 'taxonomies_json', 'media_json', 'acf_json', 'seo_json' ) as $name ) { if ( (array) self::object( $row[ $name ], $name ) ) { throw new \InvalidArgumentException( '下書き更新では分類・メディア・ACF・SEOを保持します。対応するJSON列は{}にしてください。' ); } }
					$media = array( 'selected' => array(), 'snapshot' => array(), 'featured' => '', 'thumbnail' => 0, 'warnings' => array() );
					$acf = array( 'values' => array(), 'schema' => array(), 'warnings' => array(), 'plain' => '', 'used_media' => array() );
				} else {
					$media_input = self::object( $row['media_json'], 'media_json' );
					if ( '1' === $row['schema_version'] && property_exists( $media_input, 'files' ) ) { throw new \InvalidArgumentException( '資料計画filesを含む記事CSVはschema_version=2にしてください。' ); }
					$media = Media::resolve( $media_input, $key, $type, $bindings[ $key ] ?? array(), $image_options[ $key ] ?? array() );
					$acf = Acf::validate( self::object( $row['acf_json'], 'acf_json' ), $type, $media['selected'] );
				}
				$seo = (array) self::object( $row['seo_json'], 'seo_json' );
				if ( array_diff( array_keys( $seo ), array( 'title', 'description' ) ) ) { throw new \InvalidArgumentException( 'SEOのキーはtitleとdescriptionだけです。' ); }
				foreach ( $seo as $name => $value ) { if ( ! is_string( $value ) || Blocks::length( $value ) > 2000 ) { throw new \InvalidArgumentException( 'SEO情報は2000文字以下の文字列にしてください。' ); } $seo[ $name ] = sanitize_textarea_field( $value ); }
				$structure = self::decode( $row['content_json'], 'content_json（本文構造）' );
				$content = Blocks::convert( $structure, $media['selected'], $key );
				if ( is_wp_error( $content ) ) { throw new \InvalidArgumentException( $content->get_error_message() ); }
				if ( '' === $content['plain'] ) { throw new \InvalidArgumentException( '記事本文が空です。' ); }
				if ( ! empty( $site['min_article'] ) && $content['length'] < $site['min_article'] ) { $warnings[] = '本文文字数がサイト設定の目安より短くなっています。'; }
				$warnings = array_merge( $warnings, $content['warnings'] );
				$warnings = array_merge( $warnings, $media['warnings'] );
				$warnings = array_merge( $warnings, $acf['warnings'] ); $used_media = array_merge( $content['used_media'], $acf['used_media'] );
				foreach ( $media['selected'] as $ref => $material ) { if ( ! in_array( $ref, $used_media, true ) && $media['featured'] !== $ref ) { $warnings[] = '資料 ' . $ref . ' が本文・ACFに配置されていません。'; } }
				foreach ( $media['snapshot'] as $ref => $material ) { if ( $material['required'] && ! in_array( $ref, $used_media, true ) && $media['featured'] !== $ref ) { $warnings[] = '必須資料 ' . $ref . ' の本文・ACF配置を確認してください。'; } }
				if ( $title !== $row['post_title'] || $excerpt !== $row['post_excerpt'] ) { $warnings[] = 'タイトル・抜粋を安全な文字列へ整形しました。'; }
				foreach ( preg_split( '/\R/u', $site['forbidden_words'] ?? '' ) as $word ) { $word = trim( $word ); if ( '' !== $word && false !== strpos( $title . "\n" . $excerpt . "\n" . $content['plain'] . "\n" . $acf['plain'] . "\n" . implode( "\n", $seo ), $word ) ) { throw new \InvalidArgumentException( '管理者設定の禁止ワードが含まれています。原稿を確認してください。' ); } }
				$items[] = array( 'line' => $line, 'article_key' => $key, 'post_type' => $type, 'post_name' => $slug, 'post_title' => $title, 'post_excerpt' => $excerpt, 'post_content' => $content['content'], 'terms' => $terms, 'seo' => $seo, 'length' => $content['length'], 'warnings' => array_values( array_unique( $warnings ) ), 'media' => $media['snapshot'], 'thumbnail' => $media['thumbnail'] );
				$items[ count( $items ) - 1 ]['acf'] = $acf;
				if ( $source_snapshot ) { $items[ count( $items ) - 1 ]['settings_snapshot'] = $source_snapshot; }
			} catch ( \JsonException $error ) { $errors[] = $line . '行目：JSONの形式が正しくありません。'; }
			catch ( \InvalidArgumentException $error ) { $errors[] = $line . '行目：' . $error->getMessage(); }
		}
		$quality = array_intersect_key( $site, array_flip( array( 'min_section', 'min_article', 'forbidden_words', 'locked_rules', 'publication_checks' ) ) );
		return array( 'items' => $items, 'errors' => $errors, 'fingerprint' => hash( 'sha256', wp_json_encode( array( $items, $quality ) ) ) );
	}
}
