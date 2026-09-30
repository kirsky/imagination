<?php
/**
 * Palette (indexed) PNG output decisions.
 *
 * @package Imagination
 */

declare( strict_types=1 );

namespace Indigit\Imagination\Encoder;

defined( 'ABSPATH' ) || exit;

use Indigit\Imagination\Vendor\Jcupitt\Vips\{BandFormat, ForeignPngFilter, Image, Interpretation};

/**
 * Decides when a PNG is written as a palette PNG, and how.
 *
 * ImageMagick, the default WordPress editor, writes a palette PNG for images
 * with at most 256 colours and re-quantises indexed sources. libvips only
 * writes the bands it is given, so the plugin does both.
 */
final class Png_Palette {

	/** Pixels sampled by the early "too many colours" test. */
	private const SAMPLE_PIXELS = 65536;

	/** Largest image (in pixels) for which an exact palette is attempted. */
	private const MAX_EXACT_PIXELS = 4194304;

	/** Quantiser effort of an exact palette; below 4 it is not exact. */
	private const EXACT_EFFORT = 4;

	/** Quantiser quality target of a lossy palette. */
	private const LOSSY_QUALITY = 80;

	/** Quantiser effort of a lossy palette (the fastest). */
	private const LOSSY_EFFORT = 1;

	/**
	 * Whether libvips can write palette PNGs here.
	 *
	 * @see is_supported()
	 * @var bool|null
	 */
	private static ?bool $supported = null;

	/**
	 * Whether this libvips can write exact palette PNGs.
	 *
	 * Probed once with a two-colour image: it must come back as a palette PNG
	 * (colour type 3) with identical pixels.
	 *
	 * @return bool
	 */
	public static function is_supported(): bool {
		if ( null !== self::$supported ) {
			return self::$supported;
		}

		try {
			$image = Image::newFromArray( [ [ 10, 200 ] ] )
				->bandjoin(
					[
						Image::newFromArray(
							[ [ 20, 100 ] ]
						),
						Image::newFromArray(
							[ [ 30, 50 ] ]
						),
					]
				)
				->cast( BandFormat::UCHAR )
				->copy( [ 'interpretation' => Interpretation::SRGB ] );

			$buffer = $image->pngsave_buffer(
				self::get_options( 8, self::EXACT_EFFORT )
			);

			self::$supported = 3 === ord( $buffer[25] )
				&& 0.0 === (float) $image->subtract(
					Image::newFromBuffer( $buffer )
				)->abs()->max();
		} catch ( \Throwable $e ) {
			self::$supported = false;
		}

		return self::$supported;
	}

	/**
	 * Whether the image comes from an indexed (palette) PNG.
	 *
	 * Detected by the `palette-bit-depth` / `palette` fields the PNG loader sets.
	 *
	 * @param Image $image Image about to be encoded.
	 * @return bool
	 */
	public function is_indexed_source( Image $image ): bool {
		return 0 !== $image->getType( 'palette-bit-depth' )
			|| 0 !== $image->getType( 'palette' );
	}

	/**
	 * Saver options for a palette that holds the image exactly, or null when
	 * the image has more than 256 colours (or is too large to check).
	 *
	 * A cheap sample rejects most images (photos). Survivors are encoded as a
	 * palette, decoded and compared, which proves the palette is exact; the
	 * colour count picks the bit depth.
	 *
	 * @param Image $image 8-bit sRGB RGB or RGBA image, in memory.
	 * @return array<string, mixed>|null
	 */
	public function get_lossless_options( Image $image ): ?array {
		if ( $image->width * $image->height > self::MAX_EXACT_PIXELS ) {
			return null;
		}

		if ( $this->has_many_colours( $image ) ) {
			return null;
		}

		// Probed late so images rejected by the sample do not pay for it.
		if ( ! self::is_supported() ) {
			return null;
		}

		$colours = $this->verify( $image, 8 );

		if ( null === $colours ) {
			return null;
		}

		$bit_depth = 8;

		if ( $colours <= 2 ) {
			$bit_depth = 1;
		} elseif ( $colours <= 4 ) {
			$bit_depth = 2;
		} elseif ( $colours <= 16 ) {
			$bit_depth = 4;
		}

		// libspng mis-packs the last pixel of odd-width rows at 4 bits.
		if ( 4 === $bit_depth && 0 !== $image->width % 2 ) {
			$bit_depth = 8;
		}

		// Only 8 bits was verified so far.
		if ( $bit_depth < 8 && null === $this->verify( $image, $bit_depth ) ) {
			$bit_depth = 8;
		}

		return self::get_options( $bit_depth, self::EXACT_EFFORT );
	}

