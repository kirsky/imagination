<?php
/**
 * Libvips PNG encoder.
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
	ForeignKeep,
	ForeignPngFilter,
	Image,
	Intent,
	Interpretation
};

/**
 * PNG-specific Libvips encoding behavior.
 */
final class Png implements Format, Image_Preparer {

	/** Largest image (in pixels) analysed for colour-type reduction; it is held in memory. */
	private const MAX_REDUCTION_PIXELS = 33554432;

	/** Images up to this many pixels (300 x 300) skip the row filter trial: it would cost more than it saves. */
	private const MIN_FILTER_TRIAL_PIXELS = 90000;

	/** Row filter trial sample: strips spread over the image, and rows per strip. */
	private const FILTER_TRIAL_STRIPS     = 8;
	private const FILTER_TRIAL_STRIP_ROWS = 8;

	/** Deflate level of the trial encodes (fast); the saved file keeps its own level. */
	private const FILTER_TRIAL_COMPRESSION = 1;

	/**
	 * Results of classify_profile(): the profile changes greys or is not RGB,
	 * only greys look like sRGB, or greys and colours both do.
	 */
	private const PROFILE_DIFFERENT = 0;
	private const PROFILE_GREY_ONLY = 1;
	private const PROFILE_SRGB      = 2;

	/**
	 * Neutral greys the profile probe converts (tolerance 1 level).
	 *
	 * @var int[]
	 */
	private const PROFILE_PROBE_GREYS = [ 20, 64, 128, 192, 235 ];

	/**
	 * Saturated colours the profile probe converts (tolerance 2 levels).
	 * Interior values on purpose: pure primaries of wide gamuts clip to the
	 * same sRGB corners.
	 *
	 * @var array<int, int[]>
	 */
	private const PROFILE_PROBE_COLOURS = [
		[ 200, 60, 60 ],
		[ 60, 200, 60 ],
		[ 60, 60, 200 ],
		[ 200, 200, 60 ],
		[ 60, 200, 200 ],
		[ 200, 60, 200 ],
	];

