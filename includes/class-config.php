<?php
namespace AAA;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Config {
	const SLUG = 'ai-article-assistant';
	const SITE = 'aaa_site_v1';
	const PROFILES = 'aaa_profiles_v1';
	const DESTINATIONS = 'aaa_destinations_v1';
	const TERM = '_aaa_context_v1';
	const ARTICLE = '_aaa_article_v1';
	const AUTHOR = '_aaa_author_v1';
	const PROMPT = '_aaa_prompt_v1';
	const MEDIA = '_aaa_media_v1';
	const ACF = 'aaa_acf_v1';

	public static function name(): string {
		return __( 'AI記事作成サポート', 'ai-article-assistant' );
	}

	/** Schema entry: label, type, default, description, optional choices. */
	public static function site_fields(): array {
		return array_merge( array(
			'site_name' => array( 'サイト名', 'text', get_bloginfo( 'name' ), '初回はWordPressのサイト名を表示します。空欄で保存すると除外できます。' ),
			'organization' => array( '運営会社・団体名', 'text', '', '' ),
			'activities' => array( '事業内容・活動内容', 'textarea', '', '' ),
			'area' => array( '所在地・対応地域', 'text', '', '' ),
			'services' => array( '主なサービス', 'textarea', '', '' ),
			'audience' => array( '想定読者', 'textarea', '', '' ),
			'purpose' => array( 'サイトの目的', 'textarea', '', '' ),
			'strengths' => array( '独自の強み', 'textarea', '', '確認できる事実だけを入力してください。' ),
			'official_url' => array( '公式サイトURL', 'url', home_url( '/' ), '初回はWordPressのホームURLを表示します。' ),
			'contact' => array( 'お問い合わせ先', 'textarea', '', '確認済みのURL・電話番号・メール等を入力してください。' ),
			'additional' => array( 'その他AIへ伝える情報', 'textarea', '', '外部AIに渡してよい情報だけを入力してください。' ),
			'style' => array( '基本の文体', 'text', 'です・ます調', '' ),
			'friendliness' => array( '親しみやすさ', 'text', '', '' ),
			'expertise' => array( '専門性の度合い', 'text', '', '' ),
			'preferred' => array( '使用したい表現', 'textarea', '', '' ),
			'avoid' => array( '避けたい表現', 'textarea', '', '' ),
			'terminology' => array( '専門用語の説明方針', 'textarea', '初出時に平易に説明する。', '' ),
			'repetition' => array( '同じ語尾・言い回しの繰り返し回避', 'textarea', '同じ説明や語尾を不自然に繰り返さない。', '' ),
			'punctuation' => array( '読点・箇条書きの方針', 'textarea', '', '' ),
			'writing_notes' => array( '特別な執筆指示', 'textarea', '', '' ),
			'forbidden_words' => array( '禁止ワード（管理者専用）', 'textarea', '', '1行に1つ。記事個別設定では上書きできません。本文の検査は後続Phaseです。' ),
			'locked_rules' => array( '禁止事項（管理者専用）', 'textarea', '料金・実績・日時などを推測で作成しない。', '記事個別設定では上書きできません。' ),
			'publication_checks' => array( '公開前チェック（管理者専用）', 'textarea', '事実・権利・個人情報・リンクを確認する。', '記事個別設定では上書きできません。' ),
			'min_section' => array( '短すぎるセクションの目安（文字）', 'number', 80, 'Phase 3以降の品質警告で利用する目安です。0で無効。上限100000。' ),
			'min_article' => array( '記事本文の文字数の下限目安', 'number', 0, '取り込み前に警告する目安です。0で無効。短い告知に長文を強制する設定ではありません。' ),
		), self::quality_fields(), array( 'default_interaction' => array( '新しい記事の標準の進め方', 'select', 'guided', '記事ごとに変更できます。保存済みの作業には影響しません。', array( 'guided' => '相談しながら作りたい', 'delegated' => 'なるべくAIにお任せ' ) ) ) );
	}

	/** New controls remain in the existing site option/CSV scope; legacy text is never rewritten. */
	public static function quality_fields(): array {
		$fields = array();
		foreach ( self::quality_recommendations() as $key => $rule ) {
			$fields[ $key ] = array( $rule[0], 'select', 'yes', $rule[1], array( 'yes' => '推奨ルールを使用', 'no' => 'この推奨ルールを追加しない' ) );
		}
		return $fields;
	}

