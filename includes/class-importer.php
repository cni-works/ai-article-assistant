<?php
namespace AAA;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Importer {
	const LOCK = 'aaa_import_lock_v1';
	public static function storage_key(): string { return 'aaa_import_' . get_current_blog_id() . '_' . get_current_user_id(); }
	public static function session(): array { $value = get_transient( self::storage_key() ); return is_array( $value ) ? $value : array(); }
	public static function allowed(): bool { return current_user_can( 'read' ) && (bool) Store::writable_types(); }

	public static function preview( string $source ) {
		if ( ! self::allowed() ) { return new \WP_Error( 'permission', 'CSV取り込みに利用できる投稿先・権限がありません。' ); }
		$lock = OperationLock::acquire( self::LOCK, 'article-preview' ); if ( is_wp_error( $lock ) ) { return $lock; }
		try {
		$rows = Csv::parse( $source ); if ( is_wp_error( $rows ) ) { return $rows; }
		$bindings = array_intersect_key( self::session()['bindings'] ?? array(), array_fill_keys( array_map( 'strtolower', array_column( $rows, 'article_key' ) ), true ) );
		$image_options = array_intersect_key( self::session()['image_options'] ?? array(), array_fill_keys( array_map( 'strtolower', array_column( $rows, 'article_key' ) ), true ) );
		$check = CsvValidator::check( $rows, 0, $bindings, $image_options );
		$stage = array( 'token' => wp_generate_uuid4(), 'state' => 'preview', 'source' => $source, 'check' => $check, 'bindings' => $bindings, 'image_options' => $image_options, 'created_at' => current_time( 'mysql' ) );
		set_transient( self::storage_key(), $stage, 1800 );
		if ( self::session() !== $stage ) { return new \WP_Error( 'save', 'プレビューを一時保存できませんでした。' ); }
		return $stage;
		} finally { OperationLock::release( $lock ); }
	}