	/**
	 * Result of classify_profile() per embedded profile (md5 of its bytes).
	 *
	 * @var array<string, int>
	 */
	private static array $profile_cache = [];

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
			// @see https://www.libvips.org/API/current/method.Image.pngsave.html
			'compression' => 7,
			'filter'      => ForeignPngFilter::ALL,
			'interlace'   => (bool) apply_filters(
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

	/**
	 * Prepares a PNG for encoding.
	 *
	 * Keeps only the ICC profile (dropping it when it is equivalent to sRGB
	 * and metadata is stripped), losslessly reduces grey RGB(A) to grey(+alpha),
	 * picks a palette where possible, and otherwise picks the row filter.
	 *
	 * @param Image            $image   Image about to be encoded.
	 * @param Encoding_Context $context Encoding context.
	 * @return Prepared_Image
	 */
	public function prepare_image(
		Image $image,
		Encoding_Context $context
	): Prepared_Image {
		$keep    = ForeignKeep::ICC;
		$profile = null;

		if (
			$this->is_rgb_image( $image ) &&
			0 !== $image->getType( 'icc-profile-data' )
		) {
			$profile = $this->classify_profile( $image );

			if ( self::PROFILE_SRGB === $profile && $context->strip_metadata ) {
				// A copy: remove() mutates the image, which the editor may still own.
				$image = $image->copy();
				$image->remove( 'icc-profile-data' );
				$profile = null;
			}
		}

		if ( ! $this->is_reduction_candidate( $image ) ) {
			if ( ! $this->is_filter_trial_candidate( $image ) ) {
				return new Prepared_Image( $image, [], $keep );
			}

			// The trial and the encoder both read the pixels.
			$image = $image->copyMemory();

			return new Prepared_Image(
				$image,
				$this->get_filter_options( $image, $context ),
				$keep
			);
		}

		// The analysis and the encoder both read every pixel: evaluate once.
		$image = $image->copyMemory();
		$grey  = $this->to_grey( $image, $profile );

		if ( null !== $grey ) {
			return new Prepared_Image(
				$grey,
				$this->get_filter_options( $grey, $context ),
				$keep
			);
		}

		return new Prepared_Image(
			$image,
			$this->get_palette_options( $image, $context )
				?? $this->get_filter_options( $image, $context ),
			$keep
		);
	}

	/**
	 * Whether the row filter trial runs for an image: 8-bit, larger than
	 * 300 x 300 and small enough to be held in memory.
	 *
	 * @param Image $image Image about to be encoded.
	 * @return bool
	 */
	private function is_filter_trial_candidate( Image $image ): bool {
		$pixels = $image->width * $image->height;

		return BandFormat::UCHAR === $image->format
			&& $pixels > self::MIN_FILTER_TRIAL_PIXELS
			&& $pixels <= self::MAX_REDUCTION_PIXELS;
	}

	/**
	 * Saver options with the row filter that compresses the image better.
	 *
	 * The adaptive filter (ALL) suits photos and gradients; flat graphics,
	 * text and screenshots are often much smaller unfiltered (NONE). A sample
	 * of rows is encoded both ways with fast deflate and the smaller one wins;
	 * ties keep ALL.
	 *
	 * @param Image            $image   Grey or truecolor image, in memory.
	 * @param Encoding_Context $context Encoding context.
	 * @return array<string, mixed> The filter override, or [] for the default (ALL).
	 */
	private function get_filter_options(
		Image $image,
		Encoding_Context $context
	): array {
		if ( ! $this->is_filter_trial_candidate( $image ) ) {
			return [];
		}

		try {
			$sample  = $this->get_filter_sample( $image );
			$options = array_merge(
				$this->get_options( $sample, $context ),
				[ 'compression' => self::FILTER_TRIAL_COMPRESSION ]
			);
			$none    = $sample->pngsave_buffer(
				array_merge( $options, [ 'filter' => ForeignPngFilter::NONE ] )
			);
			$all     = $sample->pngsave_buffer(
				array_merge( $options, [ 'filter' => ForeignPngFilter::ALL ] )
			);
		} catch ( \Throwable $e ) {
			return [];
		}

		return strlen( $none ) < strlen( $all )
			? [ 'filter' => ForeignPngFilter::NONE ]
			: [];
	}

	/**
	 * Rows the filter trial encodes: strips spread evenly from top to
	 * bottom, or the whole image when it is short.
	 *
	 * @param Image $image Image, in memory.
	 * @return Image
	 */
	private function get_filter_sample( Image $image ): Image {
		$strips = self::FILTER_TRIAL_STRIPS;
		$rows   = self::FILTER_TRIAL_STRIP_ROWS;

		if ( $image->height <= 2 * $strips * $rows ) {
			return $image;
		}

		$parts = [];

		for ( $i = 0; $i < $strips; $i++ ) {
			$parts[] = $image->crop(
				0,
				intdiv( ( $image->height - $rows ) * $i, $strips - 1 ),
				$image->width,
				$rows
			);
		}

		// Both trial encodes read it.
		return Image::arrayjoin( $parts, [ 'across' => 1 ] )->copyMemory();
	}

	/**
	 * Saver options for a palette PNG, or null to write truecolor.
	 *
	 * @param Image            $image   8/16-bit RGB or RGBA image, in memory.
	 * @param Encoding_Context $context Encoding context.
	 * @return array<string, mixed>|null
	 */
	private function get_palette_options(
		Image $image,
		Encoding_Context $context
	): ?array {
		// libvips silently ignores `palette` for 16-bit images.
		if ( BandFormat::UCHAR !== $image->format ) {
			return null;
		}

		$palette  = new Png_Palette();
		$lossless = $palette->get_lossless_options( $image );

		if ( null !== $lossless ) {
			return $lossless;
		}

		/**
		 * Filters whether an image from an indexed (PNG8) source is quantised
		 * to 256 colours when it has more after processing, as core does.
		 * The quantisation is lossy; without it the image is written as
		 * truecolor, which is exact but usually 2-3 times larger.
		 *
		 * Images with at most 256 colours always get an exact palette.
		 *
		 * @param bool   $quantize    Whether to quantise. Default true.
		 * @param string $source_file Path to the source image.
		 */
		if (
			$palette->is_indexed_source( $image )
			&& Png_Palette::is_supported()
			&& apply_filters(
				'imagination_png_quantize',
				true,
				$context->source_file
			)
		) {
			return $palette->get_lossy_options();
		}

		return null;
	}

	/**
	 * Whether the image is 8-bit sRGB or 16-bit RGB16 with 3 or 4 bands.
	 *
	 * @param Image $image Image about to be encoded.
	 * @return bool
	 */
	private function is_rgb_image( Image $image ): bool {
		if ( $image->bands < 3 || $image->bands > 4 ) {
			return false;
		}

		return ( BandFormat::UCHAR === $image->format && Interpretation::SRGB === $image->interpretation )
			|| ( BandFormat::USHORT === $image->format && Interpretation::RGB16 === $image->interpretation );
	}

	/**
	 * Whether the image is RGB(A) and small enough to be analysed.
	 *
	 * @param Image $image Image about to be encoded.
	 * @return bool
	 */
	private function is_reduction_candidate( Image $image ): bool {
		return $this->is_rgb_image( $image )
			&& $image->width * $image->height <= self::MAX_REDUCTION_PIXELS;
	}

	/**
	 * Converts an image with R = G = B in every pixel to grey(+alpha).
	 *
	 * @param Image    $image   RGB or RGBA image, in memory.
	 * @param int|null $profile classify_profile() result of the embedded ICC
	 *                          profile the image still carries, or null when it
	 *                          has none.
	 * @return Image|null The grey image, or null when it has to stay RGB(A).
	 */
	private function to_grey( Image $image, ?int $profile ): ?Image {
		// Those images keep their profile, so they stay RGB(A).
		if ( self::PROFILE_DIFFERENT === $profile ) {
			return null;
		}

		$red = $image->extract_band( 0 );

		// 0/255 mask of "G and B equal R"; the minimum is 255 only if all pixels match.
		$same = $image->extract_band( 1, [ 'n' => 2 ] )->equal(
			$red->bandjoin( $red )
		);

		if ( 255 !== (int) $same->min() ) {
			return null;
		}

		$is_16_bit = BandFormat::USHORT === $image->format;
		$grey      = $red;

		// Keep the alpha band only if it is not fully opaque.
		if ( 4 === $image->bands ) {
			$alpha = $image->extract_band( 3 );

			if ( $alpha->min() < ( $is_16_bit ? 65535 : 255 ) ) {
				$grey = $grey->bandjoin( $alpha );
			}
		}

		$grey = $grey->copy(
			[ 'interpretation' => $is_16_bit ? Interpretation::GREY16 : Interpretation::B_W ]
		);

		if ( null !== $profile ) {
			// libpng refuses an RGB profile in a grey PNG.
			$grey->remove( 'icc-profile-data' );
		}

		return $grey;
	}

	/**
	 * Classifies the image's embedded ICC profile (PROFILE_* constants),
	 * cached per profile: the probe costs milliseconds and every sub-size of
	 * an upload carries the same profile.
	 *
	 * @param Image $image Image with an `icc-profile-data` field.
	 * @return int
	 */
	private function classify_profile( Image $image ): int {
		$blob = (string) $image->get( 'icc-profile-data' );
		$key  = md5( $blob );

		if ( ! isset( self::$profile_cache[ $key ] ) ) {
			self::$profile_cache[ $key ] = $this->classify_profile_blob(
				$image,
				$blob
			);
		}

		return self::$profile_cache[ $key ];
	}

	/**
	 * Classifies a profile without the cache.
	 *
	 * @param Image  $image Image carrying the profile.
	 * @param string $blob  The profile.
	 * @return int
	 */
	private function classify_profile_blob( Image $image, string $blob ): int {
		// libvips ignores a gray or CMYK profile on RGB pixels, which the probe
		// would mistake for sRGB.
		if ( strlen( $blob ) < 128 || 'RGB ' !== substr( $blob, 16, 4 ) ) {
			return self::PROFILE_DIFFERENT;
		}

		if ( $this->is_classic_srgb( $blob ) ) {
			return self::PROFILE_SRGB;
		}

		return $this->probe_profile( $image );
	}

	/**
	 * Whether a profile is the standard "sRGB IEC61966-2.1" (HP, 1998) one,
	 * recognised by its header fields (much cheaper than the probe).
	 *
	 * @param string $blob ICC profile, at least 128 bytes.
	 * @return bool
	 */
	private function is_classic_srgb( string $blob ): bool {
		return 'IEC ' === substr(
			$blob,
			48,
			4
		)              // Device manufacturer.
			&& 'sRGB' === substr( $blob, 52, 4 )              // Device model.
			&& 'HP  ' === substr( $blob, 80, 4 )              // Profile creator.
			&& "\x07\xCE\x00\x02\x00\x09" === substr(
				$blob,
				24,
				6
			) // Creation date, 1998-12-01.
			&& "\x02\x10\x00\x00" === substr( $blob, 8, 4 );  // Version 2.1.
	}

	/**
	 * Converts probe greys and colours from the image's profile to sRGB and
	 * checks how far they move.
	 *
	 * @param Image $image Image with an RGB `icc-profile-data` field.
	 * @return int PROFILE_* constant.
	 */
	private function probe_profile( Image $image ): int {
		$pixels = [];

		foreach ( self::PROFILE_PROBE_GREYS as $level ) {
			$pixels[] = [ $level, $level, $level ];
		}

		$grey_count = count( $pixels );
		$pixels     = array_merge( $pixels, self::PROFILE_PROBE_COLOURS );

		try {
			$bands = [];

			foreach ( [ 0, 1, 2 ] as $band ) {
				$bands[] = Image::newFromArray(
					[ array_column( $pixels, $band ) ]
				);
			}

			$values = $bands[0]->bandjoin( [ $bands[1], $bands[2] ] )->cast(
				BandFormat::UCHAR
			);

			// A row carrying the image's profile, then the probe values added
			// (newFromImage() would drop the profile; linear() and add() keep it).
			$probe = $image->crop( 0, 0, 1, 1 )
				->extract_band( 0, [ 'n' => 3 ] )
				->replicate( count( $pixels ), 1 )
				->linear( 0, 0 )
				->cast( BandFormat::UCHAR )
				->add( $values )
				->cast( BandFormat::UCHAR )
				->copy( [ 'interpretation' => Interpretation::SRGB ] );

			$converted = $probe->icc_transform(
				'srgb',
				[
					'embedded' => true,
					'intent'   => Intent::RELATIVE,
				]
			);

			$greys_ok   = true;
			$colours_ok = true;

			foreach ( $pixels as $x => $pixel ) {
				$is_grey   = $x < $grey_count;
				$tolerance = $is_grey ? 1 : 2;

				foreach ( $converted->getpoint( $x, 0 ) as $band => $value ) {
					if ( abs( $value - $pixel[ $band ] ) > $tolerance ) {
						$greys_ok   = $greys_ok && ! $is_grey;
						$colours_ok = $colours_ok && $is_grey;
					}
				}
			}
		} catch ( \Throwable $e ) {
			return self::PROFILE_DIFFERENT;
		}

		if ( ! $greys_ok ) {
			return self::PROFILE_DIFFERENT;
		}

		return $colours_ok ? self::PROFILE_SRGB : self::PROFILE_GREY_ONLY;
	}
}
