<?php
/**
 * Libvips TIFF encoder.
 *
 * @package Imagination
 *
 * phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag
 */

declare( strict_types=1 );

namespace Indigit\Imagination\Encoder;

defined( 'ABSPATH' ) || exit;

use Indigit\Imagination\Vendor\Jcupitt\Vips\Image;

/**
 * TIFF-specific Libvips encoding behavior.
 */
final class Tiff implements Format {

	/**
	 * {@inheritdoc}
	 */
	public function resolve_quality(
		Encoding_Context $context
	): int {
		return $context->quality;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_options(
		Image $image,
		Encoding_Context $context
	): array {
		return [
			// @see https://www.libvips.org/API/current/method.Image.tiffsave.html
			'compression' => 'lzw',
		];
	}

	/**
	 * {@inheritdoc}
	 */
	public function supports_animation(): bool {
		return false;
	}
}
