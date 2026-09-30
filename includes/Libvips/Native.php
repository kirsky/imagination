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
	 * Declares C functions of the libraries loaded into this process, after
	 * loading libvips.
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
	 * @param \FFI   $ffi          Instance that declares the function.
	 * @param string $name         Function name.
	 * @param mixed  ...$arguments Arguments.
	 * @return mixed
	 */
	public static function call( \FFI $ffi, string $name, ...$arguments ) {
		return $ffi->$name( ...$arguments );
	}
}
