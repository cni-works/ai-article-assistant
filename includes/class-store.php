<?php
namespace AAA;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Store {
	public static function defaults( array $fields ): array {
		return array_map( static function ( $field ) { return $field[2]; }, $fields );
	}

	public static function text( $value ): string {
		return is_scalar( $value ) ? sanitize_textarea_field( (string) $value ) : '';
	}

	/** Unknown fields are discarded. Invalid typed values are rejected before writing. */
	public static function sanitize( array $fields, $raw, bool $partial = false ) {
		if ( ! is_array( $raw ) ) {
			return new \WP_Error( 'invalid', '設定の形式が正しくありません。' );
		}
		$result = $partial ? array() : self::defaults( $fields );
		foreach ( $fields as $key => $field ) {
			if ( ! array_key_exists( $key, $raw ) ) {
				continue;
			}
			$value = $raw[ $key ];
			if ( ! is_scalar( $value ) || strlen( (string) $value ) > 30000 ) {
				return new \WP_Error( 'invalid', $field[0] . 'の形式または長さが正しくありません。' );
			}
			$value = (string) $value;
			if ( 'questions' === $field[1] ) {
				$checked = ProfileRuntime::questions( $value );
				if ( is_wp_error( $checked ) ) { return $checked; }
				$result[ $key ] = (string) wp_json_encode( $checked, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			} elseif ( 'number' === $field[1] ) {
				if ( ! preg_match( '/^\d{1,6}$/D', $value ) || (int) $value > 100000 ) {
					return new \WP_Error( 'number', $field[0] . 'は0〜100000の整数で入力してください。' );
				}
				$result[ $key ] = (int) $value;
			} elseif ( 'select' === $field[1] ) {
				if ( ! isset( $field[4][ $value ] ) ) {
					return new \WP_Error( 'choice', $field[0] . 'を選び直してください。' );
				}
				$result[ $key ] = $value;
			} elseif ( 'url' === $field[1] ) {
				$url = esc_url_raw( $value, array( 'https', 'http' ) );
				if ( '' !== $value && ( ! $url || ! preg_match( '~^https?://[^/]+~i', $url ) ) ) {
					return new \WP_Error( 'url', $field[0] . 'はhttpまたはhttpsのURLで入力してください。' );
				}
				$result[ $key ] = $url;
			} elseif ( 'profile' === $field[1] ) {
				if ( '' !== $value && ! isset( self::profiles()[ $value ] ) ) {
					return new \WP_Error( 'profile', '記事の種類が削除されています。選び直してください。' );
				}
				$result[ $key ] = $value;
			} else {
				$result[ $key ] = 'textarea' === $field[1] ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
			}
		}
		$min = $result['length_min'] ?? 0;
		$max = $result['length_max'] ?? 0;
		if ( $max && $min > $max ) {
			return new \WP_Error( 'range', '文字数の下限は上限以下にしてください。' );
		}
		if ( isset( $fields['default_post_type'], $fields['default_terms'] ) && ! $partial ) {
			$binding = ProfileRuntime::binding( $result );
			if ( is_wp_error( $binding ) ) { return $binding; }
		}
		return $result;
	}

	public static function site(): array {
		$saved = get_option( Config::SITE, array() );
		return array_merge( self::defaults( Config::site_fields() ), is_array( $saved ) ? $saved : array() );
	}

	public static function profiles(): array {
		$value = get_option( Config::PROFILES, array() );
		return is_array( $value ) ? $value : array();
	}

	public static function write_option( string $key, array $value ): bool {
		update_option( $key, $value, false );
		// A false return may mean either unchanged data or a failed database write.
		return get_option( $key ) === $value;
	}

	public static function initialize(): void {
		if ( ! current_user_can( 'manage_options' ) || false !== get_option( Config::PROFILES, false ) ) {
			return;
		}
		$profiles = array();
		$purposes = array( 'notice', 'general', 'seo', 'report', 'event' );
		foreach ( Config::presets() as $index => $preset ) {
			$profiles[ wp_generate_uuid4() ] = array_merge( self::defaults( Config::profile_fields() ), $preset, array( 'schema_version' => 1, 'purpose_type' => $purposes[ $index ] ) );
		}
		add_option( Config::PROFILES, $profiles, '', false );
	}

	/** Keep pages and WordPress internal types outside this initial scope. */
	public static function types(): array {
		$types = get_post_types( array( 'public' => true ), 'objects' );
		return array_filter( $types, static function ( $type ) {
			return 'post' === $type->name || ( empty( $type->_builtin ) && ! empty( $type->show_ui ) );
		} );
	}

	public static function enabled(): array {
		$raw = get_option( Config::DESTINATIONS, array( 'post' ) );
		return array_values( array_intersect( is_array( $raw ) ? $raw : array(), array_keys( self::types() ) ) );
	}

	public static function can_create( string $type ): bool {
		$types = self::types();
		if ( ! isset( $types[ $type ] ) || ! in_array( $type, self::enabled(), true ) ) {
			return false;
		}
		$caps = $types[ $type ]->cap;
		return current_user_can( $caps->create_posts ?? $caps->edit_posts ) && current_user_can( $caps->edit_posts );
	}

	public static function writable_types(): array {
		return array_filter( self::types(), static function ( $type ) { return self::can_create( $type->name ); } );
	}

	public static function taxonomies( string $type ): array {
		return array_filter( get_object_taxonomies( $type, 'objects' ), static function ( $tax ) { return ! empty( $tax->show_ui ); } );
	}

	public static function can_edit_author(): bool {
		return current_user_can( 'read' ) && ( current_user_can( 'manage_options' ) || (bool) self::writable_types() );
	}

	/** No caller-supplied user ID: personal data always belongs to the signed-in user. */
	public static function author(): array {
		$all = get_user_meta( get_current_user_id(), Config::AUTHOR, true );
		$saved = is_array( $all ) ? ( $all[ get_current_blog_id() ] ?? array() ) : array();
		$saved = is_array( $saved ) ? $saved : array();
		$fields = self::sanitize( Config::author_fields(), $saved['profile'] ?? array() );
		$fields = is_wp_error( $fields ) ? self::defaults( Config::author_fields() ) : $fields;
		$usage = array();
		$saved_usage = isset( $saved['usage'] ) && is_array( $saved['usage'] ) ? $saved['usage'] : array();
		foreach ( Config::author_fields() as $key => $field ) {
			$value = $saved_usage[ $key ] ?? 'exclude';
			$usage[ $key ] = is_string( $value ) && isset( Config::author_usage()[ $value ] ) ? $value : 'exclude';
		}
		return array( 'schema_version' => 1, 'profile' => $fields, 'usage' => $usage, 'updated_at' => self::text( $saved['updated_at'] ?? '' ) );
	}

	public static function save_author( array $raw ) {
		if ( ! self::can_edit_author() ) {
			return new \WP_Error( 'permission', '投稿者プロフィールを保存する権限がありません。投稿先の設定と投稿権限を確認してください。' );
		}
		if ( ! isset( $raw['profile'], $raw['usage'] ) || ! is_array( $raw['profile'] ) || ! is_array( $raw['usage'] ) ) {
			return new \WP_Error( 'author', '投稿者プロフィールの形式が正しくありません。' );
		}
		$fields = self::sanitize( Config::author_fields(), $raw['profile'] );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}
		$usage = array();
		foreach ( Config::author_fields() as $key => $field ) {
			$value = $raw['usage'][ $key ] ?? 'exclude';
			if ( ! is_string( $value ) || ! isset( Config::author_usage()[ $value ] ) ) {
				return new \WP_Error( 'usage', $field[0] . 'の使い方を選び直してください。' );
			}
			$usage[ $key ] = $value;
		}
		$author = array( 'schema_version' => 1, 'profile' => $fields, 'usage' => $usage, 'updated_at' => current_time( 'mysql' ) );
		$all = get_user_meta( get_current_user_id(), Config::AUTHOR, true );
		$all = is_array( $all ) ? $all : array();
		$all[ get_current_blog_id() ] = $author;
		update_user_meta( get_current_user_id(), Config::AUTHOR, wp_slash( $all ) );
		if ( get_user_meta( get_current_user_id(), Config::AUTHOR, true ) !== $all ) {
			return new \WP_Error( 'save', '投稿者プロフィールを保存できませんでした。時間をおいて再試行してください。' );
		}
		return $author;
	}

