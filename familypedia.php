<?php
/**
 * Plugin Name: Familypedia
 * Plugin URI: https://github.com/akirk/familypedia
 * Description: Like Wikipedia, but private and just for your family — stories and photos for every relative, compatible with other family tree apps via GEDCOM.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Alex Kirk
 * License: GPL-2.0-or-later
 * Text Domain: familypedia
 *
 * @package Familypedia
 */

namespace Familypedia;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/vendor/autoload.php';

// Autoloader for plugin classes.
spl_autoload_register(
	function ( $class_name ) {
		$prefix = 'Familypedia\\';
		$len    = strlen( $prefix );
		if ( strncmp( $prefix, $class_name, $len ) !== 0 ) {
			return;
		}

		$file = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class_name, $len ) ) . '.php';
		if ( file_exists( $file ) ) {
			require $file;
		}
	}
);

add_action(
	'plugins_loaded',
	function () {
		load_plugin_textdomain( 'familypedia' );

		$app = new App();
		$app->init();
	}
);

register_activation_hook( __FILE__, array( __NAMESPACE__ . '\Main', 'activate_plugin' ) );
add_action( 'activate_blog', array( __NAMESPACE__ . '\Main', 'activate_plugin' ) );
add_action( 'wp_initialize_site', array( __NAMESPACE__ . '\Main', 'activate_for_blog' ) );

register_deactivation_hook(
	__FILE__,
	function () {
		flush_rewrite_rules();
	}
);
