<?php
/**
 * Plugin Name: AI Article Assistant
 * Plugin URI: https://github.com/cni-works/ai-article-assistant
 * Update URI: https://github.com/cni-works/ai-article-assistant
 * Description: ChatGPTとの手動相談による記事制作を支援します。相談・CSV出力プロンプトと、新規下書きへのCSV取り込み。
 * Version: 0.1.0
 * Requires at least: 6.3
 * Requires PHP: 8.0
 * License: GPL-2.0-or-later
 * Text Domain: ai-article-assistant
 */

namespace AAA;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AAA_VERSION', '0.1.0' );
define( 'AAA_FILE', __FILE__ );

require_once __DIR__ . '/includes/class-config.php';
require_once __DIR__ . '/includes/class-updater.php';
require_once __DIR__ . '/includes/class-operation-lock.php';
require_once __DIR__ . '/includes/class-profile-runtime.php';
require_once __DIR__ . '/includes/class-store.php';
require_once __DIR__ . '/includes/class-csv.php';
require_once __DIR__ . '/includes/class-blocks.php';
require_once __DIR__ . '/includes/class-media.php';
require_once __DIR__ . '/includes/class-placeholder.php';
require_once __DIR__ . '/includes/class-media-screen.php';
require_once __DIR__ . '/includes/class-acf.php';
require_once __DIR__ . '/includes/class-acf-screen.php';
require_once __DIR__ . '/includes/class-csv-validator.php';
require_once __DIR__ . '/includes/class-importer.php';
require_once __DIR__ . '/includes/class-draft-update.php';
require_once __DIR__ . '/includes/class-update-screen.php';
require_once __DIR__ . '/includes/class-import-screen.php';
require_once __DIR__ . '/includes/class-consultation.php';
require_once __DIR__ . '/includes/class-prompt.php';
require_once __DIR__ . '/includes/class-views.php';
require_once __DIR__ . '/includes/class-transfer.php';
require_once __DIR__ . '/includes/class-transfer-screen.php';
require_once __DIR__ . '/includes/class-term-creation.php';
require_once __DIR__ . '/includes/class-setup.php';
require_once __DIR__ . '/includes/class-setup-screen.php';
require_once __DIR__ . '/includes/class-admin-setup.php';
require_once __DIR__ . '/includes/class-reference.php';
require_once __DIR__ . '/includes/class-reference-screen.php';
require_once __DIR__ . '/includes/class-admin.php';

if ( function_exists( 'register_activation_hook' ) ) { register_activation_hook( __FILE__, array( Placeholder::class, 'activate' ) ); }
add_action( 'init', array( Updater::class, 'boot' ), 0 );
add_action( 'plugins_loaded', static function () {
	new Reference();
	if ( is_admin() ) {
		new Admin();
	}
} );