	/** This is the only author data exposed to the article context. Excluded values stay out. */
	public static function author_context(): array {
		$author = self::author();
		$context = array( 'reference_only' => array(), 'publishable' => array() );
		foreach ( $author['profile'] as $key => $value ) {
			$usage = $author['usage'][ $key ];
			if ( '' === trim( $value ) || 'exclude' === $usage ) {
				continue;
			}
			$group = 'publish' === $usage ? 'publishable' : 'reference_only';
			$context[ $group ][ $key ] = $value;
		}
		if ( ! $context['reference_only'] && ! $context['publishable'] ) {
			return array();
		}
		$context['instructions'] = 'reference_onlyは理解や文体調整の参考に限り、その事実情報を記事に載せない。publishableも記事に必要な項目だけ使い、原稿承認を受ける。登録のない経歴・経験・意見は創作しない。サイト共通の禁止事項・公開前チェックを優先する。';
		return $context;
	}

	public static function article(): array {
		$all = get_user_meta( get_current_user_id(), Config::ARTICLE, true );
		$article = is_array( $all ) ? ( $all[ get_current_blog_id() ] ?? array() ) : array();
		return is_array( $article ) ? $article : array();
	}

	public static function save_article( array $raw ) {
		$previous = self::article();
		if ( 'titles' === ( $raw['planning_mode'] ?? '' ) ) { return Consultation::save_titles( $raw, $previous ); }
		$profile = self::text( $raw['profile_id'] ?? '' );
		$snapshot = ProfileRuntime::snapshot( $previous );
		$reuse = $snapshot && $profile === ( $previous['profile_id'] ?? '' ) && '1' !== ( $raw['adopt_settings'] ?? '' );
		$profile_data = $reuse ? $snapshot['profile'] : ( ProfileRuntime::available()[ $profile ] ?? null );
		if ( ! is_array( $profile_data ) ) { return new \WP_Error( 'profile', '有効な記事の種類を選び直してください。' ); }
		$profile_data = self::sanitize( Config::profile_fields(), $profile_data );
		if ( is_wp_error( $profile_data ) ) { return $profile_data; }
		$binding = ProfileRuntime::binding( $profile_data );
		if ( is_wp_error( $binding ) ) { return $binding; }
		$type = self::text( $raw['post_type'] ?? $binding['post_type'] );
		if ( ! self::can_create( $type ) ) {
			return new \WP_Error( 'destination', 'この投稿先は利用できません。管理者の設定と投稿権限を確認してください。' );
		}
		if ( '' !== $binding['post_type'] && $type !== $binding['post_type'] ) { return new \WP_Error( 'binding', '選んだ記事の種類に設定された投稿先を選択してください。' ); }
		$answers = ProfileRuntime::answers( $profile_data, $raw['answers'] ?? ( $reuse ? ( $previous['answers'] ?? array() ) : array() ) );
		if ( is_wp_error( $answers ) ) { return $answers; }
		$article = self::sanitize( Config::article_fields(), array_replace( array_intersect_key( $previous, array_flip( array( 'source_draft', 'interaction_mode', 'reference_summary' ) ) ), $raw ) );
		if ( is_wp_error( $article ) ) {
			return $article;
		}
		if ( 'proofread' === $article['interaction_mode'] && ( 'ideas' === $article['planning_mode'] || '' === trim( $article['source_draft'] ) ) ) { return new \WP_Error( 'proofread', '添削は「書きたいことが決まっている」を選び、原稿を入力してください。' ); }
		if ( '' === $article['theme'] ) {
			if ( 'proofread' === $article['interaction_mode'] && '' !== trim( $article['source_draft'] ) ) { $article['theme'] = '提供原稿の添削';
			} elseif ( 'ideas' === $article['planning_mode'] ) {
				$article['theme'] = '目的と読者に合う記事テーマから相談';
			} elseif ( '' !== trim( $article['facts'] ) ) {
				preg_match( '/^.{1,80}/us', $article['facts'], $match );
				$article['theme'] = sanitize_text_field( $match[0] ?? '' );
			}
		}
		$overrides = $raw['overrides'] ?? array();
		if ( ! is_array( $overrides ) ) {
			return new \WP_Error( 'overrides', '個別設定の形式が正しくありません。' );
		}
		// Blank means inherit. Explicit zero remains an override.
		$overrides = array_filter( $overrides, static function ( $value ) { return '' !== $value; } );
		$overrides = self::sanitize( Config::rules(), $overrides, true );
		if ( is_wp_error( $overrides ) ) {
			return $overrides;
		}
		$effective = array_merge( $profile_data, $overrides );
		if ( ! empty( $effective['length_max'] ) && ( $effective['length_min'] ?? 0 ) > $effective['length_max'] ) {
			return new \WP_Error( 'range', '記事の種類と個別設定を合わせると、文字数の下限が上限を超えます。' );
		}
		$terms = $raw['terms'] ?? ( isset( $raw['terms_present'] ) ? array() : $binding['terms'] );
		if ( ! is_array( $terms ) || count( $terms ) > 200 ) {
			return new \WP_Error( 'terms', 'カテゴリー・タグの形式または選択数が正しくありません。' );
		}
		$valid = array();
		$taxonomies = self::taxonomies( $type );
		foreach ( $terms as $reference ) {
			if ( ! is_string( $reference ) || ! preg_match( '/^([a-zA-Z0-9_-]+):(\d+)$/D', $reference, $match ) ) {
				return new \WP_Error( 'term', 'カテゴリー・タグを選び直してください。' );
			}
			$taxonomy = $match[1];
			$id = (int) $match[2];
			if ( ! isset( $taxonomies[ $taxonomy ] ) || ! current_user_can( $taxonomies[ $taxonomy ]->cap->assign_terms ) || ! term_exists( $id, $taxonomy ) ) {
				return new \WP_Error( 'term', '投稿先に対応しない、または利用できないカテゴリー・タグが含まれています。' );
			}
			$valid[] = $taxonomy . ':' . $id;
		}
		$article = array_merge( $article, array(
			'schema_version' => 1,
			'article_key' => $previous['article_key'] ?? wp_generate_uuid4(),
			'post_type' => $type,
			'profile_id' => $profile,
			'terms' => array_values( array_unique( $valid ) ),
			'overrides' => $overrides,
			'answers' => $answers,
			'updated_at' => current_time( 'mysql' ),
		) );
		if ( '1' === ( $raw['use_snapshot'] ?? '' ) || $snapshot ) {
			$article['settings_snapshot'] = $reuse ? $snapshot : ProfileRuntime::capture( $article, $profile_data );
			$article['settings_snapshot']['categories'] = self::context( $article )['categories'];
		}
		$all = get_user_meta( get_current_user_id(), Config::ARTICLE, true );
		$all = is_array( $all ) ? $all : array();
		$all[ get_current_blog_id() ] = $article;
		update_user_meta( get_current_user_id(), Config::ARTICLE, wp_slash( $all ) );
		if ( get_user_meta( get_current_user_id(), Config::ARTICLE, true ) !== $all ) {
			return new \WP_Error( 'save', '記事条件を保存できませんでした。時間をおいて再試行してください。' );
		}
		return $article;
	}

