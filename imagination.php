<?php
/**
 * Plugin Name:       Imagination
 * Plugin URI:        https://indigit.info/imagination/
 * Description:       Runs WordPress image processing through libvips, a fast multi-threaded image library.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Kirill S.
 * Author URI:        https://indigit.info
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       imagination
 *
 * @package           Imagination
 */

namespace Indigit\Imagination;

defined( 'ABSPATH' ) || exit;

if ( ! is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}

			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__(
					'Imagination cannot start because its vendor folder is missing. Install the release ZIP, or run "composer install" in the plugin folder.',
					'imagination'
				)
			);
		}
	);

	return;
}

require __DIR__ . '/vendor/autoload.php';

/*
 * Block libvips' untrusted loaders (ImageMagick, JPEG XL, ...). libvips
 * reads this once, when it is first initialized in the process, so it is set
 * here, before any php-vips call. See Image_Editor_Libvips::init_libvips().
 */
if ( false === getenv( 'VIPS_BLOCK_UNTRUSTED' ) ) {
	putenv( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
		'VIPS_BLOCK_UNTRUSTED=1'
	);
}

define( 'IMAGINATION_VERSION', '1.0.0' );

/**
 * Gets the plugin's instance.
 *
 * @return Main
 */
function get_imagination_instance(): Main {
	static $instance = null;

	if ( null === $instance ) {
		$instance = new Main();
	}

	return $instance;
}

get_imagination_instance()->init_hooks();

Source_Cache::init();

register_activation_hook(
	__FILE__,
	[ get_imagination_instance(), 'activation' ]
);
register_deactivation_hook(
	__FILE__,
	[ get_imagination_instance(), 'deactivation' ]
);
