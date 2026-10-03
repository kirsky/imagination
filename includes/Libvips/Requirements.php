<?php
/**
 * Runtime requirements of the libvips editors.
 *
 * @package Imagination
 */

declare(strict_types=1);

namespace Indigit\Imagination\Libvips;

defined( 'ABSPATH' ) || exit;

use Indigit\Imagination\Vendor\Jcupitt\Vips\{Config, FFI, Image};

/**
 * Checks the runtime requirements of the libvips editors: the FFI extension,
 * enabled for the current SAPI, php-vips, and libvips MIN_LIBVIPS_VERSION or
 * newer. Also sets up libvips for the process.
 */
final class Requirements {

	/**
	 * Minimum supported libvips version.
	 */
	public const MIN_LIBVIPS_VERSION = '8.12.0';

	/**
	 * File name php-vips loads libvips from on Linux, used by
	 * keep_libvips_loaded() to find the libvips that php-vips loaded.
	 *
	 * The name comes from php-vips 2.6.1: "libvips" + ".so." + ABI version
	 * 42. It is also the SONAME of libvips 8.12.1, 8.13.3 and 8.16.1 (checked
	 * with `readelf -d`). When dlopen() gets a name without a slash, glibc
	 * compares it with the SONAME of each library that is already loaded, so
	 * this name finds libvips wherever php-vips loaded it from.
	 *
	 * @see https://github.com/libvips/php-vips/blob/v2.6.1/src/FFI.php#L236-L250 php-vips builds the file name.
	 * @see https://github.com/libvips/php-vips/blob/v2.6.1/src/FFI.php#L288 php-vips asks for "libvips" with ABI 42.
	 * @see https://sourceware.org/git/?p=glibc.git;a=blob;f=elf/dl-load.c;hb=refs/tags/glibc-2.35#l2026 glibc matches a loaded library by SONAME.
	 *
	 * @var string
	 */
	private const LIBVIPS_SONAME = 'libvips.so.42';

