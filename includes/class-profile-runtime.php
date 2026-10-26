<?php
namespace AAA;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** One profile definition drives the form, validation and consultation context. */
final class ProfileRuntime {
	public static function questions( string $json ) {
		$items = json_decode( $json );
		if ( ! is_array( $items ) || count( $items ) > 10 ) { return new \WP_Error( 'questions', '追加質問は最大10件のJSON配列で指定してください。' ); }
		$seen = $result = array();
		foreach ( $items as $item ) {
			if ( ! $item instanceof \stdClass || array_diff( array_keys( (array) $item ), array( 'key', 'label', 'type', 'help', 'options', 'required' ) ) ) { return new \WP_Error( 'questions', '追加質問の形式が正しくありません。' ); }
			$key = $item->key ?? null; $type = $item->type ?? 'textarea';
			if ( ! is_string( $key ) || ! preg_match( '/^[a-z][a-z0-9_]{0,31}$/D', $key ) || isset( $seen[ $key ] ) || ! in_array( $type, array( 'text', 'textarea', 'select' ), true ) ) { return new \WP_Error( 'questions', '質問キーの重複・形式または入力形式が正しくありません。' ); }
			foreach ( array( 'label', 'help' ) as $field ) { if ( ! is_string( $item->$field ?? '' ) || strlen( $item->$field ?? '' ) > 1000 ) { return new \WP_Error( 'questions', '質問の見出し・補足は1000バイト以下の文章で指定してください。' ); } }
			$label = sanitize_text_field( $item->label ?? '' );
			if ( '' === $label || ( isset( $item->required ) && ! is_bool( $item->required ) ) ) { return new \WP_Error( 'questions', '質問の見出しと確認要否を見直してください。' ); }
			$options = $item->options ?? array();
			if ( ! is_array( $options ) || count( $options ) > 20 || ( 'select' === $type && ! $options ) || ( 'select' !== $type && $options ) ) { return new \WP_Error( 'questions', '選択肢はselect形式だけに1〜20件で指定してください。' ); }
			$clean = array();
			foreach ( $options as $option ) {
				if ( ! is_string( $option ) || strlen( $option ) > 300 || '' === trim( $option ) ) { return new \WP_Error( 'questions', '選択肢は空でない短い文章で指定してください。' ); }
				$clean[] = sanitize_text_field( $option );
			}
			if ( count( array_unique( $clean ) ) !== count( $clean ) || in_array( '', $clean, true ) ) { return new \WP_Error( 'questions', '空または重複する選択肢は使えません。' ); }
			$result[] = array( 'key' => $key, 'label' => $label, 'type' => $type, 'help' => sanitize_textarea_field( $item->help ?? '' ), 'options' => $clean, 'required' => $item->required ?? false ); $seen[ $key ] = true;
		}
		return $result;
	}
	public static function binding( array $profile ) {
		$type = $profile['default_post_type'] ?? ''; $text = $profile['default_terms'] ?? '';
		if ( '' !== $type && ! isset( Store::types()[ $type ] ) ) { return new \WP_Error( 'binding', '標準投稿タイプが実在しません。既存の投稿先を指定してください。' ); }
		$terms = preg_split( '/\s+/', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );
		if ( count( $terms ) > 200 || ( $terms && '' === $type ) ) { return new \WP_Error( 'binding', '標準分類を使う場合は標準投稿タイプを指定してください。分類は200件までです。' ); }
		$taxonomies = '' === $type ? array() : Store::taxonomies( $type ); $valid = array();
		foreach ( $terms as $term ) {
			if ( ! preg_match( '/^([a-zA-Z0-9_-]+):([1-9][0-9]*)$/D', $term, $match ) || ! isset( $taxonomies[ $match[1] ] ) || ! term_exists( (int) $match[2], $match[1] ) ) { return new \WP_Error( 'binding', '標準分類が実在しないか、標準投稿タイプに対応していません。' ); }
			$valid[] = $match[1] . ':' . (int) $match[2];
		}
		return array( 'post_type' => $type, 'terms' => array_values( array_unique( $valid ) ) );
	}
	public static function available(): array {
		$result = array();
		foreach ( Store::profiles() as $id => $raw ) {
			if ( ! is_array( $raw ) ) { continue; }
			$profile = Store::sanitize( Config::profile_fields(), $raw );
			if ( ! is_wp_error( $profile ) && 'yes' === $profile['enabled'] && ( '' === $profile['default_post_type'] || Store::can_create( $profile['default_post_type'] ) ) ) { $result[ $id ] = $profile; }
		}
		return $result;
	}
	public static function snapshot( array $article ): array {
		$value = $article['settings_snapshot'] ?? array();
		return is_array( $value ) && 1 === ( $value['version'] ?? null ) && ( $value['profile_id'] ?? '' ) === ( $article['profile_id'] ?? '' ) && is_array( $value['profile'] ?? null ) && is_array( $value['site'] ?? null ) ? $value : array();
	}
	public static function profile( array $article ): array {
		$snapshot = self::snapshot( $article );
		return $snapshot ? $snapshot['profile'] : ( Store::profiles()[ $article['profile_id'] ?? '' ] ?? array() );
	}
	public static function answers( array $profile, $raw ) {
		$questions = self::questions( $profile['questions_json'] ?? '[]' );
		if ( is_wp_error( $questions ) ) { return $questions; }
		if ( ! is_array( $raw ) || count( $raw ) > 10 ) { return new \WP_Error( 'answers', '追加質問への回答の形式が正しくありません。' ); }
		$map = array_column( $questions, null, 'key' ); $result = array();
		foreach ( $raw as $key => $value ) {
			if ( ! isset( $map[ $key ] ) || ! is_string( $value ) || strlen( $value ) > 10000 || ( 'select' === $map[ $key ]['type'] && '' !== $value && ! in_array( $value, $map[ $key ]['options'], true ) ) ) { return new \WP_Error( 'answers', '追加質問の項目または選択肢を確認してください。' ); }
			$result[ $key ] = sanitize_textarea_field( $value );
		}
		return $result;
	}
	public static function capture( array $article, array $profile ): array {
		$context = Store::context( array_diff_key( $article, array( 'settings_snapshot' => true ) ) );
		return array( 'version' => 1, 'captured_at' => current_time( 'mysql' ), 'profile_id' => $article['profile_id'], 'profile' => $profile, 'site' => $context['site'], 'categories' => $context['categories'] );
	}
	public static function presentation( array $profile ): array {
		$questions = self::questions( $profile['questions_json'] ?? '[]' ); $binding = self::binding( $profile );
		return array(
			'description' => $profile['description'] ?? '', 'overview_help' => $profile['overview_help'] ?? '', 'materials_hint' => $profile['materials_hint'] ?? '',
			'purpose' => $profile['purpose'] ?? '', 'questions' => is_wp_error( $questions ) ? array() : $questions,
			'default_post_type' => is_wp_error( $binding ) ? '' : $binding['post_type'], 'default_terms' => is_wp_error( $binding ) ? array() : $binding['terms'],
			'rules' => array_intersect_key( $profile, Config::rules() ),
		);
	}
}