	/**
	 * Saver options for 256-colour quantisation of an indexed source, like
	 * core's quantizeImage(256) without dithering.
	 *
	 * @return array<string, mixed>
	 */
	public function get_lossy_options(): array {
		return self::get_options( 8, self::LOSSY_EFFORT, self::LOSSY_QUALITY );
	}

	/**
	 * Saver options of a palette PNG.
	 *
	 * Rows are written unfiltered: filter ALL makes palette files about 10 % larger.
	 *
	 * @param int $bit_depth 1, 2, 4 or 8.
	 * @param int $effort    Quantiser effort, 1 (fastest) to 10.
	 * @param int $quality   Quantiser quality target; 100 is exact for 256 colours or fewer.
	 * @return array<string, mixed>
	 */
	private static function get_options(
		int $bit_depth,
		int $effort,
		int $quality = 100
	): array {
		return [
			'palette'  => true,
			'Q'        => $quality,
			'effort'   => $effort,
			'dither'   => 0.0,
			'bitdepth' => $bit_depth,
			'filter'   => ForeignPngFilter::NONE,
		];
	}

	/**
	 * Whether a sample of the image proves it has more than 256 colours.
	 *
	 * False only means "maybe not": rare colours can be missed. Fully
	 * transparent pixels count as one colour.
	 *
	 * @param Image $image RGB or RGBA image, in memory.
	 * @return bool
	 */
	private function has_many_colours( Image $image ): bool {
		$bands = $image->bands;
		$step  = max(
			1,
			(int) floor(
				sqrt( ( $image->width * $image->height ) / self::SAMPLE_PIXELS )
			)
		);
		$bytes = ( $step > 1 ? $image->subsample(
			$step,
			$step
		) : $image )->writeToMemory();
		$seen  = [];

		for ( $i = 0, $length = strlen(
			$bytes
		); $i + $bands <= $length; $i += $bands ) {
			if ( 4 === $bands && "\0" === $bytes[ $i + 3 ] ) {
				$seen['transparent'] = true;
				continue;
			}

			$seen[ substr( $bytes, $i, $bands ) ] = true;

			if ( count( $seen ) > 256 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Encodes the image as a palette PNG, decodes it and compares.
	 *
	 * @param Image $image     RGB or RGBA image, in memory.
	 * @param int   $bit_depth 1, 2, 4 or 8.
	 * @return int|null Number of palette entries when the picture is identical, else null.
	 */
	private function verify( Image $image, int $bit_depth ): ?int {
		try {
			$buffer = $image->pngsave_buffer(
				array_merge(
					self::get_options( $bit_depth, self::EXACT_EFFORT ),
					[ 'compression' => 1 ]
				)
			);

			if ( ! $this->is_same_picture(
				$image,
				Image::newFromBuffer( $buffer )
			) ) {
				return null;
			}
		} catch ( \Throwable $e ) {
			return null;
		}

		return $this->count_palette_entries( $buffer );
	}

	/**
	 * Whether two images show the same picture: alpha exactly, RGB wherever
	 * the pixel is not fully transparent.
	 *
	 * @param Image $original Image before encoding.
	 * @param Image $decoded  The palette PNG, decoded.
	 * @return bool
	 */
	private function is_same_picture( Image $original, Image $decoded ): bool {
		if ( $decoded->bands !== $original->bands ) {
			// A palette PNG without transparency loads as 3 bands.
			if (
				4 !== $original->bands ||
				3 !== $decoded->bands ||
				$original->extract_band(
					3
				)->min() < 255
			) {
				return false;
			}

			$original = $original->extract_band( 0, [ 'n' => 3 ] );
		}

		$diff = $original->subtract( $decoded )->abs();

		if ( 4 !== $original->bands ) {
			return 0.0 === (float) $diff->max();
		}

		$rgb_diff = $original->extract_band( 3 )->more( 0 )
			->ifthenelse( $diff->extract_band( 0, [ 'n' => 3 ] )->bandmean(), 0 )
			->max();

		return 0.0 === (float) $diff->extract_band(
			3
		)->max() && 0.0 === (float) $rgb_diff;
	}

	/**
	 * Number of entries in the PLTE chunk of a PNG.
	 *
	 * @param string $png PNG file contents.
	 * @return int
	 */
	private function count_palette_entries( string $png ): int {
		$offset = 8;
		$length = strlen( $png );

		while ( $offset + 8 <= $length ) {
			$chunk_length = (int) unpack( 'N', substr( $png, $offset, 4 ) )[1];

			if ( 'PLTE' === substr( $png, $offset + 4, 4 ) ) {
				return intdiv( $chunk_length, 3 );
			}

			$offset += 12 + $chunk_length;
		}

		return 256;
	}
}
