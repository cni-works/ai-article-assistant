<?php
namespace AAA;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Admin {
	public function __construct() {
		new ImportScreen();
		new Placeholder();
		new MediaScreen();
		new AcfScreen();
		new UpdateScreen();
		new TransferScreen();
		new SetupScreen();
		new ReferenceScreen();
		add_action( 'admin_init', array( Store::class, 'initialize' ) );
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_aaa_save', array( $this, 'save' ) );
	}

	public static function pages(): array {
		return array( '' => '記事を作る', 'help' => '使い方', 'setup' => '管理者設定', 'placeholders' => 'ダミー画像・仮配置', 'import' => '原稿を取り込む', 'update' => '下書きの更新', 'personal' => '自分のプロフィール', 'profiles' => '記事の種類', 'destinations' => 'カテゴリー・投稿先設定', 'media' => '画像・PDF管理', 'acf' => 'ACF連携', 'references' => '既存記事の確認', 'transfer' => '設定の移行', 'settings' => 'サイト・事業情報', 'quality' => '記事品質の共通ルール' );
	}

	public static function admin_pages(): array {
		return array( 'setup', 'settings', 'quality', 'profiles', 'destinations', 'acf', 'transfer', 'placeholders' );
	}

	public static function url( string $page = '', array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => Config::SLUG . ( $page ? '-' . $page : '' ) ), $args ), admin_url( 'admin.php' ) );
	}

	public function menu(): void {
		if ( ! current_user_can( 'manage_options' ) && ! Store::writable_types() ) {
			return;
		}
		add_menu_page( Config::name(), Config::name(), 'read', Config::SLUG, array( $this, 'render' ), 'dashicons-edit-page', 26 );
		foreach ( self::pages() as $page => $title ) {
			$cap = in_array( $page, self::admin_pages(), true ) ? 'manage_options' : 'read';
			// Hidden routes need a discoverable parent matching the registered page hook.
			$parent = in_array( $page, array( '', 'help', 'setup' ), true ) ? Config::SLUG : 'admin.php';
			add_submenu_page( $parent, $title, $title, $cap, Config::SLUG . ( $page ? '-' . $page : '' ), array( $this, 'render' ) );
		}
	}

	public function assets(): void {
		$page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$allowed = array_map( static function ( $key ) { return Config::SLUG . ( $key ? '-' . $key : '' ); }, array_keys( self::pages() ) );
		if ( ! in_array( $page, $allowed, true ) ) {
			return;
		}
		$base = dirname( AAA_FILE ) . '/assets/';
		if ( in_array( $page, array( Config::SLUG . '-media', Config::SLUG . '-import', Config::SLUG . '-placeholders' ), true ) && current_user_can( 'upload_files' ) ) { wp_enqueue_media(); }
		$css_version = is_file( $base . 'admin.css' ) ? AAA_VERSION . '.' . filemtime( $base . 'admin.css' ) : AAA_VERSION;
		$js_version = is_file( $base . 'admin.js' ) ? AAA_VERSION . '.' . filemtime( $base . 'admin.js' ) : AAA_VERSION;
		wp_enqueue_style( 'aaa-admin', plugins_url( 'assets/admin.css', AAA_FILE ), array(), $css_version );
		wp_enqueue_script( 'aaa-admin', plugins_url( 'assets/admin.js', AAA_FILE ), array(), $js_version, true );
		$profiles_version = AAA_VERSION . '.' . filemtime( $base . 'profiles.js' );
		wp_enqueue_script( 'aaa-profiles', plugins_url( 'assets/profiles.js', AAA_FILE ), array( 'aaa-admin' ), $profiles_version, true );
	}

	public function render(): void {
		$page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : Config::SLUG;
		$key = Config::SLUG === $page ? '' : substr( $page, strlen( Config::SLUG ) + 1 );
		$pages = self::pages();
		if ( ! isset( $pages[ $key ] ) ) {
			wp_die( '画面が見つかりません。' );
		}
		$admin = in_array( $key, self::admin_pages(), true );
		if ( ! current_user_can( 'read' ) || ( $admin && ! current_user_can( 'manage_options' ) ) || ( ! $admin && ! current_user_can( 'manage_options' ) && ! Store::writable_types() ) ) {
			wp_die( 'この画面を利用する権限がありません。', '', array( 'response' => 403 ) );
		}
		echo '<div class="wrap aaa-admin"><h1>' . esc_html( Config::name() . ' — ' . $pages[ $key ] ) . '</h1>';
		if ( $admin ) {
			Views::setup_navigation( $key );
		} elseif ( '' !== $key ) {
			echo '<p><a href="' . esc_url( self::url() ) . '">← 記事を作るに戻る</a></p>';
		}
		if ( isset( $_GET['saved'] ) && '1' === $_GET['saved'] && ! isset( $_GET['prompt_pending'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>保存しました。</p></div>';
		}
		if ( isset( $_GET['generated'] ) && '1' === $_GET['generated'] ) {
			echo '<div class="notice notice-success is-dismissible"><p>相談文の準備ができました。コピーしてChatGPTへ貼り付けてください。</p></div>';
		}

		if ( 'setup' === $key ) {
			Views::setup();
		} elseif ( 'settings' === $key ) {
			Views::settings();
		} elseif ( 'quality' === $key ) {
			Views::quality();
		} elseif ( 'placeholders' === $key ) {
			Placeholder::render();
		} elseif ( 'personal' === $key ) {
			Views::personal();
		} elseif ( 'profiles' === $key ) {
			Views::profiles();
		} elseif ( 'destinations' === $key ) {
			Views::destinations();
		} elseif ( '' === $key ) {
			Views::article();
		} elseif ( 'import' === $key ) {
			ImportScreen::render();
		} elseif ( 'media' === $key ) {
			MediaScreen::render();
		} elseif ( 'acf' === $key ) {
			AcfScreen::render();
		} elseif ( 'transfer' === $key ) {
			TransferScreen::render();
		} elseif ( 'references' === $key ) {
			ReferenceScreen::render();
		} elseif ( 'update' === $key ) {
			UpdateScreen::render();
		} else {
			Views::help( $key );
		}
		echo '</div>';
	}

	private function fail( string $message ): void {
		wp_die( esc_html( $message ), '保存できませんでした', array( 'response' => 400, 'back_link' => true ) );
	}

	public function save(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			wp_die( 'POSTで操作してください。', '', array( 'response' => 405 ) );
		}
		check_admin_referer( 'aaa_save', 'aaa_nonce' );
		$raw = wp_unslash( $_POST );
		$operation = Store::text( $raw['operation'] ?? '' );
		if ( ! current_user_can( 'read' ) || ( ! in_array( $operation, array( 'article', 'author', 'generate_prompt' ), true ) && ! current_user_can( 'manage_options' ) ) ) {
			wp_die( '保存する権限がありません。', '', array( 'response' => 403 ) );
		}
		$data = $raw['data'] ?? array();
		if ( ! is_array( $data ) ) {
			$this->fail( '入力データの形式が正しくありません。' );
		}
		$page = '';
		$args = array( 'saved' => '1' );
		if ( 'generate_prompt' === $operation ) {
			$result = Prompt::generate( Store::text( $raw['fingerprint'] ?? '' ) );
			if ( is_wp_error( $result ) ) {
				$this->fail( $result->get_error_message() );
			}
			$args = array( 'step' => '2', 'generated' => '1' );
		} elseif ( 'author' === $operation ) {
			$result = Store::save_author( $data );
			if ( is_wp_error( $result ) ) {
				$this->fail( $result->get_error_message() );
			}
			$page = 'personal';
		} elseif ( in_array( $operation, array( 'site', 'site_info', 'quality' ), true ) ) {
			$fields = 'site_info' === $operation ? Config::site_info_fields() : ( 'quality' === $operation ? array_merge( Config::quality_fields(), Config::quality_legacy_fields(), Config::safety_fields() ) : Config::site_fields() );
			$site = Store::sanitize( Config::site_fields(), array_replace( Store::site(), array_intersect_key( $data, $fields ) ) );
			if ( is_wp_error( $site ) ) {
				$this->fail( $site->get_error_message() );
			}
			if ( ! Store::write_option( Config::SITE, $site ) ) {
				$this->fail( 'サイト共通設定を保存できませんでした。' );
			}
			$page = 'quality' === $operation ? 'quality' : 'settings';
		} elseif ( 'destinations' === $operation ) {
			$selected = $data['types'] ?? array();
			if ( ! is_array( $selected ) || array_filter( $selected, static function ( $value ) { return ! is_string( $value ); } ) || array_diff( $selected, array_keys( Store::types() ) ) ) {
				$this->fail( '利用できない投稿先が含まれています。' );
			}
			if ( ! Store::write_option( Config::DESTINATIONS, array_values( array_unique( $selected ) ) ) ) {
				$this->fail( '投稿先を保存できませんでした。' );
			}
			$page = 'destinations';
		} elseif ( 'term' === $operation ) {
			$taxonomy = Store::text( $raw['taxonomy'] ?? '' );
			$type = Store::text( $raw['post_type'] ?? '' );
			$id = Store::text( $raw['term_id'] ?? '' );
			$taxonomies = Store::taxonomies( $type );
			if ( ! ctype_digit( $id ) || ! isset( Store::types()[ $type ], $taxonomies[ $taxonomy ] ) || ! current_user_can( $taxonomies[ $taxonomy ]->cap->manage_terms ) || ! term_exists( (int) $id, $taxonomy ) ) {
				$this->fail( 'カテゴリー・タグが見つからないか、管理する権限がありません。' );
			}
			$context = Store::sanitize( Config::term_fields(), $data );
			if ( is_wp_error( $context ) ) {
				$this->fail( $context->get_error_message() );
			}
			$context['schema_version'] = 1;
			$result = update_term_meta( (int) $id, Config::TERM, wp_slash( $context ) );
			if ( is_wp_error( $result ) ) {
				$this->fail( $result->get_error_message() );
			}
			if ( get_term_meta( (int) $id, Config::TERM, true ) !== $context ) {
				$this->fail( 'カテゴリーの追加情報を保存できませんでした。' );
			}
			$page = 'destinations';
			$args += array( 'type' => $type, 'taxonomy' => $taxonomy, 'term_id' => (int) $id );
		} elseif ( in_array( $operation, array( 'profile', 'duplicate', 'delete' ), true ) ) {
			$profiles = Store::profiles();
			$id = Store::text( $raw['profile_id'] ?? '' );
			if ( '' !== $id && ! isset( $profiles[ $id ] ) ) {
				$this->fail( '記事の種類が見つかりません。画面を読み込み直してください。' );
			}
			if ( 'profile' === $operation ) {
				$profile = Store::sanitize( Config::profile_fields(), $data );
				if ( is_wp_error( $profile ) ) {
					$this->fail( $profile->get_error_message() );
				}
				if ( '' === trim( $profile['name'] ) ) {
					$this->fail( '記事の種類の名前を入力してください。' );
				}
				$id = $id ?: wp_generate_uuid4();
				$profiles[ $id ] = $profile + array( 'schema_version' => 1 );
			} elseif ( '' === $id ) {
				$this->fail( '操作対象の記事の種類を選んでください。' );
			} elseif ( 'delete' === $operation ) {
				unset( $profiles[ $id ] );
				$id = '';
			} else {
				$copy = $profiles[ $id ];
				$id = wp_generate_uuid4();
				$copy['name'] .= '（コピー）';
				$profiles[ $id ] = $copy;
			}
			if ( ! Store::write_option( Config::PROFILES, $profiles ) ) {
				$this->fail( '記事の種類を保存できませんでした。' );
			}
			$page = 'profiles';
			$args['profile_id'] = $id;
		} elseif ( 'article' === $operation ) {
			$result = Store::save_article( $data );
			if ( is_wp_error( $result ) ) {
				$this->fail( $result->get_error_message() );
			}
			$args['step'] = '1';
			if ( '1' === ( $raw['generate_after_save'] ?? '' ) ) {
				$prepared = Prompt::prepare();
				$generated = is_wp_error( $prepared ) ? $prepared : Prompt::generate( $prepared['fingerprint'] );
				$args[ is_wp_error( $generated ) ? 'prompt_pending' : 'generated' ] = '1';
				if ( ! is_wp_error( $generated ) ) { $args['step'] = '2'; unset( $args['saved'] ); }
			}
		} else {
			$this->fail( 'この操作には対応していません。' );
		}
		wp_safe_redirect( self::url( $page, $args ) );
		exit;
	}
}
