<?php
namespace AAA;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Portable shared settings only. Never accepts arbitrary option names or user metadata. */
final class Transfer {
	const BACKUP = 'aaa_transfer_backup_v1';
	const LIMIT = 1048576;
	public static function allowed(): bool { return current_user_can( 'read' ) && current_user_can( 'manage_options' ); }
	public static function storage_key(): string { return 'aaa_transfer_' . get_current_blog_id() . '_' . get_current_user_id(); }
	public static function session(): array { $value = get_transient( self::storage_key() ); return is_array( $value ) ? $value : array(); }
	private static function keys(): array { return array( Config::SITE, Config::PROFILES, Config::DESTINATIONS ); }
	private static function snapshot(): array {
		$result = array(); foreach ( self::keys() as $key ) { $result[ $key ] = get_option( $key, null ); } return $result;
	}
	public static function export() {
		if ( ! self::allowed() ) { return new \WP_Error( 'permission', '設定移行には管理者権限が必要です。' ); }
		$json = self::portable_json( Store::site(), Store::profiles(), Store::enabled() );
		$checked = self::parse( $json );
		return is_wp_error( $checked ) ? $checked : $json;
	}
	private static function portable_json( array $site, array $saved_profiles, array $destinations ): string {
		$profiles = array();
		foreach ( $saved_profiles as $id => $profile ) {
			$profiles[ $id ] = array_intersect_key( $profile, Config::profile_fields() ) + array( 'schema_version' => 1 );
		}
		$data = array( 'format' => 'aaa-shared-settings', 'schema_version' => 1, 'site' => (object) array_intersect_key( $site, Config::site_fields() ), 'profiles' => (object) array_map( static function ( $profile ) { return (object) $profile; }, $profiles ), 'destinations' => $destinations );
		return (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}
	private static function fields( $object, array $fields ) {
		if ( ! $object instanceof \stdClass || array_diff( array_keys( (array) $object ), array_keys( $fields ) ) ) { return new \WP_Error( 'fields', '未対応の設定項目または形式が含まれています。' ); }
		foreach ( (array) $object as $value ) { if ( ! is_string( $value ) && ! is_int( $value ) ) { return new \WP_Error( 'fields', '設定値は文字列または整数で指定してください。' ); } }
		return Store::sanitize( $fields, (array) $object );
	}
	public static function parse( string $json ) {
		if ( '' === $json || strlen( $json ) > self::LIMIT ) { return new \WP_Error( 'size', '設定JSONは空でないUTF-8、1MB以下で指定してください。' ); }
		$data = json_decode( preg_replace( '/^\xEF\xBB\xBF/', '', $json ), false, 32 );
		if ( JSON_ERROR_NONE !== json_last_error() || ! $data instanceof \stdClass ) { return new \WP_Error( 'json', 'UTF-8の設定JSONを指定してください。' ); }
		$keys = array( 'format', 'schema_version', 'site', 'profiles', 'destinations' );
		if ( array_diff( array_keys( (array) $data ), $keys ) || array_diff( $keys, array_keys( (array) $data ) ) || 'aaa-shared-settings' !== $data->format || 1 !== $data->schema_version ) { return new \WP_Error( 'schema', 'この設定ファイルの形式・バージョンには対応していません。' ); }
		$site = self::fields( $data->site, Config::site_fields() ); if ( is_wp_error( $site ) ) { return $site; }
		if ( ! $data->profiles instanceof \stdClass || count( (array) $data->profiles ) > 100 ) { return new \WP_Error( 'profiles', '記事の種類は最大100件のオブジェクトで指定してください。' ); }
		$profiles = array();
		foreach ( $data->profiles as $id => $profile ) {
			if ( ! preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', (string) $id ) || ! $profile instanceof \stdClass || 1 !== ( $profile->schema_version ?? null ) ) { return new \WP_Error( 'profile', '記事の種類のIDまたは形式が正しくありません。' ); }
			$raw = clone $profile; unset( $raw->schema_version );
			$value = self::fields( $raw, Config::profile_fields() ); if ( is_wp_error( $value ) ) { return $value; }
			if ( '' === trim( $value['name'] ) ) { return new \WP_Error( 'profile', '記事の種類の名前が空です。' ); }
			$profiles[ $id ] = $value + array( 'schema_version' => 1 );
		}
		if ( ! is_array( $data->destinations ) || count( $data->destinations ) > 100 ) { return new \WP_Error( 'destinations', '投稿先は最大100件の配列で指定してください。' ); }
		foreach ( $data->destinations as $type ) { if ( ! is_string( $type ) || ! isset( Store::types()[ $type ] ) ) { return new \WP_Error( 'destinations', '移行先に存在しない、または利用できない投稿先があります。投稿先を登録するか、JSONのdestinationsを修正してください。' ); } }
		return array( Config::SITE => $site, Config::PROFILES => $profiles, Config::DESTINATIONS => array_values( array_unique( $data->destinations ) ) );
	}
	public static function preview( string $json ) {
		if ( ! self::allowed() ) { return new \WP_Error( 'permission', '設定移行には管理者権限が必要です。' ); }
		delete_transient( self::storage_key() );
		if ( self::session() ) { return new \WP_Error( 'save', '以前の確認内容を消去できませんでした。' ); }
		$incoming = self::parse( $json ); if ( is_wp_error( $incoming ) ) { return $incoming; }
		$before = self::snapshot(); $after = $incoming;
		$after[ Config::PROFILES ] = array_replace( Store::profiles(), $incoming[ Config::PROFILES ] );
		$portable = self::parse( self::portable_json( $after[ Config::SITE ], $after[ Config::PROFILES ], $after[ Config::DESTINATIONS ] ) );
		if ( is_wp_error( $portable ) ) { return new \WP_Error( 'capacity', '統合後の設定が移行形式の上限を超えるか、既存設定に未対応の値があります。記事の種類数・内容を確認してください。' ); }
		$stage = array( 'state' => 'preview', 'token' => wp_generate_uuid4(), 'source' => $json, 'before' => $before, 'after' => $after, 'created_at' => current_time( 'mysql' ) );
		set_transient( self::storage_key(), $stage, 1800 );
		return self::session() === $stage ? $stage : new \WP_Error( 'save', '確認内容を一時保存できませんでした。' );
	}
	public static function commit( string $token ) {
		if ( ! self::allowed() ) { return new \WP_Error( 'permission', '設定移行には管理者権限が必要です。' ); }
		$lock = OperationLock::acquire( Importer::LOCK, 'settings-transfer' ); if ( is_wp_error( $lock ) ) { return $lock; }
		try {
			$stage = self::session();
			if ( ! $stage || 'preview' !== $stage['state'] || ! hash_equals( $stage['token'], $token ) ) { return new \WP_Error( 'token', '確認の期限が切れたか実行済みです。JSONを確認し直してください。' ); }
			$incoming = self::parse( $stage['source'] );
			if ( is_wp_error( $incoming ) || self::snapshot() !== $stage['before'] ) { return new \WP_Error( 'changed', '確認後に設定または投稿先が変わりました。JSONを確認し直してください。' ); }
			$backup = self::export(); if ( is_wp_error( $backup ) ) { return $backup; }
			$record = array( 'json' => $backup, 'created_at' => current_time( 'mysql' ), 'user' => get_current_user_id() );
			if ( ! Store::write_option( self::BACKUP, $record ) ) { return new \WP_Error( 'backup', '適用前の控えを保存できないため中止しました。' ); }
			$stage['state'] = 'consumed'; set_transient( self::storage_key(), $stage, 1800 );
			if ( self::session() !== $stage ) { return new \WP_Error( 'save', '実行状態を保存できないため中止しました。' ); }
			$written = array();
			foreach ( self::keys() as $key ) {
				// Standard option writes are not a database transaction. Detect intervening edits where possible.
				if ( get_option( $key, null ) !== $stage['before'][ $key ] || ! Store::write_option( $key, $stage['after'][ $key ] ) ) {
					$restored = get_option( $key, null ) === $stage['before'][ $key ];
					foreach ( array_reverse( $written ) as $previous ) {
						if ( get_option( $previous, null ) !== $stage['after'][ $previous ] ) { $restored = false; continue; }
						if ( null === $stage['before'][ $previous ] ) { delete_option( $previous ); } else { update_option( $previous, $stage['before'][ $previous ], false ); }
						$restored = $restored && get_option( $previous, null ) === $stage['before'][ $previous ];
					}
					return new \WP_Error( 'write', $restored ? '設定の適用に失敗しました。先に保存した項目は元に戻しました。設定画面を確認してください。' : '設定の適用と一部の復元に失敗しました。保存前の控えと現在の設定を比較して手動で戻してください。' );
				}
				$written[] = $key;
			}
			if ( self::snapshot() !== $stage['after'] ) { return new \WP_Error( 'report', '保存中に別の設定変更を検出しました。各設定画面と適用前の控えを比較してください。' ); }
			$stage = array( 'state' => 'complete', 'token' => $token ); set_transient( self::storage_key(), $stage, 1800 );
			return self::session() === $stage ? $stage : new \WP_Error( 'report', '設定は適用済みですが結果を保存できませんでした。各設定画面を確認してください。' );
		} finally { OperationLock::release( $lock ); }
	}
}
