<?php
namespace AAA;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ImportScreen {
	public function __construct() {
		foreach ( array( 'aaa_csv_preview' => 'preview', 'aaa_csv_commit' => 'commit', 'aaa_csv_bind' => 'bind', 'aaa_export_prompt' => 'export', 'aaa_new_article' => 'new_article', 'aaa_consult_result' => 'consult_result' ) as $action => $method ) { add_action( 'admin_post_' . $action, array( $this, $method ) ); }
	}

	private static function form( string $action, bool $upload = false ): void {
		echo '<form method="post" class="aaa-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . ( $upload ? ' enctype="multipart/form-data"' : '' ) . '><input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		wp_nonce_field( $action, 'aaa_nonce' );
	}
	private function guard( string $action ): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { wp_die( 'POSTで操作してください。', '', array( 'response' => 405 ) ); }
		check_admin_referer( $action, 'aaa_nonce' );
		if ( ! Importer::allowed() ) { wp_die( '記事の作成・取り込みを利用する権限がありません。', '', array( 'response' => 403 ) ); }
	}
	private function finish( $result, string $page = 'import', array $args = array() ): void {
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '操作を完了できませんでした', array( 'response' => 400, 'back_link' => true ) ); }
		wp_safe_redirect( Admin::url( $page, $args ) ); exit;
	}
	public function preview(): void {
		$this->guard( 'aaa_csv_preview' );
		$source = '';
		if ( isset( $_FILES['aaa_csv'] ) && UPLOAD_ERR_NO_FILE !== ( $_FILES['aaa_csv']['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			$file = $_FILES['aaa_csv'];
			if ( ! is_string( $file['name'] ?? null ) || ! is_string( $file['tmp_name'] ?? null ) || UPLOAD_ERR_OK !== ( $file['error'] ?? -1 ) || ! is_uploaded_file( $file['tmp_name'] ) || 'csv' !== strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) ) || filesize( $file['tmp_name'] ) > Csv::MAX_BYTES ) { $this->finish( new \WP_Error( 'upload', '正常にアップロードされた1MB以下の.csvファイルを選んでください。' ) ); }
			if ( ! function_exists( 'finfo_open' ) ) { $this->finish( new \WP_Error( 'mime', 'サーバーでファイル形式を検査できません。CSV本文の貼り付けを使用してください。' ) ); }
			$info = finfo_open( FILEINFO_MIME_TYPE ); $mime = finfo_file( $info, $file['tmp_name'] ); finfo_close( $info );
			if ( ! in_array( $mime, array( 'text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel' ), true ) ) { $this->finish( new \WP_Error( 'mime', 'CSVのファイル形式を確認できません。UTF-8のテキストCSVを使用してください。' ) ); }
			$source = file_get_contents( $file['tmp_name'], false, null, 0, Csv::MAX_BYTES + 1 );
			if ( false === $source ) { $this->finish( new \WP_Error( 'upload', 'CSVを読み込めませんでした。' ) ); }
		} elseif ( isset( $_POST['csv_text'] ) && is_string( $_POST['csv_text'] ) ) { $source = wp_unslash( $_POST['csv_text'] ); }
		$this->finish( Importer::preview( $source ) );
	}
	public function commit(): void {
		$this->guard( 'aaa_csv_commit' );
		if ( ! isset( $_POST['confirmed'] ) || '1' !== $_POST['confirmed'] ) { $this->finish( new \WP_Error( 'confirm', 'プレビューと警告を確認してください。' ) ); }
		$this->finish( Importer::commit( Store::text( wp_unslash( $_POST['token'] ?? '' ) ), '1' === ( $_POST['confirmed_missing'] ?? '' ), '1' === ( $_POST['confirmed_dummy'] ?? '' ) ) );
	}
	public function bind(): void {
		$this->guard( 'aaa_csv_bind' );
		$bindings = wp_unslash( $_POST['bindings'] ?? array() );
		$options = wp_unslash( $_POST['image_options'] ?? array() );
		if ( ! is_array( $bindings ) || ! is_array( $options ) ) { $this->finish( new \WP_Error( 'media', '資料の対応付けの形式を確認してください。' ) ); }
		$this->finish( Importer::bind( Store::text( wp_unslash( $_POST['token'] ?? '' ) ), $bindings, $options ), 'import', array( 'stage' => 'review' ) );
	}
	public function consult_result(): void {
		$this->guard( 'aaa_consult_result' );
		$result = Consultation::result( wp_unslash( $_POST ) );
		$this->finish( $result, '', is_int( $result ) ? array( 'step' => '2', 'title_draft' => $result ) : array( 'step' => '1' ) );
	}
	private static function export_key(): string { return 'aaa_export_' . get_current_blog_id() . '_' . get_current_user_id(); }
	public function export(): void {
		$this->guard( 'aaa_export_prompt' );
		$prepared = Prompt::prepare(); if ( is_wp_error( $prepared ) ) { $this->finish( $prepared ); }
		if ( ! hash_equals( $prepared['fingerprint'], Store::text( wp_unslash( $_POST['fingerprint'] ?? '' ) ) ) ) { $this->finish( new \WP_Error( 'changed', '記事条件が変わっています。保存した内容を確認し直してください。' ) ); }
		$draft = isset( $_POST['approved_draft'] ) && is_string( $_POST['approved_draft'] ) ? trim( wp_unslash( $_POST['approved_draft'] ) ) : '';
		if ( '1' !== ( $_POST['approved'] ?? '' ) || '' === $draft || strlen( $draft ) > 200000 || ! preg_match( '//u', $draft ) || false !== strpos( $draft, "\0" ) ) { $this->finish( new \WP_Error( 'draft', '承認済み原稿を20万バイト以下のUTF-8で入力し、原稿確認済みを選んでください。' ) ); }
		$text = Csv::export_instructions( $prepared['context'], $draft );
		$record = array( 'text' => $text, 'fingerprint' => $prepared['fingerprint'], 'sha256' => hash( 'sha256', $text ), 'schema_version' => Csv::VERSION, 'export_version' => Csv::EXPORT_VERSION, 'generated_at' => current_time( 'mysql' ) );
		set_transient( self::export_key(), $record, 1800 );
		if ( get_transient( self::export_key() ) !== $record ) { $this->finish( new \WP_Error( 'save', 'CSV出力用プロンプトを一時保存できませんでした。' ) ); }
		$this->finish( true, '', array( 'step' => '4' ) );
	}
	public function new_article(): void {
		$this->guard( 'aaa_new_article' );
		foreach ( array( Config::ARTICLE, Config::PROMPT, Config::MEDIA, Reference::SELECTED ) as $key ) {
			$all = get_user_meta( get_current_user_id(), $key, true ); $all = is_array( $all ) ? $all : array(); unset( $all[ get_current_blog_id() ] );
			update_user_meta( get_current_user_id(), $key, wp_slash( $all ) );
			if ( get_user_meta( get_current_user_id(), $key, true ) !== $all ) { $this->finish( new \WP_Error( 'save', '新しい記事の開始処理を保存できませんでした。' ) ); }
		}
		delete_transient( self::export_key() );
		$this->finish( true, '' );
	}

	public static function new_article_form(): void {
		self::form( 'aaa_new_article' );
		echo '<p><button class="button" type="submit" data-aaa-delete="保存中の記事条件と相談プロンプトを置き換えて、新しい管理IDの記事を開始します。必要な原稿は先に保存してください。">新しい記事を開始する</button></p></form>';
	}
	public static function export_form( array $prepared ): void {
		echo '<section class="aaa-next-step"><h3>記事と画像が完成したら</h3><p>ChatGPTから受け取った最終CSVと画像を、次の画面へ取り込みます。ZIPの場合は先にパソコンで展開してください。</p><p><a class="button button-primary aaa-next-step-button" href="' . esc_url( Admin::url( 'import' ) ) . '"><span>STEP3：完成ファイルを取り込む</span><span class="aaa-next-arrow" aria-hidden="true">→</span></a></p><p>このボタンで、CSV・画像・PDFを登録する画面へ進みます。本文の貼り直しは不要です。</p></section><details><summary>代替手段：承認済みの原稿を貼り付けてCSV出力指示を作る</summary><p>従来の記事CSV v1と保存済み資料対応表を使う補助機能です。</p>';
		self::form( 'aaa_export_prompt' );
		echo '<input type="hidden" name="fingerprint" value="' . esc_attr( $prepared['fingerprint'] ) . '"><label for="aaa-approved-draft">承認済みの原稿</label><textarea id="aaa-approved-draft" name="approved_draft" class="large-text" rows="8" required></textarea><p><label><input type="checkbox" name="approved" value="1" required> 原稿の事実・内容を確認し、承認しました</label></p>';
		submit_button( 'CSV出力用プロンプトを生成' ); echo '</form>';
		$record = get_transient( self::export_key() );
		if ( is_array( $record ) && is_string( $record['text'] ?? null ) && ( $record['export_version'] ?? '' ) === Csv::EXPORT_VERSION && ( $record['schema_version'] ?? '' ) === Csv::VERSION && ( $record['fingerprint'] ?? '' ) === $prepared['fingerprint'] && hash( 'sha256', $record['text'] ) === ( $record['sha256'] ?? '' ) ) {
			echo '<p>同じChatGPTのチャットに貼り付けてCSVを生成し、<a href="' . esc_url( Admin::url( 'import' ) ) . '">CSVインポート</a>で検証してください。出力用プロンプトは30分間だけ保持します。</p><label for="aaa-export-output">CSV出力用プロンプト</label><textarea id="aaa-export-output" class="large-text code" rows="12" readonly>' . esc_textarea( $record['text'] ) . '</textarea><p><button class="button" type="button" data-aaa-copy data-aaa-copy-target="aaa-export-output" data-aaa-copy-status-target="aaa-export-copy-status" hidden>CSV出力用プロンプトをコピー</button> <span id="aaa-export-copy-status" role="status" aria-live="polite"></span></p>';
		}
		echo '</details>';
	}

	private static function materials( array $stage ): void {
		$rows = Importer::requirements( $stage );
		if ( ! $rows ) { return; }
		$available = count( array_filter( $rows, static function ( $row ) { return 'available' === $row['media']['status']; } ) );
		echo '<h2>画像・PDFを見ながら対応を確認</h2><p>資料 ' . esc_html( count( $rows ) ) . '件／登録済み ' . esc_html( $available ) . '件／未配置 ' . esc_html( count( $rows ) - $available ) . '件。今回追加したファイルは先頭番号で仮配置します。サムネイルと掲載位置を確認し、下のボタンでまとめてプレビューへ進みます。</p><p>番号なし・番号重複・登録済み画像は手動で選びます。選択済みの配置は自動で上書きしません。画像ZIPはパソコンで展開してから登録してください。</p>';
		if ( ! current_user_can( 'upload_files' ) ) { echo '<p class="aaa-warning">ファイルアップロード権限がありません。管理担当者に資料の準備を依頼するか、未配置のまま下書きを作成できます。</p>'; return; }
		self::form( 'aaa_csv_bind' );
		wp_nonce_field( 'media-form', 'aaa_media_upload_nonce' );
		echo '<input type="hidden" name="token" value="' . esc_attr( $stage['token'] ) . '"><div class="aaa-upload-area aaa-media-drop" data-aaa-media-drop data-aaa-upload-url="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '" data-aaa-upload-limit="' . esc_attr( function_exists( 'wp_max_upload_size' ) ? wp_max_upload_size() : 0 ) . '"><p class="aaa-upload-title">画像・PDFをこの枠にドラッグ＆ドロップ</p><button class="button aaa-main-action" type="button" data-aaa-media-batch hidden>画像・PDFを選ぶ／登録済みから選ぶ</button><p>この枠へのドロップでメディアライブラリに追加します。記事はまだ作成されません。</p><p>01-cover.png、02-image.pngのように番号を先頭に付けると仮配置できます。保存前に下のサムネイルを確認してください。</p><p data-aaa-upload-status role="status" aria-live="polite"></p><div data-aaa-upload-errors role="alert"></div></div><div data-aaa-batch-selection role="status"></div>';
		$articles = array(); foreach ( $rows as $row ) { $articles[ $row['key'] ] = $row['title']; }
		echo '<details><summary>この記事の未準備画像の扱い</summary>';
		foreach ( $articles as $key => $title ) {
			echo '<fieldset><legend>' . esc_html( $title ) . '</legend>';
			foreach ( array( 'body' => '本文画像', 'featured' => 'アイキャッチ' ) as $field => $label ) {
				echo '<p><label>' . esc_html( $label ) . ' <select name="image_options[' . esc_attr( $key ) . '][' . esc_attr( $field ) . ']">';
				foreach ( array( 'inherit' => 'サイト共通設定に従う', 'hold' => '未設定のまま保留', 'dummy' => 'ダミーで仮配置' ) as $value => $text ) { echo '<option value="' . esc_attr( $value ) . '" ' . selected( $stage['image_options'][ $key ][ $field ] ?? 'inherit', $value, false ) . '>' . esc_html( $text ) . '</option>'; } echo '</select></label></p>';
			} echo '</fieldset>';
		}
		echo '</details><div class="aaa-materials">';
		$display_number = 0;
		foreach ( $rows as $row ) {
			++$display_number;
			$m = $row['media']; $ref = $row['ref']; $key = $row['key']; $ready = 'available' === $m['status'];
			$file_number = preg_match( '/^(\d{1,3})[-_\s]/', $m['suggested_filename'], $number_match ) && (int) $number_match[1] > 0 ? (int) $number_match[1] : $display_number;
			$label = sprintf( '%02d', $file_number ) . ' ' . ( $m['featured'] ? 'アイキャッチ' : ( 'pdf' === $m['kind'] ? 'PDF' : '本文画像' ) );
			$bound = isset( $stage['bindings'][ $key ][ $ref ] ) && $ready && empty( $m['is_placeholder'] );
			$state = ! empty( $m['is_placeholder'] ) ? 'ダミーで仮配置（登録前に承認）' : ( $bound ? '対応確認済み・保存済み' : ( $ready ? '登録済み・プレビューで対応を確認' : '未配置・資料の追加または保留が必要' ) );
			echo '<fieldset class="aaa-material-card" data-aaa-media-row data-aaa-file-number="' . esc_attr( $file_number ) . '" data-aaa-package-target="' . esc_attr( $key . '|' . $ref ) . '" data-aaa-package-label="' . esc_attr( $label ) . '" data-aaa-suggested-name="' . esc_attr( $m['suggested_filename'] ) . '"><legend><strong>' . esc_html( $label ) . '</strong></legend><div class="aaa-material-grid"><div><p><strong>用途：</strong>' . esc_html( $m['description'] ) . '</p><p><strong>掲載位置：</strong>' . esc_html( $m['position'] ) . '</p></div><div class="aaa-material-selected"><div data-aaa-media-preview>';
			if ( $ready && 'image' === $m['kind'] ) {
				$thumb = wp_get_attachment_image_url( $m['attachment_id'], 'thumbnail' );
				echo '<img class="aaa-media-thumb" src="' . esc_url( $thumb ?: $m['url'] ) . '" alt="選択された画像：' . esc_attr( $ref ) . '">';
			}
			echo '</div><p class="aaa-file-name" data-aaa-media-selection>' . esc_html( $ready ? '登録済み：' . $m['filename'] . ' / ID:' . $m['attachment_id'] : '未登録：ファイルを追加・選択してください。' ) . '</p><p data-aaa-mapping-state role="status">' . esc_html( $state ) . '</p><input type="hidden" data-aaa-media-kind value="' . esc_attr( $m['kind'] ) . '"><button class="button" type="button" data-aaa-media-pick hidden>選ぶ／選び直す</button><p class="description">このカードへファイル1点をドロップしても選べます。</p></div></div><details><summary>詳細・画像がない場合</summary>';
			if ( 'image' === $m['kind'] ) {
				echo '<p><label>配置方法 <select data-aaa-placement-mode aria-label="' . esc_attr( $ref . 'の配置モード' ) . '" name="image_options[' . esc_attr( $key ) . '][items][' . esc_attr( $ref ) . ']">';
				foreach ( array( 'auto' => '登録画像を使う／未登録なら記事・共通設定', 'real' => '実画像を使う', 'generated' => 'AI生成画像を使う', 'dummy' => 'ダミーで仮配置', 'hold' => '画像なしで保留' ) as $value => $text ) { echo '<option value="' . esc_attr( $value ) . '" ' . selected( $stage['image_options'][ $key ]['items'][ $ref ] ?? 'auto', $value, false ) . '>' . esc_html( $text ) . '</option>'; } echo '</select></label></p>';
			}
			echo '<p>識別子：' . esc_html( $ref ) . ' ／ 推奨名：' . esc_html( $m['suggested_filename'] ) . '</p><p data-aaa-media-origin>追加時期：未記録</p><label>対応するメディアID（0＝未配置） <input type="number" min="0" max="9999999999" data-aaa-media-id name="bindings[' . esc_attr( $key ) . '][' . esc_attr( $ref ) . ']" value="' . esc_attr( $m['original_attachment_id'] ?? ( $m['attachment_id'] ?? 0 ) ) . '"></label><p>alt：' . esc_html( $m['alt'] ) . ' ／ キャプション：' . esc_html( $m['caption'] ) . ' ／ PDF表示文：' . esc_html( $m['text'] ) . '</p>';
			if ( 'image' === $m['kind'] ) {
				echo '<label>ダミー比率 <select aria-label="' . esc_attr( $ref . 'のダミー比率' ) . '" name="image_options[' . esc_attr( $key ) . '][aspects][' . esc_attr( $ref ) . ']">';
				foreach ( array( 'landscape' => '横長', 'square' => '正方形', 'portrait' => '縦長' ) as $value => $text ) { echo '<option value="' . esc_attr( $value ) . '" ' . selected( $stage['image_options'][ $key ]['aspects'][ $ref ] ?? 'landscape', $value, false ) . '>' . esc_html( $text ) . '</option>'; } echo '</select></label>';
			}
			echo '</details></fieldset>';
		}
		echo '</div><p>選択内容とサムネイルを確認して保存してください。保存だけでは投稿を作成しません。</p>';
		submit_button( '配置を確認して記事をプレビュー', 'primary aaa-main-action' ); echo '</form>';
	}

	private static function requirements_present( array $stage ): bool { return (bool) Importer::requirements( $stage ); }
	public static function render(): void {
		$review = 'review' === Views::get( 'stage' );
		Views::journey( $review || 'complete' === ( Importer::session()['state'] ?? '' ) ? 4 : 3 );
		echo '<p>ChatGPTから受け取った記事CSVと画像・PDFを使って、下書きを作ります。内容を確認してから登録でき、ここでは公開されません。</p><details><summary>使えるファイル・以前の下書きの更新について</summary><p>記事CSV v1/v2、UTF-8・1MB以下・20記事まで対応します。初期設定・詳細設定CSVは管理者設定で読み込んでください。以前の下書きは<a href="' . esc_url( Admin::url( 'update' ) ) . '">下書きの更新</a>で対象と差分を確認します。</p></details>';
		$current_stage = Importer::session();
		if ( $current_stage ) { echo '<p class="aaa-current-task">' . esc_html( 'complete' === ( $current_stage['state'] ?? '' ) ? '取り込み処理が終わりました。下の結果を確認してください。' : 'CSVを読み込みました。下の画像・PDFと本文を確認してください。' ) . '</p><details><summary>別の記事CSVを選ぶ・読み直す</summary>'; }
		self::form( 'aaa_csv_preview', true );
		echo '<h2>ChatGPTから受け取った記事CSVを選ぶ</h2><div class="aaa-csv-drop aaa-upload-area" data-aaa-csv-drop><p class="aaa-upload-title">記事CSVをここにドラッグ＆ドロップ</p><p>または、下のボタンから選択してください。</p><label class="button aaa-main-action" for="aaa-csv-file" data-aaa-csv-pick hidden>記事CSVを選ぶ</label><input id="aaa-csv-file" type="file" name="aaa_csv" accept=".csv,text/csv" aria-describedby="aaa-csv-help aaa-csv-selection"><p id="aaa-csv-help">.csvファイル1点・1MB以下。ZIPの場合は、先にパソコンで展開してください。</p><p id="aaa-csv-selection" data-aaa-csv-selection role="status" aria-live="polite">まだファイルを選んでいません。</p><p data-aaa-csv-error role="alert" hidden></p></div><p>ファイルを選んだだけでは保存されません。次のボタンで内容を読み込みます。</p><details><summary>上級者向け：CSV本文を貼り付ける</summary><label for="aaa-csv-text">CSV本文（ファイルを選んだ場合はファイルを優先）</label><textarea id="aaa-csv-text" name="csv_text" class="large-text code" rows="8"></textarea></details>';
		submit_button( '選んだCSVを読み込んで次へ', 'primary aaa-main-action' ); echo '</form><details><summary>専用CSVのサンプル</summary><p>架空の検証用原稿です。投稿先・スラッグ・管理ID等を用途に合わせて変更してください。内容を表計算ソフトで編集するときは文字列として扱ってください。</p><textarea class="large-text code" rows="5" readonly aria-label="検証用CSVサンプル">' . esc_textarea( Csv::example() ) . '</textarea></details>';
		if ( $current_stage ) { echo '</details>'; }
		$stage = Importer::session(); if ( ! $stage ) { return; }
		if ( 'complete' === $stage['state'] ) {
			echo '<h2>STEP4：下書きを確認</h2><p>取り込み結果です。下書きを開いて、記事と画像を確認してください。</p><ul>';
			foreach ( $stage['report'] as $item ) {
				echo '<li>' . esc_html( $item['title'] ) . '：' . ( $item['id'] ? '下書きID ' . esc_html( $item['id'] ) : '登録なし' );
				$link = $item['id'] ? get_edit_post_link( $item['id'] ) : '';
				if ( $link ) { echo ' <a href="' . esc_url( $link ) . '">下書きを確認する</a>'; }
				$preview = $item['id'] && function_exists( 'get_preview_post_link' ) ? get_preview_post_link( $item['id'] ) : '';
				if ( $preview ) { echo ' <a href="' . esc_url( $preview ) . '" target="_blank" rel="noopener noreferrer">画像付き下書きをプレビュー</a>'; }
				foreach ( $item['errors'] as $error ) { echo '<p class="aaa-warning">' . esc_html( $error ) . '</p>'; } echo '</li>';
			} echo '</ul>'; return;
		}
		if ( 'preview' !== $stage['state'] ) { echo '<p class="aaa-warning">このプレビューは実行済みです。結果が表示されない場合、再取り込みの前に投稿一覧を確認してください。</p>'; return; }
		if ( ! $review ) { self::materials( $stage ); foreach ( $stage['check']['errors'] as $error ) { echo '<p class="aaa-warning">' . esc_html( $error ) . '</p>'; } if ( ! self::requirements_present( $stage ) && ! $stage['check']['errors'] && $stage['check']['items'] ) { echo '<p><a class="button button-primary aaa-main-action" href="' . esc_url( Admin::url( 'import', array( 'stage' => 'review' ) ) ) . '">内容を確認して次へ</a></p>'; } return; }
		echo '<p><a class="button" href="' . esc_url( Admin::url( 'import' ) ) . '">STEP3へ戻って画像・PDFを変更</a></p>';
		echo '<h2>内容を確認して下書き保存</h2><p>有効期限は検証から30分です。設定や既存投稿の変更時は再検証が必要です。</p>';
		foreach ( $stage['check']['errors'] as $error ) { echo '<p class="aaa-warning">' . esc_html( $error ) . '</p>'; }
		foreach ( $stage['check']['items'] as $item ) {
			echo '<h3>' . esc_html( $item['post_title'] ) . '</h3><details><summary>投稿先・分類・ファイルの詳細</summary><p>' . esc_html( $item['post_type'] . ' / ' . $item['post_name'] . ' / 下書き / 本文 ' . $item['length'] . '文字' ) . '</p>';
			$labels = array();
			foreach ( $item['terms'] as $taxonomy => $ids ) { foreach ( $ids as $id ) { $term = get_term( $id, $taxonomy ); $labels[] = $taxonomy . ':' . $id . ( $term && ! is_wp_error( $term ) ? ' ' . $term->name : '（削除されています）' ); } }
			$missing_count = count( array_filter( $item['media'] ?? array(), static function ( $media ) { return 'missing' === $media['status']; } ) );
			$dummy_count = count( array_filter( $item['media'] ?? array(), static function ( $media ) { return ! empty( $media['is_placeholder'] ); } ) );
			echo '</details><div class="aaa-mode-summary"><p>投稿先：' . esc_html( $item['post_type'] ) . ' ／ カテゴリー：' . esc_html( $labels ? implode( '、', $labels ) : '未指定（WordPressの既定値）' ) . '</p><p>アイキャッチ：' . esc_html( $item['thumbnail'] ? '設定あり' : '未設定' ) . ' ／ 資料 ' . esc_html( count( $item['media'] ?? array() ) ) . '点 ／ 未登録 ' . esc_html( $missing_count ) . '点 ／ ダミー ' . esc_html( $dummy_count ) . '点</p></div><details><summary>抜粋・アイキャッチ・資料の詳細</summary>';
			echo '<p><strong>分類：</strong>' . esc_html( $labels ? implode( '、', $labels ) : '指定なし' ) . '</p><p><strong>抜粋：</strong>' . nl2br( esc_html( $item['post_excerpt'] ) ) . '</p>';
			if ( $item['seo'] ) { echo '<p><strong>SEO案（自動適用なし）：</strong>' . esc_html( wp_json_encode( $item['seo'], JSON_UNESCAPED_UNICODE ) ) . '</p>'; }
			if ( ! empty( $item['acf']['values'] ) ) { echo '<details><summary>ACFへ保存する内容</summary><pre class="aaa-json">' . esc_html( wp_json_encode( $item['acf']['values'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ) . '</pre></details>'; }
			echo '<p><strong>アイキャッチ：</strong>' . ( ! empty( $item['thumbnail'] ) ? 'メディアID ' . esc_html( $item['thumbnail'] ) : '設定なし' ) . '</p>';
			foreach ( $item['media'] ?? array() as $ref => $material ) { echo '<p>資料 ' . esc_html( $ref ) . '：' . esc_html( 'available' === $material['status'] ? $material['filename'] : '未配置・利用不可' ) . '</p>'; }
			echo '</details><details><summary>確認事項 ' . esc_html( count( $item['warnings'] ) ) . '件</summary>';
			foreach ( $item['warnings'] as $warning ) { echo '<p class="aaa-warning">' . esc_html( $warning ) . '</p>'; }
			echo '</details><details><summary>本文と画像の配置を確認</summary><div class="aaa-preview" tabindex="0" role="region" aria-label="記事本文プレビュー">' . wp_kses_post( $item['post_content'] ) . '</div></details>';
		}
		if ( ! $stage['check']['errors'] && $stage['check']['items'] ) {
			self::form( 'aaa_csv_commit' ); echo '<input type="hidden" name="token" value="' . esc_attr( $stage['token'] ) . '"><p><label><input type="checkbox" name="confirmed" value="1" required> 内容と警告を確認しました。新規下書きとして登録します</label></p>'; if ( Importer::dummy( $stage['check'] ) ) { echo '<p><label><input type="checkbox" name="confirmed_dummy" value="1" required> ダミー画像で仮配置し、公開前に置換の要否を確認します</label></p>'; } if ( Importer::missing( $stage['check'] ) ) { echo '<p><label><input type="checkbox" name="confirmed_missing" value="1" required> 不足ファイルを確認し、未配置の確認用段落を残して下書きを作成します（公開前に差し替え）</label></p>'; } submit_button( '確認した内容で下書きを作成する', 'primary aaa-main-action' ); echo '</form>';
		}
	}
}
