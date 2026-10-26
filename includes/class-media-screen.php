<?php
namespace AAA;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class MediaScreen {
	public function __construct() { add_action( 'admin_post_aaa_media_save', array( $this, 'save' ) ); }
	public function save(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { wp_die( 'POSTで操作してください。', '', array( 'response' => 405 ) ); }
		check_admin_referer( 'aaa_media_save', 'aaa_nonce' );
		$raw = wp_unslash( $_POST ); $result = Media::save( $raw );
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '資料を保存できませんでした', array( 'response' => 400, 'back_link' => true ) ); }
		wp_safe_redirect( Admin::url( 'media', array( 'saved' => '1' ) ) ); exit;
	}
	public static function render(): void {
		$key = Store::article()['article_key'] ?? '';
		if ( ! $key || ! Store::can_create( Store::article()['post_type'] ?? '' ) ) { echo '<p>先に<a href="' . esc_url( Admin::url() ) . '">記事を作成</a>で今回の記事条件を保存してください。</p>'; return; }
		$items = Media::catalog( $key ); $resolved = Media::context( $key );
		echo '<p>今回の記事の画像・PDFを最大20件登録します。「photo-1」「guide」などの識別子を付け、標準メディアから選択してください。ファイル名による自動照合はしません。未アップロードならIDを0にして説明だけ保存し、後から同じ識別子にメディアを対応付けられます。</p><p>対応表は本人・サイトごとの現在の記事1件分です。新しい記事を開始すると置き換わるため、必要なCSV・原稿・対応内容を先に保管してください。メディア自体は削除・変更しません。</p>';
		if ( ! current_user_can( 'upload_files' ) ) { echo '<p class="aaa-warning">メディアを選択・使用する権限がありません。未配置の資料として説明を保存できます。アップロード権限は管理者へ確認してください。</p>'; }
		echo '<p>記事管理ID：<code>' . esc_html( $key ) . '</code>。説明を登録しても、画像・PDFの内容をAIが読んだことにはなりません。ChatGPTへ実ファイルを添付し、読めなければ必要部分をテキストで提供してください。</p><form class="aaa-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="aaa_media_save"><input type="hidden" name="article_key" value="' . esc_attr( $key ) . '"><input type="hidden" name="revision" value="' . esc_attr( Media::revision( $key ) ) . '">';
		wp_nonce_field( 'aaa_media_save', 'aaa_nonce' );
		$rows = array(); foreach ( $items as $ref => $item ) { $rows[] = array( 'ref' => $ref ) + $item; }
		for ( $i = 0; $i < Media::MAX_ITEMS; ++$i ) {
			$row = $rows[ $i ] ?? array( 'ref' => '', 'kind' => 'image', 'attachment_id' => 0, 'description' => '', 'alt' => '', 'caption' => '', 'required' => false, 'featured' => false );
			$name = 'items[' . $i . ']'; $prefix = 'aaa-material-' . $i;
			echo '<details data-aaa-media-row' . ( 0 === $i || '' !== $row['ref'] ? ' open' : '' ) . '><summary>資料 ' . ( $i + 1 ) . ( $row['ref'] ? '：' . esc_html( $row['ref'] ) : '（未使用）' ) . '</summary><p><label for="' . $prefix . '-ref">識別子</label> <input id="' . $prefix . '-ref" name="' . $name . '[ref]" value="' . esc_attr( $row['ref'] ) . '" maxlength="40" pattern="[a-z][a-z0-9_-]{0,39}"> <label for="' . $prefix . '-kind">種類</label> <select id="' . $prefix . '-kind" name="' . $name . '[kind]" data-aaa-media-kind><option value="image" ' . selected( $row['kind'], 'image', false ) . '>画像（JPEG・PNG・GIF・WebP）</option><option value="pdf" ' . selected( $row['kind'], 'pdf', false ) . '>PDF</option></select></p>';
			echo '<p><label for="' . $prefix . '-id">メディアID（0＝未配置）</label> <input id="' . $prefix . '-id" type="number" min="0" max="9999999999" name="' . $name . '[attachment_id]" value="' . esc_attr( $row['attachment_id'] ) . '" data-aaa-media-id>';
			if ( current_user_can( 'upload_files' ) ) { echo ' <button class="button" type="button" data-aaa-media-pick hidden>標準メディアから選ぶ</button>'; }
			echo '</p><p data-aaa-media-selection role="status">';
			$source = $resolved[ $row['ref'] ] ?? null;
			if ( $source && 'available' === $source['status'] ) { echo '保存済み：' . esc_html( $source['filename'] ) . ' <a href="' . esc_url( $source['url'] ) . '" target="_blank" rel="noopener noreferrer">資料を開く</a>'; }
			elseif ( $row['ref'] ) { echo '未配置または利用できない資料です。'; }
			echo '</p>';
			foreach ( array( 'description' => '資料の説明・用途（PDFリンクの既定の表示文）', 'alt' => '画像のalt（画像ごとの説明）', 'caption' => '画像のキャプション' ) as $field => $label ) {
				echo '<p><label for="' . $prefix . '-' . $field . '">' . esc_html( $label ) . '</label><textarea id="' . $prefix . '-' . $field . '" class="large-text" rows="2" name="' . $name . '[' . $field . ']">' . esc_textarea( $row[ $field ] ) . '</textarea></p>';
			}
			foreach ( array( 'required' => '今回の記事で必須', 'featured' => 'アイキャッチに使う（画像1件だけ）', 'remove' => 'この対応を一覧から削除（メディアは削除しません）' ) as $field => $label ) { echo '<p><label><input type="checkbox" name="' . $name . '[' . $field . ']" value="1" ' . checked( $row[ $field ] ?? false, true, false ) . '> ' . esc_html( $label ) . '</label></p>'; }
			echo '</details>';
		}
		submit_button( '今回の記事の画像・PDFを保存' ); echo '</form><p>保存・照合後は<a href="' . esc_url( Admin::url( '', array( 'step' => '4' ) ) ) . '">確認・プロンプト出力</a>で相談・CSV出力プロンプトを再生成してください。資料を後から対応付けた場合はCSVも検証し直します。</p>';
	}
}