	/** Explicit bindings only. Upload transport stays entirely in WordPress's standard media modal. */
	public static function requirements( array $stage ): array {
		$rows = Csv::parse( $stage['source'] ?? '' ); if ( is_wp_error( $rows ) ) { return array(); }
		$result = array(); $seen = array();
		foreach ( $rows as $row ) {
			$key = strtolower( $row['article_key'] );
			if ( '2' !== $row['schema_version'] || ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $key ) || ! Store::can_create( $row['post_type'] ) ) { continue; }
			if ( isset( $seen[ $key ] ) ) { return array(); } $seen[ $key ] = true;
			try {
				$input = json_decode( $row['media_json'], false, 32, JSON_THROW_ON_ERROR );
				if ( ! $input instanceof \stdClass || ! isset( $input->files ) ) { continue; }
				$media = Media::resolve( $input, $key, $row['post_type'], $stage['bindings'][ $key ] ?? array(), $stage['image_options'][ $key ] ?? array() );
				foreach ( $media['snapshot'] as $ref => $item ) { $result[] = array( 'key' => $key, 'title' => $row['post_title'], 'ref' => $ref, 'media' => $item ); }
			} catch ( \JsonException | \InvalidArgumentException $error ) { continue; }
		}
		return $result;
	}

	/** Saves only a staged mapping; invalid article fields remain blocked from draft creation. */
	public static function bind( string $token, array $input, array $raw_options = array() ) {
		if ( ! self::allowed() || ! current_user_can( 'upload_files' ) ) { return new \WP_Error( 'permission', '資料の対応付けには記事作成とファイルアップロードの権限が必要です。' ); }
		$lock = OperationLock::acquire( self::LOCK, 'media-bind' ); if ( is_wp_error( $lock ) ) { return $lock; }
		try {
			$stage = self::session();
			if ( 'preview' !== ( $stage['state'] ?? '' ) || ! hash_equals( $stage['token'], $token ) ) { return new \WP_Error( 'token', 'CSVの確認内容が変わったか期限切れです。再読込してください。' ); }
			$rows = Csv::parse( $stage['source'] ); if ( is_wp_error( $rows ) ) { return $rows; }
			$allowed = array();
			try {
				foreach ( $rows as $row ) {
					$data = json_decode( $row['media_json'], false, 32, JSON_THROW_ON_ERROR );
					if ( '2' === $row['schema_version'] && Store::can_create( $row['post_type'] ) && preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di', $row['article_key'] ) && $data instanceof \stdClass && isset( $data->files ) ) { $allowed[ strtolower( $row['article_key'] ) ] = Media::plans( $data->files ); }
				}
			} catch ( \JsonException | \InvalidArgumentException $error ) { return new \WP_Error( 'media', 'CSVの資料計画を検証できません。' ); }
			$bindings = array_intersect_key( $stage['bindings'] ?? array(), $allowed );
			foreach ( $bindings as $key => $items ) { $bindings[ $key ] = array_intersect_key( $items, $allowed[ $key ] ); }
			foreach ( $input as $key => $items ) {
				if ( ! isset( $allowed[ $key ] ) || ! is_array( $items ) || array_diff_key( $items, $allowed[ $key ] ) ) { return new \WP_Error( 'media', 'このCSVにない記事・資料へ対応付けできません。' ); }
				foreach ( $items as $ref => $id ) {
					if ( ! is_scalar( $id ) || ! preg_match( '/^(0|[1-9][0-9]{0,9})$/D', (string) $id ) || ( (int) $id && ! Media::attachment( (int) $id, $allowed[ $key ][ $ref ]['kind'] ) ) ) { return new \WP_Error( 'attachment', '資料の種類・実在性・利用権限を確認してください。未設定は0です。' ); }
					$bindings[ $key ][ $ref ] = (int) $id;
				}
			}
			$image_options = Placeholder::options( $raw_options ?: ( $stage['image_options'] ?? array() ), $allowed );
			if ( is_wp_error( $image_options ) ) { return $image_options; }
			$check = CsvValidator::check( $rows, 0, $bindings, $image_options );
			$stage['image_options'] = $image_options; $stage['bindings'] = $bindings; $stage['check'] = $check; $stage['token'] = wp_generate_uuid4();
			set_transient( self::storage_key(), $stage, 1800 );
			return self::session() === $stage ? $stage : new \WP_Error( 'save', '資料の対応付けを保存できませんでした。' );
		} finally { OperationLock::release( $lock ); }
	}
	public static function missing( array $check ): bool {
		foreach ( $check['items'] ?? array() as $item ) { foreach ( $item['media'] ?? array() as $media ) { if ( 'missing' === $media['status'] ) { return true; } } }
		return false;
	}
	public static function dummy( array $check ): bool {
		foreach ( $check['items'] ?? array() as $item ) { foreach ( $item['media'] ?? array() as $media ) { if ( ! empty( $media['is_placeholder'] ) ) { return true; } } } return false;
	}

	/** Lock serializes plugin imports across users. A preview is consumed before any insert. */
	public static function commit( string $token, bool $confirm_missing = false, bool $confirm_dummy = false ) {
		if ( ! self::allowed() ) { return new \WP_Error( 'permission', 'CSVを取り込む権限がありません。' ); }
		$lock = OperationLock::acquire( self::LOCK, 'article-commit' ); if ( is_wp_error( $lock ) ) { return $lock; }
		try {
			$stage = self::session();
			if ( ! $stage || 'preview' !== $stage['state'] || ! hash_equals( $stage['token'], $token ) ) { return new \WP_Error( 'token', 'プレビューが期限切れ、実行済み、または別の利用者のものです。CSVを検証し直してください。' ); }
			if ( $stage['check']['errors'] ) { return new \WP_Error( 'invalid', 'CSVにエラーがあります。修正して検証し直してください。' ); }
			$rows = Csv::parse( $stage['source'] ); if ( is_wp_error( $rows ) ) { return $rows; }
			$check = CsvValidator::check( $rows, 0, $stage['bindings'] ?? array(), $stage['image_options'] ?? array() );
			if ( $check['errors'] || ! hash_equals( $stage['check']['fingerprint'], $check['fingerprint'] ) ) { return new \WP_Error( 'changed', '確認後に投稿・分類・権限・品質設定が変更されました。CSVを検証し直してください。' ); }
			if ( self::dummy( $check ) && ! $confirm_dummy ) { return new \WP_Error( 'dummy', 'ダミー画像で仮配置する内容を確認し、公開前の差し替え確認を承認してください。' ); }
			if ( in_array( '2', array_column( $rows, 'schema_version' ), true ) && self::missing( $check ) && ! $confirm_missing ) { return new \WP_Error( 'missing', '未登録の画像・PDFがあります。追加するか、未配置の確認用段落を残して下書き登録することを承認してください。' ); }
			$stage['state'] = 'consumed'; unset( $stage['source'] );
			set_transient( self::storage_key(), $stage, 1800 );
			if ( self::session() !== $stage ) { return new \WP_Error( 'save', '実行状態を保存できないため、登録を中止しました。' ); }
			$report = array();
			foreach ( $check['items'] as $item ) {
				if ( CsvValidator::duplicate( $item['article_key'], $item['post_type'], $item['post_name'] ) ) { $report[] = array( 'title' => $item['post_title'], 'id' => 0, 'errors' => array( '実行直前に重複を検出しました。登録しませんでした。' ) ); continue; }
				$post = array_intersect_key( $item, array_flip( array( 'post_title', 'post_excerpt', 'post_content', 'post_name', 'post_type' ) ) );
				$post['post_status'] = 'draft'; $post['post_author'] = get_current_user_id();
				$post['meta_input'] = array( '_aaa_article_key' => $item['article_key'], '_aaa_import_token' => $token, '_aaa_seo_v1' => $item['seo'] );
				if ( ! empty( $item['settings_snapshot'] ) ) { $post['meta_input']['_aaa_settings_snapshot_v1'] = $item['settings_snapshot']; }
				if ( $item['media'] ) { $post['meta_input'][ Config::MEDIA ] = $item['media']; }
				$id = wp_insert_post( wp_slash( $post ), true );
				if ( is_wp_error( $id ) || ! $id ) { $report[] = array( 'title' => $item['post_title'], 'id' => 0, 'errors' => array( is_wp_error( $id ) ? $id->get_error_message() : '下書きを作成できませんでした。' ) ); continue; }
				$errors = array();
				$errors = array_merge( $errors, Acf::write( (int) $id, $item['acf'], $item['post_type'] ) );
				if ( $item['thumbnail'] ) {
					$shared_dummy = (bool) array_filter( $item['media'], static function ( $media ) use ( $item ) { return ! empty( $media['is_placeholder'] ) && ( $media['attachment_id'] ?? 0 ) === $item['thumbnail']; } );
					if ( ! ( $shared_dummy ? Placeholder::asset( $item['thumbnail'] ) : Media::attachment( $item['thumbnail'], 'image' ) ) || ! post_type_supports( $item['post_type'], 'thumbnail' ) ) { $errors[] = 'アイキャッチ画像を利用できません。作成した下書きを確認してください。'; }
					else { set_post_thumbnail( $id, $item['thumbnail'] ); if ( (int) get_post_thumbnail_id( $id ) !== $item['thumbnail'] ) { $errors[] = 'アイキャッチを保存できませんでした。作成した下書きを確認してください。'; } }
				}
				if ( get_post_meta( $id, '_aaa_article_key', true ) !== $item['article_key'] ) { $errors[] = '管理IDを保存できませんでした。作成された下書きを確認してください。'; }
				if ( get_post_meta( $id, '_aaa_seo_v1', true ) !== $item['seo'] ) { $errors[] = 'SEO案を保存できませんでした。作成された下書きの本文と合わせて確認してください。'; }
				if ( $item['media'] && get_post_meta( $id, Config::MEDIA, true ) !== $item['media'] ) { $errors[] = '資料の対応記録を保存できませんでした。作成された下書きを確認してください。'; }
				foreach ( $item['terms'] as $taxonomy => $ids ) {
					$result = wp_set_object_terms( $id, $ids, $taxonomy, false );
					$saved = wp_get_object_terms( $id, $taxonomy, array( 'fields' => 'ids' ) );
					if ( is_wp_error( $result ) || is_wp_error( $saved ) ) { $errors[] = '分類の登録に失敗しました：' . $taxonomy; continue; }
					$saved = array_map( 'intval', $saved ); sort( $saved ); sort( $ids );
					if ( $saved !== $ids ) { $errors[] = '分類が指定と一致しません：' . $taxonomy; }
				}
				$report[] = array( 'title' => $item['post_title'], 'id' => (int) $id, 'errors' => $errors );
			}
			$stage = array( 'token' => $token, 'state' => 'complete', 'report' => $report );
			set_transient( self::storage_key(), $stage, 1800 );
			if ( self::session() !== $stage ) { return new \WP_Error( 'report', '登録結果を保存できませんでした。下書きが作成されている可能性があるため、再取り込みの前に投稿一覧を確認してください。' ); }
			return $stage;
		} finally { OperationLock::release( $lock ); }
	}
}
