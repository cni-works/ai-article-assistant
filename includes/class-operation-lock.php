<?php
namespace AAA;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Ownership-safe option locks. An advisory lock protects live requests beyond the lease. */
final class OperationLock {
	const TTL = 900;
	private static array $held = array();
	private static bool $shutdown = false;

	private static function cache_clear( string $name ): void {
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
	}

	private static function raw( string $name ) {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM %i WHERE option_name = %s", $wpdb->options, $name ) );
	}

	/** Compare and delete atomically; never delete a successor's lock. */
	private static function remove( string $name, string $raw ): bool {
		global $wpdb;
		$removed = $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE option_name = %s AND BINARY option_value = BINARY %s", $wpdb->options, $name, $raw ) );
		self::cache_clear( $name );
		return 1 === $removed;
	}

	private static function advisory( string $function, string $name ) {
		global $wpdb;
		$previous = $wpdb->suppress_errors( true );
		try {
			return $wpdb->get_var( $wpdb->prepare( 'GET_LOCK' === $function ? 'SELECT GET_LOCK(%s, 0)' : 'SELECT RELEASE_LOCK(%s)', $name ) );
		} finally { $wpdb->suppress_errors( $previous ); }
	}

	public static function acquire( string $name, string $operation ) {
		global $wpdb;
		$guard = 'aaa_' . substr( hash( 'sha256', DB_NAME . ':' . $wpdb->options . ':' . $name ), 0, 60 );
		if ( isset( self::$held[ $guard ] ) ) { return new \WP_Error( 'busy', '別の処理が実行中です。完了後に再試行してください。' ); }
		$status = self::advisory( 'GET_LOCK', $guard );
		if ( '0' === (string) $status ) { return new \WP_Error( 'busy', '別の処理が実行中です。完了後に再試行してください。' ); }
		$guarded = '1' === (string) $status;
		$keep_guard = false;
		try {
			$raw = self::raw( $name );
			if ( null !== $raw ) {
				$old = maybe_unserialize( $raw );
				$expired = is_array( $old ) && isset( $old['started'] ) && is_int( $old['started'] ) && $old['started'] > 0 && $old['started'] <= time() - self::TTL;
				// Older releases did not hold an advisory lock: never assume those requests are dead.
				if ( ! $guarded || ! $expired || true !== ( $old['guarded'] ?? null ) || ! is_string( $old['owner'] ?? null ) || '' === $old['owner'] ) {
					return new \WP_Error( 'busy', '別の処理のロックが残っています。時間をおいて再試行してください。解消しない場合は管理者へ確認してください。旧形式・期限不明・排他確認不能のロックは自動解除しません。' );
				}
				if ( ! self::remove( $name, $raw ) ) { return new \WP_Error( 'busy', '処理状態が変わりました。再試行してください。' ); }
			}
			self::cache_clear( $name );
			$record = array( 'owner' => wp_generate_uuid4(), 'started' => time(), 'user' => get_current_user_id(), 'operation' => $operation, 'guarded' => $guarded );
			if ( ! add_option( $name, $record, '', false ) ) { return new \WP_Error( 'busy', '処理ロックを取得できませんでした。再試行してください。' ); }
			if ( self::raw( $name ) !== maybe_serialize( $record ) ) { return new \WP_Error( 'busy', '処理ロックの保存結果を確認できません。管理者に確認してください。' ); }
			$handle = array( 'name' => $name, 'guard' => $guard, 'raw' => maybe_serialize( $record ), 'guarded' => $guarded );
			self::$held[ $guard ] = $handle;
			$keep_guard = true;
			if ( ! self::$shutdown ) {
				register_shutdown_function( static function () { foreach ( self::$held as $held ) { self::release( $held ); } } );
				self::$shutdown = true;
			}
			return $handle;
		} finally { if ( $guarded && ! $keep_guard ) { self::advisory( 'RELEASE_LOCK', $guard ); } }
	}

	public static function release( array $handle ): void {
		if ( ( self::$held[ $handle['guard'] ] ?? null ) !== $handle ) { return; }
		try { self::remove( $handle['name'], $handle['raw'] ); }
		finally {
			unset( self::$held[ $handle['guard'] ] );
			if ( $handle['guarded'] ) { self::advisory( 'RELEASE_LOCK', $handle['guard'] ); }
		}
	}
}
