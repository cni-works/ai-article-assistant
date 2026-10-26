<?php
namespace AAA;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Optional ACF adapter. Schema comes from ACF, permission to write from an administrator's allowlist. */
final class Acf {
	public static function available(): bool {
		return function_exists( 'acf_get_field_groups' ) && function_exists( 'acf_get_fields' ) && function_exists( 'update_field' ) && function_exists( 'get_field' );
	}
	public static function settings(): array { $value = get_option( Config::ACF, array() ); return is_array( $value ) ? $value : array(); }
	public static function revision(): string { return hash( 'sha256', wp_json_encode( self::settings() ) ); }

	/** Fail closed for conditions requiring a real post, term, user, or dynamic context. */
	private static function matches( array $group, string $type ): bool {
		if ( empty( $group['active'] ) || ! is_array( $group['location'] ?? null ) ) { return false; }
		foreach ( $group['location'] as $rules ) {
			if ( ! is_array( $rules ) || ! $rules ) { continue; }
			$match = true;
			foreach ( $rules as $rule ) {
				if ( ! is_array( $rule ) || 'post_type' !== ( $rule['param'] ?? '' ) || ! in_array( $rule['operator'] ?? '', array( '==', '!=' ), true ) || ! is_string( $rule['value'] ?? null ) ) { $match = false; break; }
				$match = $match && ( '==' === $rule['operator'] ? $type === $rule['value'] : $type !== $rule['value'] );
			}
			if ( $match ) { return true; }
		}
		return false;
	}

	private static function field( array $field, int $depth = 0 ): ?array {
		$key = $field['key'] ?? ''; $name = $field['name'] ?? ''; $type = $field['type'] ?? '';
		if ( $depth > 3 || ! is_string( $key ) || ! preg_match( '/^field_[A-Za-z0-9_]{1,90}$/D', $key ) || ! is_string( $name ) || ! preg_match( '/^[a-z][a-z0-9_]{0,63}$/D', $name ) || ! empty( $field['conditional_logic'] ) || ! empty( $field['disabled'] ) || ! empty( $field['readonly'] ) || ! in_array( $type, array( 'text', 'textarea', 'number', 'select', 'true_false', 'image', 'file', 'group' ), true ) ) { return null; }
		$label = $field['label'] ?? $name; if ( ! is_string( $label ) || strlen( $label ) > 2000 || ! preg_match( '//u', $label ) ) { return null; }
		$result = array( 'key' => $key, 'name' => $name, 'label' => sanitize_text_field( $label ), 'type' => $type, 'required' => ! empty( $field['required'] ) );
		if ( 'image' === $type || 'file' === $type ) { foreach ( array( 'mime_types', 'min_width', 'min_height', 'max_width', 'max_height', 'min_size', 'max_size' ) as $constraint ) { if ( ! empty( $field[ $constraint ] ) ) { return null; } } }
		if ( 'group' === $type ) {
			$children = $field['sub_fields'] ?? acf_get_fields( $key );
			if ( ! is_array( $children ) || ! $children || count( $children ) > 30 ) { return null; }
			$result['children'] = array();
			foreach ( $children as $child ) { if ( ! is_array( $child ) || ! ( $schema = self::field( $child, $depth + 1 ) ) || isset( $result['children'][ $schema['key'] ] ) ) { return null; } $result['children'][ $schema['key'] ] = $schema; }
		} elseif ( 'select' === $type ) {
			if ( ! is_array( $field['choices'] ?? null ) || ! $field['choices'] || count( $field['choices'] ) > 200 ) { return null; }
			$result['choices'] = array();
			foreach ( $field['choices'] as $value => $label ) { if ( ! is_scalar( $label ) || strlen( (string) $value ) > 2000 || strlen( (string) $label ) > 2000 ) { return null; } $result['choices'][ (string) $value ] = sanitize_text_field( (string) $label ); }
			$result['multiple'] = ! empty( $field['multiple'] ); $result['allow_null'] = ! empty( $field['allow_null'] );
		} elseif ( 'text' === $type || 'textarea' === $type ) {
			$result['maxlength'] = '' === ( $field['maxlength'] ?? '' ) ? 0 : max( 0, (int) $field['maxlength'] );
		} elseif ( 'number' === $type ) {
			foreach ( array( 'min', 'max', 'step' ) as $constraint ) { $value = $field[ $constraint ] ?? ''; if ( '' !== $value && ( ! is_scalar( $value ) || ! preg_match( '/^-?(?:0|[1-9][0-9]{0,8})(?:\.[0-9]{1,6})?$/D', (string) $value ) || ( 'step' === $constraint && (float) $value <= 0 ) ) ) { return null; } $result[ $constraint ] = '' === $value ? null : (string) $value; }
		}
		return $result;
	}

