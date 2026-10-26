<?php
namespace AAA;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Views {
	public static function journey( int $current ): void {
		echo '<ol class="aaa-journey" aria-label="記事完成までの流れ">';
		$steps = Consultation::titles() && $current <= 2 ? array( 1 => '相談の準備', 2 => 'ChatGPTでネタ・タイトルを相談' ) : array( 1 => '記事の準備', 2 => 'ChatGPTで制作', 3 => '完成ファイルを取り込む', 4 => '内容を確認して下書き保存' );
		foreach ( $steps as $number => $label ) {
			echo '<li' . ( $number === $current ? ' aria-current="step"' : '' ) . ' class="' . ( $number < $current ? 'is-complete' : '' ) . '">' . esc_html( ( $number < $current ? '✓ ' : $number . '. ' ) . $label ) . '</li>';
		}
		echo '</ol>';
	}
	public static function get( string $key ): string {
		return isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) ) : '';
	}

	private static function hidden( string $key, string $value ): void {
		echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '">';
	}

	private static function form( string $operation ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="aaa-form" data-aaa-operation="' . esc_attr( $operation ) . '">';
		self::hidden( 'action', 'aaa_save' );
		self::hidden( 'operation', $operation );
		wp_nonce_field( 'aaa_save', 'aaa_nonce' );
	}

	private static function end( string $label = '保存する' ): void {
		submit_button( $label );
		echo '</form>';
	}

	/** Output is escaped here; the schema supplies all accepted names and field types. */
	private static function fields( array $fields, array $values, string $prefix = 'data', bool $inherit = false, ?array $usage = null ): void {
		echo '<table class="form-table" role="presentation"><tbody>';
		foreach ( $fields as $key => $field ) {
			if ( 'default_post_type' === $key ) {
				$choices = array( '' => '利用者が選択' );
				foreach ( Store::types() as $type => $object ) { $choices[ $type ] = $object->labels->name; }
				$field[1] = 'select'; $field[4] = $choices;
			}
			$value = $values[ $key ] ?? ( $inherit ? '' : $field[2] );
			$name = $prefix . '[' . $key . ']';
			$id = 'aaa-' . sanitize_key( $prefix . '-' . $key );
			echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $field[0] ) . '</label></th><td>';
			if ( in_array( $field[1], array( 'textarea', 'questions' ), true ) ) {
				echo '<textarea class="large-text" rows="3" maxlength="10000" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">' . esc_textarea( $value ) . '</textarea>';
			} elseif ( 'select' === $field[1] || 'profile' === $field[1] ) {
				$choices = $field[4] ?? array();
				if ( 'profile' === $field[1] ) {
					$choices = array( '' => '指定なし' );
					foreach ( Store::profiles() as $profile_id => $profile ) {
						$choices[ $profile_id ] = $profile['name'];
					}
				}
				echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';
				if ( $inherit ) {
					echo '<option value="">記事の種類に従う</option>';
				}
				if ( '' !== (string) $value && ! isset( $choices[ $value ] ) ) {
					echo '<option value="" selected>参照先がありません。選び直してください</option>';
				}
				foreach ( $choices as $choice => $label ) {
					echo '<option value="' . esc_attr( $choice ) . '" ' . selected( (string) $value, (string) $choice, false ) . '>' . esc_html( $label ) . '</option>';
				}
				echo '</select>';
			} else {
				echo '<input class="regular-text" type="' . esc_attr( $field[1] ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" ' . ( 'number' === $field[1] ? 'min="0" max="100000" step="1"' : 'maxlength="10000"' ) . '>';
			}
			if ( $field[3] || $inherit ) {
				echo '<p class="description">' . esc_html( $field[3] . ( $inherit ? ' 空欄は記事の種類に従います。' : '' ) ) . '</p>';
			}
			if ( null !== $usage ) {
				echo '<p><label for="' . esc_attr( $id . '-usage' ) . '">この情報の使い方</label><br><select id="' . esc_attr( $id . '-usage' ) . '" name="data[usage][' . esc_attr( $key ) . ']">';
				foreach ( Config::author_usage() as $choice => $label ) {
					echo '<option value="' . esc_attr( $choice ) . '" ' . selected( $usage[ $key ] ?? 'exclude', $choice, false ) . '>' . esc_html( $label ) . '</option>';
				}
				echo '</select></p>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	public static function setup_navigation( string $current ): void { AdminSetup::navigation( $current ); }

	public static function setup(): void { AdminSetup::render(); }

	public static function settings(): void {
		self::form( 'site_info' );
		$values = Store::site();
		echo '<h2>サイト・事業の共通情報</h2><p>どんなサービス・活動なのか、誰向けなのかを共通情報として保存します。設定した情報は今後のAI相談の材料になります。保存だけでは外部へ送信されません。</p><p>本人の担当・経験・考え方は、<a href="' . esc_url( Admin::url( 'personal' ) ) . '">個人設定・投稿者プロフィール</a>に登録できます。個人運営でも、共通情報と本人の情報はそれぞれ保存します。</p>';
		echo '<p>事実に関する情報は、基本初期設定でAIと相談し、内容を承認してから取り込めます。<a href="' . esc_url( Admin::url( 'setup' ) ) . '">基本初期設定へ</a>。文章の推奨設定は「記事品質の共通ルール」で管理します。</p>';
		self::fields( Config::site_info_fields(), $values );
		self::end();
	}

	public static function quality(): void {
		$values = Store::site();
		echo '<h2>記事品質の共通ルール</h2><p>標準ではすべての推奨ルールを使用します。詳細AI相談を行わなくても利用できます。「追加しない」は、この推奨だけを外す設定です。既存の自由入力・記事目的の条件は維持します。</p>';
		echo '<p><a class="button" href="' . esc_url( Admin::url( 'setup', array( 'setup_mode' => 'details' ) ) ) . '">詳細設定をAIと相談する（任意）</a></p>';
		self::form( 'quality' );
		self::fields( Config::quality_fields(), $values );
		echo '<details><summary>文体・独自の文章指示（既存設定を保持）</summary><p>既存の自由入力はそのまま保存されます。独自指示は推奨ルールより優先し、記事目的や個別指定と矛盾する場合はAIが確認します。</p>';
		self::fields( Config::quality_legacy_fields(), $values );
		echo '</details><details><summary>管理者の禁止事項・公開前チェック・警告の目安</summary><p>事実の創作禁止・未確認情報の断定禁止・承認の確認は常に適用され、上の選択設定では解除できません。</p>';
		self::fields( Config::safety_fields(), $values );
		echo '</details>';
		self::end();
		self::quality_summary( Store::writing_context( $values ) );
		echo '<details><summary>記事の種類と統合したルールを確認</summary>';
		foreach ( Store::profiles() as $id => $profile ) {
			echo '<h3><a href="' . esc_url( Admin::url( 'profiles', array( 'profile_id' => $id ) ) ) . '">' . esc_html( $profile['name'] ) . '</a></h3>';
			self::quality_summary( Store::writing_context( $values, $profile ) );
		}
		echo '</details>';
	}

	public static function quality_summary( array $quality ): void {
		echo '<details><summary>AIへ適用する品質ルール（保存済み）</summary><p>' . esc_html( $quality['precedence'] ) . '</p><h4>常に適用するルール</h4><ul>';
		foreach ( $quality['mandatory'] as $rule ) { echo '<li>' . esc_html( $rule ) . '</li>'; }
		echo '</ul><h4>有効な共通推奨ルール</h4><ul>';
		foreach ( $quality['recommendations'] as $rule ) { echo '<li><strong>' . esc_html( $rule['label'] ) . '</strong>：' . esc_html( $rule['instruction'] ) . '</li>'; }
		if ( ! $quality['recommendations'] ) { echo '<li>追加の共通推奨ルールなし（独自指示・記事目的の条件・安全ルールは引き続き適用）</li>'; }
		echo '</ul><dl>';
		foreach ( array( 'administrator_rules' => Config::safety_fields(), 'site_instructions' => Config::quality_legacy_fields(), 'effective_article_rules' => Config::rules() ) as $group => $fields ) {
			foreach ( $quality[ $group ] as $key => $value ) {
				$field = $fields[ $key ];
				echo '<dt>' . esc_html( ( 'effective_article_rules' === $group ? '記事条件：' : 'サイト共通：' ) . $field[0] ) . '</dt><dd>' . nl2br( esc_html( $field[4][ $value ] ?? (string) $value ) ) . '</dd>';
			}
		}
		echo '</dl><p>管理者の追加禁止事項・禁止ワード・公開前チェックも別途適用されます。変更後は保存して再表示してください。</p></details>';
	}

	public static function personal(): void {
		$author = Store::author();
		echo '<p>自分の担当・経験・考え方を登録します。同じサイトでも利用者ごとに別の情報を保存し、ここでは自分の情報だけを編集できます。WordPress標準のプロフィールや公開中の記事は変更しません。</p>';
		echo '<p>会社・団体全体のサービス内容はサイト共通設定、今回の出来事や意見は記事作成画面に入力します。個人事業の場合は、担当するサービスの概要もここに記入できます。</p>';
		if ( current_user_can( 'manage_options' ) ) {
			echo '<p><a class="button" href="' . esc_url( Admin::url( 'settings' ) ) . '">サイト・事業の共通情報を設定する</a></p>';
		}
		echo '<h2>情報の使い方</h2><ul><li><strong>使わない</strong>：設定として保存するだけで、記事制作に使うデータには含めません。</li><li><strong>AIの参考のみ</strong>：理解や文体調整の参考にし、その事実情報を記事に掲載しない条件を付けます。</li><li><strong>記事への掲載可</strong>：記事に必要な場合だけ使い、原稿の確認を受ける条件を付けます。登録しただけでは公開されません。</li></ul><p>初期値は全項目「使わない」です。「記事を作る」でプロンプトを生成するときも、この利用範囲に従って情報を選びます。外部AIへの自動送信は行いません。</p>';
		self::form( 'author' );
		$fields = Config::author_fields();
		echo '<h2>基本情報</h2>';
		self::fields( array_slice( $fields, 0, 3, true ), $author['profile'], 'data[profile]', false, $author['usage'] );
		echo '<h2>自分のサービス・活動</h2>';
		self::fields( array_slice( $fields, 3, 3, true ), $author['profile'], 'data[profile]', false, $author['usage'] );
		echo '<details><summary>専門性・経験・考え方</summary>';
		self::fields( array_slice( $fields, 6, 5, true ), $author['profile'], 'data[profile]', false, $author['usage'] );
		echo '</details><details><summary>文章の好み・掲載時の注意事項</summary>';
		self::fields( array_slice( $fields, 11, null, true ), $author['profile'], 'data[profile]', false, $author['usage'] );
		echo '</details>';
		self::end( '自分のプロフィールを保存' );
		if ( $author['updated_at'] ) {
			echo '<p>最終保存：' . esc_html( $author['updated_at'] ) . '</p>';
		}
		self::author_summary( Store::author_context() );
	}

	private static function author_summary( array $context ): void {
		echo '<h3>記事制作の参考にする投稿者情報</h3>';
		if ( ! $context ) {
			echo '<p>使う情報はまだ指定されていません。未設定のままでも記事の条件を保存できます。</p>';
			return;
		}
		echo '<p>保存した最新の個人設定を参照しています。「使わない」の項目は含めません。サイト共通の禁止事項・公開前チェックは引き続き適用されます。</p><table class="widefat striped"><thead><tr><th>項目</th><th>内容</th><th>使い方</th></tr></thead><tbody>';
		$fields = Config::author_fields();
		foreach ( array( 'reference_only' => 'AIの参考のみ・事実情報は掲載不可', 'publishable' => '必要な場合に掲載可・原稿確認後' ) as $group => $label ) {
			foreach ( $context[ $group ] as $key => $value ) {
				echo '<tr><th scope="row">' . esc_html( $fields[ $key ][0] ) . '</th><td>' . nl2br( esc_html( $value ) ) . '</td><td>' . esc_html( $label ) . '</td></tr>';
			}
		}
		echo '</tbody></table>';
	}

	public static function profiles(): void {
		$profiles = Store::profiles();
		echo '<h2>サイトに合わせて使う記事の種類</h2><p>初期登録の5種類は、編集・複製・追加・無効化できるテンプレートです。固定の5種類を使い続ける必要はありません。同じ書き方で対応できる内容は共通化し、必要な種類だけを使ってください。</p><p>カテゴリーは「何を掲載するか」、記事の種類は「どう書くか・どの工程で作るか」です。例：「地域のお知らせ」カテゴリーに、告知や活動報告などの記事の種類を使い分けられます。</p><p>不要な種類は名前を選んで編集し、「利用者向けの表示」を無効にしてください。保存済みの設定を残せるため、通常は削除より無効化を推奨します。</p>';
		echo '<p>記事の種類ごとに表示・説明・投稿先・標準分類・追加質問を設定します。新画面で開始した記事は開始時の設定を保持し、最新設定の明示的な採用時に更新します。旧画面からの作業は従来どおり最新設定を参照します。</p>';
		echo '<table class="widefat striped"><thead><tr><th>記事の種類</th><th>目的</th><th>状態</th><th>操作</th></tr></thead><tbody>';
		foreach ( $profiles as $id => $profile ) {
			echo '<tr><td><a href="' . esc_url( Admin::url( 'profiles', array( 'profile_id' => $id ) ) ) . '">' . esc_html( $profile['name'] ) . '</a></td><td>' . esc_html( $profile['purpose'] ) . '</td><td>' . esc_html( 'no' === ( $profile['enabled'] ?? 'yes' ) ? '無効（新規選択に表示しない）' : '有効' ) . '</td><td class="aaa-actions"><a class="button" href="' . esc_url( Admin::url( 'profiles', array( 'profile_id' => $id ) ) ) . '">編集</a>';
			foreach ( array( 'duplicate' => '複製', 'delete' => '削除' ) as $operation => $label ) {
				if ( 'delete' === $operation ) { echo '<details><summary>その他の操作（削除）</summary><p>通常は無効化を使用してください。</p>'; }
				self::form( $operation );
				self::hidden( 'profile_id', $id );
				echo '<button type="submit" class="button"' . ( 'delete' === $operation ? ' data-aaa-delete="この記事の種類を削除します。参照中の記事条件では選び直しが必要です。"' : '' ) . '>' . esc_html( $label ) . '</button></form>';
				if ( 'delete' === $operation ) { echo '</details>'; }
			}
			echo '</td></tr>';
		}
		if ( ! $profiles ) {
			echo '<tr><td colspan="4">記事の種類がありません。下のフォームで作成してください。</td></tr>';
		}
		echo '</tbody></table><p><a class="button" href="' . esc_url( Admin::url( 'profiles' ) ) . '">新しい記事の種類を作成</a></p>';
		$id = self::get( 'profile_id' );
		if ( $id && ! isset( $profiles[ $id ] ) ) {
			echo '<div class="notice notice-warning inline"><p>選択した記事の種類は削除されています。</p></div>';
			return;
		}
		echo '<h2>' . ( $id ? '編集中：' . esc_html( $profiles[ $id ]['name'] ) : '新しい記事の種類を作成' ) . '</h2><p>サイト共通の品質ルールを基本に、この種類の文章量・構成を調整します。</p>';
		self::form( 'profile' );
		self::hidden( 'profile_id', $id );
		$values = $profiles[ $id ] ?? Store::defaults( Config::profile_fields() );
		self::fields( array_diff_key( Config::profile_fields(), Config::workflow_fields(), Config::rules() ), $values );
		echo '<details><summary>標準分類に指定できる候補（各分類100件まで）</summary>';
		foreach ( Setup::catalogue() as $taxonomy => $items ) { foreach ( $items as $term_id => $term ) { echo '<p>' . esc_html( $term['name'] . '：' . $taxonomy . ':' . $term_id ) . '</p>'; } }
		echo '</details>';
		echo '<h3>制作工程</h3><p>以前に保存した記事の種類では「一般ブログ・コラム／AIが推奨」が初期表示されます。内容に合う種類を選んで保存してください。</p>';
		self::fields( Config::workflow_fields(), $values );
		echo '<details open><summary>文章量・構成・資料・SEO・ヒアリング</summary>';
		self::fields( Config::rules(), $values );
		echo '</details>';
		self::end();
		self::quality_summary( Store::writing_context( Store::site(), $values ) );
	}

	/** GET controls only select what to display; they never write data. */
	public static function destinations(): void {
		$types = Store::types();
		echo '<h2>記事制作で使う投稿先</h2><p>既存の投稿タイプを選びます。投稿タイプの作成や既存記事の変更は行いません。全て外すと記事条件を保存できなくなります。</p>';
		self::form( 'destinations' );
		foreach ( $types as $slug => $type ) {
			echo '<p><label><input type="checkbox" name="data[types][]" value="' . esc_attr( $slug ) . '" ' . checked( in_array( $slug, Store::enabled(), true ), true, false ) . '> ' . esc_html( $type->labels->name . ' (' . $slug . ')' ) . '</label></p>';
		}
		self::end( '投稿先を保存' );
		$type = self::get( 'type' );
		$type = isset( $types[ $type ] ) ? $type : ( array_key_first( $types ) ?? '' );
		$taxonomies = Store::taxonomies( $type );
		$tax = self::get( 'taxonomy' );
		$tax = isset( $taxonomies[ $tax ] ) ? $tax : ( array_key_first( $taxonomies ) ?? '' );
		echo '<h2>カテゴリー・タグ等の追加情報</h2><p>名前とスラッグはWordPressの登録内容を表示します。ここではAI相談向けの情報だけを保存します。</p>';
		echo '<form method="get" class="aaa-filter">';
		self::hidden( 'page', Config::SLUG . '-destinations' );
		echo '<label>投稿先 <select name="type">';
		foreach ( $types as $slug => $object ) {
			echo '<option value="' . esc_attr( $slug ) . '" ' . selected( $type, $slug, false ) . '>' . esc_html( $object->labels->name ) . '</option>';
		}
		echo '</select></label> <label>分類 <select name="taxonomy"><option value="">選択してください</option>';
		foreach ( $taxonomies as $slug => $object ) {
			echo '<option value="' . esc_attr( $slug ) . '" ' . selected( $tax, $slug, false ) . '>' . esc_html( $object->labels->name ) . '</option>';
		}
		echo '</select></label> <label>名前を検索 <input type="search" name="term_search" value="' . esc_attr( self::get( 'term_search' ) ) . '"></label> <button class="button">一覧を表示</button></form>';
		if ( ! $tax ) {
			echo '<p>この投稿先に編集可能な分類はありません。</p>';
			return;
		}
		$terms = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false, 'number' => 100, 'search' => self::get( 'term_search' ) ) );
		if ( is_wp_error( $terms ) ) {
			echo '<p>' . esc_html( $terms->get_error_message() ) . '</p>';
			return;
		}
		echo '<p class="description">最大100件を表示します。見つからない場合は名前で検索してください。</p><ul class="aaa-term-list">';
		foreach ( $terms as $term ) {
			echo '<li><a href="' . esc_url( Admin::url( 'destinations', array( 'type' => $type, 'taxonomy' => $tax, 'term_id' => $term->term_id ) ) ) . '">' . esc_html( $term->name . ' (' . $term->slug . ')' ) . '</a></li>';
		}
		echo '</ul>';
		$id = self::get( 'term_id' );
		if ( ! ctype_digit( $id ) ) {
			return;
		}
		$term = get_term( (int) $id, $tax );
		if ( ! $term || is_wp_error( $term ) || ! current_user_can( $taxonomies[ $tax ]->cap->manage_terms ) ) {
			echo '<p>この分類は削除されているか、管理する権限がありません。</p>';
			return;
		}
		echo '<h3>' . esc_html( $term->name . ' / ' . $term->slug ) . '</h3>';
		self::form( 'term' );
		self::hidden( 'post_type', $type );
		self::hidden( 'taxonomy', $tax );
		self::hidden( 'term_id', (string) $term->term_id );
		$values = get_term_meta( $term->term_id, Config::TERM, true );
		self::fields( Config::term_fields(), is_array( $values ) ? $values : array() );
		self::end( '追加情報を保存' );
	}

	public static function article(): void {
		$types = Store::writable_types();
		$profiles = ProfileRuntime::available();
		$article = Store::article();
		$snapshot = ProfileRuntime::snapshot( $article );
		// A pinned work in progress remains resumable; disabled entries are never new choices.
		if ( $snapshot ) { $profiles[ $article['profile_id'] ] = $snapshot['profile']; }
		if ( ! $types ) {
			echo '<div class="notice notice-warning inline"><p>利用できる投稿先または記事の種類がありません。管理者に投稿先設定・投稿権限・記事の種類の確認を依頼してください。</p></div>';
			return;
		}
		$requested = self::get( 'step' );
		$record = $article ? Prompt::current() : array();
		$ready = is_array( $record ) && ! empty( $record );
		if ( in_array( $requested, array( '', '2', '4' ), true ) && $ready ) {
			self::journey( 2 );
			echo '<section class="aaa-card" data-aaa-article-stage="2"><h2>' . esc_html( Consultation::titles() ? 'STEP2：ネタ・タイトルを相談' : ( 'proofread' === Consultation::mode() ? 'STEP2：ChatGPTで添削' : 'STEP2：ChatGPTで制作' ) ) . '</h2>';
			if ( ! Consultation::titles() ) { echo '<p class="aaa-mode-summary">進め方：' . esc_html( Config::article_fields()['interaction_mode'][4][ Consultation::mode() ] ) . '</p>'; }
			self::prompt();
			echo '<details><summary>概要・保存済み条件を確認する</summary><p><a href="' . esc_url( Admin::url( '', array( 'step' => '1' ) ) ) . '">概要を修正する（STEP1へ）</a></p>';
			$context = Store::context( $article );
			echo '<p>概要：' . nl2br( esc_html( $article['facts'] ?? '' ) ) . '</p>';
			if ( ! Consultation::titles() ) { self::summary( $context ); } self::quality_summary( $context['quality'] );
			self::author_summary( Store::author_context() );
			echo '<p>作成開始時の設定：' . esc_html( $snapshot['captured_at'] ?? '' ) . '</p></details>';
			echo '<details><summary>別の記事を始める</summary>'; ImportScreen::new_article_form(); echo '</details></section>';
			return;
		}
		self::journey( 1 );
		if ( in_array( $requested, array( '2', '4' ), true ) || '1' === self::get( 'prompt_pending' ) ) {
			echo '<p class="aaa-warning">入力内容は保存しましたが、相談文の準備が完了していません。内容を確認して、もう一度下のボタンを押してください。' . esc_html( is_wp_error( $record ) ? $record->get_error_message() : '概要を確認して、下のボタンから準備してください。' ) . '</p>';
			if ( 'off' !== Reference::policy() ) { echo '<p><a href="' . esc_url( Admin::url( 'references' ) ) . '">既存記事の確認を行う</a>（確認後、この画面に戻って準備してください）</p>'; }
		}
		$type = $article['post_type'] ?? '';
		$profile_id = $article['profile_id'] ?? '';
		if ( ! $article ) {
			$type = 1 === count( $types ) ? (string) array_key_first( $types ) : '';
			$profile_id = 1 === count( $profiles ) ? (string) array_key_first( $profiles ) : '';
		}
		$selected_profile = $profiles[ $profile_id ] ?? array();
		if ( $article && ( ! isset( $types[ $type ] ) || ! isset( $profiles[ $profile_id ] ) ) ) { echo '<p class="aaa-warning">保存した投稿先または記事の種類が利用できません。選び直してください。</p>'; }
		$form_article = $article;
		if ( ! $article && '' !== ( $selected_profile['default_post_type'] ?? '' ) ) {
			$type = $selected_profile['default_post_type'];
			$binding = ProfileRuntime::binding( $selected_profile );
			if ( ! is_wp_error( $binding ) ) { $form_article['terms'] = $binding['terms']; }
		}
		self::form( 'article' );
		self::hidden( 'generate_after_save', '1' );
		self::hidden( 'data[use_snapshot]', '1' );
		self::hidden( 'data[terms_present]', '1' );
		echo '<section class="aaa-card" data-aaa-article-stage="1"><h2>STEP1：記事の準備</h2><p>詳しい情報は、この後のAIとの対話で整理できます。まだテーマが決まっていない場合も相談できます。</p>';
		$basic = Config::article_fields();
		$basic['facts'][0] = '記事の概要・相談したいこと';
		$basic['facts'][3] = '短い説明や箇条書きで構いません。例：来月の清掃活動を告知したい。／サービスについて何を書くとよいか相談したい。日時等は分かる範囲だけ。不明な事実はAIとの対話で確認します。テーマから相談する場合は空欄でも始められます。';
		Consultation::cards( 'planning_mode', '① 相談の始め方', $basic['planning_mode'][4], $article['planning_mode'] ?? 'topic' );
		echo '<div data-aaa-interaction-fields>';
		Consultation::cards( 'interaction_mode', '② 記事の進め方', $basic['interaction_mode'][4], $article['interaction_mode'] ?? ( $article ? 'guided' : Store::site()['default_interaction'] ) );
		echo '<p class="description" data-aaa-mode-help>添削は「書きたいことが決まっている」を選び、原稿を入力します。お任せでも重要な事実確認と最終承認は必要です。</p></div><div data-aaa-overview-fields>';
		self::fields( array_intersect_key( $basic, array_flip( array( 'facts' ) ) ), $article );
		echo '</div><div data-aaa-proofread-fields>';
		self::fields( array_intersect_key( $basic, array_flip( array( 'source_draft' ) ) ), $article );
		echo '</div><details><summary>今回の読者（任意）</summary>';
		self::fields( array_intersect_key( $basic, array_flip( array( 'audience' ) ) ), $article );
		echo '</details><div data-aaa-titles-fields>';
		echo '<p>設定済みのカテゴリーと既存の公開記事を参考に、ネタ・タイトルを提案します。公開記事は管理者が許可した範囲の最新50件まで。情報は相談文をコピーしてChatGPTへ貼り付けたときに渡ります。</p>';
		echo '<p>本文や画像は作らず、最初に5件程度の案を相談します。既存記事を渡す場合も参照できた範囲と、需要の仮説を区別します。</p></div><div data-aaa-production-fields>';
		self::article_choices( 'aaa-profile', 'data[profile_id]', '記事の種類', array_map( static function ( $profile ) { return $profile['name']; }, $profiles ), $profile_id );
		self::article_choices( 'aaa-post-type', 'data[post_type]', '投稿先', array_map( static function ( $object ) { return $object->labels->name; }, $types ), $type );
		echo '<div data-aaa-writing-fields>';
		self::profile_guidance( $profiles, $profile_id, $article );
		echo '<details><summary>カテゴリー・資料・詳しい条件（必要な場合だけ）</summary>' ;
		if ( $snapshot ) { echo '<p>作成開始時の設定：' . esc_html( $snapshot['captured_at'] ) . '</p><p><label><input type="checkbox" name="data[adopt_settings]" value="1"> 管理者の最新設定を採用する</label></p>'; }
		echo '<h3>カテゴリー・タグ（複数選択可）</h3><p class="description">投稿先に対応する分類だけを選択してください。カテゴリーの最終決定は利用者が行います。</p>';
		self::article_terms( $types, $form_article );
		self::fields( array_diff_key( Config::article_fields(), array_flip( array( 'planning_mode', 'facts', 'interaction_mode', 'source_draft', 'reference_summary', 'audience' ) ) ), $article );
		echo '<h3>今回だけの文章の指定</h3><p>空欄は管理者が用意した設定に従います。禁止事項や公開前チェックは変更できません。</p>';
		self::fields( Config::rules(), $article['overrides'] ?? array(), 'data[overrides]', true );
		echo '<p>記事条件を保存した後、<a href="' . esc_url( Admin::url( 'media' ) ) . '">画像・PDF管理</a>で資料を登録できます。実ファイルはChatGPTへ直接添付し、読めない資料は説明を補ってください。</p></details></div></div></section>';
		self::end( '保存して相談文を作り、STEP2へ進む →' );
		echo '<details><summary>個人情報・別の操作</summary>';
		echo '<p><a href="' . esc_url( Admin::url( 'personal' ) ) . '">自分のプロフィールを確認・編集する</a></p>';
		self::author_summary( Store::author_context() );
		if ( $ready ) { echo '<p><a href="' . esc_url( Admin::url( '', array( 'step' => '2' ) ) ) . '">保存済みの相談を続ける（STEP2）</a></p>'; }
		echo '<p><a href="' . esc_url( Admin::url( 'import' ) ) . '">すでに完成ファイルがある場合は取り込む（STEP3）</a></p>';
		if ( $article ) { ImportScreen::new_article_form(); }
		echo '</details>';
	}

	private static function profile_guidance( array $profiles, string $selected, array $article ): void {
		$config = array_map( array( ProfileRuntime::class, 'presentation' ), $profiles );
		$latest = array_map( array( ProfileRuntime::class, 'presentation' ), ProfileRuntime::available() );
		$data = $config[ $selected ] ?? array();
		echo '<section class="aaa-profile-guidance" aria-live="polite"><p data-aaa-profile-description>' . nl2br( esc_html( $data['description'] ?? '' ) ) . '</p><p data-aaa-profile-purpose>' . nl2br( esc_html( $data['purpose'] ?? '' ) ) . '</p><p data-aaa-overview-help>' . nl2br( esc_html( $data['overview_help'] ?? '' ) ) . '</p><p data-aaa-materials-hint>' . nl2br( esc_html( $data['materials_hint'] ?? '' ) ) . '</p><div data-aaa-profile-questions>';
		foreach ( $data['questions'] ?? array() as $question ) {
			$id = 'aaa-answer-' . $question['key']; $name = 'data[answers][' . $question['key'] . ']'; $value = $article['answers'][ $question['key'] ] ?? '';
			echo '<p><label for="' . esc_attr( $id ) . '">' . esc_html( $question['label'] . ( $question['required'] ? '（執筆前に確認）' : '' ) ) . '</label><br>';
			if ( 'select' === $question['type'] ) {
				echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" data-aaa-answer="' . esc_attr( $question['key'] ) . '"><option value="">AIと相談する・未回答</option>';
				foreach ( $question['options'] as $option ) { echo '<option value="' . esc_attr( $option ) . '" ' . selected( $value, $option, false ) . '>' . esc_html( $option ) . '</option>'; } echo '</select>';
			} elseif ( 'text' === $question['type'] ) { echo '<input class="regular-text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" data-aaa-answer="' . esc_attr( $question['key'] ) . '" maxlength="10000" value="' . esc_attr( $value ) . '">'; }
			else { echo '<textarea class="large-text" rows="2" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" data-aaa-answer="' . esc_attr( $question['key'] ) . '" maxlength="10000">' . esc_textarea( $value ) . '</textarea>'; }
			echo '<span class="description">' . esc_html( $question['help'] ) . '</span></p>';
		}
		echo '</div><details><summary>この種類の推奨設定</summary><dl data-aaa-profile-rules>';
		foreach ( $data['rules'] ?? array() as $key => $value ) { $field = Config::rules()[ $key ]; echo '<dt>' . esc_html( $field[0] ) . '</dt><dd>' . nl2br( esc_html( $field[4][ $value ] ?? $value ) ) . '</dd>'; }
		echo '</dl></details></section><script type="application/json" data-aaa-profile-config>' . wp_json_encode( array( 'profiles' => $config, 'latest' => $latest, 'rules' => Config::rules() ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE ) . '</script>';
	}

	private static function article_choices( string $id, string $name, string $label, array $choices, string $value ): void {
		if ( 1 === count( $choices ) && isset( $choices[ $value ] ) ) {
			echo '<p><strong>' . esc_html( $label ) . '：</strong>' . esc_html( $choices[ $value ] ) . '<input type="hidden" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"></p>';
			return;
		}
		echo '<p><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label> <select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" required><option value="">選択してください</option>';
		foreach ( $choices as $key => $text ) { echo '<option value="' . esc_attr( $key ) . '" ' . selected( $value, $key, false ) . '>' . esc_html( $text ) . '</option>'; }
		echo '</select></p>';
	}

	private static function prompt(): void {
		$prepared = Prompt::prepare();
		if ( is_wp_error( $prepared ) ) { echo '<p class="aaa-warning">' . esc_html( $prepared->get_error_message() ) . '</p>'; return; }
		$record = Prompt::current();
		if ( is_wp_error( $record ) || ! $record ) { echo '<p class="aaa-warning">概要を確認し、STEP1から相談文を準備してください。</p>'; return; }
		echo '<p>下のボタンで相談文を全文コピーし、新しいChatGPTのチャットへ貼り付けてください。その後はAIの案内に沿って進めます。コピーだけでは制作完了になりません。</p><p><button type="button" class="button button-primary aaa-main-action" data-aaa-copy hidden>相談文をコピーする</button> <span role="status" aria-live="polite" data-aaa-copy-status></span></p><label for="aaa-prompt-output">ChatGPTへ渡す相談文（スクロールして確認できます）</label><textarea id="aaa-prompt-output" class="large-text code aaa-prompt-scroll" rows="10" readonly>' . esc_textarea( $record['text'] ) . '</textarea><details><summary>生成記録</summary><p>プロンプト版：' . esc_html( $record['prompt_version'] ) . ' ／ 生成日時：' . esc_html( $record['generated_at'] ) . '</p></details>';
		if ( Consultation::titles() ) {
			$scope = $prepared['context']['existing_articles'] ?? array();
			if ( $scope ) { echo '<p>既存記事の参照：' . esc_html( 'public_summary' === ( $scope['status'] ?? '' ) ? $scope['count'] . '件／対象' . $scope['total'] . '件（最新50件まで・本文全体なし）' : '管理者設定で無効' ) . '</p>'; }
			$categories = $prepared['context']['category_catalogue'] ?? array();
			echo '<p>カテゴリーの参照：' . esc_html( $categories['count'] ?? 0 ) . '件' . esc_html( ! empty( $categories['limited'] ) ? '（上限による省略あり）' : '' ) . '。目的・掲載方針を提案に反映します。' . esc_html( 'partially_unavailable' === ( $categories['status'] ?? '' ) ? '一部の分類を取得できませんでした。' : '' ) . '</p>';
			Consultation::results();
		}
		if ( isset( $_GET['title_draft'] ) ) { $draft = get_post( (int) self::get( 'title_draft' ) ); if ( $draft && current_user_can( 'edit_post', $draft->ID ) && get_post_meta( $draft->ID, '_aaa_article_key', true ) === Store::article()['article_key'] ) { echo '<p class="aaa-current-task">下書きを作成しました。<a href="' . esc_url( get_edit_post_link( $draft->ID ) ) . '">下書きを開く</a></p>'; } }

		echo '<details><summary>AIに渡す設定・資料・相談文の再生成（必要な場合だけ）</summary><pre class="aaa-json">' . esc_html( wp_json_encode( $prepared['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) . '</pre>';
		if ( 'off' !== Reference::policy() ) { echo '<p><a href="' . esc_url( Admin::url( 'references' ) ) . '">既存記事の参照内容を確認する</a></p>'; }
		self::form( 'generate_prompt' ); self::hidden( 'fingerprint', $prepared['fingerprint'] );
		echo '<p><button type="submit" class="button" data-aaa-generate>現在の設定で相談文を作り直す</button></p></form></details>';
		if ( ! Consultation::titles() ) { if ( 'proofread' === Consultation::mode() ) { echo '<p>修正版をコピーして終了できます。WordPressへ取り込みたい場合だけ、ChatGPTへ記事CSVの出力を依頼してください。</p><details><summary>修正版を新規下書きへ取り込む（任意）</summary>'; } ImportScreen::export_form( $prepared ); if ( 'proofread' === Consultation::mode() ) { echo '</details>'; } }
	}

	private static function article_terms( array $types, array $article ): void {
		$taxonomies = array();
		foreach ( $types as $slug => $type ) {
			foreach ( Store::taxonomies( $slug ) as $tax => $object ) {
				if ( current_user_can( $object->cap->assign_terms ) ) {
					$taxonomies[ $tax ]['object'] = $object;
					$taxonomies[ $tax ]['types'][] = $slug;
				}
			}
		}
		$selected = $article['terms'] ?? array();
		$found = array();
		foreach ( $taxonomies as $tax => $entry ) {
			$terms = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false, 'number' => 200 ) );
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			// Retain selected terms even if they are beyond the initial display limit.
			$map = array();
			foreach ( $terms as $term ) {
				$map[ $term->term_id ] = $term;
			}
			foreach ( $selected as $reference ) {
				$parts = explode( ':', $reference, 2 );
				if ( count( $parts ) === 2 && $tax === $parts[0] ) {
					$term = get_term( (int) $parts[1], $tax );
					if ( $term && ! is_wp_error( $term ) ) {
						$map[ $term->term_id ] = $term;
					}
				}
			}
			echo '<fieldset class="aaa-terms" data-aaa-types="' . esc_attr( implode( ' ', $entry['types'] ) ) . '"><legend>' . esc_html( $entry['object']->labels->name ) . '</legend><label>名前を絞り込む <input type="search" data-aaa-term-filter></label><div class="aaa-term-choices">';
			foreach ( $map as $term ) {
				$reference = $tax . ':' . $term->term_id;
				$found[] = $reference;
				echo '<label><input type="checkbox" name="data[terms][]" value="' . esc_attr( $reference ) . '" ' . checked( in_array( $reference, $selected, true ), true, false ) . '> ' . esc_html( $term->name ) . '</label>';
			}
			if ( ! $map ) {
				echo '<p>登録された項目はありません。</p>';
			}
			echo '</div><p class="description">最初の200件と保存済みの選択項目を表示します。200件を超える分類の新規選択は後続対応です。</p></fieldset>';
		}
		if ( array_diff( $selected, $found ) ) {
			echo '<p class="aaa-warning">保存した分類の一部が削除されたか、選択権限がなくなっています。確認して保存し直してください。</p>';
		}
	}

	private static function summary( array $context ): void {
		echo '<table class="widefat striped"><thead><tr><th>項目</th><th>今回の適用内容</th></tr></thead><tbody>';
		foreach ( Config::rules() as $key => $field ) {
			$value = $context['effective_rules'][ $key ] ?? '';
			if ( 'select' === $field[1] ) {
				$value = $field[4][ $value ] ?? $value;
			}
			echo '<tr><td>' . esc_html( $field[0] ) . '</td><td>' . nl2br( esc_html( (string) $value ) ) . '</td></tr>';
		}
		foreach ( array( 'forbidden_words' => '禁止ワード', 'locked_rules' => '禁止事項', 'publication_checks' => '公開前チェック' ) as $key => $label ) {
			echo '<tr><td>' . esc_html( $label ) . '（管理者設定）</td><td>' . nl2br( esc_html( $context['locked'][ $key ] ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	public static function help( string $page ): void {
		if ( 'import' === $page ) {
			ImportScreen::render();
		} elseif ( 'media' === $page ) {
			MediaScreen::render();
		} else {
			echo '<h2>記事を作る手順</h2><ol><li>「記事を作る」で記事の種類・テーマ・分かっている情報を入力して保存します。</li><li>必要な写真・PDFや既存記事は、作成画面の案内から確認します。</li><li>相談プロンプトをコピーし、新しいChatGPTのチャットへ貼り付けます。AIの質問に答え、構成と原稿を確認します。</li><li>本文と画像・PDFの制作計画を最終確認して、ChatGPTから記事CSVを受け取ります。</li><li>「完成した原稿を取り込む」でCSVを選び、必要な画像・PDFをまとめてアップロード・対応付けし、プレビューを承認して下書きを作ります。</li><li>WordPressのブロックエディターで最終確認・修正します。公開は確認後に自分で行います。</li></ol><p>サイトや文章の基本設定は管理者が準備します。設定について困った場合は、サイトの管理担当者へ相談してください。</p><details><summary>プロフィール・詳しい仕様と制限</summary>';
			echo '<h2>カテゴリーと記事の種類</h2><p>カテゴリーは掲載内容の分類、記事の種類は文章の書き方・構成・制作工程のテンプレートです。同じカテゴリーで複数の種類を使え、別のカテゴリーでも同じ種類を使えます。初期の5種類は編集・複製・追加・無効化できます。管理者が必要な種類だけを有効にし、通常は削除より無効化を使います。</p><h2>個人設定の使い方</h2><p>本人の公開名やサービスの担当、経験、文体等を項目ごとに保存します。「使わない」の内容は記事制作用データから除外し、「AIの参考のみ」と「記事への掲載可」を分けて保持します。初期値は全項目「使わない」です。掲載可でも自動公開はせず、必要な場合に使って原稿を確認する方針です。設定は利用者・サイトごとに分かれ、WordPress標準のプロフィールは変更しません。</p>';
			echo '<h2>AI相談の進め方</h2><p>カードで相談内容と進め方を選びます。ネタ・タイトルだけなら候補の相談で終了できます。お任せは必要な確認後に構成と本文をまとめて提案し、添削は提供原稿の趣旨を保って整えます。最終確認は省略しません。</p><p>相談しながら作る場合は、概要を入力し、保存後にAIへ渡す内容を確認して相談プロンプトを生成します。コピーして新しいChatGPTのチャットへ貼り付けてください。必要な質問への回答、構成の承認、原稿の確認をChatGPT側で進めます。管理者は記事の種類ごとに書き方と制作モードの推奨・固定を設定できます。</p><p>生成したプロンプトは、版、部品の版とハッシュ、生成日時とともに現在の利用者・サイトごとに1件を保存します。設定が変わったら以前の文面をそのまま表示せず再生成を案内します。すでに手動コピーした文章を取り消すことはできません。</p>';
			echo '<h2>CSVで下書きに登録する</h2><p>本文・画像計画の最終承認後にChatGPTで記事CSV v2を生成します。原稿貼り付け方式のv1も代替手段として利用できます。「原稿を取り込む」でファイルか本文を検証し、プレビューと警告を確認して新規下書きへ登録してください。次の記事は「新しい記事を開始する」から新しい管理IDで始めます。</p>';
			echo '<h2>現時点の制限</h2><p>下書き更新は本人の管理ID付き記事のタイトル・本文・抜粋に対応します。公開中の記事や画像・PDF等を含む本文は標準エディターで更新してください。自動公開は行いません。対応ブロックは段落・H2/H3・単純リスト・引用・区切り・ボタン・表・画像・PDFリンクです。表は最大12列・データ100行で、セル結合やセル内リンクには未対応です。画像・PDFは今回の記事の対応表から識別子で指定します。PDFの埋め込み表示や内容の自動読取、外部資料の自動取得は行いません。外部AIへの自動送信やAPI呼び出しは行わず、APIキーも不要です。</p><p>記事条件と資料の対応表は利用者ごと・サイトごとに現在の記事1件分です。新しい記事の開始で対応表もリセットします。取り込み済みの下書きには当時の対応記録を保持し、メディア自体は変更しません。資料が未配置でも警告を確認して下書きを作成できますが、公開前に確認用の段落を差し替えてください。新画面で開始した記事は開始時の共通情報・記事の種類の設定を保持します。個人情報の利用許可・禁止事項・権限は最新の状態を確認します。</p><p>新規カテゴリーは初期設定CSVの版2で比較・追加承認後に作成できます。既存カテゴリーの改名・親子関係の変更はWordPressの標準画面で行ってください。通常投稿と本文エディターを持つ公開カスタム投稿タイプが取り込み対象です。ChatGPTの調査・原稿作成・品質点検をWordPressが実行・確認する機能ではありません。</p>';
			echo '<h2>ACF連携</h2><p>ACFがあるサイトでは、管理者が「ACF連携」で投稿先ごとに保存を許可する項目を選べます。初期状態は無効です。文字・数値・選択・真偽値・画像・PDF・単純なグループを扱い、許可された項目の定義だけをAIへ渡します。値は投稿者が確認・承認し、CSVのプレビューで確認します。繰り返し・関連付け・条件付き項目や、サイズ等の制限付きメディア項目は対象外です。ACFがなくても通常の記事作成を利用できます。</p>';
			echo '<h2>今後の段階</h2><ol><li>画像・PDF・表・ACFを含む一連の操作を実WordPressと利用テーマで検証</li><li>既存記事参照・設定移行を含む操作の実証、品質確認とマニュアルの仕上げ</li></ol>';
			echo '</details>';
		}
	}
}
