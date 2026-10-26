<?php
namespace AAA;
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Consultation-only data never inherits hidden article/media fields. */
final class Consultation {
	public static function titles( ?array $article = null ): bool { return 'titles' === ( ( $article ?? Store::article() )['planning_mode'] ?? '' ); }
	public static function mode( ?array $article = null ): string { return ( $article ?? Store::article() )['interaction_mode'] ?? 'guided'; }
	public static function save_titles( array $raw, array $previous ) {
		if ( ! Importer::allowed() ) { return new \WP_Error( 'permission', '相談を利用する権限がありません。' ); }
		$details = Store::sanitize( Config::article_fields(), array_replace( array_intersect_key( $previous, Config::article_fields() ), $raw ) );
		if ( is_wp_error( $details ) ) { return $details; }
		$details['reference_summary'] = 'yes'; // Legacy field retained; reference is now standard, subject to administrator policy.
		$article = array_replace( $previous, $details, array( 'schema_version' => 1, 'article_key' => $previous['article_key'] ?? wp_generate_uuid4(), 'profile_id' => $previous['profile_id'] ?? '', 'updated_at' => current_time( 'mysql' ) ) );
		return self::persist( $article );
	}
	private static function persist( array $article ) {
		$all = get_user_meta( get_current_user_id(), Config::ARTICLE, true ); $all = is_array( $all ) ? $all : array();
		$all[ get_current_blog_id() ] = $article;
		update_user_meta( get_current_user_id(), Config::ARTICLE, wp_slash( $all ) );
		return get_user_meta( get_current_user_id(), Config::ARTICLE, true ) === $all ? $article : new \WP_Error( 'save', '相談内容を保存できませんでした。' );
	}
	public static function prepare_titles( array $article ) {
		if ( ! Importer::allowed() ) { return new \WP_Error( 'permission', '相談を利用する権限がありません。' ); }
		$details = Store::sanitize( array_intersect_key( Config::article_fields(), array_flip( array( 'planning_mode', 'facts', 'audience' ) ) ), $article );
		if ( is_wp_error( $details ) ) { return $details; }
		$site = Store::site();
		$quality = Store::writing_context( $site );
		$quality['mandatory'] = array( '未確認の事実・体験・意見・出典を創作しない。', '管理者の禁止事項と本人情報の利用許可を守り、採用を利用者に委ねる。' );
		$context = array( 'article' => $details + array( 'article_key' => $article['article_key'] ), 'site' => array_intersect_key( $site, array_flip( array( 'site_name', 'activities', 'services', 'audience', 'purpose', 'strengths', 'additional' ) ) ), 'quality' => $quality, 'author' => Store::author_context() );
		$context['existing_articles'] = self::catalogue();
		$context['category_catalogue'] = self::categories();
		$modules = array();
		foreach ( array( 'japanese', 'consult-titles' ) as $id ) {
			$file = dirname( __DIR__ ) . '/prompts/' . $id . '.md';
			$text = is_readable( $file ) ? file_get_contents( $file ) : false;
			if ( false === $text ) { return new \WP_Error( 'module', '相談ルールを読み込めません。' ); }
			$modules[ $id ] = array( 'version' => 'consult-titles' === $id ? '2' : '1', 'text' => $text, 'sha256' => hash( 'sha256', $text ) );
		}
		$encoded = wp_json_encode( array( Prompt::VERSION, $context, $modules ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $encoded || strlen( $encoded ) > 250000 ) { return new \WP_Error( 'size', '相談する内容を絞ってください。' ); }
		return array( 'context' => $context, 'modules' => $modules, 'fingerprint' => hash( 'sha256', $encoded ) );
	}
	/** Administrator-approved, bounded, public-only summaries; no arbitrary full-site export. */
	public static function catalogue(): array {
		$settings = Reference::settings();
		if ( ! Reference::allowed() || 'off' === $settings['policy'] || ! $settings['types'] ) { return array( 'status' => 'disabled', 'items' => array(), 'limitations' => '既存記事の参照は管理者設定で無効です。未確認の重複や需要を断定しない。' ); }
		$query = new \WP_Query( array( 'post_type' => $settings['types'], 'post_status' => 'publish', 'has_password' => false, 'posts_per_page' => 50, 'paged' => 1, 'fields' => 'ids', 'orderby' => 'date', 'order' => 'DESC', 'ignore_sticky_posts' => true, 'suppress_filters' => false ) );
		$items = array();
		foreach ( $query->posts as $id ) {
			$post = Reference::eligible( (int) $id ); if ( ! $post ) { continue; }
			$url = esc_url_raw( get_permalink( $id ), array( 'http', 'https' ) ); if ( ! $url ) { continue; }
			$index = get_post_meta( $id, Reference::INDEX, true );
			$fresh = is_array( $index ) && ( $index['modified_at'] ?? null ) === $post->post_modified;
			$entry = array( 'id' => (int) $id, 'title' => Reference::clip( wp_strip_all_tags( $post->post_title ), 200 ), 'url' => $url, 'excerpt' => Reference::clip( wp_strip_all_tags( $post->post_excerpt ), 250 ), 'headings' => $fresh ? array_slice( $index['headings'] ?? array(), 0, 5 ) : array(), 'terms' => self::article_terms( (int) $id, $post->post_type ) );
			if ( strlen( (string) wp_json_encode( array_merge( $items, array( $entry ) ), JSON_UNESCAPED_UNICODE ) ) > 100000 ) { break; }
			$items[] = $entry;
		}
		return array( 'status' => 'public_summary', 'count' => count( $items ), 'total' => (int) $query->found_posts, 'limited' => $query->found_posts > count( $items ), 'items' => $items, 'limitations' => '管理者が許可した公開・パスワードなしの記事の最新50件まで。本文全体・非公開記事・検索需要データは含まない。所属分類は現在の登録状態、見出しは更新日時が一致する既存索引のみ。情報量の上限で50件未満になる場合もある。需要は仮説と根拠を区別し、重複なしを保証しない。' );
	}
	/** Live public taxonomy names, independently of the optional content index. */
	private static function article_terms( int $id, string $type ): array {
		$result = array();
		foreach ( get_object_taxonomies( $type, 'objects' ) as $name => $tax ) {
			if ( property_exists( $tax, 'public' ) && ! $tax->public ) { continue; }
			if ( 'category' !== $name && empty( $tax->public ) ) { continue; }
			$names = wp_get_object_terms( $id, $name, array( 'fields' => 'names' ) );
			if ( is_wp_error( $names ) ) { continue; }
			$result[ $name ] = array();
			foreach ( array_slice( $names, 0, 10 ) as $value ) {
				if ( is_int( $value ) ) { $term = get_term( $value, $name ); $value = $term && ! is_wp_error( $term ) ? $term->name : ''; }
				if ( is_string( $value ) && '' !== $value ) { $result[ $name ][] = Reference::clip( sanitize_text_field( $value ), 80 ); }
			}
			if ( count( $result ) >= 5 ) { break; }
		}
		return $result;
	}
	/** Current category settings; never create terms or reuse unverified term IDs. */
	public static function categories(): array {
		$result = array(); $taxonomies = array(); $limited = false; $unavailable = false;
		foreach ( Store::writable_types() as $type => $object ) {
			foreach ( Store::taxonomies( $type ) as $name => $tax ) {
				if ( property_exists( $tax, 'public' ) && ! $tax->public ) { continue; }
				if ( ( 'category' !== $name && ( empty( $tax->public ) || empty( $tax->hierarchical ) ) ) || ! current_user_can( $tax->cap->assign_terms ) ) { continue; }
				$taxonomies[ $name ][] = $type;
			}
		}
		foreach ( $taxonomies as $taxonomy => $types ) {
			$terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'number' => 101, 'orderby' => 'name', 'order' => 'ASC' ) );
			if ( is_wp_error( $terms ) ) { $unavailable = true; continue; }
			foreach ( $terms as $term ) {
				if ( count( $result ) >= 100 ) { $limited = true; break 2; }
				$raw = get_term_meta( $term->term_id, Config::TERM, true ); $context = array();
				foreach ( array( 'purpose', 'audience', 'include', 'exclude', 'direction', 'interview', 'recommended_profile' ) as $field ) {
					if ( is_array( $raw ) && is_string( $raw[ $field ] ?? null ) && '' !== $raw[ $field ] ) { $context[ $field ] = Reference::clip( sanitize_textarea_field( $raw[ $field ] ), 400 ); }
				}
				$entry = array( 'taxonomy' => $taxonomy, 'id' => (int) $term->term_id, 'name' => Reference::clip( sanitize_text_field( $term->name ), 200 ), 'slug' => Reference::clip( sanitize_text_field( $term->slug ), 200 ), 'description' => Reference::clip( sanitize_textarea_field( $term->description ?? '' ), 400 ), 'post_types' => $types, 'context' => $context );
				if ( strlen( (string) wp_json_encode( array_merge( $result, array( $entry ) ), JSON_UNESCAPED_UNICODE ) ) > 60000 ) { $limited = true; break 2; }
				$result[] = $entry;
			}
		}
		return array( 'status' => $unavailable ? 'partially_unavailable' : 'current_settings', 'count' => count( $result ), 'limited' => $limited, 'items' => $result, 'limitations' => '利用できる投稿先の公開カテゴリーと割当権限のある分類のみ。空カテゴリーも含む。最大100分類・情報量の上限あり。方針の長文は400文字まで。省略・取得不能の分類もあり得る。既存IDだけを使い、未登録の分類は自動作成しない。' );
	}
	public static function cards( string $field, string $legend, array $choices, string $value ): void {
		echo '<fieldset class="aaa-choice-group" data-aaa-choice="' . esc_attr( $field ) . '"><legend>' . esc_html( $legend ) . '</legend><div class="aaa-choice-cards">';
		$icons = array( 'topic' => 'edit-page', 'ideas' => 'lightbulb', 'titles' => 'editor-textcolor', 'guided' => 'format-chat', 'delegated' => 'star-filled', 'proofread' => 'editor-spellcheck' );
		$help = array( 'topic' => '決まっているテーマをもとに記事を作る', 'ideas' => 'テーマや切り口からAIと相談する', 'titles' => '複数のネタ・タイトル案を考える', 'guided' => '構成や本文を段階的に確認する', 'delegated' => '必要な確認後に構成と本文をまとめて提案', 'proofread' => '自分で書いた原稿の趣旨を保って整える' );
		foreach ( $choices as $key => $label ) {
			echo '<label class="aaa-choice-card"><input type="radio" name="data[' . esc_attr( $field ) . ']" value="' . esc_attr( $key ) . '" ' . checked( $value, $key, false ) . '><span class="aaa-choice-content"><span class="dashicons dashicons-' . esc_attr( $icons[ $key ] ?? 'edit' ) . '" aria-hidden="true"></span><span class="aaa-choice-text"><span class="aaa-choice-label">' . esc_html( $label ) . '</span><span class="aaa-choice-help">' . esc_html( $help[ $key ] ?? '' ) . '</span></span><span class="aaa-choice-tick" aria-hidden="true">✓</span></span></label>';
		}
		echo '</div></fieldset>';
	}
	public static function results(): void {
		$article = Store::article();
		if ( ! empty( $article['title_draft_id'] ) ) {
			$id = (int) $article['title_draft_id'];
			if ( current_user_can( 'edit_post', $id ) && get_post_meta( $id, '_aaa_article_key', true ) === $article['article_key'] ) { echo '<p class="aaa-current-task">タイトルだけの下書きを作成済みです。<a href="' . esc_url( get_edit_post_link( $id ) ) . '">下書きを開いて続きを書く</a>。AIで本文を作る場合も、この下書きへ標準エディターで貼り付けてください。</p>'; }
			return;
		}
		echo '<details class="aaa-card"><summary>相談が終わったら：下書きを作る／記事制作を続ける</summary><p>候補はChatGPTでコピーして終了できます。続ける場合は、採用タイトルや記事案を下へ貼り付けます。自動では取り込まれません。</p><form method="post" class="aaa-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="aaa_consult_result">';
		wp_nonce_field( 'aaa_consult_result', 'aaa_nonce' );
		echo '<input type="hidden" name="revision" value="' . esc_attr( Reference::hash( Store::article() ) ) . '"><label>採用タイトル・記事案<textarea name="result" class="large-text" rows="3" maxlength="2000" required></textarea></label><p><label>操作 <select name="intent"><option value="continue">この案で記事制作を続ける</option><option value="draft">タイトルだけの空の下書きを作る</option></select></label></p><p><label>下書きの投稿先 <select name="post_type">';
		foreach ( Store::writable_types() as $type => $object ) { echo '<option value="' . esc_attr( $type ) . '">' . esc_html( $object->labels->name ) . '</option>'; }
		echo '</select></label>（下書きを作る場合のみ使用）</p><button type="submit" class="button">選んだ操作を実行する</button></form></details>';
	}
	public static function result( array $raw ) {
		$article = Store::article();
		if ( ! self::titles( $article ) || ! Importer::allowed() ) { return new \WP_Error( 'permission', '先にタイトル相談を開始してください。' ); }
		if ( ! is_string( $raw['revision'] ?? null ) || ! hash_equals( Reference::hash( $article ), $raw['revision'] ) ) { return new \WP_Error( 'changed', '相談内容が変わっています。画面を更新してください。' ); }
		$title = is_string( $raw['result'] ?? null ) ? trim( sanitize_textarea_field( $raw['result'] ) ) : '';
		if ( '' === $title || strlen( $title ) > 2000 ) { return new \WP_Error( 'title', '採用したタイトル・記事案を2000バイト以内で入力してください。' ); }
		if ( ! empty( $article['title_draft_id'] ) ) { return new \WP_Error( 'duplicate', '空の下書きを作成済みです。標準エディターで続きを編集してください。別の記事は新しい記事として開始します。' ); }
		if ( 'continue' === ( $raw['intent'] ?? '' ) ) {
			$article['planning_mode'] = 'topic'; $article['facts'] = $title; $article['theme'] = Reference::clip( $title, 80 ); $article['interaction_mode'] = 'guided';
			return self::persist( $article );
		}
		$type = Store::text( $raw['post_type'] ?? '' );
		if ( 'draft' !== ( $raw['intent'] ?? '' ) || ! Store::can_create( $type ) || ! post_type_supports( $type, 'editor' ) || Blocks::length( $title ) > 200 || preg_match( '/[\r\n]/', $title ) ) { return new \WP_Error( 'title', '投稿先と200文字以内の1行のタイトルを確認してください。' ); }
		foreach ( preg_split( '/\R/u', Store::site()['forbidden_words'] ?? '' ) as $word ) { if ( '' !== trim( $word ) && str_contains( $title, trim( $word ) ) ) { return new \WP_Error( 'title', 'タイトルに管理者設定の禁止ワードが含まれています。' ); } }
		$lock = OperationLock::acquire( Importer::LOCK, 'title-draft' ); if ( is_wp_error( $lock ) ) { return $lock; }
		try {
			if ( ! hash_equals( Reference::hash( Store::article() ), $raw['revision'] ) || ! Store::can_create( $type ) ) { return new \WP_Error( 'changed', '相談内容または投稿権限が変わりました。画面を更新してください。' ); }
			$slug = 'article-' . str_replace( '-', '', $article['article_key'] );
			if ( CsvValidator::duplicate( $article['article_key'], $type, $slug ) ) { return new \WP_Error( 'duplicate', 'この相談の下書きは作成済みです。投稿一覧で確認してください。' ); }
			$id = wp_insert_post( wp_slash( array( 'post_title' => $title, 'post_content' => '', 'post_excerpt' => '', 'post_type' => $type, 'post_status' => 'draft', 'post_author' => get_current_user_id(), 'post_name' => $slug, 'meta_input' => array( '_aaa_article_key' => $article['article_key'] ) ) ), true );
			if ( is_wp_error( $id ) || ! $id ) { return new \WP_Error( 'save', '下書きを作成できません。投稿一覧も確認してください。' ); }
			$post = get_post( $id );
			if ( ! $post || 'draft' !== $post->post_status || '' !== $post->post_content || (int) $post->post_author !== get_current_user_id() || $post->post_title !== $title || get_post_meta( $id, '_aaa_article_key', true ) !== $article['article_key'] ) { return new \WP_Error( 'save', '保存結果を確認できません。作成された投稿ID ' . (int) $id . ' を確認してください。' ); }
			if ( hash_equals( Reference::hash( Store::article() ), $raw['revision'] ) ) {
				$article['title_draft_id'] = (int) $id;
				if ( is_wp_error( self::persist( $article ) ) ) { return new \WP_Error( 'save', '下書きID ' . (int) $id . ' は作成済みですが、作業記録を保存できません。投稿一覧を確認してください。' ); }
			}
			return (int) $id;
		} finally { OperationLock::release( $lock ); }
	}
}
