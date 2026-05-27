<?php
/**
 * Plugin Name: Карта магазинов
 * Description: Плагин для показа магазинов 3 фиксированных брендов с фильтрацией по бренду и городу, импортом из Excel и справкой по шорткодам.
 * Version: 1.1.0
 * Requires at least: 6.0
 * Author: Harvi Code
 * Text Domain: wordpress-shop-map
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WSM_VERSION', '1.1.0' );
define( 'WSM_FILE', __FILE__ );
define( 'WSM_PATH', plugin_dir_path( __FILE__ ) );
define( 'WSM_URL', plugin_dir_url( __FILE__ ) );

require_once WSM_PATH . 'includes/class-wsm-plugin.php';
require_once WSM_PATH . 'includes/class-wsm-importer.php';
require_once WSM_PATH . 'includes/class-wsm-admin.php';
require_once WSM_PATH . 'includes/class-wsm-frontend.php';
require_once WSM_PATH . 'includes/class-wsm-geocoder.php';

register_activation_hook( __FILE__, array( 'WSM_Plugin', 'activate' ) );

add_action( 'plugins_loaded', static function () {
	WSM_Plugin::instance()->init();
} );