	public static function quality_recommendations(): array {
		return array(
			'quality_natural' => array( '文章の自然さ', '主語と述語・修飾関係・文体を整え、定型的な一般論から始めず、今回伝える内容から書く。' ),
			'quality_repetition' => array( '同じ語尾・表現の連続回避', '意味の重複や同じ結論の反復を減らす。語尾を変えるために不自然な言い換えをしない。' ),
			'quality_terms' => array( '専門用語の説明', '読者に必要な専門用語を初出時に平易に説明する。既に説明済みの内容を繰り返さない。' ),
			'quality_headings' => array( '見出しと本文量のバランス', '内容の薄い見出しや似た論点を統合する。短い記事に見出し数や本文量を一律に強制しない。' ),
			'quality_lists' => array( '箇条書き・表の過剰使用防止', '箇条書きや表は読み取りや比較に役立つ場合だけ使い、文章を機械的に表へ置き換えない。' ),
			'quality_concise' => array( '不必要な長文化の防止', '目的に必要十分な文章量にする。文字数やキーワードのために水増しせず、不要な導入・まとめ・FAQを付けない。' ),
		);
	}

	public static function site_info_fields(): array { return array_slice( self::site_fields(), 0, 11, true ) + array_intersect_key( self::site_fields(), array_flip( array( 'default_interaction' ) ) ); }
	public static function quality_legacy_fields(): array { return array_slice( self::site_fields(), 11, 9, true ); }
	public static function safety_fields(): array { return array_intersect_key( self::site_fields(), array_flip( array( 'forbidden_words', 'locked_rules', 'publication_checks', 'min_section', 'min_article' ) ) ); }

	public static function rules(): array {
		return array(
			'length_mode' => array( '文章量の決め方', 'select', 'auto', '文字数を満たすための水増しはしません。', array( 'auto' => 'AIにおまかせ', 'range' => '範囲指定', 'target' => '目標文字数' ) ),
			'length_min' => array( '文字数の下限', 'number', 0, '範囲指定時に使用。0は指定なし。' ),
			'length_max' => array( '文字数の上限', 'number', 0, '範囲指定時に使用。0は指定なし。' ),
			'length_target' => array( '目標文字数', 'number', 0, '目標文字数モードで使用。' ),
			'headings' => array( '見出し', 'select', 'standard', '', array( 'none' => 'なし', 'few' => '少なめ', 'standard' => '標準', 'detailed' => '詳細' ) ),
			'h2_count' => array( 'H2数の目安', 'number', 0, '0は指定なし。' ),
			'h3' => array( 'H3の使用', 'select', 'yes', '', array( 'yes' => '使用可', 'no' => '使用しない' ) ),
			'section_length' => array( '見出しあたりの本文量（文字）', 'number', 0, '0は指定なし。内容の薄い見出しは統合します。' ),
			'structure' => array( '記事に使いたい要素', 'textarea', '導入文、必要に応じてまとめ', '導入文、まとめ、FAQ、箇条書き、引用、注意事項、関連記事など。目的に合うものだけ指定してください。' ),
			'images' => array( '画像の使用方針', 'textarea', '', 'アイキャッチ・本文画像の有無、枚数、おまかせ、必須画像、alt・キャプション方針等。資料の登録はPhase 4です。' ),
			'pdfs' => array( 'PDFの使用方針', 'textarea', '', '案内PDFの要否、資料の確認範囲、リンクの配置等。実ファイルの内容は別途AIへ提供します。' ),
			'tables' => array( '表の使用方針', 'textarea', '必要な場合のみ提案する。数値を推測しない。', '不要、おまかせ、数・種類の指定など。' ),
			'cta' => array( 'リンク・問い合わせ誘導の方針', 'textarea', '', '不要な場合は「なし」。使用する場合は位置・文言・確認済みURLを指定します。' ),
			'keywords' => array( 'メイン・関連キーワード', 'textarea', '', '' ),
			'search_intent' => array( '検索意図・読者の悩み・解決内容', 'textarea', '', '' ),
			'seo_notes' => array( 'タイトル・抜粋・スラッグ・SEO方針', 'textarea', '', 'SEOタイトル案、メタディスクリプション案、出典・内部リンクの扱い等。SEOプラグインへの書き込みは行いません。' ),
			'interview' => array( '必要なヒアリング項目', 'textarea', '', '' ),
			'instructions' => array( 'AIへの追加指示', 'textarea', '', '' ),
		);
	}

