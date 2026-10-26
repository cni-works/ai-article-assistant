<?php
namespace AAA;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class SetupScreen {
	public function __construct() {
		foreach ( array( 'preview', 'commit', 'download', 'discard', 'generate' ) as $action ) { add_action( 'admin_post_aaa_setup_' . $action, array( $this, $action ) ); }
	}
	private function guard( string $action ): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { wp_die( 'POSTで操作してください。', '', array( 'response' => 405 ) ); }
		check_admin_referer( 'aaa_setup_' . $action, 'aaa_nonce' );
		if ( ! Setup::allowed() ) { wp_die( '初期設定には管理者権限が必要です。', '', array( 'response' => 403 ) ); }
	}
	private function finish( $result, array $args = array() ): void {
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '初期設定を完了できませんでした', array( 'response' => 400, 'back_link' => true ) ); }
		wp_safe_redirect( AdminSetup::url( 'wizard', array_merge( array( 'setup_step' => '3', 'setup_mode' => 'details' === ( $_POST['setup_mode'] ?? '' ) ? 'details' : 'basic' ), $args ) ) ); exit;
	}
	public function generate(): void {
		$this->guard( 'generate' );
		$record = AdminSetup::generate( wp_unslash( $_POST ) );
		$this->finish( $record, is_wp_error( $record ) ? array() : array( 'setup_step' => '2', 'setup_mode' => $record['mode'] ) );
	}
	public function preview(): void {
		$this->guard( 'preview' );
		$csv = is_string( $_POST['setup_csv'] ?? null ) ? wp_unslash( $_POST['setup_csv'] ) : '';
		if ( isset( $_FILES['setup_file'] ) && UPLOAD_ERR_NO_FILE !== ( $_FILES['setup_file']['error'] ?? null ) ) {
			$file = $_FILES['setup_file'];
			if ( UPLOAD_ERR_OK !== ( $file['error'] ?? null ) || ! is_string( $file['tmp_name'] ?? null ) || ! is_uploaded_file( $file['tmp_name'] ) || ! is_string( $file['name'] ?? null ) || 'csv' !== strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) ) ) { $this->finish( new \WP_Error( 'file', '初期設定用CSVファイルを選び直してください。' ) ); }
			$source = file_get_contents( $file['tmp_name'], false, null, 0, Csv::MAX_BYTES + 1 );
			if ( false === $source ) { $this->finish( new \WP_Error( 'file', 'CSVを読み取れませんでした。' ) ); }
			$csv = $source;
		}
		$this->finish( Setup::preview( $csv, '1' === ( $_POST['replace_pending'] ?? '' ), Store::text( wp_unslash( $_POST['pending_token'] ?? '' ) ), 'details' === ( $_POST['setup_mode'] ?? '' ) ? 'details' : 'basic' ) );
	}
	public function commit(): void {
		$this->guard( 'commit' );
		if ( '1' !== ( $_POST['confirmed'] ?? '' ) ) { $this->finish( new \WP_Error( 'confirm', '変更内容を確認してください。' ) ); }
		$this->finish( Setup::commit( Store::text( wp_unslash( $_POST['token'] ?? '' ) ), '1' === ( $_POST['confirmed_conflicts'] ?? '' ), '1' === ( $_POST['confirmed_categories'] ?? '' ) ) );
	}
	public function discard(): void {
		$this->guard( 'discard' );
		if ( '1' !== ( $_POST['confirmed_discard'] ?? '' ) ) { $this->finish( new \WP_Error( 'confirm', '未適用の変更を破棄することを確認してください。保存済み設定は変更されません。' ) ); }
		$this->finish( Setup::discard( Store::text( wp_unslash( $_POST['token'] ?? '' ) ) ) );
	}
	public function download(): void {
		$this->guard( 'download' );
		$backup = get_option( Setup::BACKUP, array() );
		if ( ! is_array( $backup ) || 'aaa-setup-backup' !== ( $backup['format'] ?? '' ) ) { $this->finish( new \WP_Error( 'backup', '変更前の控えがありません。' ) ); }
		nocache_headers(); header( 'Content-Type: application/json; charset=UTF-8' ); header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: attachment; filename="aaa-setup-before-' . gmdate( 'Ymd-His' ) . '.json"' );
		echo wp_json_encode( $backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); exit;
	}
	private static function form( string $action ): void {
		echo '<form class="aaa-form" method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="aaa_setup_' . esc_attr( $action ) . '">'; wp_nonce_field( 'aaa_setup_' . $action, 'aaa_nonce' );
		echo '<input type="hidden" name="setup_mode" value="' . esc_attr( 'details' === Views::get( 'setup_mode' ) ? 'details' : 'basic' ) . '">';
	}
	public static function render(): void {
		if ( ! Setup::allowed() ) { wp_die( '初期設定には管理者権限が必要です。', '', array( 'response' => 403 ) ); }
		$mode = 'details' === Views::get( 'setup_mode' ) ? 'details' : 'basic';
		$stage = Setup::session();
		$import_label = 'details' === $mode ? '詳細設定' : '基本初期設定';
		$prompt_label = 'details' === $mode ? '詳細設定プロンプト' : '基本初期設定プロンプト';
		echo '<h2>STEP3：設定CSVを取り込む</h2><p>保存済み設定はCSVを選択・読み込みしただけでは変わりません。比較内容への承認後に保存します。</p><p><a class="button" href="' . esc_url( AdminSetup::wizard_url( 2, $mode ) ) . '">STEP2へ戻る（比較は保持）</a></p>';
		$collapsed = (bool) $stage;
		if ( $collapsed ) { echo '<details><summary>別の設定CSVを読み込む・比較を置き換える</summary>'; }
		echo '<h3>' . esc_html( $import_label ) . 'CSVを読み込む</h3><p><strong>現在の取り込み操作：' . esc_html( $import_label ) . '</strong>。基本・詳細は同じ保存領域へ項目単位で反映します。CSVに含まれない保存済み設定は維持します。</p><p>記事本文用CSVとは形式が異なります。UTF-8・1MB以下・1000行まで。ファイルを選んだ場合は貼り付け内容より優先します。空欄の値は明示的な消去、省略した項目は変更なしです。</p>';
		self::form( 'preview' ); echo '<p>ファイルの選択・読み込みだけでは設定に適用されません。「変更内容を確認」で比較し、最後の「適用」で保存します。</p><div class="aaa-csv-drop aaa-upload-area" data-aaa-csv-drop data-aaa-csv-label="設定CSV"><p class="aaa-upload-title">' . esc_html( $import_label ) . 'CSVをここにドラッグ＆ドロップ</p><label class="button aaa-main-action" for="aaa-setup-file" data-aaa-csv-pick hidden>設定CSVを選ぶ</label><input id="aaa-setup-file" type="file" name="setup_file" accept=".csv,text/csv" aria-describedby="aaa-setup-selection"><p>.csvファイル1点・1MB以下。記事CSVや画像はこの画面では扱いません。</p><p id="aaa-setup-selection" data-aaa-csv-selection role="status" aria-live="polite">まだファイルを選んでいません。</p><p data-aaa-csv-error role="alert" hidden></p></div><details><summary>上級者向け：CSVを貼り付ける</summary><label for="aaa-setup-csv">またはCSVを貼り付け</label><textarea id="aaa-setup-csv" name="setup_csv" class="large-text code" rows="6"></textarea></details>';
		if ( 'preview' === ( $stage['state'] ?? '' ) ) { echo '<input type="hidden" name="pending_token" value="' . esc_attr( $stage['token'] ) . '"><p class="notice notice-warning">未適用の変更あり：別のCSVへ置き換えると、現在の比較内容は破棄されます。設定へ保存された内容は消えません。</p><p><label><input type="checkbox" name="replace_pending" value="1"> 未適用の比較を破棄して、次のCSVの比較に置き換える</label></p>'; }
		submit_button( $import_label . 'の変更内容を確認', $collapsed ? 'secondary' : 'primary aaa-main-action' ); echo '</form>';
		if ( $collapsed ) { echo '</details>'; }
		if ( 'complete' === ( $stage['state'] ?? '' ) ) { echo '<p class="notice notice-success">設定への適用が完了しました（' . esc_html( 'details' === ( $stage['csv_mode'] ?? '' ) ? '詳細設定' : '基本初期設定' ) . '）。変更した設定項目：' . esc_html( $stage['changed_count'] ?? '―' ) . '件、変更なし：' . esc_html( $stage['unchanged_count'] ?? '―' ) . '件、新規カテゴリー：' . esc_html( $stage['created_count'] ?? count( $stage['created_terms'] ?? array() ) ) . '件、作成済みカテゴリー照合：' . esc_html( $stage['reused_count'] ?? 0 ) . '件。保存結果を照合済みです。CSVにない設定は維持しました。次のCSVの比較表示に切り替わっても保存済み設定は消えません。</p><p><a class="button button-primary aaa-main-action" href="' . esc_url( AdminSetup::url() ) . '">管理者設定トップへ戻る →</a></p><p><a class="button" href="' . esc_url( Admin::url() ) . '">記事を作成する</a> <a class="button" href="' . esc_url( AdminSetup::wizard_url( 1, 'details' ) ) . '">詳細設定も相談する（任意）</a></p>'; }
		elseif ( 'preview' === ( $stage['state'] ?? '' ) ) {
			if ( $stage['before'] !== Setup::snapshot() ) { echo '<p class="aaa-warning">比較後に保存済み設定が変更されています。承認しても古い比較のまま適用はできません。比較を保持したまま、CSVを再読み込みして変更内容を確認してください。</p>'; }
			echo '<p><strong>読み込んだCSVの内容：' . esc_html( 'details' === ( $stage['csv_mode'] ?? '' ) ? '詳細設定項目の差分' : '基本初期設定（サイト情報・記事の種類・カテゴリー等）' ) . '</strong>。表示は今回のCSVの変更対象です。保存済み設定の全一覧ではありません。</p>';
			if ( ! empty( $stage['reused_terms'] ) ) { echo '<p>作成済みカテゴリーは名前・スラッグ・方針の完全一致を照合済みです。重複作成も方針の上書きも行いません。</p>'; }
			if ( ! empty( $stage['term_creations'] ) ) { echo '<p class="notice notice-warning">カテゴリー新規作成予定：名前・スラッグ・目的・推奨記事の種類を確認してください。既存カテゴリーの名称変更・削除は行いません。作成済みIDは適用後に表示します。</p>'; }
			echo '<h3>変更内容を確認中・未適用（30分間有効）</h3><p>まだ設定には保存されていません。新規記事の種類は追加し、既存のものは削除しません。作業中の記事の設定スナップショットは自動更新されません。</p>';
			if ( ! empty( $stage['rebased'] ) ) { echo '<p>相談後の設定変更を照合済みです。変更対象は相談時・現在・CSVの3つの値で比較しています。</p>'; }
			if ( ! empty( $stage['conflicts'] ) ) { echo '<p class="notice notice-warning">同じ項目の変更が競合しています。競合行を確認し、CSVの値を採用する場合だけ上書きを承認してください。現在値を維持する場合はCSVから対象行を除いて再確認してください。</p>'; }
			echo '<div class="aaa-diff-wrap"><table class="widefat striped"><thead><tr><th>対象</th><th>項目</th><th>相談時</th><th>現在の保存値</th><th>CSV適用後</th></tr></thead><tbody>';
			foreach ( $stage['changes'] as $change ) {
				$label = array( 'site' => '共通設定', 'profile' => '記事の種類', 'term' => 'カテゴリー', 'term_create' => '新規カテゴリー' )[ $change['scope'] ];
				$before = $change['before']; $after = $change['after']; $baseline = $change['baseline'] ?? $before;
				if ( 'recommended_profile' === ( $change['field_key'] ?? '' ) ) {
					$before = '' === $before ? '指定なし' : ( $stage['before']['profiles'][ $before ]['name'] ?? '参照先がありません' );
					$after = '' === $after ? '指定なし' : ( $stage['after']['profiles'][ $after ]['name'] ?? '参照先がありません' );
					$baseline = '' === $baseline ? '指定なし' : ( $stage['before']['profiles'][ $baseline ]['name'] ?? '参照先がありません' );
				} else {
					$fields = 'site' === $change['scope'] ? Config::site_fields() : ( 'profile' === $change['scope'] ? Config::profile_fields() : ( 'term_create' === $change['scope'] ? TermCreation::fields() : Config::term_fields() ) );
					$field = $fields[ $change['field_key'] ?? '' ] ?? array();
					if ( 'select' === ( $field[1] ?? '' ) ) { $before = $field[4][ $before ] ?? $before; $after = $field[4][ $after ] ?? $after; $baseline = $field[4][ $baseline ] ?? $baseline; }
				}
				echo '<tr><td>' . esc_html( $label . '：' . ( $change['target_label'] ?? $change['target'] ) ) . '</td><td>' . esc_html( ( ! empty( $change['conflict'] ) ? '競合：' : '' ) . ( $change['operation'] ?? '変更' ) . '：' . $change['field'] ) . '</td><td>' . nl2br( esc_html( $baseline ) ) . '</td><td>' . nl2br( esc_html( $before ) ) . '</td><td>' . nl2br( esc_html( $after ) ) . '</td></tr>';
			}
			echo '</tbody></table></div>'; self::form( 'commit' ); echo '<input type="hidden" name="token" value="' . esc_attr( $stage['token'] ) . '"><p><label><input type="checkbox" name="confirmed" value="1" required> 変更内容と記事作成への影響を確認しました</label></p>';
			if ( ! empty( $stage['conflicts'] ) ) { echo '<p><label><input type="checkbox" name="confirmed_conflicts" value="1" required> 競合行を確認し、現在値をCSVの値で上書きすることを承認します</label></p>'; }
			if ( ! empty( $stage['term_creations'] ) ) { echo '<p><label><input type="checkbox" name="confirmed_categories" value="1" required> 表示されたカテゴリーを新規作成し、実IDへ方針を保存することを承認します</label></p>'; }
			submit_button( '変更内容を承認して適用', 'primary aaa-main-action' ); echo '</form>';
			echo '<details><summary>未適用の比較を破棄する（保存済み設定は維持）</summary>'; self::form( 'discard' ); echo '<input type="hidden" name="token" value="' . esc_attr( $stage['token'] ) . '"><p><label><input type="checkbox" name="confirmed_discard" value="1" required> 未適用の比較内容を破棄します（保存済み設定は維持）</label></p>'; submit_button( '未適用の比較を破棄', 'secondary' ); echo '</form></details>';
		} elseif ( $stage ) { echo '<p class="aaa-warning">この確認は実行済みです。各設定画面と変更前の控えを確認してください。</p>'; }
		if ( ! empty( $stage['created_terms'] ) ) {
			echo '<h3>作成されたカテゴリー</h3><p>適用未完了の場合もカテゴリー自体は保持します。自動削除はしません。方針の保存状態はカテゴリー設定で確認してください。</p><ul>';
			foreach ( $stage['created_terms'] as $term ) { echo '<li>' . esc_html( $term['name'] . ' / ' . $term['slug'] . ' / ' . $term['taxonomy'] . ':' . $term['id'] ) . '</li>'; }
			echo '</ul>';
		}
		$backup = get_option( Setup::BACKUP, array() );
		if ( is_array( $backup ) && isset( $backup['writes'] ) ) {
			echo '<details><summary>直近の変更前の控え</summary><p>' . esc_html( $backup['created_at'] ?? '' ) . '。このJSONは確認・手動復元用です。「設定の移行」のJSONとは形式が異なり、直接インポートはできません。beforeを参照して各設定画面で戻してください。新規記事の種類は各画面から削除できます。</p><pre class="aaa-json">' . esc_html( wp_json_encode( $backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ) . '</pre>'; self::form( 'download' ); submit_button( '変更前の控えをJSONで保存', 'secondary' ); echo '</form></details>';
		}
	}
}
