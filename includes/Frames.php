<?php
/**
 * Frame (page) helpers for animated and multi-page libvips images.
 *
 * @package Imagination
 */

declare(strict_types=1);

namespace Indigit\Imagination;

defined( 'ABSPATH' ) || exit;

use Indigit\Imagination\Vendor\Jcupitt\Vips\Image;

/**
 * Libvips holds an animated/multi-page image as one tall strip of frames with
 * the frame height in `page-height`. Frame counts come from `page-height`,
 * never from `n-pages`.
 *
 * @see https://www.libvips.org/API/current/multipage-and-animated-images.html
 */
final class Frames {

	/**
	 * Gets the height of a single frame.
	 *
	 * @param Image $image Libvips image.
	 * @return int Frame height, or the full image height for single-frame images.
	 */
	public static function page_height( Image $image ): int {
		if ( 0 === $image->getType( 'page-height' ) ) {
			return $image->height;
		}

		$page_height = (int) $image->get( 'page-height' );

		if (
			$page_height < 1
			|| $page_height > $image->height
			|| 0 !== $image->height % $page_height
		) {
			return $image->height;
		}

		return $page_height;
	}

	/**
	 * Gets the number of frames actually present in the image.
	 *
	 * @param Image $image Libvips image.
	 * @return int
	 */
	public static function count( Image $image ): int {
		return intdiv( $image->height, self::page_height( $image ) );
	}

	/**
	 * Whether the image holds more than one frame.
	 *
	 * @param Image $image Libvips image.
	 * @return bool
	 */
	public static function is_multi_frame( Image $image ): bool {
		return self::count( $image ) > 1;
	}

	/**
	 * Applies an operation to every frame independently and rebuilds the strip.
	 *
	 * Frames are processed separately so kernels do not bleed across frame
	 * boundaries. Metadata (`delay`, `loop`, ICC, ...) is inherited from the
	 * source.
	 *
	 * @param Image    $image    Source image.
	 * @param callable $callback Receives an Image (one frame), returns an Image.
	 *                           Must return frames of identical dimensions.
	 * @return Image
	 * @throws \RuntimeException If the callback returns frames of different sizes.
	 */
	public static function map( Image $image, callable $callback ): Image {
		$count = self::count( $image );

		if ( $count < 2 ) {
			return $callback( $image );
		}

		// Cutting frames needs random access.
		$image = $image->copyMemory();

		$page_height = self::page_height( $image );
		$frames      = [];

		for ( $i = 0; $i < $count; $i++ ) {
			$frame = $callback(
				$image->crop( 0, $i * $page_height, $image->width, $page_height )
			);

			if (
				isset( $frames[0] )
				&& (
					$frame->width !== $frames[0]->width
					|| $frame->height !== $frames[0]->height
				)
			) {
				throw new \RuntimeException(
					'Frame operation produced frames of different sizes.'
				);
			}

			$frames[] = $frame;
		}

		$joined = Image::arrayjoin( $frames, [ 'across' => 1 ] )->copy();
		$joined->set( 'page-height', $frames[0]->height );
		$joined->set( 'n-pages', $count );

		return $joined;
	}

	/**
	 * Reduces an image to its first frame.
	 *
	 * Needed before saving to formats that cannot animate: their savers ignore
	 * `page-height` and would write the whole strip.
	 *
	 * @param Image $image Libvips image.
	 * @return Image
	 */
	public static function first( Image $image ): Image {
		if ( ! self::is_multi_frame( $image ) ) {
			return $image;
		}

		$first = $image->crop(
			0,
			0,
			$image->width,
			self::page_height( $image )
		)->copy();
		$first->remove( 'page-height' );
		$first->set( 'n-pages', 1 );

		return $first;
	}
}