	private static function paths( array $field, string $prefix, array &$paths, array &$keys, int &$count ): void {
		$name = $prefix . $field['name'];
		if ( ++$count > 200 || strlen( $name ) > 150 || isset( $paths[ $name ] ) || isset( $keys[ $field['key'] ] ) ) { throw new \InvalidArgumentException( 'ACFのキー・保存名が重複するか、フィールド数・階層が上限を超えています。' ); }
		$paths[ $name ] = true; $keys[ $field['key'] ] = true;
		foreach ( $field['children'] ?? array() as $child ) { self::paths( $child, $name . '_', $paths, $keys, $count ); }
	}

	public static function discover( string $type ) {
		if ( ! self::available() ) { return new \WP_Error( 'acf_unavailable', 'ACFが利用できません。通常の記事作成は利用できます。' ); }
		$groups = acf_get_field_groups();
		if ( ! is_array( $groups ) || count( $groups ) > 300 ) { return new \WP_Error( 'acf_schema', 'ACFのグループを取得できないか、上限を超えています。' ); }
		$result = array(); $paths = array(); $keys = array(); $count = 0;
		try {
			foreach ( $groups as $group ) {
				if ( ! is_array( $group ) || ! self::matches( $group, $type ) ) { continue; }
				$fields = acf_get_fields( $group['key'] ?? '' );
				if ( ! is_array( $fields ) || count( $fields ) > 100 ) { throw new \InvalidArgumentException( 'ACFフィールドを取得できないか、上限を超えています。' ); }
				foreach ( $fields as $field ) {
					if ( ! is_array( $field ) || ! ( $schema = self::field( $field ) ) ) { continue; }
					self::paths( $schema, '', $paths, $keys, $count ); $result[ $schema['key'] ] = $schema;
				}
			}
		} catch ( \InvalidArgumentException $error ) { return new \WP_Error( 'acf_schema', $error->getMessage() ); }
		return $result;
	}

	public static function context( string $type ): array {
		$setting = self::settings()[ $type ] ?? array(); $selected = $setting['fields'] ?? array();
		if ( ! is_array( $selected ) || count( $selected ) > 60 || array_filter( $selected, static function ( $value ) { return ! is_string( $value ); } ) ) { return array( 'fields' => array(), 'unavailable' => 'ACF連携の設定形式が不正です。管理者が保存し直してください。' ); }
		if ( ! $selected ) { return array( 'fields' => array() ); }
		$schema = self::discover( $type );
		if ( is_wp_error( $schema ) ) { return array( 'fields' => array(), 'unavailable' => $schema->get_error_message() ); }
		if ( array_diff( $selected, array_keys( $schema ) ) ) { return array( 'fields' => array(), 'unavailable' => '許可したACF定義が変更されています。管理者がACF連携設定を保存し直してください。' ); }
		$fields = array_intersect_key( $schema, array_fill_keys( $selected, true ) );
		if ( ( $setting['definitions'] ?? '' ) !== hash( 'sha256', wp_json_encode( $fields ) ) ) { return array( 'fields' => array(), 'unavailable' => '許可後にACFの型・制約・選択肢等が変更されています。管理者がACF連携設定を確認して保存し直してください。' ); }
		return array( 'fields' => $fields );
	}

	public static function save_settings( array $raw ) {
		if ( ! current_user_can( 'read' ) || ! current_user_can( 'manage_options' ) ) { return new \WP_Error( 'permission', 'ACF連携を設定する権限がありません。' ); }
		$type = $raw['post_type'] ?? ''; $selected = $raw['fields'] ?? array();
		if ( ! is_string( $type ) || ! isset( Store::types()[ $type ] ) || ! is_array( $selected ) || count( $selected ) > 60 || array_filter( $selected, static function ( $value ) { return ! is_string( $value ); } ) ) { return new \WP_Error( 'acf_settings', '投稿先と許可するフィールドを選び直してください。' ); }
		if ( ! is_string( $raw['revision'] ?? null ) || ! hash_equals( self::revision(), $raw['revision'] ) ) { return new \WP_Error( 'changed', 'ACF連携の設定が変わっています。画面を読み込み直してください。' ); }
		if ( $selected ) { $schema = self::discover( $type ); if ( is_wp_error( $schema ) ) { return $schema; } if ( array_diff( $selected, array_keys( $schema ) ) || ( $raw['schema_hash'] ?? '' ) !== hash( 'sha256', wp_json_encode( $schema ) ) ) { return new \WP_Error( 'changed', 'ACF定義が変わっています。画面を読み込み直してください。' ); } }
		$all = self::settings(); $all[ $type ] = array( 'fields' => array_values( array_unique( $selected ) ), 'definitions' => $selected ? hash( 'sha256', wp_json_encode( array_intersect_key( $schema, array_fill_keys( $selected, true ) ) ) ) : '' );
		return Store::write_option( Config::ACF, $all ) ? true : new \WP_Error( 'save', 'ACF連携の設定を保存できませんでした。' );
	}

