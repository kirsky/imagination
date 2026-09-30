<?php
/**
 * Libvips WebP encoder.
 *
 * @package Imagination
 *
 * phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag
 */

declare( strict_types=1 );

namespace Indigit\Imagination\Encoder;

defined( 'ABSPATH' ) || exit;

use Indigit\Imagination\Vendor\Jcupitt\Vips\Image;

use function Indigit\Imagination\get_webp_info_precise;

/**
 * WebP-specific Libvips encoding behavior.
 */
final class Webp implements Format {

	/**
	 * {@inheritdoc}
	 *
	 * Mirrors core Imagick: WebP output from a lossless WebP source is saved
	 * losslessly, which WordPress reports as quality 100.
	 */
	public function resolve_quality(
		Encoding_Context $context
	): int {
		return $this->is_source_lossless( $context )
			? 100
			: $context->quality;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_options(
		Image $image,
		Encoding_Context $context
	): array {
		$lossless = $this->is_source_lossless( $context );

		return [
			// @see https://www.libvips.org/API/current/method.Image.webpsave.html
			'Q'        => $lossless ? 100 : $context->quality,
			'lossless' => $lossless,
			'effort'   => 4,
		];
	}

	/**
	 * {@inheritdoc}
	 */
	public function supports_animation(): bool {
		return true;
	}

	/**
	 * Determines whether the source is a lossless WebP.
	 *
	 * Uses get_webp_info_precise(): core's wp_get_webp_info() reports every
	 * VP8X file as 'animated-alpha' and misses lossless WebPs with metadata.
	 *
	 * @param Encoding_Context $context Encoding context.
	 * @return bool
	 */
	private function is_source_lossless(
		Encoding_Context $context
	): bool {
		if (
			'image/webp' !== $context->source_mime_type
			|| ! is_file( $context->source_file )
		) {
			return false;
		}

		return true === get_webp_info_precise(
			$context->source_file
		)['lossless'];
	}
}