	/** Shared by admin previews and article prompts. Text settings remain independent, not overwritten by presets. */
	public static function writing_context( array $site, array $profile = array(), array $overrides = array() ): array {
		$recommendations = array();
		foreach ( Config::quality_recommendations() as $key => $rule ) {
			if ( 'yes' === ( $site[ $key ] ?? 'yes' ) ) { $recommendations[ $key ] = array( 'label' => $rule[0], 'instruction' => $rule[1] ); }
		}
		$rules = self::sanitize( Config::rules(), array_merge( array_intersect_key( $profile, Config::rules() ), $overrides ) );
		if ( is_wp_error( $rules ) ) { $rules = array(); }
		$active = $rules;
		if ( 'range' !== ( $rules['length_mode'] ?? '' ) ) { unset( $active['length_min'], $active['length_max'] ); }
		if ( 'target' !== ( $rules['length_mode'] ?? '' ) ) { unset( $active['length_target'] ); }
		$active = array_filter( $active, static function ( $value ) { return '' !== $value; } );
		return array(
			'version' => 'quality-rules.1',
			'precedence' => '安全・事実確認・利用許可は常に優先。文章条件は記事個別指定＞記事の種類指定。サイトの独自指示は共通条件として併用し、推奨ルールより優先する。明示指示同士の矛盾は勝手に解消せず利用者へ確認する。推奨を追加しない設定でも、既存の自由入力や目的別条件は取り消さない。',
			'mandatory' => array( '日時・料金・実績・資格・経験・意見・URL・出典を創作しない。', '未読の資料や未実施の調査を確認済みと報告しない。', '管理者の禁止事項・公開前チェック・本人情報の利用許可を守り、構成と原稿の承認を受ける。' ),
			'administrator_rules' => array_filter( array_intersect_key( $site, array_flip( array( 'forbidden_words', 'locked_rules', 'publication_checks' ) ) ), static function ( $value ) { return '' !== $value; } ),
			'recommendations' => $recommendations,
			'site_instructions' => array_filter( array_intersect_key( $site, Config::quality_legacy_fields() ), static function ( $value ) { return '' !== $value; } ),
			'effective_article_rules' => $profile ? $active : array(),
			'article_override_keys' => array_keys( array_intersect_key( $overrides, Config::rules() ) ),
		);
	}