	private static function decimal( $value ): string {
		if ( ! is_string( $value ) || ! preg_match( '/^-?(?:0|[1-9][0-9]{0,8})(?:\.[0-9]{1,6})?$/D', $value ) ) { throw new \InvalidArgumentException( '数値は整数部9桁・小数部6桁以下の10進文字列で指定してください。' ); }
		$value = false !== strpos( $value, '.' ) ? rtrim( rtrim( $value, '0' ), '.' ) : $value;
		return '-0' === $value ? '0' : $value;
	}

	private static function value( array $field, $value, array $media, array &$warnings, string &$plain, array &$used ) {
		$type = $field['type'];
		if ( 'group' === $type ) {
			if ( ! $value instanceof \stdClass || array_diff( array_keys( (array) $value ), array_keys( $field['children'] ) ) || array_diff( array_keys( $field['children'] ), array_keys( (array) $value ) ) ) { throw new \InvalidArgumentException( 'グループはすべての子フィールドキーを持つオブジェクトにしてください。省略・未知キーは使えません。' ); }
			$result = array(); foreach ( $field['children'] as $key => $child ) { $result[ $key ] = self::value( $child, $value->$key, $media, $warnings, $plain, $used ); } return $result;
		}
		if ( 'text' === $type || 'textarea' === $type ) {
			if ( ! is_string( $value ) || strlen( $value ) > 10000 ) { throw new \InvalidArgumentException( '文字項目は1万バイト以下の平文文字列にしてください。' ); }
			$clean = Blocks::text( $value ); if ( 'text' === $type ) { $clean = sanitize_text_field( $clean ); }
			if ( $clean !== $value ) { $warnings[] = 'ACFの文章を平文へ整形しました。'; }
			if ( $field['maxlength'] && Blocks::length( $clean ) > $field['maxlength'] ) { throw new \InvalidArgumentException( 'ACF設定の最大文字数を超えています。' ); }
			$result = $clean;
		} elseif ( 'number' === $type ) {
			if ( '' === $value && ! $field['required'] ) { return ''; }
			$result = self::decimal( $value ); $scaled = round( (float) $result * 1000000 );
			// Supported scaled integers fit exactly within IEEE-754's integer range, including on 32-bit PHP.
			if ( ( null !== $field['min'] && $scaled < round( (float) $field['min'] * 1000000 ) ) || ( null !== $field['max'] && $scaled > round( (float) $field['max'] * 1000000 ) ) ) { throw new \InvalidArgumentException( 'ACF設定の数値範囲外です。' ); }
			if ( null !== $field['step'] && 0.0 !== abs( fmod( $scaled - round( (float) ( $field['min'] ?? 0 ) * 1000000 ), round( (float) $field['step'] * 1000000 ) ) ) ) { throw new \InvalidArgumentException( 'ACF設定の数値の刻みに一致しません。' ); }
		} elseif ( 'true_false' === $type ) {
			if ( ! is_bool( $value ) ) { throw new \InvalidArgumentException( '真偽値はtrueまたはfalseにしてください。' ); } $result = $value;
		} elseif ( 'select' === $type ) {
			$values = $field['multiple'] ? $value : array( $value );
			if ( ! is_array( $values ) || count( $values ) > 200 ) { throw new \InvalidArgumentException( '複数選択は選択肢の文字列配列です。' ); }
			foreach ( $values as $choice ) { if ( ! is_string( $choice ) || ( '' === $choice && ! $field['required'] && $field['allow_null'] ? false : ! array_key_exists( $choice, $field['choices'] ) ) ) { throw new \InvalidArgumentException( 'ACFにない選択肢または型が含まれています。' ); } }
			if ( count( $values ) !== count( array_unique( $values ) ) ) { throw new \InvalidArgumentException( '選択肢を重複させないでください。' ); }
			$result = $field['multiple'] ? $values : $value;
			foreach ( $values as $choice ) { $plain .= ( $field['choices'][ $choice ] ?? '' ) . "\n"; }
		} else {
			if ( '' === $value && ! $field['required'] ) { return 0; }
			if ( ! is_string( $value ) || ! isset( $media[ $value ] ) || 'available' !== $media[ $value ]['status'] || ( 'image' === $type ? 'image' : 'pdf' ) !== $media[ $value ]['kind'] ) { throw new \InvalidArgumentException( '画像・ファイル項目は利用可能な資料識別子で指定してください。ファイルはPDFのみ対応します。' ); }
			if ( ! empty( $media[ $value ]['is_placeholder'] ) ) { throw new \InvalidArgumentException( 'ACF画像項目には実画像を対応付けてください。ダミーの仮配置は本文画像とアイキャッチに対応しています。' ); }
			$used[] = $value; return $media[ $value ]['attachment_id'];
		}
		if ( $field['required'] && ( '' === $result || array() === $result || false === $result ) ) { throw new \InvalidArgumentException( '必須ACF項目が空です。' ); }
		$plain .= is_array( $result ) ? implode( "\n", $result ) : ( is_bool( $result ) ? ( $result ? 'true' : 'false' ) : $result ); $plain .= "\n";
		return $result;
	}