	public static function profile_fields(): array {
		return array_merge( array(
			'name' => array( '記事の種類の名前', 'text', '', '必須。カテゴリーとは別に記事の目的ごとの設定を保存します。' ),
			'enabled' => array( '利用者向けの表示', 'select', 'yes', '', array( 'yes' => '有効', 'no' => '無効（新しい記事の選択肢に出さない）' ) ),
			'description' => array( '利用者に表示する説明', 'textarea', '', '' ),
			'overview_help' => array( '概要入力の補足・回答例', 'textarea', '', '未入力なら共通の説明を使います。' ),
			'materials_hint' => array( '用意してほしい資料', 'textarea', '', '写真、PDF、確認済みの料金表など。' ),
			'default_post_type' => array( '標準の投稿タイプ', 'text', '', '既存の投稿タイプの識別子。空欄は利用者が選びます。投稿先の有効化と権限は別途必要です。' ),
			'default_terms' => array( '標準カテゴリー・分類', 'textarea', '', '1行にtaxonomy:ID。指定した標準投稿タイプに所属する既存の分類のみ。' ),
			'questions_json' => array( '追加の質問項目', 'questions', '[]', 'JSON配列。key、label、type（text/textarea/select）、help、options（select時の選択肢配列）、required（執筆前にAIが確認する項目）。最大10件。入力を省略した必須事項はAIのヒアリングで確認します。' ),
			'purpose' => array( '記事の目的', 'textarea', '', '' ),
		), self::workflow_fields(), self::rules() );
	}

	public static function workflow_fields(): array {
		return array(
			'reference_policy' => array( '既存記事の確認', 'select', 'inherit', 'SEO記事では執筆前の確認を推奨します。サイト全体で無効の場合は利用できません。', array( 'inherit' => 'サイト設定に従う', 'off' => '使わない', 'optional' => '任意で確認する', 'required' => '執筆前に確認する' ) ),
			'purpose_type' => array( '制作ルールの基本タイプ', 'select', 'general', '記事の種類の名前は自由に設定できます。この5区分から近い制作ルールを選びます。', array( 'general' => '一般ブログ・コラム', 'notice' => 'お知らせ・告知', 'report' => '活動報告・事例', 'event' => 'イベント案内', 'seo' => 'SEO記事' ) ),
			'workflow_policy' => array( '制作モードの決め方', 'select', 'recommend', '', array( 'recommend' => 'AIが記事内容から推奨', 'fixed' => '管理者が固定' ) ),
			'workflow_mode' => array( '固定する制作モード', 'select', 'simple', '「管理者が固定」の場合に使用します。固定しても重要な事実確認は省略しません。', array( 'simple' => '簡易作成', 'interview' => 'ヒアリング重視', 'research' => 'リサーチ重視' ) ),
		);
	}

	public static function article_fields(): array {
		return array(
			'planning_mode' => array( '相談の始め方', 'select', 'topic', '', array( 'topic' => '書きたいことが決まっている', 'ideas' => '何を書くかから相談したい', 'titles' => '記事のネタ・タイトルだけ相談したい' ) ),
			'interaction_mode' => array( '記事の進め方', 'select', 'guided', '', array( 'guided' => '相談しながら作りたい', 'delegated' => 'なるべくAIにお任せ', 'proofread' => '添削だけしてほしい' ) ),
			'source_draft' => array( '添削したい原稿', 'textarea', '', '平文で3万バイト以内。書式ではなく文章を添削します。' ),
			'reference_summary' => array( '公開記事の概要をAIへ渡す', 'select', 'no', '管理者が許可した公開記事の最新50件まで。本文全体は送りません。', array( 'no' => '渡さない', 'yes' => 'タイトル・分類・要約を渡す' ) ),
			'theme' => array( '記事のテーマ', 'text', '', '相談プロンプトを生成する際は入力してください。条件だけなら空欄でも保存できます。' ),
			'facts' => array( '伝えたい内容・分かっている事実', 'textarea', '', '日時・場所・対象者・根拠等。不明な点は不明と書いてください。' ),
			'audience' => array( '今回の読者', 'textarea', '', '' ),
			'materials' => array( '資料の説明・確認済みの出典', 'textarea', '', '写真やPDFの説明、出典URL・確認日・該当箇所など。実ファイルはChatGPTへ直接添付してください。この欄だけでは資料を読んだことにはなりません。' ),
			'freshness' => array( '最新情報の確認', 'select', 'auto', '', array( 'auto' => '必要かどうかAIと相談', 'required' => '最新情報の確認が必要', 'provided' => '今回使う情報は提供済み' ) ),
			'experience_need' => array( '本人の経験・意見', 'select', 'auto', '', array( 'auto' => '必要かどうかAIと相談', 'required' => 'ヒアリングしてほしい', 'provided' => '必要な内容は提供済み' ) ),
			'research_available' => array( '利用するChatGPTでの外部調査', 'select', 'unknown', '利用者が把握している範囲の情報です。利用可能でも調査完了とは扱いません。', array( 'unknown' => '不明・ChatGPT側で確認', 'yes' => '検索できる環境を使用予定', 'no' => '検索できない・提供資料を使う' ) ),
		);
	}

