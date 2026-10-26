<?php
namespace AAA;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Explicit, per-user/per-site mapping for the one current article. No attachment writes/downloads. */
final class Media {
	const MAX_ITEMS = 20;

	public static function catalog( string $key ): array {
		$all = get_user_meta( get_current_user_id(), Config::MEDIA, true );
		$saved = is_array( $all ) ? ( $all[ get_current_blog_id() ] ?? array() ) : array();
		return is_array( $saved ) && ( $saved['article_key'] ?? '' ) === $key && is_array( $saved['items'] ?? null ) ? $saved['items'] : array();
	}

	public static function revision( string $key ): string { return hash( 'sha256', wp_json_encode( self::catalog( $key ) ) ); }

	public static function attachment( int $id, string $kind ): ?array {
		if ( ! $id || ! current_user_can( 'upload_files' ) || ! current_user_can( 'edit_post', $id ) ) { return null; }
		$post = get_post( $id );
		if ( ! $post || 'attachment' !== $post->post_type || 'trash' === $post->post_status ) { return null; }
		$mime = get_post_mime_type( $id );
		if ( 'image' === $kind ? ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ), true ) : 'application/pdf' !== $mime ) { return null; }
		$url = 'image' === $kind ? wp_get_attachment_image_url( $id, 'full' ) : wp_get_attachment_url( $id );
		if ( ! is_string( $url ) || ! preg_match( '~^https?://~i', $url ) ) { return null; }
		try { $url = Blocks::url( $url ); } catch ( \InvalidArgumentException $error ) { return null; }
		$parts = wp_parse_url( $url );
		return array( 'attachment_id' => $id, 'url' => $url, 'filename' => sanitize_text_field( rawurldecode( basename( $parts['path'] ?? '' ) ) ), 'mime' => $mime, 'modified_gmt' => (string) ( $post->post_modified_gmt ?? '' ) );
	}

	public static function save( array $raw ) {
		$key = Store::article()['article_key'] ?? '';
		if ( ! current_user_can( 'read' ) || ! Store::can_create( Store::article()['post_type'] ?? '' ) || ! $key ) { return new \WP_Error( 'permission', '記事条件を保存し、利用できる投稿先を確認してください。' ); }
		if ( ( $raw['article_key'] ?? '' ) !== $key || ! is_string( $raw['revision'] ?? null ) || ! hash_equals( self::revision( $key ), $raw['revision'] ) ) { return new \WP_Error( 'changed', '記事または資料の対応表が変わっています。画面を読み込み直してください。' ); }
		if ( ! is_array( $raw['items'] ?? null ) || count( $raw['items'] ) > self::MAX_ITEMS ) { return new \WP_Error( 'media', '資料は20件以下の一覧で指定してください。' ); }
		$items = array(); $featured = 0;
		foreach ( $raw['items'] as $row ) {
			if ( ! is_array( $row ) ) { return new \WP_Error( 'media', '資料の形式が正しくありません。' ); }
			if ( '1' === ( $row['remove'] ?? '' ) ) { continue; }
			$ref = $row['ref'] ?? '';
			if ( '' === $ref && ! array_filter( array( $row['description'] ?? '', $row['alt'] ?? '', $row['caption'] ?? '', $row['required'] ?? '', $row['featured'] ?? '', $row['attachment_id'] ?? '' ), static function ( $value ) { return '' !== $value && '0' !== $value && 0 !== $value; } ) ) { continue; }
			if ( ! is_string( $ref ) || ! preg_match( '/^[a-z][a-z0-9_-]{0,39}$/D', $ref ) || isset( $items[ $ref ] ) ) { return new \WP_Error( 'ref', '識別子は英小文字から始まる英小文字・数字・_・-の40文字以下で、重複させないでください。' ); }
			$kind = $row['kind'] ?? ''; $id = $row['attachment_id'] ?? '0';
			if ( ! in_array( $kind, array( 'image', 'pdf' ), true ) || ( ! is_string( $id ) && ! is_int( $id ) ) || ! preg_match( '/^(0|[1-9][0-9]{0,9})$/D', (string) $id ) || (string) (int) $id !== (string) $id ) { return new \WP_Error( 'media', '資料の種類またはメディアIDが正しくありません。未配置は0で指定します。' ); }
			$item = array( 'kind' => $kind, 'attachment_id' => (int) $id );
			foreach ( array( 'description', 'alt', 'caption' ) as $field ) {
				$value = $row[ $field ] ?? '';
				if ( ! is_string( $value ) || strlen( $value ) > 2000 || ! preg_match( '//u', $value ) || preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value ) ) { return new \WP_Error( 'media', '説明・alt・キャプションは各2000バイト以下の平文にしてください。' ); }
				$item[ $field ] = sanitize_textarea_field( $value );
			}
			foreach ( array( 'required', 'featured' ) as $flag ) {
				if ( ! in_array( $row[ $flag ] ?? '0', array( '0', '1' ), true ) ) { return new \WP_Error( 'media', '資料の必須・アイキャッチの指定が正しくありません。' ); }
				$item[ $flag ] = '1' === ( $row[ $flag ] ?? '0' );
			}
			if ( $item['featured'] && ( 'image' !== $kind || ++$featured > 1 ) ) { return new \WP_Error( 'featured', 'アイキャッチは画像1件だけを選んでください。' ); }
			// Existing unavailable mappings may be retained to show missing material, but never newly assigned.
			$old = self::catalog( $key )[ $ref ] ?? array();
			if ( $item['attachment_id'] && ! self::attachment( $item['attachment_id'], $kind ) && ( ( $old['attachment_id'] ?? 0 ) !== $item['attachment_id'] || ( $old['kind'] ?? '' ) !== $kind ) ) { return new \WP_Error( 'attachment', '選択した資料が存在しない、種類が違う、または利用権限がありません。' ); }
			$items[ $ref ] = $item;
		}
		$all = get_user_meta( get_current_user_id(), Config::MEDIA, true ); $all = is_array( $all ) ? $all : array();
		$all[ get_current_blog_id() ] = array( 'article_key' => $key, 'items' => $items );
		update_user_meta( get_current_user_id(), Config::MEDIA, wp_slash( $all ) );
		return get_user_meta( get_current_user_id(), Config::MEDIA, true ) === $all ? $items : new \WP_Error( 'save', '資料の対応表を保存できませんでした。' );
	}

	public static function context( string $key ): array {
		$result = array();
		foreach ( self::catalog( $key ) as $ref => $item ) {
			$resolved = self::attachment( $item['attachment_id'], $item['kind'] );
			$result[ $ref ] = array_intersect_key( $item, array_flip( array( 'kind', 'description', 'alt', 'caption', 'required', 'featured' ) ) ) + array( 'status' => $resolved ? 'available' : 'missing', 'content_read' => false );
			if ( $resolved ) { $result[ $ref ] += $resolved; }
		}
		return $result;
	}

	/** CSV carries identifiers only; IDs/URLs always come from this user's saved catalog. */
	public static function resolve( \stdClass $input, string $key, string $type, array $bindings = array(), array $image_options = array() ): array {
		$raw = (array) $input;
		if ( array_diff( array_keys( $raw ), array( 'items', 'featured', 'files' ) ) ) { throw new \InvalidArgumentException( 'media_jsonのキーはitems・featured・filesだけです。メディアIDやURLは対応表で指定してください。' ); }
		$refs = array_key_exists( 'items', $raw ) ? $raw['items'] : array(); $featured = array_key_exists( 'featured', $raw ) ? $raw['featured'] : '';
		if ( ! is_array( $refs ) || count( $refs ) > self::MAX_ITEMS || ! is_string( $featured ) ) { throw new \InvalidArgumentException( 'media_json.itemsは識別子の配列、featuredは識別子の文字列です。' ); }
		if ( count( array_filter( $refs, 'is_string' ) ) !== count( $refs ) || count( array_unique( $refs ) ) !== count( $refs ) ) { throw new \InvalidArgumentException( 'media_json.itemsは重複しない文字列の配列です。' ); }
		$catalog = self::context( $key ); $selected = array(); $warnings = array();
		if ( array_key_exists( 'files', $raw ) ) {
			$plans = self::plans( $raw['files'] ); $planned = array();
			foreach ( $plans as $ref => $plan ) {
				$old = $catalog[ $ref ] ?? array();
				$id = $bindings[ $ref ] ?? ( ( $old['kind'] ?? '' ) === $plan['kind'] ? ( $old['attachment_id'] ?? 0 ) : 0 );
				$resolved = self::attachment( (int) $id, $plan['kind'] );
				list( $resolved, $placement ) = Placeholder::choose( $plan, $resolved, $image_options, $ref );
				$planned[ $ref ] = $plan + $placement + array( 'status' => $resolved ? 'available' : 'missing', 'content_read' => false );
				if ( $resolved ) { $planned[ $ref ] += $resolved; }
			}
			$catalog = $planned; // Latest CSV plan is authoritative; removed plans are not resurrected.
			if ( count( $refs ) !== count( $catalog ) || array_diff( array_keys( $catalog ), $refs ) ) { throw new \InvalidArgumentException( 'filesの全識別子をitemsにも1回ずつ指定してください。' ); }
		}
		foreach ( $refs as $ref ) {
			if ( ! is_string( $ref ) || ! isset( $catalog[ $ref ] ) || isset( $selected[ $ref ] ) ) { throw new \InvalidArgumentException( '未登録または重複した資料識別子です。画像・PDF管理の対応表を確認してください。' ); }
			$selected[ $ref ] = $catalog[ $ref ];
		}
		$expected_featured = '';
		foreach ( $catalog as $ref => $item ) { if ( $item['featured'] ) { $expected_featured = $ref; } }
		if ( $featured !== $expected_featured ) { throw new \InvalidArgumentException( 'アイキャッチ指定が管理画面の対応表と一致しません。出力指示を再生成してください。' ); }
		if ( '' !== $featured && ( ! isset( $selected[ $featured ] ) || 'image' !== $selected[ $featured ]['kind'] ) ) { throw new \InvalidArgumentException( 'アイキャッチ画像をmedia_json.itemsにも指定してください。' ); }
		$thumbnail = '' !== $featured && 'available' === $selected[ $featured ]['status'] ? $selected[ $featured ]['attachment_id'] : 0;
		if ( '' !== $featured && ! post_type_supports( $type, 'thumbnail' ) ) { $thumbnail = 0; $warnings[] = 'この投稿先はアイキャッチに対応していません。画像はアイキャッチに設定しません。'; }
		foreach ( $catalog as $ref => $item ) {
			if ( ! empty( $item['is_placeholder'] ) ) { $warnings[] = '画像 ' . $ref . ' はダミーで仮配置します。元の識別子・推奨名・位置を保持します。公開前に置換してください。'; }
			elseif ( ! empty( $item['placeholder_requested'] ) ) { $warnings[] = '画像 ' . $ref . ' のダミー画像を利用できません。管理者が標準画像または指定画像を確認してください。'; }
			if ( 'missing' === $item['status'] ) { $warnings[] = '資料 ' . $ref . ' が未配置または利用できません。対応表と下書きを確認してください。'; }
			if ( $item['required'] && ! isset( $selected[ $ref ] ) ) { $warnings[] = '必須資料 ' . $ref . ' がCSVに含まれていません。'; }
		}
		return array( 'selected' => $selected, 'snapshot' => $catalog, 'featured' => $featured, 'thumbnail' => $thumbnail, 'warnings' => $warnings );
	}

	/** Portable plans contain no IDs or URLs. The same ref is unique within its article UUID. */
	public static function plans( $files ): array {
		if ( ! $files instanceof \stdClass || count( (array) $files ) > self::MAX_ITEMS ) { throw new \InvalidArgumentException( 'filesは識別子をキーにした20件以下のJSONオブジェクトです。' ); }
		$result = array(); $featured = 0;
		foreach ( $files as $ref => $raw ) {
			if ( ! preg_match( '/^[a-z][a-z0-9_-]{0,39}$/D', (string) $ref ) || ! $raw instanceof \stdClass ) { throw new \InvalidArgumentException( '資料識別子と計画の形式を確認してください。' ); }
			$row = (array) $raw;
			$texts = array( 'filename', 'description', 'position', 'alt', 'caption', 'text', 'size', 'generation_prompt' );
			if ( array_diff( array_keys( $row ), array_merge( $texts, array( 'kind', 'role', 'required', 'production_status' ) ) ) ) { throw new \InvalidArgumentException( '画像計画に未対応の項目があります。URL・Attachment IDはCSVに入れません。' ); }
			$kind = $row['kind'] ?? ''; $role = $row['role'] ?? '';
			if ( ! in_array( $kind, array( 'image', 'pdf' ), true ) || ! in_array( $role, 'image' === $kind ? array( 'featured', 'body' ) : array( 'pdf' ), true ) || ( 'featured' === $role && ++$featured > 1 ) ) { throw new \InvalidArgumentException( '資料の種類・用途、またはアイキャッチの件数を確認してください。' ); }
			$item = array( 'kind' => $kind, 'role' => $role, 'featured' => 'featured' === $role, 'required' => $row['required'] ?? false );
			if ( ! is_bool( $item['required'] ) || ! in_array( $row['production_status'] ?? 'planned', array( 'planned', 'prepared' ), true ) ) { throw new \InvalidArgumentException( 'requiredは真偽値、production_statusはplanned/preparedです。' ); }
			$item['production_status'] = $row['production_status'] ?? 'planned';
			foreach ( $texts as $field ) {
				$value = $row[ $field ] ?? '';
				if ( ! is_string( $value ) || strlen( $value ) > 2000 || ! preg_match( '//u', $value ) || preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value ) ) { throw new \InvalidArgumentException( '資料計画の説明は各2000バイト以下のUTF-8平文にしてください。' ); }
				$item[ 'filename' === $field ? 'suggested_filename' : $field ] = sanitize_textarea_field( $value );
			}
			$name = $item['suggested_filename'];
			if ( '' === $name || strlen( $name ) > 240 || preg_match( '~[\\\\/:\r\n]~', $name ) || ! preg_match( 'image' === $kind ? '/\.(jpe?g|png|gif|webp)$/iD' : '/\.pdf$/iD', $name ) ) { throw new \InvalidArgumentException( '推奨ファイル名はパスを含めず、対応する画像拡張子または.pdfを付けてください。' ); }
			$result[ $ref ] = $item;
		}
		return $result;
	}

	private static function rich( string $value ): string { return str_replace( array( "\r\n", "\r", "\n" ), '<br>', esc_html( $value ) ); }

	public static function block( string $type, array $item, array $media, string $seed = '' ): array {
		$ref = $item['ref'] ?? null;
		if ( ! is_string( $ref ) || ! isset( $media[ $ref ] ) || $media[ $ref ]['kind'] !== ( 'image' === $type ? 'image' : 'pdf' ) ) { throw new \InvalidArgumentException( 'ブロックの資料識別子がmedia_jsonまたは資料の種類と一致しません。' ); }
		$source = $media[ $ref ]; $warnings = array();
		$text = 'file' === $type ? ( array_key_exists( 'text', $item ) ? $item['text'] : ( ( $source['text'] ?? '' ) ?: ( $source['description'] ?: 'PDF資料' ) ) ) : ( array_key_exists( 'alt', $item ) ? $item['alt'] : $source['alt'] );
		$caption = 'file' === $type ? '' : ( array_key_exists( 'caption', $item ) ? $item['caption'] : $source['caption'] );
		$download = array_key_exists( 'download', $item ) ? $item['download'] : true;
		if ( ! is_bool( $download ) ) { throw new \InvalidArgumentException( 'PDFのdownloadは真偽値です。' ); }
		foreach ( array( $text, $caption ) as $value ) { if ( ! is_string( $value ) || strlen( $value ) > 2000 ) { throw new \InvalidArgumentException( '資料の表示文は各2000バイト以下の平文文字列にしてください。' ); } }
		$clean_text = Blocks::text( $text ); $clean_caption = Blocks::text( $caption );
		if ( $clean_text !== $text || $clean_caption !== $caption ) { $warnings[] = '資料の表示文からHTML等を除去・整形しました。'; }
		$text = $clean_text; $caption = $clean_caption;
		if ( 'file' === $type && '' === $text ) { throw new \InvalidArgumentException( 'PDFリンクの表示文を指定してください。' ); }
		if ( 'missing' === $source['status'] ) {
			$plain = '【資料未配置：' . $ref . '】' . $source['description'] . ( '' !== $text ? ' ' . $text : '' ) . ( '' !== $caption ? ' ' . $caption : '' );
			return array( 'block' => Blocks::node( 'paragraph', array(), '<p>' . self::rich( $plain ) . '</p>' ), 'plain' => $plain, 'warnings' => array_merge( $warnings, array( '資料 ' . $ref . ' は未配置のため確認用の段落を残しました。公開前に差し替えてください。' ) ) );
		}
		if ( 'image' === $type ) {
			$html = '<figure class="wp-block-image size-full"><img src="' . esc_attr( $source['url'] ) . '" alt="' . esc_attr( $text ) . '" class="wp-image-' . $source['attachment_id'] . '"/>' . ( '' !== $caption ? '<figcaption class="wp-element-caption">' . self::rich( $caption ) . '</figcaption>' : '' ) . '</figure>';
			$node = Blocks::node( 'image', array( 'id' => $source['attachment_id'], 'sizeSlug' => 'full', 'linkDestination' => 'none' ), $html );
			if ( '' === $text ) { $warnings[] = '画像 ' . $ref . ' のaltが空です。装飾画像か、説明が必要か確認してください。'; }
		} else {
			$file_id = 'aaa-file-' . substr( hash( 'sha256', $seed . ':' . $ref . wp_json_encode( $item ) ), 0, 20 );
			$html = '<div class="wp-block-file"><a id="' . $file_id . '" href="' . esc_attr( $source['url'] ) . '">' . self::rich( $text ) . '</a>' . ( $download ? '<a href="' . esc_attr( $source['url'] ) . '" class="wp-block-file__button wp-element-button" download aria-describedby="' . $file_id . '">ダウンロード</a>' : '' ) . '</div>';
			$node = Blocks::node( 'file', array( 'id' => $source['attachment_id'], 'href' => $source['url'], 'fileId' => $file_id, 'displayPreview' => false, 'showDownloadButton' => $download ), $html );
			$warnings[] = 'PDF ' . $ref . ' の内容・リンク・配布可否を確認してください。PDFの本文は自動読取していません。';
		}
		return array( 'block' => $node, 'plain' => $text . "\n" . $caption, 'warnings' => $warnings );
	}
}
