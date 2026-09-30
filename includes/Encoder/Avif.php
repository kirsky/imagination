<?php
/**
 * Libvips AVIF encoder.
 *
 * @package Imagination
 *
 * phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag
 */

declare( strict_types=1 );

namespace Indigit\Imagination\Encoder;

defined( 'ABSPATH' ) || exit;

use Indigit\Imagination\Vendor\Jcupitt\Vips\{Image, ForeignHeifCompression};

/**
 * AVIF-specific Libvips encoding behavior.
 */
final class Avif implements Format {

	/**
	 * JPEG-equivalent WordPress quality => libvips AVIF `Q` anchor points.
	 *
	 * @see https://www.industrialempathy.com/posts/avif-webp-quality-settings/
	 */
	private const QUALITY_MAP = [
		50 => 48,
		60 => 51,
		70 => 56,
		80 => 64,
	];

	/**
	 * Default encoder effort.
	 *
	 * @see get_options()
	 */
	private const EFFORT = 3;

	/**
	 * {@inheritdoc}
	 *
	 * The JPEG-scale value is kept; it is mapped to AVIF `Q` in get_options().
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
		/**
		 * Filters the libvips AVIF `effort` (0-9). Higher is slower and smaller;
		 * 4 and above is several times slower than core's Imagick.
		 *
		 * @param int    $effort      AVIF effort. Default 3.
		 * @param string $source_mime Source image MIME type.
		 */
		$effort = (int) apply_filters(
			'imagination_avif_effort',
			self::EFFORT,
			$context->source_mime_type
		);

		return [
			// @see https://www.libvips.org/API/current/method.Image.heifsave.html
			'Q'           => $this->map_quality( $context ),
			'compression' => ForeignHeifCompression::AV1,
			'effort'      => max( 0, min( 9, $effort ) ),
		];
	}

	/**
	 * {@inheritdoc}
	 */
	public function supports_animation(): bool {
		return false;
	}

	/**
	 * Maps a WordPress (JPEG-scale) quality to a libvips AVIF `Q` value.
	 *
	 * Interpolates between the QUALITY_MAP anchors, extending the nearest
	 * segment outside them (WordPress' default 82 maps to 66).
	 *
	 * @param Encoding_Context $context Encoding context.
	 * @return int AVIF `Q` in the range 1-100.
	 */
	private function map_quality( Encoding_Context $context ): int {
		$quality = $context->quality;
		$points  = [];
		foreach ( self::QUALITY_MAP as $wp_quality => $avif_q ) {
			$points[] = [ $wp_quality, $avif_q ];
		}

		// Segment containing $quality, or the nearest end segment.
		$last = count( $points ) - 2;
		$seg  = 0;
		while ( $seg < $last && $quality > $points[ $seg + 1 ][0] ) {
			++$seg;
		}

		[ $x0, $y0 ] = $points[ $seg ];
		[ $x1, $y1 ] = $points[ $seg + 1 ];

		// Anchors are distinct; the guard only rules out a zero divisor.
		$span         = $x1 - $x0;
		$avif_quality = $y0;
		if ( 0 !== $span ) {
			$avif_quality = (int) round(
				$y0 + ( $quality - $x0 ) * ( $y1 - $y0 ) / $span
			);
		}

		/**
		 * Filters the libvips AVIF `Q` value derived from the WordPress quality.
		 *
		 * @param int    $avif_quality AVIF `Q` (1-100).
		 * @param int    $quality      WordPress (JPEG-scale) quality (1-100).
		 * @param string $source_mime  Source image MIME type.
		 */
		$avif_quality = (int) apply_filters(
			'imagination_avif_quality',
			$avif_quality,
			$quality,
			$context->source_mime_type
		);

		return max( 1, min( 100, $avif_quality ) );
	}
}