	/** Context stays separate from locked rules so no individual field can replace them. */
	public static function context( array $article ): array {
		$snapshot = ProfileRuntime::snapshot( $article );
		$live_site = self::site();
		$site = $snapshot ? $snapshot['site'] : $live_site;
		$locked = array();
		foreach ( array( 'forbidden_words', 'locked_rules', 'publication_checks' ) as $key ) {
			$locked[ $key ] = $live_site[ $key ];
			unset( $site[ $key ] );
		}
		$contexts = array();
		foreach ( $article['terms'] ?? array() as $reference ) {
			$parts = explode( ':', $reference, 2 );
			if ( count( $parts ) !== 2 ) {
				continue;
			}
			$term = get_term( (int) $parts[1], $parts[0] );
			if ( ! $term || is_wp_error( $term ) ) {
				continue;
			}
			$item = array( 'taxonomy' => $parts[0], 'id' => $term->term_id, 'name' => $term->name, 'context' => get_term_meta( $term->term_id, Config::TERM, true ) );
			foreach ( $snapshot['categories'] ?? array() as $saved ) { if ( $saved['taxonomy'] === $parts[0] && (int) $saved['id'] === (int) $term->term_id ) { $item['context'] = $saved['context']; break; } }
			$contexts[] = $item;
		}
		$profile = ProfileRuntime::profile( $article );
		return array( 'site' => $site, 'categories' => $contexts, 'profile' => $profile, 'author' => self::author_context(), 'article' => $article, 'effective_rules' => array_merge( array_intersect_key( $site, Config::rules() ), array_intersect_key( $profile, Config::rules() ), $article['overrides'] ?? array() ), 'quality' => self::writing_context( array_merge( $site, $locked ), $profile, $article['overrides'] ?? array() ), 'locked' => $locked );
	}
}
