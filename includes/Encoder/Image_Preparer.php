<?php
/**
 * Optional contract for formats that prepare the image right before saving.
 *
 * @package Imagination
 */

declare( strict_types=1 );

namespace Indigit\Imagination\Encoder;

defined( 'ABSPATH' ) || exit;

use Indigit\Imagination\Vendor\Jcupitt\Vips\Image;

/**
 * Implemented by a {@see Format} that transforms the image, or decides what
 * metadata it keeps, right before it is encoded: e.g. the lossless PNG
 * colour-type reduction and the PNG ICC-only metadata policy.
 *
 * Libvips_Encoder calls it for files, streams and buffers alike, before its
 * own metadata handling.
 */
interface Image_Preparer {

	/**
	 * Prepares an image for encoding.
	 *
	 * May change how pixels are stored and which metadata is kept, but not how
	 * the image looks unless documented otherwise. May return the given image.
	 *
	 * @param Image            $image   Image about to be encoded.
	 * @param Encoding_Context $context Encoding context.
	 * @return Prepared_Image
	 */
	public function prepare_image(
		Image $image,
		Encoding_Context $context
	): Prepared_Image;
}
