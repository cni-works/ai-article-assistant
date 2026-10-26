<?php
namespace AAA;
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** GitHub stable-release assets only; installation and rollback remain WordPress core's job. */
final class Updater {
	const REPOSITORY = 'https://github.com/cni-works/ai-article-assistant';
	const ASSET = 'ai-article-assistant.zip';
	private static $checker = null;
	private static $attempted = false;

	/** Run on init for admin, front-end, WP-Cron and WP-CLI, without making an immediate HTTP request. */
	public static function boot(): void {
		if ( self::$attempted ) { return; }
		self::$attempted = true;
		try {
			$loader = dirname( __DIR__ ) . '/vendor/plugin-update-checker/plugin-update-checker.php';
			if ( ! is_readable( $loader ) ) { throw new \RuntimeException( 'Updater library unavailable' ); }
			require_once $loader;
			if ( apply_filters( 'puc_is_slug_in_use-' . Config::SLUG, false ) ) { throw new \RuntimeException( 'Updater slug already registered' ); }
			$api = new \YahnisElsts\PluginUpdateChecker\v5p7\Vcs\GitHubApi( self::REPOSITORY );
			// Require the exact deployable ZIP. Never substitute GitHub's source archive.
			$api->enableReleaseAssets( '/^ai-article-assistant\.zip$/D', $api::REQUIRE_RELEASE_ASSETS );
			$api->setReleaseFilter( array( self::class, 'release_allowed' ), $api::RELEASE_FILTER_SKIP_PRERELEASE, 1 );
			self::$checker = new \YahnisElsts\PluginUpdateChecker\v5p7\Vcs\PluginUpdateChecker( $api, AAA_FILE, Config::SLUG, 12, 'aaa_github_updates_v1' );
			self::$checker->setBranch( 'main' );
			self::$checker->addFilter( 'vcs_update_detection_strategies', array( self::class, 'release_only' ) );
			self::$checker->addFilter( 'request_info_result', array( self::class, 'validate_info' ) );
			// Do not call allowAutoupdateField() or force the core auto_update_plugin filter.
		} catch ( \Throwable $error ) {
			// An unavailable updater must not disable article editing or expose exception details.
			add_action( 'admin_notices', array( self::class, 'unavailable_notice' ) );
		}
	}
	public static function release_only( array $strategies ): array {
		return isset( $strategies['latest_release'] ) ? array( 'latest_release' => $strategies['latest_release'] ) : array();
	}
	public static function release_allowed( $version, $release ): bool {
		if ( ! is_string( $version ) || ! preg_match( '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/D', $version ) || ! is_object( $release ) || ( $release->tag_name ?? null ) !== 'v' . $version || ! empty( $release->draft ) || ! empty( $release->prerelease ) || ! is_array( $release->assets ?? null ) ) { return false; }
		$assets = array_filter( $release->assets, static function ( $asset ) { return is_object( $asset ) && self::ASSET === ( $asset->name ?? null ); } );
		if ( count( $assets ) !== 1 ) { return false; }
		$asset = reset( $assets );
		return 'uploaded' === ( $asset->state ?? null ) && is_numeric( $asset->size ?? null ) && $asset->size > 0 && self::download_url( $version ) === ( $asset->browser_download_url ?? null );
	}
	private static function download_url( string $version ): string { return self::REPOSITORY . '/releases/download/v' . $version . '/' . self::ASSET; }
	/** Header/readme and Release version must agree; mismatches are not installable updates. */
	public static function validate_info( $info ) {
		if ( ! is_object( $info ) || ! is_string( $info->version ?? null ) || ! preg_match( '/^[0-9]+\.[0-9]+\.[0-9]+$/D', $info->version ) || self::download_url( $info->version ) !== ( $info->download_url ?? null ) ) { return null; }
		return $info;
	}
	public static function unavailable_notice(): void {
		if ( ! current_user_can( 'update_plugins' ) ) { return; }
		echo '<div class="notice notice-warning"><p>' . esc_html( 'AI記事作成サポートの更新機構を読み込めませんでした。記事制作は引き続き利用できます。検証済みの配布ZIPからプラグインを再インストールして確認してください。' ) . '</p></div>';
	}
}
