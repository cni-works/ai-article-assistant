<?php
namespace AAA;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Create-only plans; exact existing matches may be reused without any metadata writes. */
final class TermCreation {
	public static function fields(): array {
		return array_merge( array(
			'taxonomy' => array( '分類の識別子', 'text', 'category', '現在はcategoryのみ。' ),
			'name' => array( '新規カテゴリー名', 'text', '', '管理者が承認した名前。' ),
			'slug' => array( '新規スラッグ', 'text', '', '半角英小文字・数字・ハイフン、200文字以下。' ),
		), Config::term_fields() );
	}
	/** Allowlist kept separate so custom taxonomies can be enabled deliberately in a later version. */
	private static function allowed_taxonomy( string $taxonomy ): bool {
		if ( ! in_array( $taxonomy, array( 'category' ), true ) ) { return false; }
		foreach ( Store::types() as $type => $object ) {
			$tax = Store::taxonomies( $type )[ $taxonomy ] ?? null;
			if ( $tax && current_user_can( $tax->cap->manage_terms ) ) { return true; }
		}
		return false;
	}
	public static function duplicate( array $plan ) {
		foreach ( array( 'slug' => $plan['slug'], 'name' => $plan['name'] ) as $field => $value ) {
			$term = get_term_by( $field, $value, $plan['taxonomy'] );
			if ( is_wp_error( $term ) ) { return new \WP_Error( 'term_lookup', '既存カテゴリーを確認できませんでした。' ); }
			if ( $term ) { return new \WP_Error( 'term_duplicate', '名前またはスラッグが既存カテゴリーと重複します：' . $plan['name'] . '。既存IDを使うか、案を見直してください。既存カテゴリーは変更しません。' ); }
		}
		return true;
	}
	private static function same_context( $saved, array $expected ): bool {
		if ( ! is_array( $saved ) ) { return false; }
		ksort( $saved ); ksort( $expected );
		return $saved === $expected;
	}
	public static function plans( array $patches, array $profiles, array $new_profiles ) {
		if ( count( $patches ) > 20 ) { return new \WP_Error( 'term_limit', '新規カテゴリーは1回20件までです。' ); }
		$plans = $names = $slugs = array();
		foreach ( $patches as $alias => $raw ) {
			if ( ! preg_match( '/^new:[a-z][a-z0-9-]{0,39}$/D', $alias ) || array_diff_key( $raw, self::fields() ) ) { return new \WP_Error( 'term_plan', '新規カテゴリーの識別子または項目が不正です。' ); }
			$fields = self::fields(); unset( $fields['recommended_profile'] );
			$checked = Store::sanitize( $fields, $raw ); if ( is_wp_error( $checked ) ) { return $checked; }
			if ( ! self::allowed_taxonomy( $checked['taxonomy'] ) ) { return new \WP_Error( 'permission', 'この分類の新規作成に対応していないか、カテゴリーを管理する権限がありません。' ); }
			if ( ! preg_match( '/^.{1,200}$/usD', $checked['name'] ) || $checked['name'] !== trim( $raw['name'] ?? '' ) || ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $checked['slug'] ) || strlen( $checked['slug'] ) > 200 || $checked['slug'] !== ( $raw['slug'] ?? '' ) ) { return new \WP_Error( 'term_plan', '新規カテゴリー名と、半角英小文字・数字・ハイフンの明示的なスラッグを確認してください。' ); }
			$name_key = function_exists( 'mb_strtolower' ) ? mb_strtolower( $checked['name'], 'UTF-8' ) : strtolower( $checked['name'] );
			if ( isset( $names[ $name_key ] ) || isset( $slugs[ $checked['slug'] ] ) ) { return new \WP_Error( 'term_duplicate', 'CSV内の新規カテゴリー名またはスラッグが重複しています。' ); }
			$names[ $name_key ] = true; $slugs[ $checked['slug'] ] = true;
			$recommended = $raw['recommended_profile'] ?? '';
			$recommended = $new_profiles[ $recommended ] ?? $recommended;
			if ( '' !== $recommended && ( ! isset( $profiles[ $recommended ] ) || 'no' === ( $profiles[ $recommended ]['enabled'] ?? 'yes' ) ) ) { return new \WP_Error( 'profile', '新規カテゴリーの推奨記事の種類が存在しないか無効です。' ); }
			$context = array_intersect_key( $checked, Config::term_fields() ); $context['recommended_profile'] = $recommended; $context['schema_version'] = 1;
			$plan = array( 'alias' => $alias, 'taxonomy' => $checked['taxonomy'], 'name' => $checked['name'], 'slug' => $checked['slug'], 'context' => $context );
			$duplicate = self::duplicate( $plan );
			if ( is_wp_error( $duplicate ) ) {
				$by_slug = get_term_by( 'slug', $plan['slug'], $plan['taxonomy'] );
				$by_name = get_term_by( 'name', $plan['name'], $plan['taxonomy'] );
				if ( ! $by_slug || ! $by_name || is_wp_error( $by_slug ) || is_wp_error( $by_name ) || $by_slug->term_id !== $by_name->term_id || $by_slug->name !== $plan['name'] || $by_slug->slug !== $plan['slug'] || 0 !== (int) ( $by_slug->parent ?? 0 ) || ! self::same_context( get_term_meta( $by_slug->term_id, Config::TERM, true ), $context ) ) { return $duplicate; }
				// This is an identity check, never permission to update an existing category.
				$plan['existing_id'] = (int) $by_slug->term_id;
			}
			$plans[ $alias ] = $plan;
		}
		return $plans;
	}
	/** created_term is not fired when core resolves an insertion race to someone else's existing ID. */
	public static function create( array $plan ): array {
		$observed = array();
		$observer = static function ( $id, $tt_id, $taxonomy ) use ( &$observed, $plan ) {
			if ( $taxonomy !== $plan['taxonomy'] ) { return; }
			$term = get_term( $id, $taxonomy );
			if ( $term && ! is_wp_error( $term ) && $term->name === $plan['name'] ) { $observed[] = (int) $id; }
		};
		add_action( 'created_term', $observer, 10, 3 );
		try { $result = wp_insert_term( wp_slash( $plan['name'] ), $plan['taxonomy'], array( 'slug' => $plan['slug'], 'parent' => 0 ) ); }
		catch ( \Throwable $exception ) { $result = new \WP_Error( 'term_exception', 'カテゴリー作成処理で例外が発生しました。作成結果を管理画面で確認してください。' ); }
		finally { remove_action( 'created_term', $observer, 10 ); }
		$id = is_wp_error( $result ) ? 0 : (int) ( $result['term_id'] ?? 0 );
		$term = $id ? get_term( $id, $plan['taxonomy'] ) : null;
		$valid = $term && ! is_wp_error( $term ) && in_array( $id, $observed, true ) && $term->name === $plan['name'] && $term->slug === $plan['slug'] && 0 === (int) ( $term->parent ?? 0 );
		return array( 'term_id' => $id, 'created_ids' => $observed, 'error' => $valid ? '' : ( is_wp_error( $result ) ? $result->get_error_message() : '同時作成または予定と異なる作成結果を検出しました。既存カテゴリーへ設定を書き込みません。' ) );
	}
}
