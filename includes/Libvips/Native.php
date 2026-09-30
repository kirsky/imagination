<?php
/**
 * C functions of the libraries loaded into this process.
 *
 * @package Imagination
 */

declare(strict_types=1);

namespace Indigit\Imagination\Libvips;

defined( 'ABSPATH' ) || exit;

use Indigit\Imagination\Vendor\Jcupitt\Vips\Config;

/**
 * Declares and calls C functions of libvips and the libraries loaded with it.
 */
final class Native {

	/**
	 * Declares C functions of libvips and of the libraries loaded with it
	 * (GLib, libheif), so that PHP can call them.
	 *
	 * The php-vips library declares only the libvips functions it uses. For
	 * other functions, this calls FFI::cdef() without a library name. PHP then
	 * looks each function up in every library already loaded into the process
	 * (RTLD_DEFAULT). php-vips loads libvips with RTLD_GLOBAL, so its
	 * functions are found there. That is why libvips is loaded first, with
	 * Config::version(). Functions of GLib and libheif are found the same way
	 * (checked with libvips 8.12 to 8.18).
	 *
	 * FFI::cdef() throws when a declared function is not in the process, for
	 * example heif_get_decoder_descriptors() before libheif 1.15. This method
	 * then returns null.
	 *
	 * @see https://www.php.net/manual/en/ffi.cdef.php Without a library, "platforms supporting RTLD_DEFAULT attempt to lookup symbols declared in code in the normal global scope".
	 * @see https://github.com/php/php-src/blob/php-8.4.26/ext/ffi/ffi.c#L3053-L3057 PHP uses RTLD_DEFAULT when no library is given.
	 * @see https://github.com/php/php-src/blob/php-8.4.26/ext/ffi/ffi.c#L3036 php-vips' FFI::cdef() calls load libvips with DL_LOAD...
	 * @see https://github.com/php/php-src/blob/php-8.4.26/Zend/zend_portability.h#L162-L169 ...which includes RTLD_GLOBAL.
	 * @see https://github.com/php/php-src/blob/php-8.4.26/ext/ffi/ffi.c#L3089 A missing function throws "Failed resolving C function".
	 *
	 * @param string $declarations C declarations.
	 * @return \FFI|null Null when libvips or one of the functions cannot be found.
	 */
	public static function declare_functions( string $declarations ): ?\FFI {
		try {
			Config::version();

			return \FFI::cdef( $declarations );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Calls a C function declared on an FFI instance.
	 *
	 * FFI turns each declared C function into a method of the instance, for
	 * example `$ffi->vips_concurrency_get()`. PHPStan and IDEs cannot see
	 * those methods and report them as undefined. A method name in a variable
	 * avoids that.
	 *
	 * @param \FFI   $ffi          Instance that declares the function.
	 * @param string $name         Function name.
	 * @param mixed  ...$arguments Arguments.
	 * @return mixed
	 */
	public static function call( \FFI $ffi, string $name, ...$arguments ) {
		return $ffi->$name( ...$arguments );
	}
}
