<?php

/**
 * Plugin Name: Knižnica Mamateyka
 * Description: Správa malej knižnice - verejný katalóg, rezervácie kníh a editor rozhranie pre knihovníčky.
 * Version: 1.93.0
 * Author: Mikulas Polak
 * License: Proprietary - All rights reserved
 * Text Domain: knizica-mamateyka
 * Domain Path: /languages
 * Requires PHP: 7.4
 */

if (! defined('ABSPATH')) {
	exit; // No direct access.
}

define('KM_VERSION', '1.93.0');
define('KM_DB_VERSION', '1.13.0');
define('KM_PLUGIN_FILE', __FILE__);
define('KM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('KM_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once KM_PLUGIN_DIR . 'includes/class-km-db.php';
require_once KM_PLUGIN_DIR . 'includes/class-km-roles.php';
require_once KM_PLUGIN_DIR . 'includes/class-km-settings.php';
require_once KM_PLUGIN_DIR . 'includes/class-km-activator.php';
require_once KM_PLUGIN_DIR . 'includes/class-km-deactivator.php';
require_once KM_PLUGIN_DIR . 'includes/class-km-import-export.php';
require_once KM_PLUGIN_DIR . 'includes/class-km-rest-books.php';
require_once KM_PLUGIN_DIR . 'includes/class-km-rest-categories.php';
require_once KM_PLUGIN_DIR . 'includes/class-km-rest-reservations.php';
require_once KM_PLUGIN_DIR . 'includes/class-km-rest-auth.php';
require_once KM_PLUGIN_DIR . 'public/class-km-shortcodes.php';

if (is_admin()) {
	require_once KM_PLUGIN_DIR . 'admin/class-km-admin.php';
}

register_activation_hook(__FILE__, array('KM_Activator', 'activate'));
register_deactivation_hook(__FILE__, array('KM_Deactivator', 'deactivate'));

add_action('plugins_loaded', array('KM_DB', 'upgrade'));
add_action('plugins_loaded', array('KM_Roles', 'add_roles'), 11);

add_action('plugins_loaded', 'km_load_textdomain');
function km_load_textdomain()
{
	load_plugin_textdomain('knizica-mamateyka', false, dirname(plugin_basename(KM_PLUGIN_FILE)) . '/languages');
}

add_action('init', array('KM_Shortcodes', 'register'));

add_action('rest_api_init', function () {
	KM_REST_Books::register_routes();
	KM_REST_Categories::register_routes();
	KM_REST_Reservations::register_routes();
	KM_REST_Auth::register_routes();
});

if (is_admin()) {
	add_action('init', array('KM_Admin', 'init'));
}
