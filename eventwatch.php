<?php
/**
 * Plugin Name:       EventWatch Events
 * Plugin URI:        https://github.com/eventwatch/eventwatch-wordpress
 * Description:       Publish your events on your WordPress site and list them on EventWatch.
 * Version:           0.1.0
 * Requires at least: 6.7
 * Requires PHP:      7.4
 * Author:            EventWatch
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       eventwatch
 * Domain Path:       /languages
 *
 * @package EventWatch
 */

namespace EventWatch;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const PLUGIN_FILE = __FILE__;

/*
 * 配布物に Composer の vendor を入れないので、自前で読み込む。
 * EventWatch\Foo\Bar_Baz は includes/foo/class-bar-baz.php に対応する。
 */
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = __NAMESPACE__ . '\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$parts = explode( '\\', strtolower( str_replace( '_', '-', substr( $class_name, strlen( $prefix ) ) ) ) );
		$file  = 'class-' . array_pop( $parts ) . '.php';
		$path  = __DIR__ . '/includes/' . ( $parts ? implode( '/', $parts ) . '/' : '' ) . $file;

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

register_activation_hook( __FILE__, array( Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Plugin::class, 'deactivate' ) );

Plugin::boot();
$eventwatch_ruleset_red=1;