	/**
	 * Flags for dlopen() in keep_libvips_loaded(): RTLD_LAZY (0x1),
	 * RTLD_NOLOAD (0x4) and RTLD_NODELETE (0x1000).
	 *
	 * - RTLD_NOLOAD: do not load anything. dlopen() returns the handle of the
	 *   library when it is already loaded, and NULL when it is not.
	 * - RTLD_NODELETE: never unload the library, even when every handle to it
	 *   is closed. With RTLD_NOLOAD this flag is added to the library that is
	 *   already loaded.
	 * - RTLD_LAZY: dlopen() needs RTLD_LAZY or RTLD_NOW in every call. It has
	 *   no effect here, because nothing is loaded.
	 *
	 * PHP has no constants for these values. musl uses them on every
	 * architecture, and glibc too, except on MIPS. On MIPS, glibc uses 0x8 for
	 * RTLD_NOLOAD and 0x4 for RTLD_GLOBAL, so this value means RTLD_LAZY |
	 * RTLD_GLOBAL | RTLD_NODELETE there. The effect on a loaded libvips is the
	 * same, because PHP loads every FFI library with RTLD_GLOBAL anyway. The
	 * only difference: a libvips that is not loaded would be loaded.
	 *
	 * @see https://man7.org/linux/man-pages/man3/dlopen.3.html dlopen(3): RTLD_NOLOAD, RTLD_NODELETE, and "one of the following two values must be included".
	 * @see https://sourceware.org/git/?p=glibc.git;a=blob;f=bits/dlfcn.h;hb=refs/tags/glibc-2.35#l24 glibc values.
	 * @see https://sourceware.org/git/?p=glibc.git;a=blob;f=sysdeps/mips/bits/dlfcn.h;hb=refs/tags/glibc-2.35#l24 glibc values on MIPS, the only architecture with its own.
	 * @see https://github.com/php/php-src/blob/php-8.4.26/ext/ffi/ffi.c#L3036 FFI::cdef() loads a library with DL_LOAD, which includes RTLD_GLOBAL.
	 * @see https://git.musl-libc.org/cgit/musl/tree/include/dlfcn.h?h=v1.2.5#n10 musl values.
	 * @see https://sourceware.org/git/?p=glibc.git;a=blob;f=elf/dl-open.c;hb=refs/tags/glibc-2.35#l566 glibc adds RTLD_NODELETE to a library that is already loaded.
	 *
	 * @var int
	 */
	private const DLOPEN_KEEP_LOADED = 0x1005;

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
	 * initialization (the bootstrap in indigit-imagination.php sets it too).
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
	 * Keeps libvips loaded until the PHP process ends. Runs on `shutdown`.
	 *
	 * The problem, with libvips 8.12 in a PHP process that serves more than
	 * one request (a PHP-FPM worker, `php -S`):
	 *
	 * 1. php-vips keeps its FFI handles to libvips in static properties.
	 * 2. When a request ends, PHP frees them, and PHP's FFI calls dlclose()
	 *    on each handle. After the last one, the system unloads libvips.
	 * 3. GLib is not unloaded, because it is linked with `-z nodelete`. Its
	 *    type registry still holds the GObject types libvips registered.
	 * 4. In a later request, php-vips loads libvips again. A reloaded library
	 *    starts with fresh static variables, so libvips registers its types
	 *    again. GLib refuses ("cannot register existing type 'VipsObject'"),
	 *    and the request hangs or crashes.
	 *
	 * libvips 8.13.0 and later are linked with `-z nodelete`, so they are never
	 * unloaded. libvips 8.12 is not, and Ubuntu 22.04 LTS ships 8.12.1. This
	 * method marks the libvips that php-vips loaded with RTLD_NODELETE, which
	 * has the same effect as `-z nodelete`. On libvips 8.13+ the mark is
	 * already there, so nothing changes. musl never unloads a library, so
	 * nothing changes there either.
	 *
	 * Reproduced on Ubuntu 22.04 (PHP 8.1 PHP-FPM with one worker, libvips
	 * 8.12.1): the first request worked, the second hung until PHP-FPM killed
	 * the worker. With this method, every request works.
	 *
	 * It runs last on `shutdown` (priority PHP_INT_MAX), so it also marks a
	 * libvips that an earlier `shutdown` callback loaded for the first time.
	 * That is still in time: WordPress runs the `shutdown` action from a PHP
	 * shutdown function, and PHP frees php-vips' FFI objects only after every
	 * shutdown function. Not covered: a PHP shutdown function registered after
	 * WordPress', or a `shutdown` callback at PHP_INT_MAX added after this one,
	 * that is the first code in the request to load libvips.
	 *
	 * @see https://github.com/libvips/libvips/pull/2934 libvips 8.13.0 is linked with -z nodelete "to prevent unloading".
	 * @see https://github.com/libvips/libvips/commit/fa6c034b325eafcb9e6f6b3dc00244ac040c744f The libvips commit.
	 * @see https://github.com/libvips/php-vips-ext/issues/43#issuecomment-913139460 The libvips developers found that PHP unloading libvips breaks it.
	 * @see https://github.com/libvips/php-vips/blob/v2.6.1/src/FFI.php#L59-L73 php-vips keeps its FFI handles in static properties.
	 * @see https://github.com/php/php-src/blob/php-8.4.26/ext/ffi/ffi.c#L2436-L2447 PHP calls dlclose() when an FFI object is freed.
	 * @see https://gitlab.gnome.org/GNOME/glib/-/blob/2.72.4/meson.build#L492 GLib is linked with -z nodelete.
	 * @see https://man7.org/linux/man-pages/man3/dlopen.3.html dlopen(3), RTLD_NODELETE: without it, static variables are reinitialized when the library is loaded again.
	 * @see https://wiki.musl-libc.org/functional-differences-from-glibc.html musl, "Unloading libraries": dlclose is a no-op.
	 * @see https://github.com/WordPress/wordpress-develop/blob/7.1.2/src/wp-settings.php#L166 WordPress registers its shutdown function.
	 * @see https://github.com/WordPress/wordpress-develop/blob/7.1.2/src/wp-includes/load.php#L1302-L1311 That function fires the `shutdown` action.
	 * @see https://github.com/php/php-src/blob/php-8.4.26/main/main.c#L1907-L1952 PHP calls shutdown functions (step 1) before it frees static properties (step 9, zend_deactivate()).
	 *
	 * @return void
	 */
	public static function keep_libvips_loaded(): void {
		// DLOPEN_KEEP_LOADED holds the values of glibc and musl, the Linux C
		// libraries. They are not checked for other systems.
		if ( 'Linux' !== PHP_OS_FAMILY ) {
			return;
		}

		// php-vips loads this class on its first call into libvips. When it is
		// not loaded, php-vips did not load libvips in this request.
		if ( ! class_exists( FFI::class, false ) ) {
			return;
		}

		try {
			// Without a library name, PHP looks the functions up in the
			// libraries already loaded into the process (RTLD_DEFAULT).
			// dlopen() and dlclose() are always among them, because PHP itself
			// loads its extensions with dlopen().
			// See https://www.php.net/manual/en/ffi.cdef.php and
			// https://github.com/php/php-src/blob/php-8.4.26/Zend/zend_portability.h#L162-L169.
			$libc = \FFI::cdef(
				'void *dlopen( const char *filename, int flags ); int dlclose( void *handle );'
			);

			// Finds the libvips that is already loaded and marks it
			// RTLD_NODELETE. Returns NULL, and loads nothing, when libvips is
			// not loaded. FFI returns a NULL pointer as PHP null.
			$handle = Native::call(
				$libc,
				'dlopen',
				self::LIBVIPS_SONAME,
				self::DLOPEN_KEEP_LOADED
			);

			// dlopen() added a reference to libvips. This releases it. The
			// RTLD_NODELETE mark stays, so libvips is still never unloaded.
			if ( null !== $handle ) {
				Native::call( $libc, 'dlclose', $handle );
			}
		} catch ( \Throwable $e ) {
			// FFI is disabled or missing. libvips then keeps its default
			// behaviour, and nothing breaks.
			return;
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
