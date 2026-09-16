<?php
/**
 *
 * Plugin Name: rAIven Connector
 * Description: Adds rAIven as an AlphaSys AI connector and provides a live admin test console.
 * Version: 0.2.2
 * Requires at least: 7.0
 * Requires PHP: 8.1
 * Update URI: https://github.com/cchatterton/raiven-connector
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: raiven-connector
 * Author: AlphaSys
 * Author URI: https://alphasys.com.au/
 *
 * Release Notes:
 * ==============
 * 0.1 20260606 CC: It begins
 *
 */

if (!defined('ABSPATH')) exit;

define('AS329_RAI_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('AS329_RAI_PLUGIN_FILE', __FILE__);
define('AS329_RAI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AS329_RAI_PLUGIN_URL', plugin_dir_url(__FILE__));

define('AS329_RAI_VERSION', '0.2.2');
define('AS329_RAI_PROVIDER_ID', 'raiven');
define('AS329_RAI_OPTION', 'as329_rai_settings');
define('AS329_RAI_POST_TYPE', 'as329_rai_session');
define('AS329_RAI_DEFAULT_BASE_URL', 'https://raiven.alphasys.com/proxy/v1');

/**
 * This is the expected native connector API key option.
 * If the WP build stores connector credentials differently, we can adjust this one place.
 */
define('AS329_RAI_NATIVE_KEY_OPTION', 'connectors_ai_raiven_api_key');

$dir = plugin_dir_path(__FILE__);

$functions = array(
	'connector.php',
	'logs.php',
	'console.php',
	'github-updater.php',
);

foreach ($functions as $function) {
	require($dir . 'functions/' . $function);
}
