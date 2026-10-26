<?php
namespace AAA;
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Shared administrator-approved assets. Stored block URLs never refer to the plugin directory. */
final class Placeholder {
	const SETTINGS = 'aaa_placeholder_settings_v1';
	const ASSET = 'aaa_placeholder_asset_v1';
	const LOCK = 'aaa_placeholder_asset_lock_v1';
	const MARKER = '_aaa_builtin_placeholder_v1';
	public function __construct() {
		add_action( 'admin_init', array( self::class, 'initialize' ) );
		add_action( 'admin_post_aaa_placeholder_save', array( self::class, 'save' ) );
		add_action( 'admin_notices', array( self::class, 'notice' ) );
		add_filter( 'display_post_states', array( self::class, 'post_states' ), 10, 2 );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'editor' ) );
	}
	public static function settings(): array {
		$raw = get_option( self::SETTINGS, array() );
		return array_replace( array( 'body' => 'hold', 'featured' => 'hold', 'landscape' => 0, 'square' => 0, 'portrait' => 0, 'publication' => 'warn' ), is_array( $raw ) ? $raw : array() );
	}
	public static function source(): string {
		$base = dirname( __DIR__ ) . '/assets/images/placeholder-dummy';
		return is_file( $base . '.webp' ) ? $base . '.webp' : ( is_file( $base . '.jpg' ) ? $base . '.jpg' : '' );
	}
	public static function options( array $raw, array $allowed ) {
		$result = array();
		foreach ( $raw as $key => $options ) {
			if ( ! isset( $allowed[ $key ] ) || ! is_array( $options ) || array_diff( array_keys( $options ), array( 'body', 'featured', 'items', 'aspects' ) ) ) { return new \WP_Error( 'options', '画像設定の対象を確認してください。' ); }
			foreach ( array( 'body', 'featured' ) as $field ) { $value = $options[ $field ] ?? 'inherit'; if ( ! in_array( $value, array( 'inherit', 'hold', 'dummy' ), true ) ) { return new \WP_Error( 'options', '記事ごとの仮配置設定が不正です。' ); } $result[ $key ][ $field ] = $value; }
			foreach ( array( 'items', 'aspects' ) as $field ) {
				$values = $options[ $field ] ?? array();
				if ( ! is_array( $values ) || array_diff_key( $values, $allowed[ $key ] ) ) { return new \WP_Error( 'options', 'CSVにない画像設定は使えません。' ); }
				foreach ( $values as $ref => $value ) {
					if ( ! in_array( $value, 'items' === $field ? array( 'auto', 'real', 'generated', 'dummy', 'hold' ) : array( 'landscape', 'square', 'portrait' ), true ) ) { return new \WP_Error( 'options', '画像ごとの仮配置設定が不正です。' ); }
					if ( 'items' === $field && 'dummy' === $value && 'pdf' === $allowed[ $key ][ $ref ]['kind'] ) { return new \WP_Error( 'options', 'PDFをダミー画像へ置き換えることはできません。' ); }
					$result[ $key ][ $field ][ $ref ] = $value;
				}
			}
		}
		return $result;
	}
	/** Shared use does not grant authors edit access to the administrator's attachment. */
	public static function asset( int $id ): ?array {
		if ( ! $id || ! current_user_can( 'upload_files' ) ) { return null; }
		$post = get_post( $id );
		if ( ! $post || 'attachment' !== $post->post_type || 'trash' === $post->post_status || ! in_array( get_post_mime_type( $id ), array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ), true ) ) { return null; }
		$url = wp_get_attachment_image_url( $id, 'full' );
		try { $url = Blocks::url( $url ); } catch ( \InvalidArgumentException $error ) { return null; }
		if ( ! preg_match( '~^https?://~i', $url ) ) { return null; }
		return array( 'attachment_id' => $id, 'url' => $url, 'filename' => sanitize_text_field( rawurldecode( basename( wp_parse_url( $url )['path'] ?? '' ) ) ), 'mime' => get_post_mime_type( $id ), 'modified_gmt' => (string) ( $post->post_modified_gmt ?? '' ) );
	}
	public static function initialize(): void {
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'upload_files' ) ) { return; }
		$record = get_option( self::ASSET, array() );
		if ( self::asset( (int) ( $record['id'] ?? 0 ) ) || get_transient( 'aaa_placeholder_retry_v1' ) ) { return; }
		$result = self::ensure();
		if ( is_wp_error( $result ) ) { set_transient( 'aaa_placeholder_retry_v1', true, 3600 ); }
	}
	public static function activate(): void { delete_transient( 'aaa_placeholder_retry_v1' ); self::initialize(); }
	public static function ensure() {
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'upload_files' ) ) { return new \WP_Error( 'permission', '標準画像の登録には管理者・アップロード権限が必要です。' ); }
		$lock = OperationLock::acquire( self::LOCK, 'placeholder-register' ); if ( is_wp_error( $lock ) ) { return $lock; }
		try {
			$record = get_option( self::ASSET, array() );
			if ( self::asset( (int) ( $record['id'] ?? 0 ) ) ) { return (int) $record['id']; }
			// Recover an insertion whose settings write failed; never choose an attachment by filename.
			$ids = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => 10, 'fields' => 'ids', 'meta_key' => self::MARKER, 'meta_value' => 'standard', 'suppress_filters' => true ) );
			foreach ( $ids as $id ) { if ( self::asset( (int) $id ) ) { return Store::write_option( self::ASSET, array( 'id' => (int) $id ) ) ? (int) $id : new \WP_Error( 'save', '登録済み標準画像IDを保存できません。' ); } }
			$file = self::source();
			if ( ! $file ) { return new \WP_Error( 'asset', '標準ダミー画像が未同梱です。管理者がメディアから指定するか、placeholder-dummy.webpを同梱してください。' ); }
			$info = getimagesize( $file );
			if ( ! $info || ( $info['mime'] ?? '' ) !== ( 'webp' === pathinfo( $file, PATHINFO_EXTENSION ) ? 'image/webp' : 'image/jpeg' ) ) { return new \WP_Error( 'asset', '標準画像の実ファイル形式と拡張子を確認できません。' ); }
			if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) { require_once ABSPATH . 'wp-admin/includes/image.php'; }
			$bytes = file_get_contents( $file );
			if ( false === $bytes ) { return new \WP_Error( 'asset', '標準画像を読み取れません。' ); }
			$upload = wp_upload_bits( basename( $file ), null, $bytes );
			if ( ! empty( $upload['error'] ) ) { return new \WP_Error( 'upload', '標準画像のアップロードに失敗しました。サーバーの書込権限・容量を確認してください。' ); }
			$id = wp_insert_attachment( wp_slash( array( 'post_title' => '記事制作の仮画像（dummy）', 'post_mime_type' => $info['mime'], 'post_status' => 'inherit', 'meta_input' => array( self::MARKER => 'standard' ) ) ), $upload['file'], 0, true );
			if ( is_wp_error( $id ) || ! $id ) { return new \WP_Error( 'attachment', '標準画像のメディア登録に失敗しました。アップロード済みファイルは自動削除しません。' ); }
			wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );
			if ( ! self::asset( (int) $id ) ) { return new \WP_Error( 'attachment', '登録した標準画像を利用できません。メディアライブラリを確認してください。' ); }
			return Store::write_option( self::ASSET, array( 'id' => (int) $id, 'source_sha256' => hash( 'sha256', $bytes ) ) ) ? (int) $id : new \WP_Error( 'save', '標準画像IDを保存できませんでした。登録済みメディアは保持しています。' );
		} catch ( \Throwable $error ) { return new \WP_Error( 'asset', '標準画像の登録中に問題が発生しました。メディアライブラリと登録ボタンで再確認してください。作成済み画像は自動削除しません。' ); }
		finally { OperationLock::release( $lock ); }
	}
	public static function choose( array $plan, ?array $real, array $options, string $ref ): array {
		$settings = self::settings();
		$mode = $options['items'][ $ref ] ?? 'auto';
		$default = $options[ $plan['featured'] ? 'featured' : 'body' ] ?? 'inherit';
		if ( 'inherit' === $default ) { $default = $settings[ $plan['featured'] ? 'featured' : 'body' ]; }
		if ( 'auto' === $mode ) { $mode = $real ? 'real' : $default; }
		$result = array( 'placement_state' => $real ? 'real' : 'hold', 'is_placeholder' => false, 'original_attachment_id' => $real['attachment_id'] ?? 0 );
		if ( 'pdf' === $plan['kind'] ) { return array( $real, $result ); }
		if ( in_array( $mode, array( 'real', 'generated' ), true ) ) { $result['placement_state'] = $real ? $mode : 'hold'; return array( $real, $result ); }
		if ( 'dummy' !== $mode ) { $result['placement_state'] = 'hold'; return array( null, $result ); }
		$shape = $options['aspects'][ $ref ] ?? 'landscape';
		$id = (int) ( $settings[ $shape ] ?: $settings['landscape'] );
		if ( ! $id ) { $id = (int) ( get_option( self::ASSET, array() )['id'] ?? 0 ); }
		$dummy = self::asset( $id );
		$result = array( 'placement_state' => $dummy ? 'dummy' : 'hold', 'is_placeholder' => (bool) $dummy, 'placeholder_requested' => true, 'placeholder_attachment_id' => $dummy ? $id : 0, 'original_attachment_id' => $real['attachment_id'] ?? 0 );
		return array( $dummy, $result );
	}
	/** No re-resolution from current settings: inspect the original IDs saved with this article. */
	public static function ids( int $post_id ): array {
		$items = get_post_meta( $post_id, Config::MEDIA, true ); $ids = array();
		foreach ( is_array( $items ) ? $items : array() as $item ) { if ( ! empty( $item['is_placeholder'] ) && ! empty( $item['attachment_id'] ) ) { $ids[] = (int) $item['attachment_id']; } }
		return array_values( array_unique( $ids ) );
	}
	public static function remaining( $post ): bool {
		if ( ! $post ) { return false; }
		$ids = self::ids( (int) $post->ID ); if ( ! $ids ) { return false; }
		if ( in_array( (int) get_post_thumbnail_id( $post->ID ), $ids, true ) ) { return true; }
		$scan = static function ( array $blocks ) use ( &$scan, $ids ): bool {
			foreach ( $blocks as $block ) { if ( 'core/image' === $block['blockName'] && in_array( (int) ( $block['attrs']['id'] ?? 0 ), $ids, true ) ) { return true; } if ( $scan( $block['innerBlocks'] ?? array() ) ) { return true; } }
			return false;
		};
		return $scan( parse_blocks( $post->post_content ) );
	}
	public static function notice(): void {
		$id = isset( $_GET['post'] ) && is_scalar( $_GET['post'] ) ? (int) $_GET['post'] : 0;
		if ( 'warn' === self::settings()['publication'] && $id && current_user_can( 'edit_post', $id ) && self::remaining( get_post( $id ) ) ) { echo '<div class="notice notice-warning"><p>この記事にはダミー画像が残っています。画像ブロックの「置換」またはアイキャッチ設定から差し替え、公開前に確認してください。公開操作は妨げません。</p></div>'; }
	}
	public static function post_states( array $states, $post ): array {
		if ( current_user_can( 'edit_post', $post->ID ) && self::remaining( $post ) ) { $states['aaa_dummy'] = 'ダミー画像あり'; } return $states;
	}
	public static function editor(): void {
		global $post;
		if ( ! $post || 'warn' !== self::settings()['publication'] || ! current_user_can( 'edit_post', $post->ID ) || ! self::ids( (int) $post->ID ) ) { return; }
		wp_enqueue_script( 'aaa-dummy-editor', plugins_url( 'assets/dummy-editor.js', AAA_FILE ), array( 'wp-data', 'wp-notices', 'wp-dom-ready' ), (string) filemtime( dirname( __DIR__ ) . '/assets/dummy-editor.js' ), true );
		wp_add_inline_script( 'aaa-dummy-editor', 'window.aaaDummyIds=' . wp_json_encode( self::ids( (int) $post->ID ) ) . ';', 'before' );
	}
	public static function save(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { wp_die( 'POSTで操作してください。', '', array( 'response' => 405 ) ); }
		check_admin_referer( 'aaa_placeholder_save', 'aaa_nonce' );
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'upload_files' ) ) { wp_die( '管理者・アップロード権限が必要です。', '', array( 'response' => 403 ) ); }
		if ( '1' === ( $_POST['register_standard'] ?? '' ) ) {
			$result = self::ensure(); if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ) ); }
		} else {
			$value = self::settings();
			foreach ( array( 'body', 'featured', 'publication', 'landscape', 'square', 'portrait' ) as $field ) {
				$raw = $_POST[ $field ] ?? ''; if ( ! is_string( $raw ) ) { wp_die( '設定の形式を確認してください。' ); }
				if ( in_array( $field, array( 'body', 'featured', 'publication' ), true ) ) {
					if ( ! in_array( $raw, 'publication' === $field ? array( 'warn', 'off' ) : array( 'hold', 'dummy' ), true ) ) { wp_die( '設定の選択肢を確認してください。' ); }
					$value[ $field ] = $raw;
				} else {
					if ( ! preg_match( '/^(0|[1-9][0-9]{0,9})$/D', $raw ) || ( (int) $raw && ! Media::attachment( (int) $raw, 'image' ) ) ) { wp_die( 'ダミー画像のメディアID・利用権限を確認してください。' ); } $value[ $field ] = (int) $raw;
				}
			}
			if ( ! Store::write_option( self::SETTINGS, $value ) ) { wp_die( '設定を保存できません。' ); }
		}
		wp_safe_redirect( Admin::url( 'placeholders' ) ); exit;
	}
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( '管理者権限が必要です。' ); }
		$value = self::settings(); $asset = self::asset( (int) ( get_option( self::ASSET, array() )['id'] ?? 0 ) );
		echo '<h2>ダミー画像・仮配置</h2><p>記事CSV v2の未登録画像に使用する共通方針です。取り込み画面で記事別・画像別に変更できます。保存済み記事の画像は変更しません。PDFには使いません。</p><p>' . esc_html( $asset ? '標準画像：ID ' . $asset['attachment_id'] . ' / ' . $asset['filename'] : '標準画像は未登録または利用できません。登録ボタン、または管理者指定画像を使用してください。' ) . '</p>';
		echo '<form class="aaa-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="aaa_placeholder_save">'; wp_nonce_field( 'aaa_placeholder_save', 'aaa_nonce' );
		foreach ( array( 'body' => '本文画像が未登録の場合', 'featured' => 'アイキャッチが未登録の場合', 'publication' => '公開前の確認方針' ) as $key => $label ) {
			echo '<p><label>' . esc_html( $label ) . ' <select name="' . esc_attr( $key ) . '">';
			foreach ( 'publication' === $key ? array( 'warn' => '編集画面に案内（公開は妨げない）', 'off' => '編集画面の案内を表示しない' ) : array( 'hold' => '未設定のまま保留', 'dummy' => 'ダミー画像で仮配置' ) as $id => $text ) { echo '<option value="' . esc_attr( $id ) . '" ' . selected( $value[ $key ], $id, false ) . '>' . esc_html( $text ) . '</option>'; } echo '</select></label></p>';
		}
		foreach ( array( 'landscape' => '横長', 'square' => '正方形', 'portrait' => '縦長' ) as $key => $label ) {
			echo '<fieldset data-aaa-media-row><legend>' . esc_html( $label ) . 'のダミー画像（0＝横長または標準を使用）</legend><input type="hidden" data-aaa-media-kind value="image"><label>メディアID <input type="number" min="0" max="9999999999" data-aaa-media-id name="' . esc_attr( $key ) . '" value="' . esc_attr( $value[ $key ] ) . '"></label> <button type="button" class="button" data-aaa-media-pick hidden>標準メディアから選ぶ</button><p data-aaa-media-selection></p></fieldset>';
		}
		echo '<p>アイキャッチを保留すると、このプラグインはアイキャッチを設定せず、テーマ等の既定処理に委ねます。プラグイン停止・削除でも登録済み画像は自動削除しません。</p>'; submit_button( 'ダミー画像の方針を保存' ); echo '</form><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="aaa_placeholder_save"><input type="hidden" name="register_standard" value="1">'; wp_nonce_field( 'aaa_placeholder_save', 'aaa_nonce' ); submit_button( '標準画像を登録・再確認する', 'secondary' ); echo '</form>';
	}
}
