<?php
/**
 * Plugin Name:       NExT Wix2WP
 * Plugin URI:        https://github.com/AtsushiA/NExT-Wix2WP
 * Description:       WixブログをWordPressの投稿としてインポートするWP-CLIプラグイン
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            NExT-Season
 * Author URI:        https://next-season.net/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       next-wix2wp
 *
 * @package NExT_Wix2WP
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
