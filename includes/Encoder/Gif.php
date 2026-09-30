<?php
/**
 * Libvips GIF encoder.
 *
 * @package Imagination
 *
 * phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag
 */

declare( strict_types=1 );

namespace Indigit\Imagination\Encoder;

defined( 'ABSPATH' ) || exit;

use Indigit\Imagination\Vendor\Jcupitt\Vips\{
	BandFormat,
	Config,
	Image,
	Interpretation
};

/**
 * GIF-specific Libvips encoding behavior.
 */
final class Gif implements Format, Image_Preparer {

	/** Default quantiser quality of a single-frame GIF. */
	private const PALETTE_QUALITY = 91;

	/** Largest image (in pixels) quantised through a palette PNG. */
	private const MAX_PALETTE_PIXELS = 4194304;

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
		// @see https://www.libvips.org/API/current/method.Image.gifsave.html
		$options = [
			'effort' => 10,
		];

		// gifsave gained `interlace` in libvips 8.14.
		if ( Config::atLeast( 8, 14 ) ) {
			$options['interlace'] = (bool) apply_filters(
				'image_save_progressive', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
				false,
				$context->output_mime_type
			);
		}

		return $options;
	}

	/**
	 * {@inheritdoc}
	 */
	public function supports_animation(): bool {
		return true;
	}

	/**
	 * Quantises a single-frame image at the configured quality before it is
	 * saved, because gifsave has no quality option.
	 * Animations, other image types, large images and libvips builds without
	 * palette support are saved as they are.
	 */
	public function prepare_image(
		Image $image,
		Encoding_Context $context
	): Prepared_Image {
		$quality = $this->get_palette_quality( $context );

		if (
			$quality >= 100
			|| 0 !== $image->getType( 'page-height' )
			|| BandFormat::UCHAR !== $image->format
			|| ! in_array( $image->bands, [ 3, 4 ], true )
			|| Interpretation::SRGB !== $image->interpretation
			|| $image->width * $image->height > self::MAX_PALETTE_PIXELS
			|| ! Png_Palette::is_supported()
		) {
			return new Prepared_Image( $image );
		}

		try {
			$png = $image->pngsave_buffer(
				[
					'palette'     => true,
					'Q'           => $quality,
					'effort'      => 4,
					'dither'      => 1.0,
					'compression' => 1,
				]
			);

			$quantised = Image::newFromBuffer( $png )->copyMemory();
		} catch ( \Throwable $e ) {
			return new Prepared_Image( $image );
		}

		return new Prepared_Image( $quantised, [ 'dither' => 0.0 ] );
	}

	/**
	 * Gets the quantiser quality for single-frame GIFs.
	 *
	 * @param Encoding_Context $context Encoding context.
	 * @return int 1-100; 100 saves with gifsave alone.
	 */
	private function get_palette_quality( Encoding_Context $context ): int {
		/**
		 * Filters the quantiser quality of single-frame GIF output (1-100).
		 *
		 * Lower values give smaller files at lower quality; 100 skips the
		 * extra quantisation. Animated GIFs are not affected.
		 *
		 * @param int    $quality     Quality. Default 91.
		 * @param string $source_mime Source image MIME type.
		 */
		$quality = (int) apply_filters(
			'imagination_gif_palette_quality',
			self::PALETTE_QUALITY,
			$context->source_mime_type
		);

		return max( 1, min( 100, $quality ) );
	}
}
