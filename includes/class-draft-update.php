<?php
namespace AAA;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Text-only updates to an explicitly selected, plugin-managed, own draft. */
final class DraftUpdate {
	const BACKUP = '_aaa_update_backup_v1';
	public static function storage_key(): string { return 'aaa_update_' . get_current_blog_id() . '_' . get_current_user_id(); }
	public static function session(): array { $value = get_transient( self::storage_key() ); return is_array( $value ) ? $value : array(); }
	public static function hash( array $value ): string { return hash( 'sha256', wp_json_encode( $value ) ); }

	public static function target( int $id ) {
		if ( ! current_user_can( 'read' ) || ! current_user_can( 'edit_post', $id ) ) { return new \WP_Error( 'target', 'この下書きを更新する権限がありません。' ); }
		$post = get_post( $id );
		if ( ! $post || 'draft' !== $post->post_status || (int) $post->post_author !== get_current_user_id() || ! Store::can_create( $post->post_type ) || ! post_type_supports( $post->post_type, 'editor' ) ) { return new \WP_Error( 'target', '本人が作成した利用可能な下書きだけを更新できます。公開・予約・他人の記事は対象外です。' ); }
		$key = get_post_meta( $id, '_aaa_article_key', true );
		if ( ! is_string( $key ) || ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $key ) ) { return new \WP_Error( 'key', 'このプラグインから取り込んだ管理ID付きの下書きを選んでください。' ); }
		$result = array( 'ID' => $id, 'article_key' => $key );
		foreach ( array( 'post_type', 'post_name', 'post_title', 'post_content', 'post_excerpt', 'post_status', 'post_modified_gmt', 'post_modified', 'post_password' ) as $field ) { $result[ $field ] = (string) $post->$field; }
		$result['post_author'] = (int) $post->post_author;
		if ( strlen( $result['post_content'] ) > 2097152 ) { return new \WP_Error( 'size', '本文が大きいため、WordPress標準エディターで編集してください。' ); }
		return $result;
	}

	public static function supported( array $blocks ): bool {
		foreach ( $blocks as $block ) {
			if ( null === $block['blockName'] && '' === trim( $block['innerHTML'] ?? '' ) ) { continue; }
			if ( ! in_array( $block['blockName'], array( 'core/paragraph', 'core/heading', 'core/list', 'core/list-item', 'core/quote', 'core/separator', 'core/buttons', 'core/button', 'core/table' ), true ) || ! self::supported( $block['innerBlocks'] ?? array() ) ) { return false; }
		}
		return true;
	}

	private static function ready( array $before ) {
		if ( wp_check_post_lock( $before['ID'] ) ) { return new \WP_Error( 'editing', '別の利用者が編集中です。更新せず、編集終了後に確認し直してください。' ); }
		if ( ! self::supported( parse_blocks( $before['post_content'] ) ) || preg_match( '~<(?:img|object|iframe|video|audio)\b|wp-block-file~i', $before['post_content'] ) ) { return new \WP_Error( 'blocks', '画像・PDF・独自ブロック等を含む本文は、今回はWordPress標準エディターで更新してください。省略による消失を防ぐため停止しました。' ); }
		return true;
	}

	private static function changes( array $item, int $id ) {
		$result = array();
		foreach ( array( 'post_title', 'post_content', 'post_excerpt' ) as $field ) {
			$value = $item[ $field ];
			$filtered = wp_unslash( sanitize_post_field( $field, wp_slash( $value ), $id, 'db' ) );
			if ( ! is_string( $filtered ) || $filtered !== $value ) { return new \WP_Error( 'filter', 'WordPressの保存時フィルターで内容が変更されます。標準エディターで確認・保存してください。' ); }
			$result[ $field ] = $value;
		}
		return $result;
	}

	public static function preview( int $id, string $source, string $expected ) {
		$lock = OperationLock::acquire( Importer::LOCK, 'draft-preview' ); if ( is_wp_error( $lock ) ) { return $lock; }
		try {
			delete_transient( self::storage_key() ); if ( self::session() ) { return new \WP_Error( 'save', '以前の更新確認を失効させられませんでした。' ); }
			clean_post_cache( $id ); $before = self::target( $id ); if ( is_wp_error( $before ) ) { return $before; }
			if ( ! hash_equals( self::hash( $before ), $expected ) ) { return new \WP_Error( 'changed', '対象の記事が変わりました。選び直してください。' ); }
			$ready = self::ready( $before ); if ( is_wp_error( $ready ) ) { return $ready; }
			$rows = Csv::parse( $source ); if ( is_wp_error( $rows ) ) { return $rows; }
			$check = CsvValidator::check( $rows, $id );
			if ( $check['errors'] ) { return new \WP_Error( 'invalid', implode( "\n", $check['errors'] ) ); }
			$after = self::changes( $check['items'][0], $id ); if ( is_wp_error( $after ) ) { return $after; }
			if ( ! array_diff_assoc( $after, array_intersect_key( $before, $after ) ) ) { return new \WP_Error( 'same', 'タイトル・本文・抜粋に変更はありません。' ); }
			$stage = array( 'state' => 'preview', 'token' => wp_generate_uuid4(), 'before' => $before, 'after' => $after, 'source' => $source, 'check' => $check );
			set_transient( self::storage_key(), $stage, 1800 );
			return self::session() === $stage ? $stage : new \WP_Error( 'save', '更新内容を一時保存できませんでした。' );
		} finally { OperationLock::release( $lock ); }
	}

	/** Atomic conditional write: a normal wp_update_post() cannot compare the original content at SQL write time. */
	private static function replace( array $before, array $after ): int {
		global $wpdb;
		$sql = $wpdb->prepare(
			"UPDATE {$wpdb->posts} SET post_title=%s, post_content=%s, post_excerpt=%s, post_modified=%s, post_modified_gmt=%s WHERE ID=%d AND post_author=%d AND post_status='draft' AND BINARY post_type=%s AND BINARY post_name=%s AND BINARY post_title=%s AND BINARY post_content=%s AND BINARY post_excerpt=%s AND BINARY post_modified=%s AND BINARY post_modified_gmt=%s AND BINARY post_password=%s AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} AS aaa_identity WHERE aaa_identity.post_id=%d AND aaa_identity.meta_key='_aaa_article_key' AND BINARY aaa_identity.meta_value=%s)",
			$after['post_title'], $after['post_content'], $after['post_excerpt'], current_time( 'mysql' ), current_time( 'mysql', true ), $before['ID'], get_current_user_id(), $before['post_type'], $before['post_name'], $before['post_title'], $before['post_content'], $before['post_excerpt'], $before['post_modified'], $before['post_modified_gmt'], $before['post_password'], $before['ID'], $before['article_key']
		);
		$result = $wpdb->query( $sql );
		return false === $result ? -1 : (int) $result;
	}

	public static function commit( string $token ) {
		$lock = OperationLock::acquire( Importer::LOCK, 'draft-commit' ); if ( is_wp_error( $lock ) ) { return $lock; }
		try {
			$stage = self::session();
			if ( ! $stage || 'preview' !== $stage['state'] || ! hash_equals( $stage['token'], $token ) ) { return new \WP_Error( 'token', '更新確認が期限切れ、実行済み、または別の利用者のものです。' ); }
			$id = $stage['before']['ID']; clean_post_cache( $id ); $before = self::target( $id );
			if ( is_wp_error( $before ) || $before !== $stage['before'] ) { return new \WP_Error( 'changed', '確認後に対象・権限・状態が変わりました。更新内容を確認し直してください。' ); }
			$ready = self::ready( $before ); if ( is_wp_error( $ready ) ) { return $ready; }
			$rows = Csv::parse( $stage['source'] ); if ( is_wp_error( $rows ) ) { return $rows; }
			$check = CsvValidator::check( $rows, $id );
			$after = $check['errors'] ? null : self::changes( $check['items'][0], $id );
			if ( $check['errors'] || is_wp_error( $after ) || $after !== $stage['after'] || $check['fingerprint'] !== $stage['check']['fingerprint'] ) { return new \WP_Error( 'changed', '確認後に入力・品質設定・保存条件が変わりました。再確認してください。' ); }
			$stage['state'] = 'consumed'; unset( $stage['source'] ); set_transient( self::storage_key(), $stage, 1800 );
			if ( self::session() !== $stage ) { return new \WP_Error( 'save', '実行状態を保存できないため更新しませんでした。' ); }
			$backup = array( 'token' => $token, 'before' => $before, 'saved_at' => current_time( 'mysql' ) );
			update_post_meta( $id, self::BACKUP, wp_slash( $backup ) );
			if ( get_post_meta( $id, self::BACKUP, true ) !== $backup ) { return new \WP_Error( 'backup', '更新前の控えを保存できないため更新しませんでした。' ); }
			if ( post_type_supports( $before['post_type'], 'revisions' ) ) { $revision = wp_save_post_revision( $id ); if ( is_wp_error( $revision ) ) { return new \WP_Error( 'revision', 'リビジョン保存時にエラーが発生したため更新しませんでした。' ); } }
			// Permission/lock hooks may have changed since backup or revision callbacks.
			clean_post_cache( $id ); $latest = self::target( $id );
			if ( is_wp_error( $latest ) || $latest !== $before || wp_check_post_lock( $id ) ) { return new \WP_Error( 'changed', '保存直前に対象が変わりました。更新しませんでした。' ); }
			$result = self::replace( $before, $after ); clean_post_cache( $id );
			if ( 1 !== $result ) { return new \WP_Error( 'conflict', '対象が同時に変更されたか、保存に失敗しました。自動再試行しません。下書きを確認してください。' ); }
			$saved = self::target( $id );
			if ( is_wp_error( $saved ) || array_intersect_key( $saved, $after ) !== $after ) { return new \WP_Error( 'verify', '書込み後の内容を確認できません。更新された可能性があるため、標準の投稿一覧で確認してください。' ); }
			$stage['state'] = 'complete'; set_transient( self::storage_key(), $stage, 1800 );
			return self::session() === $stage ? $stage : new \WP_Error( 'report', '更新結果を保存できませんでした。再実行せず下書きを確認してください。' );
		} finally { OperationLock::release( $lock ); }
	}
}
