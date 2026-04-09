<?php
/**
 * Plugin Name: NExT Wix2WP
 * Plugin URI:  https://github.com/
 * Description: WixブログをWordPressの投稿としてインポートするWP-CLIプラグイン
 * Version:     1.0.0
 * Author:      NExT
 * License:     GPL-2.0-or-later
 * Text Domain: next-wix2wp
 */

defined( 'ABSPATH' ) || exit;

define( 'NEXT_WIX2WP_VERSION', '1.0.0' );
define( 'NEXT_WIX2WP_DIR', plugin_dir_path( __FILE__ ) );

require_once NEXT_WIX2WP_DIR . 'includes/class-wix-api.php';
require_once NEXT_WIX2WP_DIR . 'includes/class-image.php';
require_once NEXT_WIX2WP_DIR . 'includes/class-converter.php';
require_once NEXT_WIX2WP_DIR . 'includes/class-importer.php';
require_once NEXT_WIX2WP_DIR . 'admin/class-admin-page.php';

if ( is_admin() ) {
	new NExT_Wix2WP_Admin();
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once NEXT_WIX2WP_DIR . 'cli/class-cli-command.php';
	WP_CLI::add_command( 'wix2wp', 'NExT_Wix2WP_CLI_Command' );
}
