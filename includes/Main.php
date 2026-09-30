<?php
/**
 * Main plugin class
 *
 * @package Indigit\Imagination
 * @since 1.0.0
 */

declare(strict_types=1);

namespace Indigit\Imagination;

defined( 'ABSPATH' ) || exit;

use Indigit\Imagination\Admin\{Settings, Site_Health};
use Indigit\Imagination\Libvips\{Capability_Cache, Requirements, Runtime};

/**
 * Main plugin class
 */
final class Main {

	/**
	 * Settings admin page.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Site Health integration.
	 *
	 * @var Site_Health
	 */
	private Site_Health $site_health;

	/**
	 * Construct
	 */
	public function __construct() {
		$options = new Options();
		$encoder = new Libvips_Encoder();

		$this->settings    = new Settings( $options );
		$this->site_health = new Site_Health( new Runtime( $encoder ) );
	}

	/**
	 * Wires up all plugin hooks.
	 */
	public function init_hooks(): void {
		$this->settings->register();
		$this->site_health->register();

		add_filter( 'wp_image_editors', [ $this, 'register_editor' ], 20 );
		add_action(
			'shutdown',
			[ Requirements::class, 'keep_libvips_loaded' ],
			PHP_INT_MAX
		);
	}

	/**
	 * Runs on plugin activation.
	 */
	public function activation(): void {
		add_option( Options::OPTION_NAME, [], '', false );
		wp_cache_delete( 'wp_image_editor_choose', 'image_editor' );
		delete_option( Capability_Cache::OPTION_NAME );
	}

	/**
	 * Runs on plugin deactivation.
	 */
	public function deactivation(): void {
		delete_option( Capability_Cache::OPTION_NAME );
	}

	/**
	 * Adds the libvips editors in front of the others when the PHP side of
	 * their requirements is met.
	 *
	 * @param string[] $image_editors Array of available image editor class names.
	 * @return string[]
	 */
	public function register_editor( $image_editors ): array {
		if ( ! Requirements::has_php_requirements() ) {
			return $image_editors;
		}

		array_unshift(
			$image_editors,
			__NAMESPACE__ . '\Image_Editor_Libvips_Heif',
			__NAMESPACE__ . '\Image_Editor_Libvips',
		);

		return array_unique( $image_editors );
	}
}
