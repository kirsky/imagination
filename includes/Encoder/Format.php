<?php
/**
 * Libvips encoder format contract.
 *
 * @package Imagination
 */

declare( strict_types=1 );

namespace Indigit\Imagination\Encoder;

defined( 'ABSPATH' ) || exit;

use Indigit\Imagination\Vendor\Jcupitt\Vips\Image;

/**
 * Contract for a format-specific Libvips encoder.
 */
interface Format {

	/**
	 * Resolves the effective quality for the source image.
	 *
	 * This allows a format to alter WordPress' quality value when the source
	 * format has special encoding semantics.
	 *
	 * @param Encoding_Context $context Encoding context.
	 * @return int
	 */
	public function resolve_quality(
		Encoding_Context $context
	): int;

	/**
	 * Builds Libvips saver options.
	 *
	 * @param Image            $image   Image being encoded.
	 * @param Encoding_Context $context Encoding context.
	 * @return array<string, mixed>
	 */
	public function get_options(
		Image $image,
		Encoding_Context $context
	): array;

	/**
	 * Whether the format can store animation (multiple frames).
	 *
	 * Multi-frame images are reduced to their first frame before being saved
	 * to formats that cannot.
	 *
	 * @return bool
	 */
	public function supports_animation(): bool;
}
