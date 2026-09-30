<?php
/**
 * Libvips JPEG encoder.
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
 * JPEG-specific Libvips encoding behavior.
 */
final class Jpeg implements Format {

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
			// @see https://www.libvips.org/API/current/method.Image.jpegsave.html
			'Q'               => $context->quality,
			'optimize_coding' => true,
			'interlace'       => (bool) apply_filters(
				'image_save_progressive', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
				false,
				$context->output_mime_type
			),
		];
	}

	/**
	 * {@inheritdoc}
	 */
	public function supports_animation(): bool {
		return false;
	}
}