	/** Personal context is separate from article-purpose profiles and shared site data. */
	public static function author_fields(): array {
		return array(
			'public_name' => array( '記事で使う公開名', 'text', '', '本名でなくても構いません。WordPressのユーザー名・表示名は変更しません。' ),
			'trade_name' => array( '屋号・所属', 'text', '', '個人事業の屋号や、会社・団体での所属を入力します。' ),
			'role' => array( '役職・担当', 'text', '', 'サービスや活動で、自分が担当していることを入力します。' ),
			'service_summary' => array( '自分が提供・担当するサービス', 'textarea', '', 'どんなサービス・活動かを簡潔に。サイト全体の共通情報は「サイト共通設定」で管理します。' ),
			'service_audience' => array( 'サービスの対象者', 'textarea', '', 'どんな人の、どんな悩みや目的に対応するかを入力します。' ),
			'service_area' => array( '対応地域・対応範囲', 'text', '', '自分が対応できる地域や業務範囲です。' ),
			'specialties' => array( '専門分野・得意なこと', 'textarea', '', '' ),
			'experience' => array( '経験・具体例', 'textarea', '', '本人が実際に経験したことだけを入力します。今回の記事の出来事や感想は記事ごとに入力してください。' ),
			'credentials' => array( '確認できる資格・実績', 'textarea', '', '事実として確認できる内容だけを入力します。未取得の資格や予定の成果は含めません。' ),
			'values' => array( '大切にしている考え・仕事の進め方', 'textarea', '', '' ),
			'strengths' => array( '自分の特徴・強み', 'textarea', '', '具体的な工夫や違いを入力します。根拠のない優位性は書かないでください。' ),
			'first_person' => array( '一人称', 'text', '', '例：私、私たち。当サイトの文体ルールと合わせて使います。' ),
			'writing_style' => array( '文章の好み・読者との距離感', 'textarea', '', 'サイト共通の禁止事項や執筆ルールは引き続き適用されます。' ),
			'publication_notes' => array( '掲載時の注意事項', 'textarea', '', '例：取引先の名前は掲載しない。非公開の名前や情報そのものは書かず、避ける方針を入力してください。' ),
		);
	}

	public static function author_usage(): array {
		return array( 'exclude' => '使わない（この設定内だけで保存）', 'reference' => 'AIの参考のみ（記事に事実情報として掲載しない）', 'publish' => '記事への掲載可（必要な場合・原稿確認後）' );
	}

	public static function term_fields(): array {
		return array(
			'purpose' => array( 'カテゴリーの目的', 'textarea', '', '' ),
			'audience' => array( '想定読者', 'textarea', '', '' ),
			'include' => array( '掲載する記事の内容', 'textarea', '', '' ),
			'exclude' => array( '掲載しない内容', 'textarea', '', '' ),
			'direction' => array( '文章の方向性', 'textarea', '', '' ),
			'interview' => array( '必要なヒアリング項目', 'textarea', '', '' ),
			'recommended_profile' => array( '推奨記事の種類', 'profile', '', '選択の参考情報です。利用者が最終決定します。' ),
			'instructions' => array( 'AIへの追加指示', 'textarea', '', '' ),
		);
	}

	public static function presets(): array {
		return array(
			array( 'name' => 'お知らせ・告知', 'purpose' => '事実を簡潔に伝える', 'headings' => 'none', 'structure' => '必要な情報だけ。不要な導入やまとめは作らない。', 'interview' => '日時・場所・対象者・必要な行動', 'images' => '画像なしでも成立させる。' ),
			array( 'name' => '一般ブログ・コラム', 'purpose' => '読者に分かりやすく伝える', 'headings' => 'standard', 'interview' => '伝えたい内容・読者・具体例', 'images' => '必要に応じて画像を提案する。' ),
			array( 'name' => 'SEO記事', 'purpose' => '検索意図を満たし読者の疑問を解決する', 'headings' => 'detailed', 'structure' => '導入文、必要に応じてFAQ・具体例・まとめ', 'interview' => '検索意図・独自情報・根拠・読者の悩み', 'instructions' => 'キーワードを詰め込まない。文字数や見出し数のためだけに説明を増やさない。' ),
			array( 'name' => '活動報告・施工事例', 'purpose' => '実際の活動や施工の経緯と結果を伝える', 'interview' => '日時・場所・経緯・実施内容・結果', 'images' => '提供写真を内容に合わせて配置する。' ),
			array( 'name' => 'イベント案内', 'purpose' => '参加に必要な情報を整理する', 'interview' => '開催日時・場所・対象者・参加方法・料金・締切', 'tables' => '確認できた情報で必要に応じて開催概要表を作る。', 'images' => '写真や案内PDFがあれば利用する。' ),
		);
	}
}
