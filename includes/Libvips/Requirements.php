<?php
/**
 * Runtime requirements of the libvips editors.
 *
 * @package Imagination
 */

declare(strict_types=1);

namespace Indigit\Imagination\Libvips;

defined( 'ABSPATH' ) || exit;

use Indigit\Imagination\Vendor\Jcupitt\Vips\{Config, Image};

/**
 * Checks the runtime requirements of the libvips editors: the FFI extension,
 * enabled for the current SAPI, php-vips, and libvips MIN_LIBVIPS_VERSION or
 * newer.
 */
final class Requirements {

	/**
	 * Minimum supported libvips version.
	 */
	public const MIN_LIBVIPS_VERSION = '8.12.0';

	/**
	 * Runtime requirements reported by get_unmet_requirement(): the FFI
	 * extension, its `ffi.enable` setting, the php-vips classes, a loadable
	 * libvips, and its version.
	 */
	public const REQUIREMENT_FFI_EXTENSION   = 'ffi_extension';
	public const REQUIREMENT_FFI_ENABLE      = 'ffi_enable';
	public const REQUIREMENT_PHP_VIPS        = 'php_vips';
	public const REQUIREMENT_LIBVIPS         = 'libvips';
	public const REQUIREMENT_LIBVIPS_VERSION = 'libvips_version';

	/**
	 * Gets the first requirement that is not met. The libvips part comes from
	 * the Capability_Cache and loads libvips only when it is not known there.
	 *
	 * @return string|null One of the REQUIREMENT_* constants, or null when all are met.
	 */
	public static function get_unmet_requirement(): ?string {
		$unmet = self::find_unmet_php_requirement();

		if ( null !== $unmet ) {
			return $unmet;
		}

		return Capability_Cache::get(
			'unmet',
			static function (): ?string {
				return self::find_unmet_libvips_requirement();
			}
		);
	}

	/**
	 * Whether the PHP side of the requirements is met: the FFI extension,
	 * enabled for the current SAPI, and php-vips. Does not load libvips.
	 *
	 * @return bool
	 */
	public static function has_php_requirements(): bool {
		return null === self::find_unmet_php_requirement();
	}

	/**
	 * Checks the libvips side of the requirements: libvips loads and is
	 * MIN_LIBVIPS_VERSION or newer. Initializes libvips.
	 *
	 * @return string|null One of the REQUIREMENT_* constants, or null when all are met.
	 */
	public static function find_unmet_libvips_requirement(): ?string {
		try {
			// Before the first Config call, which initializes libvips.
			self::init_libvips();

			$version = Config::version();
		} catch ( \Throwable $e ) {
			return self::REQUIREMENT_LIBVIPS;
		}

		if ( version_compare( $version, self::MIN_LIBVIPS_VERSION, '<' ) ) {
			return self::REQUIREMENT_LIBVIPS_VERSION;
		}

		return null;
	}

	/**
	 * One-time, process-wide libvips configuration.
	 *
	 * Blocks libvips' untrusted loaders (libvips 8.13+), next to the editor's
	 * loader allowlist. Uses Config::setBlockUntrusted() when php-vips has it,
	 * else the environment variable, which libvips reads once at
	 * initialization (the bootstrap in imagination.php sets it too).
	 *
	 * @return void
	 */
	public static function init_libvips(): void {
		static $initialized = false;

		if ( $initialized ) {
			return;
		}

		$initialized = true;

		if ( method_exists( Config::class, 'setBlockUntrusted' ) ) {
			Config::setBlockUntrusted( true );
			return;
		}

		if ( false === getenv( 'VIPS_BLOCK_UNTRUSTED' ) ) {
			putenv( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
				'VIPS_BLOCK_UNTRUSTED=1'
			);
		}
	}

	/**
	 * Checks the PHP side of the requirements.
	 *
	 * @return string|null One of the REQUIREMENT_* constants, or null when all are met.
	 */
	private static function find_unmet_php_requirement(): ?string {
		if ( ! extension_loaded( 'ffi' ) ) {
			return self::REQUIREMENT_FFI_EXTENSION;
		}

		// "On" in php.ini reads back as "1".
		if ( ! in_array( ini_get( 'ffi.enable' ), [ '1', 'true' ], true ) ) {
			return self::REQUIREMENT_FFI_ENABLE;
		}

		if ( ! class_exists( Image::class ) ) {
			return self::REQUIREMENT_PHP_VIPS;
		}

		return null;
	}
}
