<?php
namespace AAA;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Local publication catalogue. No rendering, remote fetch, or semantic similarity claim. */
final class Reference {
	const SETTINGS = 'aaa_reference_settings_v1';
	const INDEX = '_aaa_reference_index_v1';
	const PROGRESS = 'aaa_reference_progress_v1';
	const SELECTED = '_aaa_reference_selection_v1';
	const LOCK = 'aaa_reference_batch_lock_v1';
	public function __construct() {
		add_action( 'save_post', array( self::class, 'index_post' ), 20, 1 );
		add_action( 'before_delete_post', array( self::class, 'remove' ) );
	}
	public static function allowed(): bool { return Store::can_edit_author(); }
	public static function types(): array {
		return array_filter( get_post_types( array( 'public' => true ), 'objects' ), static function ( $type ) { return 'attachment' !== $type->name && ! empty( $type->show_ui ) && is_post_type_viewable( $type ); } );
	}
	public static function settings(): array {
		$raw = get_option( self::SETTINGS, array() ); $raw = is_array( $raw ) ? $raw : array();
		return array( 'policy' => in_array( $raw['policy'] ?? '', array( 'off', 'optional', 'required' ), true ) ? $raw['policy'] : 'off', 'types' => array_values( array_intersect( is_array( $raw['types'] ?? null ) ? $raw['types'] : array(), array_keys( self::types() ) ) ) );
	}
	public static function hash( $value ): string { return hash( 'sha256', wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); }
	public static function save_settings( array $raw ) {
		if ( ! current_user_can( 'manage_options' ) ) { return new \WP_Error( 'permission', '参照範囲の変更には管理者権限が必要です。' ); }
		if ( ! is_string( $raw['revision'] ?? null ) || ! hash_equals( self::hash( self::settings() ), $raw['revision'] ) ) { return new \WP_Error( 'changed', '設定が変更されました。画面を開き直してください。' ); }
		$types = $raw['types'] ?? array(); $policy = $raw['policy'] ?? '';
		if ( ! is_string( $policy ) || ! in_array( $policy, array( 'off', 'optional', 'required' ), true ) || ! is_array( $types ) || array_filter( $types, static function ( $type ) { return ! is_string( $type ); } ) || array_diff( $types, array_keys( self::types() ) ) ) { return new \WP_Error( 'settings', '参照方針・投稿タイプを選び直してください。' ); }
		if ( 'off' !== $policy && ! $types ) { return new \WP_Error( 'settings', '利用するときは参照する投稿タイプを選んでください。' ); }
		return Store::write_option( self::SETTINGS, array( 'policy' => $policy, 'types' => array_values( array_unique( $types ) ) ) ) ? true : new \WP_Error( 'save', '参照設定を保存できませんでした。' );
	}
	public static function policy(): string {
		$settings = self::settings(); if ( 'off' === $settings['policy'] || ! $settings['types'] ) { return 'off'; }
		$profile = ProfileRuntime::profile( Store::article() );
		$value = $profile['reference_policy'] ?? 'inherit';
		return in_array( $value, array( 'off', 'optional', 'required' ), true ) ? $value : $settings['policy'];
	}
	public static function eligible( int $id ) {
		$post = get_post( $id ); $settings = self::settings();
		return $post && 'off' !== $settings['policy'] && 'publish' === $post->post_status && '' === (string) $post->post_password && in_array( $post->post_type, $settings['types'], true ) ? $post : null;
	}
	public static function clip( string $value, int $length ): string {
		if ( function_exists( 'mb_substr' ) ) { return mb_substr( $value, 0, $length, 'UTF-8' ); }
		return preg_match( '/^.{0,' . $length . '}/us', $value, $match ) ? $match[0] : '';
	}
	private static function text( string $html ): string {
		$html = strip_shortcodes( $html );
		$html = preg_replace( '~</(?:p|div|li|h[1-6]|tr|blockquote)>|<br\s*/?>~i', "\n", $html );
		return trim( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}
	private static function static_html( array $blocks, bool &$omitted ): string {
		$html = '';
		$allowed = array( null, 'core/paragraph', 'core/heading', 'core/list', 'core/list-item', 'core/quote', 'core/table', 'core/verse', 'core/preformatted', 'core/separator', 'core/buttons', 'core/button', 'core/image', 'core/file', 'core/group', 'core/columns', 'core/column' );
		foreach ( $blocks as $block ) {
			if ( ! in_array( $block['blockName'], $allowed, true ) ) { $omitted = true; continue; }
			$html .= ! empty( $block['innerBlocks'] ) ? self::static_html( $block['innerBlocks'], $omitted ) : ( $block['innerHTML'] ?? '' );
			$html .= "\n";
		}
		return $html;
	}
	public static function material( int $id ) {
		$post = self::eligible( $id ); if ( ! $post ) { return null; }
		$omitted = false; $html = self::static_html( parse_blocks( $post->post_content ), $omitted );
		$text = self::text( $html ); $headings = array(); preg_match_all( '~<h[1-6]\b[^>]*>(.*?)</h[1-6]>~is', $html, $matches );
		foreach ( array_slice( $matches[1], 0, 30 ) as $heading ) { $headings[] = self::clip( self::text( $heading ), 150 ); }
		$terms = array();
		foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $taxonomy => $object ) {
			if ( empty( $object->public ) ) { continue; }
			$names = wp_get_object_terms( $id, $taxonomy, array( 'fields' => 'names' ) );
			if ( ! is_wp_error( $names ) ) { $terms[ $taxonomy ] = array_map( static function ( $name ) { return self::clip( sanitize_text_field( $name ), 100 ); }, array_slice( $names, 0, 20 ) ); }
		}
		$url = esc_url_raw( get_permalink( $id ), array( 'https', 'http' ) ); if ( ! $url ) { return null; }
		return array( 'id' => $id, 'title' => self::clip( self::text( $post->post_title ), 200 ), 'url' => $url, 'post_type' => $post->post_type, 'published_at' => $post->post_date, 'modified_at' => $post->post_modified, 'terms' => $terms, 'excerpt' => self::clip( self::text( $post->post_excerpt ?: $text ), 600 ), 'headings' => $headings, 'text' => self::clip( $text, 8000 ), 'body_truncated' => Blocks::length( $text ) > 8000, 'unsupported_blocks_omitted' => $omitted, 'extraction' => '保存本文の静的テキストのみ。ショートコード・動的/独自ブロック・ACF・PDF・画像内文字・外部資料は未確認。', 'source_hash' => self::hash( array( $post->post_title, $post->post_content, $post->post_excerpt, $post->post_modified, $url, $terms ) ) );
	}
	public static function remove( int $id ): void { delete_post_meta( $id, self::INDEX ); }
	public static function index_post( int $id ): bool {
		$item = self::material( $id );
		if ( ! $item ) { self::remove( $id ); return true; }
		$item['indexed_at'] = current_time( 'mysql' );
		update_post_meta( $id, self::INDEX, wp_slash( $item ) );
		return get_post_meta( $id, self::INDEX, true ) === $item;
	}
	public static function progress(): array { $raw = get_option( self::PROGRESS, array() ); return is_array( $raw ) ? $raw : array(); }
	/** Manual, bounded batches. Publication hooks maintain changes while a rebuild runs. */
	public static function batch( bool $restart, string $expected ) {
		if ( ! current_user_can( 'manage_options' ) ) { return new \WP_Error( 'permission', '索引作成には管理者権限が必要です。' ); }
		$settings = self::settings(); if ( 'off' === $settings['policy'] || ! $settings['types'] ) { return new \WP_Error( 'settings', '先に参照範囲を設定してください。' ); }
		$lock = OperationLock::acquire( self::LOCK, 'reference-index' ); if ( is_wp_error( $lock ) ) { return $lock; }
		try {
			$progress = self::progress(); if ( ! hash_equals( self::hash( array( $settings, $progress ) ), $expected ) ) { return new \WP_Error( 'changed', '作成状況が変わりました。画面を開き直してください。' ); }
			$scope = self::hash( $settings );
			if ( ! $restart && ( ( $progress['scope'] ?? '' ) !== $scope || ! empty( $progress['complete'] ) ) ) { return new \WP_Error( 'changed', '最初から索引を作成してください。' ); }
			$page = $restart ? 1 : ( $progress['page'] + 1 );
			$query = new \WP_Query( array( 'post_type' => $settings['types'], 'post_status' => 'publish', 'has_password' => false, 'posts_per_page' => 50, 'paged' => $page, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ids', 'ignore_sticky_posts' => true, 'suppress_filters' => false ) );
			foreach ( $query->posts as $id ) { if ( ! self::index_post( (int) $id ) ) { return new \WP_Error( 'save', '索引の保存に失敗しました。同じ範囲を再実行してください。' ); } }
			$value = array( 'scope' => $scope, 'page' => $page, 'complete' => $page >= (int) $query->max_num_pages, 'processed' => ( $page - 1 ) * 50 + count( $query->posts ), 'updated_at' => current_time( 'mysql' ) );
			return Store::write_option( self::PROGRESS, $value ) ? $value : new \WP_Error( 'save', '索引の作成状況を保存できませんでした。' );
		} finally { OperationLock::release( $lock ); }
	}
	public static function search( string $query, int $page = 1, string $type = '', string $category = '' ) {
		if ( ! self::allowed() || 'off' === self::policy() ) { return new \WP_Error( 'permission', '既存記事参照は無効か利用する権限がありません。' ); }
		$types = self::settings()['types'];
		if ( strlen( $query ) > 300 || $page < 1 || $page > 10000 || ( '' !== $type && ! in_array( $type, $types, true ) ) ) { return new \WP_Error( 'search', '検索条件を確認してください。' ); }
		$args = array( 'post_type' => $type ? array( $type ) : $types, 'post_status' => 'publish', 'has_password' => false, 's' => sanitize_text_field( $query ), 'posts_per_page' => 20, 'paged' => $page, 'orderby' => 'date', 'order' => 'DESC', 'fields' => 'ids', 'ignore_sticky_posts' => true, 'meta_key' => self::INDEX, 'suppress_filters' => false );
		if ( '' !== $category ) {
			if ( ! preg_match( '/^([a-z0-9_-]+):([1-9][0-9]*)$/D', $category, $match ) ) { return new \WP_Error( 'search', '分類の指定が正しくありません。' ); }
			$valid = false; foreach ( $args['post_type'] as $post_type ) { $taxonomies = get_object_taxonomies( $post_type, 'objects' ); if ( isset( $taxonomies[ $match[1] ] ) && ! empty( $taxonomies[ $match[1] ]->public ) && term_exists( (int) $match[2], $match[1] ) ) { $valid = true; } }
			if ( ! $valid ) { return new \WP_Error( 'search', '参照対象で利用できない分類です。' ); }
			$args['tax_query'] = array( array( 'taxonomy' => $match[1], 'field' => 'term_id', 'terms' => array( (int) $match[2] ) ) );
		}
		$found = new \WP_Query( $args ); $items = array();
		foreach ( $found->posts as $id ) {
			$item = self::material( (int) $id ); if ( ! $item ) { continue; }
			$reasons = array(); foreach ( array( 'title' => 'タイトル', 'headings' => '見出し', 'text' => '抽出本文' ) as $key => $label ) { $haystack = is_array( $item[ $key ] ) ? implode( ' ', $item[ $key ] ) : $item[ $key ]; if ( '' !== $query && false !== stripos( $haystack, $query ) ) { $reasons[] = $label . 'に検索語を含む'; } }
			$item['reasons'] = $reasons ?: array( '' === $query ? '対象範囲の新しい記事' : 'WordPress本文検索の候補（語句の一致）' ); $items[] = $item;
		}
		return array( 'items' => $items, 'found' => (int) $found->found_posts, 'pages' => (int) $found->max_num_pages, 'page' => $page, 'query' => $query, 'type' => $type, 'category' => $category );
	}
	public static function selection(): array {
		$all = get_user_meta( get_current_user_id(), self::SELECTED, true ); $value = is_array( $all ) ? ( $all[ get_current_blog_id() ] ?? array() ) : array(); return is_array( $value ) ? $value : array();
	}
	public static function revision(): string { return self::hash( array( Store::article(), self::settings(), self::policy(), self::selection() ) ); }
	private static function criteria(): string { return self::hash( array( array_diff_key( Store::article(), array( 'updated_at' => true ) ), self::settings(), self::policy() ) ); }
	public static function save_selection( array $raw ) {
		if ( ! self::allowed() || 'off' === self::policy() || empty( Store::article()['article_key'] ) ) { return new \WP_Error( 'permission', '先に今回の記事条件を保存し、参照を有効にしてください。' ); }
		if ( ! is_string( $raw['revision'] ?? null ) || ! hash_equals( self::revision(), $raw['revision'] ) ) { return new \WP_Error( 'changed', '記事条件・設定・選択が変わりました。画面を開き直してください。' ); }
		$ids = $raw['ids'] ?? array(); $details = $raw['details'] ?? array(); $hashes = $raw['hashes'] ?? array(); $intent = $raw['intent'] ?? 'compare';
		if ( '1' !== ( $raw['confirmed'] ?? '' ) || ! is_array( $ids ) || count( $ids ) > 5 || ! is_array( $details ) || ! is_array( $hashes ) || ! is_string( $intent ) || ! in_array( $intent, array( 'compare', 'ideas' ), true ) ) { return new \WP_Error( 'selection', '最大5件と参照内容を確認し、確認済みを選んでください。' ); }
		$query = is_string( $raw['query'] ?? null ) ? sanitize_text_field( $raw['query'] ) : ''; $type = is_string( $raw['type'] ?? null ) ? $raw['type'] : ''; $category = is_string( $raw['category'] ?? null ) ? $raw['category'] : '';
		$found = self::search( $query, 1, $type, $category ); if ( is_wp_error( $found ) ) { return $found; }
		$items = array();
		foreach ( $ids as $id ) {
			if ( ! is_string( $id ) || ! preg_match( '/^[1-9][0-9]{0,9}$/D', $id ) || (string) (int) $id !== $id || isset( $items[ $id ] ) ) { return new \WP_Error( 'selection', '参照する記事IDを選び直してください。' ); }
			$item = self::material( (int) $id ); if ( ! $item ) { return new \WP_Error( 'selection', '非公開・削除・対象外の記事は参照できません。' ); }
			if ( ! is_string( $hashes[ $id ] ?? null ) || ! hash_equals( self::hash( $item ), $hashes[ $id ] ) ) { return new \WP_Error( 'changed', '確認中に参照記事が変更されました。画面を開き直して内容を再確認してください。' ); }
			$mode = $details[ $id ] ?? 'summary'; if ( ! is_string( $mode ) || ! in_array( $mode, array( 'summary', 'body' ), true ) ) { return new \WP_Error( 'selection', '参照する本文範囲が正しくありません。' ); }
			$items[ $id ] = array( 'id' => (int) $id, 'mode' => $mode, 'hash' => self::hash( $item ) );
		}
		$value = array( 'article_key' => Store::article()['article_key'], 'criteria' => self::criteria(), 'items' => array_values( $items ), 'intent' => $intent, 'query' => $query, 'type' => $type, 'category' => $category, 'matched_at_selection' => $found['found'], 'checked_at' => current_time( 'mysql' ) );
		$all = get_user_meta( get_current_user_id(), self::SELECTED, true ); $all = is_array( $all ) ? $all : array(); $all[ get_current_blog_id() ] = $value;
		update_user_meta( get_current_user_id(), self::SELECTED, wp_slash( $all ) );
		return get_user_meta( get_current_user_id(), self::SELECTED, true ) === $all ? $value : new \WP_Error( 'save', '参照記事を保存できませんでした。' );
	}
	public static function context() {
		$policy = self::policy(); if ( 'off' === $policy ) { return array(); }
		if ( ! self::allowed() ) { return new \WP_Error( 'permission', '既存記事を参照する権限がありません。' ); }
		$saved = self::selection(); $key = Store::article()['article_key'] ?? '';
		if ( ! $saved || ( $saved['article_key'] ?? '' ) !== $key ) { return 'required' === $policy ? new \WP_Error( 'reference', '既存記事の候補と参照範囲を確認して保存してください。選択0件でも確認を記録できます。' ) : array( 'policy' => $policy, 'status' => 'not_checked', 'items' => array() ); }
		if ( ! hash_equals( $saved['criteria'], self::criteria() ) ) { return new \WP_Error( 'reference', 'テーマ・分類・参照設定が変わりました。既存記事を再確認して保存してください。' ); }
		$items = array(); foreach ( $saved['items'] as $selection ) {
			$item = self::material( $selection['id'] );
			if ( ! $item || ! hash_equals( $selection['hash'], self::hash( $item ) ) ) { return new \WP_Error( 'reference', '参照記事が変更・非公開・削除されています。既存記事を再確認して保存してください。' ); }
			$item['comparison_scope'] = $selection['mode']; unset( $item['source_hash'] );
			if ( 'summary' === $selection['mode'] ) { unset( $item['text'] ); }
			$items[] = $item;
		}
		$progress = self::progress();
		return array( 'policy' => $policy, 'status' => 'selected_scope_checked', 'intent' => $saved['intent'], 'checked_at' => $saved['checked_at'], 'reference_types' => self::settings()['types'], 'index_batch_complete' => ! empty( $progress['complete'] ) && ( $progress['scope'] ?? '' ) === self::hash( self::settings() ), 'search' => array_intersect_key( $saved, array_flip( array( 'query', 'type', 'category', 'matched_at_selection' ) ) ), 'items' => $items, 'limitations' => '索引とWordPress語句検索から利用者が選んだ最大5記事だけを参照。検索結果は確認保存時点。新規記事等は再検索が必要。検索語の表記違い・未索引・対象外の記事を網羅せず、意味的な重複なしを保証しない。' );
	}
}
