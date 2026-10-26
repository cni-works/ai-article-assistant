<?php
namespace AAA;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Blocks {
	public static function node( string $name, array $attrs, string $html, array $children = array(), ?array $chunks = null ): array {
		return array( 'blockName' => 'core/' . $name, 'attrs' => $attrs, 'innerBlocks' => $children, 'innerHTML' => $html, 'innerContent' => $chunks ?? array( $html ) );
	}

	public static function text( $value ): string {
		if ( ! is_string( $value ) || strlen( $value ) > 200000 || preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value ) ) { throw new \InvalidArgumentException( '本文のtextは制御文字を含まない20万バイト以下の文字列にしてください。' ); }
		return sanitize_textarea_field( $value );
	}

	public static function url( $value ): string {
		if ( ! is_string( $value ) || strlen( $value ) > 2000 || preg_match( '/[\s\x00-\x1F]/', $value ) ) { throw new \InvalidArgumentException( 'リンクURLの形式が正しくありません。' ); }
		$url = esc_url_raw( $value, array( 'https', 'http', 'mailto', 'tel' ) );
		$parts = wp_parse_url( $url ); $scheme = strtolower( $parts['scheme'] ?? '' );
		$valid = in_array( $scheme, array( 'http', 'https' ), true ) && ! empty( $parts['host'] ) && ! isset( $parts['user'] ) && ! isset( $parts['pass'] );
		$valid = $valid || ( 'mailto' === $scheme && is_email( $parts['path'] ?? '' ) && ! isset( $parts['query'] ) );
		$valid = $valid || ( 'tel' === $scheme && preg_match( '/^\+?[0-9()-]{3,30}$/D', $parts['path'] ?? '' ) );
		if ( ! $url || ! $valid ) { throw new \InvalidArgumentException( 'リンクは有効なhttp/https・メール・電話のURLを指定してください。' ); }
		return $url;
	}

	private static function rich( string $text ): string { return str_replace( array( "\r\n", "\r", "\n" ), '<br>', esc_html( $text ) ); }

	/** Plain rectangular data only; sourced attributes are recovered from the saved HTML by core/table. */
	private static function table( array $item ): array {
		$headers = array_key_exists( 'headers', $item ) ? $item['headers'] : array(); $rows = $item['rows'] ?? null;
		if ( ! is_array( $headers ) || ! is_array( $rows ) || ! $rows || count( $rows ) > 100 ) { throw new \InvalidArgumentException( '表は任意のheaders配列と1〜100行のrows配列を指定してください。' ); }
		if ( ! is_array( $rows[0] ) || ! $rows[0] || count( $rows[0] ) > 12 ) { throw new \InvalidArgumentException( '表は1〜12列にしてください。' ); }
		$columns = count( $rows[0] );
		if ( $headers && count( $headers ) !== $columns ) { throw new \InvalidArgumentException( '表の見出しとデータ行の列数を揃えてください。' ); }
		$warnings = array( '表の数値・単位・出典と、狭い画面での表示を確認してください。' ); $plain = ''; $body_plain = ''; $html = '<figure class="wp-block-table"><table>';
		$sections = $headers ? array( 'thead' => array( $headers ), 'tbody' => $rows ) : array( 'tbody' => $rows );
		foreach ( $sections as $section => $section_rows ) {
			$html .= '<' . $section . '>';
			foreach ( $section_rows as $row ) {
				if ( ! is_array( $row ) || count( $row ) !== $columns ) { throw new \InvalidArgumentException( '表の各行は同じ列数の配列にしてください。' ); }
				$html .= '<tr>';
				foreach ( $row as $value ) {
					if ( ! is_string( $value ) || strlen( $value ) > 2000 ) { throw new \InvalidArgumentException( '表の各セルは2000バイト以下の平文文字列にしてください。数値も文字列で指定します。' ); }
					$clean = self::text( $value );
					if ( $clean !== $value ) { $warnings[] = '表のHTMLや制御文字等を除去・整形しました。'; }
					if ( 'thead' === $section && '' === trim( $clean ) ) { throw new \InvalidArgumentException( '表の見出しには空のセルを使えません。' ); }
					$tag = 'thead' === $section ? 'th' : 'td';
					$html .= '<' . $tag . ( 'th' === $tag ? ' scope="col"' : '' ) . '>' . self::rich( $clean ) . '</' . $tag . '>';
					$plain .= $clean . "\n"; if ( 'tbody' === $section ) { $body_plain .= $clean; }
				}
				$html .= '</tr>';
			}
			$html .= '</' . $section . '>';
		}
		if ( '' === trim( $body_plain ) ) { throw new \InvalidArgumentException( '表のデータ行がすべて空です。確認済みのデータを指定してください。' ); }
		$caption = array_key_exists( 'caption', $item ) ? self::text( $item['caption'] ) : '';
		if ( strlen( $caption ) > 2000 ) { throw new \InvalidArgumentException( '表の説明文は2000バイト以下にしてください。' ); }
		if ( isset( $item['caption'] ) && $caption !== $item['caption'] ) { $warnings[] = '表の説明文のHTMLや制御文字等を除去・整形しました。'; }
		$html .= '</table>' . ( '' !== $caption ? '<figcaption class="wp-element-caption">' . self::rich( $caption ) . '</figcaption>' : '' ) . '</figure>';
		if ( ! $headers ) { $warnings[] = '表に列見出しがありません。項目の意味が伝わるか確認してください。'; }
		return array( 'block' => self::node( 'table', array(), $html ), 'plain' => $plain . $caption, 'warnings' => $warnings );
	}

	public static function convert( $input, array $media = array(), string $seed = '' ) {
		if ( ! is_array( $input ) || ! $input || count( $input ) > 300 ) { return new \WP_Error( 'blocks', 'content_jsonは1〜300個のブロック配列にしてください。' ); }
		$blocks = array(); $warnings = array(); $plain = ''; $seen_h2 = false; $section = ''; $section_open = false;
		$min = (int) ( Store::site()['min_section'] ?? 80 );
		$used = array();
		try {
			foreach ( $input as $index => $object ) {
				if ( ! $object instanceof \stdClass || ! is_string( $object->type ?? null ) ) { throw new \InvalidArgumentException( '各ブロックはtypeを持つオブジェクトにしてください。' ); }
				$item = (array) $object; $type = $item['type'];
				$allowed = array( 'paragraph' => array( 'type', 'text', 'url' ), 'heading' => array( 'type', 'text', 'level' ), 'list' => array( 'type', 'ordered', 'items' ), 'quote' => array( 'type', 'text', 'citation' ), 'separator' => array( 'type' ), 'button' => array( 'type', 'text', 'url' ), 'table' => array( 'type', 'headers', 'rows', 'caption' ) );
				$allowed['image'] = array( 'type', 'ref', 'alt', 'caption' ); $allowed['file'] = array( 'type', 'ref', 'text', 'download' );
				if ( ! isset( $allowed[ $type ] ) || array_diff( array_keys( $item ), $allowed[ $type ] ) ) { throw new \InvalidArgumentException( '未対応のブロックまたは属性です：' . $type ); }
				$text = isset( $item['text'] ) ? self::text( $item['text'] ) : '';
				if ( isset( $item['text'] ) && $text !== $item['text'] ) { $warnings[] = '本文のHTMLや制御文字等を除去・整形しました。プレビューを確認してください。'; }
				$html = self::rich( $text );
				if ( ! in_array( $type, array( 'list', 'separator', 'table', 'image', 'file' ), true ) && '' === trim( $text ) ) { throw new \InvalidArgumentException( '本文または見出しのtextが空です。' ); }
				if ( 'paragraph' === $type ) {
					if ( isset( $item['url'] ) ) { $html = '<a href="' . esc_attr( self::url( $item['url'] ) ) . '">' . $html . '</a>'; $warnings[] = 'リンクの到達先と掲載内容は手動で確認してください。'; }
					$blocks[] = self::node( 'paragraph', array(), '<p>' . $html . '</p>' );
				} elseif ( 'heading' === $type ) {
					$level = $item['level'] ?? 2;
					if ( ! is_int( $level ) || ! in_array( $level, array( 2, 3 ), true ) ) { throw new \InvalidArgumentException( '見出しのlevelは整数の2または3です。' ); }
					if ( 3 === $level && ! $seen_h2 ) { $warnings[] = 'H2より先にH3があります。見出し階層を確認してください。'; }
					if ( $section_open && $min > 0 && self::length( $section ) < $min ) { $warnings[] = '本文が目安より短い見出しがあります。必要な説明があるか確認してください。'; }
					$seen_h2 = $seen_h2 || 2 === $level; $section_open = true; $section = '';
					$blocks[] = self::node( 'heading', 2 === $level ? array() : array( 'level' => 3 ), '<h' . $level . ' class="wp-block-heading">' . $html . '</h' . $level . '>' );
				} elseif ( 'list' === $type ) {
					$items = $item['items'] ?? null; $ordered = $item['ordered'] ?? false;
					if ( ! is_bool( $ordered ) || ! is_array( $items ) || ! $items || count( $items ) > 200 ) { throw new \InvalidArgumentException( 'リストは1〜200件の平文itemsと真偽値orderedを指定してください。' ); }
					$children = array(); $text = '';
					foreach ( $items as $value ) { $clean = self::text( $value ); if ( $clean !== $value ) { $warnings[] = 'リスト内のHTMLや制御文字等を除去・整形しました。'; } $value = $clean; if ( '' === $value ) { throw new \InvalidArgumentException( 'リストに空の項目は使えません。' ); } $text .= $value . "\n"; $children[] = self::node( 'list-item', array(), '<li>' . self::rich( $value ) . '</li>' ); }
					$tag = $ordered ? 'ol' : 'ul'; $open = '<' . $tag . '>'; $close = '</' . $tag . '>';
					$blocks[] = self::node( 'list', $ordered ? array( 'ordered' => true ) : array(), $open . $close, $children, array_merge( array( $open ), array_fill( 0, count( $children ), null ), array( $close ) ) );
				} elseif ( 'quote' === $type ) {
					$citation = isset( $item['citation'] ) ? self::text( $item['citation'] ) : ''; $end = ( '' !== $citation ? '<cite>' . self::rich( $citation ) . '</cite>' : '' ) . '</blockquote>';
					$blocks[] = self::node( 'quote', array(), '<blockquote class="wp-block-quote">' . $end, array( self::node( 'paragraph', array(), '<p>' . $html . '</p>' ) ), array( '<blockquote class="wp-block-quote">', null, $end ) );
					$text .= '' !== $citation ? "\n" . $citation : '';
				} elseif ( 'separator' === $type ) {
					$blocks[] = self::node( 'separator', array(), '<hr class="wp-block-separator has-alpha-channel-opacity"/>' );
				} elseif ( 'table' === $type ) {
					$table = self::table( $item ); $blocks[] = $table['block']; $text = $table['plain']; $warnings = array_merge( $warnings, $table['warnings'] );
				} elseif ( 'image' === $type || 'file' === $type ) {
					$material = Media::block( $type, $item, $media, $seed . ':' . $index ); $blocks[] = $material['block']; $text = $material['plain']; $warnings = array_merge( $warnings, $material['warnings'] ); $used[] = $item['ref'];
				} else {
					$url = self::url( $item['url'] ?? null ); $child = self::node( 'button', array(), '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="' . esc_attr( $url ) . '">' . $html . '</a></div>' );
					$blocks[] = self::node( 'buttons', array(), '<div class="wp-block-buttons"></div>', array( $child ), array( '<div class="wp-block-buttons">', null, '</div>' ) ); $warnings[] = 'リンクの到達先と掲載内容は手動で確認してください。';
				}
				$plain .= $text . "\n"; if ( 'heading' !== $type ) { $section .= $text; }
			}
		} catch ( \InvalidArgumentException $error ) { return new \WP_Error( 'block', ( $index + 1 ) . '番目のブロック：' . $error->getMessage() ); }
		if ( $section_open && $min > 0 && self::length( $section ) < $min ) { $warnings[] = '本文が目安より短い見出しがあります。必要な説明があるか確認してください。'; }
		return array( 'blocks' => $blocks, 'content' => serialize_blocks( $blocks ), 'plain' => trim( $plain ), 'length' => self::length( trim( $plain ) ), 'warnings' => array_values( array_unique( $warnings ) ), 'used_media' => array_values( array_unique( $used ) ) );
	}

	public static function length( string $text ): int { return function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : preg_match_all( '/./us', $text ); }
}
