<?php
/**
 * Plugin Name:       Razuna DAM
 * Plugin URI:        https://razuna.com/integrations/wordpress
 * Description:        Browse, search, and embed your Razuna digital assets directly from WordPress. Connects to Razuna over OAuth; published images are served from Razuna via durable direct links (no duplication).
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Razuna
 * Author URI:        https://razuna.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       razuna
 *
 * @package Razuna
 */

namespace Razuna;

defined( 'ABSPATH' ) || exit;

define( 'RAZUNA_VERSION', '0.1.0' );
define( 'RAZUNA_PLUGIN_FILE', __FILE__ );
define( 'RAZUNA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'RAZUNA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'RAZUNA_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Lightweight, dependency-free includes (no composer install required to run).
require_once RAZUNA_PLUGIN_DIR . 'includes/class-crypto.php';
require_once RAZUNA_PLUGIN_DIR . 'includes/class-settings.php';
require_once RAZUNA_PLUGIN_DIR . 'includes/class-oauth.php';
require_once RAZUNA_PLUGIN_DIR . 'includes/class-api.php';
require_once RAZUNA_PLUGIN_DIR . 'includes/class-rest.php';
require_once RAZUNA_PLUGIN_DIR . 'includes/class-block.php';
require_once RAZUNA_PLUGIN_DIR . 'includes/class-plugin.php';

/**
 * Boot the plugin once all plugins are loaded.
 */
function bootstrap() {
	Plugin::instance();
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\bootstrap' );

// Flush rewrite rules on activation/deactivation (the OAuth client-metadata
// endpoint registers a rewrite tag/rule).
register_activation_hook( __FILE__, array( __NAMESPACE__ . '\\Plugin', 'on_activate' ) );
register_deactivation_hook( __FILE__, array( __NAMESPACE__ . '\\Plugin', 'on_deactivate' ) );