	public static function validate( \stdClass $input, string $type, array $media ): array {
		$context = self::context( $type ); $schema = $context['fields']; $warnings = array(); $plain = ''; $values = array(); $used = array();
		if ( isset( $context['unavailable'] ) ) { if ( (array) $input ) { throw new \InvalidArgumentException( $context['unavailable'] ); } $warnings[] = $context['unavailable']; }
		if ( array_diff( array_keys( (array) $input ), array_keys( $schema ) ) ) { throw new \InvalidArgumentException( 'ACFのキーが未許可・未対応・別投稿先です。ACF連携設定を確認してください。' ); }
		foreach ( $schema as $key => $field ) {
			if ( ! property_exists( $input, $key ) ) { if ( $field['required'] ) { throw new \InvalidArgumentException( '必須ACF項目 ' . $field['label'] . ' がありません。' ); } continue; }
			try { $values[ $key ] = self::value( $field, $input->$key, $media, $warnings, $plain, $used ); } catch ( \InvalidArgumentException $error ) { throw new \InvalidArgumentException( $field['label'] . '：' . $error->getMessage() ); }
		}
		return array( 'values' => $values, 'schema' => $schema, 'warnings' => $warnings, 'plain' => $plain, 'used_media' => array_values( array_unique( $used ) ) );
	}

	/** Compare raw persisted values, allowing WordPress scalar metadata representations. */
	private static function same( array $field, $actual, $expected ): bool {
		if ( 'true_false' === $field['type'] ) { return in_array( $actual, array( true, false, 0, 1, '0', '1' ), true ) && (bool) $actual === $expected; }
		if ( 'image' === $field['type'] || 'file' === $field['type'] ) { return ( is_int( $actual ) || is_string( $actual ) ) && (string) $actual === (string) $expected; }
		if ( 'number' === $field['type'] ) { try { return self::decimal( (string) $actual ) === $expected; } catch ( \InvalidArgumentException $error ) { return '' === $expected && '' === $actual; } }
		if ( 'select' === $field['type'] && $field['multiple'] ) { return is_array( $actual ) && array_values( $actual ) === $expected; }
		return $actual === $expected;
	}

	private static function persisted( int $id, array $field, $expected, string $prefix = '' ): bool {
		$name = $prefix . $field['name'];
		if ( get_post_meta( $id, '_' . $name, true ) !== $field['key'] ) { return false; }
		if ( 'group' === $field['type'] ) { foreach ( $field['children'] as $key => $child ) { if ( ! self::persisted( $id, $child, $expected[ $key ], $name . '_' ) ) { return false; } } return true; }
		return metadata_exists( 'post', $id, $name ) && self::same( $field, get_post_meta( $id, $name, true ), $expected );
	}

	public static function write( int $id, array $acf, string $type ): array {
		if ( ! $acf['values'] ) { return array(); }
		if ( ! self::available() ) { return array( 'ACFが利用できなくなりました。作成済み下書きを確認してください。' ); }
		$errors = array();
		foreach ( $acf['values'] as $key => $value ) {
			if ( self::context( $type )['fields'] !== $acf['schema'] ) { $errors[] = 'ACF定義または許可設定が変更されたため保存を中止しました。作成済み下書きを確認してください。'; break; }
			update_field( $key, wp_slash( $value ), $id );
			if ( ! self::persisted( $id, $acf['schema'][ $key ], $value ) ) { $errors[] = 'ACF項目 ' . $acf['schema'][ $key ]['label'] . ' の保存結果が一致しません。下書きを確認してください。'; }
		}
		return $errors;
	}
}
