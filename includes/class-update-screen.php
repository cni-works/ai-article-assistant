<?php
namespace AAA;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class UpdateScreen {
	public function __construct() {
		add_action( 'admin_post_aaa_update_preview', array( $this, 'preview' ) );
		add_action( 'admin_post_aaa_update_commit', array( $this, 'commit' ) );
	}
	private function guard( string $action ): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { wp_die( 'POSTで操作してください。', '', array( 'response' => 405 ) ); }
		check_admin_referer( $action, 'aaa_nonce' );
		if ( ! Importer::allowed() ) { wp_die( '下書きを編集する権限がありません。', '', array( 'response' => 403 ) ); }
	}
	private function finish( $result ): void {
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '下書き更新を完了できませんでした', array( 'response' => 400, 'back_link' => true ) ); }
		wp_safe_redirect( Admin::url( 'update' ) ); exit;
	}
	private static function id( $value ): int { return is_string( $value ) && preg_match( '/^[1-9][0-9]{0,9}$/D', $value ) && (string) (int) $value === $value ? (int) $value : 0; }
	public function preview(): void {
		$this->guard( 'aaa_update_preview' ); $raw = wp_unslash( $_POST );
		$this->finish( DraftUpdate::preview( self::id( $raw['post_id'] ?? '' ), is_string( $raw['csv_text'] ?? null ) ? $raw['csv_text'] : '', is_string( $raw['original'] ?? null ) ? $raw['original'] : '' ) );
	}
	public function commit(): void {
		$this->guard( 'aaa_update_commit' );
		if ( '1' !== ( $_POST['confirmed'] ?? '' ) ) { $this->finish( new \WP_Error( 'confirm', '差分と更新範囲を確認し、更新する確認を選んでください。' ) ); }
		$this->finish( DraftUpdate::commit( Store::text( wp_unslash( $_POST['token'] ?? '' ) ) ) );
	}
	private static function form( string $action ): void {
		echo '<form class="aaa-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( $action ) . '">'; wp_nonce_field( $action, 'aaa_nonce' );
	}
	private static function compare( array $before, array $after ): void {
		foreach ( array( 'post_title' => 'タイトル', 'post_excerpt' => '抜粋', 'post_content' => '本文（ブロックの保存形式）' ) as $field => $label ) {
			echo '<h3>' . esc_html( $label ) . '</h3>';
			if ( $before[ $field ] === $after[ $field ] ) { echo '<p>変更なし</p>'; continue; }
			if ( function_exists( 'wp_text_diff' ) && strlen( $before[ $field ] ) + strlen( $after[ $field ] ) < 150000 ) {
				echo '<div class="aaa-diff-wrap">' . wp_kses_post( wp_text_diff( $before[ $field ], $after[ $field ], array( 'title_left' => '更新前', 'title_right' => '更新後' ) ) ) . '</div>';
			}
			echo '<details><summary>更新前後の全文を確認</summary><label>更新前<textarea class="large-text code" readonly rows="5">' . esc_textarea( $before[ $field ] ) . '</textarea></label><label>更新後<textarea class="large-text code" readonly rows="5">' . esc_textarea( $after[ $field ] ) . '</textarea></label></details>';
		}
	}
	private static function backup( int $id ): void {
		$backup = get_post_meta( $id, DraftUpdate::BACKUP, true );
		if ( ! is_array( $backup ) || ! is_array( $backup['before'] ?? null ) || ( $backup['before']['post_author'] ?? 0 ) !== get_current_user_id() ) { return; }
		echo '<details><summary>直近の更新前の控え（手動で戻すための全文）</summary><p>必要な場合は標準エディターで内容を確認して戻してください。自動復元は行いません。</p>';
		foreach ( array( 'post_title' => 'タイトル', 'post_excerpt' => '抜粋', 'post_content' => '本文のブロック保存形式' ) as $field => $label ) { echo '<label>' . esc_html( $label ) . '<textarea class="large-text code" rows="5" readonly>' . esc_textarea( $backup['before'][ $field ] ?? '' ) . '</textarea></label>'; } echo '</details>';
	}
	public static function render(): void {
		echo '<p>このプラグインから取り込んだ本人の下書きを1件選び、タイトル・本文・抜粋だけを更新します。分類・スラッグ・管理ID・著者・アイキャッチ・ACF・SEO案は保持します。公開・予約・他人の記事は更新できません。</p><p class="aaa-warning">今回は画像・PDF・独自ブロックを含む本文の更新に対応していません。該当する記事はWordPress標準エディターで編集してください。保存連携が必要なサイトでも標準エディターを使ってください。この更新方式は同時変更を防ぐ条件付き保存を使い、通常のsave_post等の保存フックは発火しません。</p>';
		$ids = get_posts( array( 'post_type' => array_keys( Store::writable_types() ), 'post_status' => 'draft', 'author' => get_current_user_id(), 'meta_key' => '_aaa_article_key', 'posts_per_page' => 100, 'fields' => 'ids', 'orderby' => 'modified', 'order' => 'DESC' ) );
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( Config::SLUG . '-update' ) . '"><label for="aaa-update-choice">最近の本人の下書き（最大100件）</label> <select id="aaa-update-choice" name="post_id"><option value="">選択してください</option>';
		foreach ( $ids as $id ) { $post = DraftUpdate::target( (int) $id ); if ( is_wp_error( $post ) ) { continue; } echo '<option value="' . esc_attr( $id ) . '">' . esc_html( $post['post_title'] . '（ID ' . $id . '）' ) . '</option>'; }
		echo '</select> <button class="button">対象を確認</button></form><form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( Config::SLUG . '-update' ) . '"><p><label>一覧にない下書きの投稿ID <input type="number" name="post_id" min="1" required></label> <button class="button">IDで対象を確認</button></p></form>';
		$id = self::id( $_GET['post_id'] ?? '' );
		if ( $id ) {
			$post = DraftUpdate::target( $id );
			if ( is_wp_error( $post ) ) { echo '<p class="aaa-warning">' . esc_html( $post->get_error_message() ) . '</p>'; }
			else {
				echo '<h2>更新対象：' . esc_html( $post['post_title'] . '（ID ' . $id . '）' ) . '</h2><p>管理ID：<code>' . esc_html( $post['article_key'] ) . '</code> / 投稿先：' . esc_html( $post['post_type'] ) . ' / スラッグ：' . esc_html( $post['post_name'] ) . '</p><p>元の専用CSVを使い、1件だけにしてタイトル・本文・抜粋を修正してください。管理ID・投稿先・スラッグ・draftは変えず、分類・メディア・ACF・SEOのJSON列は{}にします。画像・PDFは本文からも追加できません。更新する3項目は全文置換です。</p>';
				self::form( 'aaa_update_preview' ); echo '<input type="hidden" name="post_id" value="' . esc_attr( $id ) . '"><input type="hidden" name="original" value="' . esc_attr( DraftUpdate::hash( $post ) ) . '"><label for="aaa-update-csv">更新用CSV本文（UTF-8・1MB以下・1件）</label><textarea id="aaa-update-csv" class="large-text code" name="csv_text" rows="8" required></textarea>'; submit_button( '変更内容と差分を確認' ); echo '</form>';
				self::backup( $id );
			}
		}
		$stage = DraftUpdate::session(); if ( ! $stage ) { return; }
		$current = DraftUpdate::target( $stage['before']['ID'] );
		if ( is_wp_error( $current ) ) { echo '<p class="aaa-warning">更新対象が利用できません。標準の投稿一覧で確認してください。</p>'; return; }
		if ( 'complete' === $stage['state'] ) { echo '<h2>下書きを更新しました</h2><p><a href="' . esc_url( get_edit_post_link( $current['ID'] ) ) . '">標準エディターで下書きを確認</a>し、プレビューしてください。</p>'; }
		elseif ( 'preview' === $stage['state'] ) {
			if ( $current !== $stage['before'] ) { echo '<p class="aaa-warning">確認後に対象が変更されました。再度対象を選んで検証してください。</p>'; return; }
			echo '<h2>更新前後の差分：' . esc_html( $current['post_title'] . '（ID ' . $current['ID'] . '）' ) . '</h2><p>確認の有効期限は30分です。CSVの3項目を全文置換し、その他は保持します。</p>'; self::compare( $stage['before'], $stage['after'] );
			foreach ( $stage['check']['items'][0]['warnings'] as $warning ) { echo '<p class="aaa-warning">' . esc_html( $warning ) . '</p>'; }
			self::form( 'aaa_update_commit' ); echo '<input type="hidden" name="token" value="' . esc_attr( $stage['token'] ) . '"><p><label><input type="checkbox" name="confirmed" value="1" required> 更新対象・差分・全文置換する範囲を確認しました</label></p>'; submit_button( 'この下書きの3項目を更新する' ); echo '</form>';
		} else { echo '<p class="aaa-warning">この更新確認は実行済みです。結果が不明な場合は、再実行せず標準の投稿一覧で確認してください。</p>'; }
		if ( $id !== $current['ID'] ) { self::backup( $current['ID'] ); }
	}
}
