<?php
/**
 * Libvips encoder.
 *
 * @package Imagination
 */

declare(strict_types=1);

namespace Indigit\Imagination;

defined( 'ABSPATH' ) || exit;

use Indigit\Imagination\Encoder\{
	Encoding_Context,
	Format,
	Image_Preparer,
	Jpeg,
	Png,
	Prepared_Image,
	Webp,
	Avif,
	Heif,
	Tiff,
	Gif
};
use Indigit\Imagination\Libvips\Capability_Cache;
use Indigit\Imagination\Vendor\Jcupitt\Vips\{Config, Image, ForeignKeep};

/**
 * Encodes a Libvips image to a file/stream or an in-memory buffer and detects
 * runtime encoder/decoder capabilities.
 */
final class Libvips_Encoder {

	/**
	 * MIME types supported by this adapter.
	 *
	 * @var array<string, string>
	 */
	private const MIME_FORMATS = [
		'image/jpeg' => 'jpeg',
		'image/jpg'  => 'jpeg',
		'image/png'  => 'png',
		'image/webp' => 'webp',
		'image/avif' => 'avif',
		'image/heic' => 'heic',
		'image/heif' => 'heif',
		'image/tiff' => 'tiff',
		'image/tif'  => 'tiff',
		'image/gif'  => 'gif',
	];

	/**
	 * Metadata kept when stripping, as in core's WP_Image_Editor_Imagick::strip_meta().
	 */
	private const KEEP_WHEN_STRIPPING = ForeignKeep::ICC
		| ForeignKeep::EXIF
		| ForeignKeep::XMP
		| ForeignKeep::IPTC;

	/**
	 * Metadata blob field of each ForeignKeep flag, for the libvips < 8.16
	 * fallback.
	 *
	 * @see strip_metadata_legacy()
	 * @var array<int, string>
	 */
	private const KEEP_FIELDS = [
		ForeignKeep::ICC  => 'icc-profile-data',
		ForeignKeep::EXIF => 'exif-data',
		ForeignKeep::XMP  => 'xmp-data',
		ForeignKeep::IPTC => 'iptc-data',
	];

	/**
	 * Tiny (8x8) mid-grey (128) images that probe the separately packaged
	 * libheif decoders.
	 *
	 * @var array<string, string> Base64 encoded samples keyed by format.
	 */
	private const DECODE_SAMPLES = [
		'heic' => 'AAAAHGZ0eXBoZWljAAAAAG1pZjFoZWljbWlhZgAAAWptZXRhAAAAAAAAACFoZGxyAAAAAAAAAABwaWN0AAAAAAAAAAAAAAAAAAAAAA5waXRtAAAAAAABAAAAImlsb2MAAAAAREAAAQABAAAAAAGOAAEAAAAAAAAAKgAAACNpaW5mAAAAAAABAAAAFWluZmUCAAAAAAEAAGh2YzEAAAAA6mlwcnAAAADLaXBjbwAAAHdodmNDAQQIAAAAAAAAAAAAHvAA/P/4+AAADwMgAAEAF0ABDAH//wQIAAADAJ44AAADAAAeugJAIQABACpCAQEECAAAAwCeOAAAAwAAHpAEECCy3VXTXNwENBgQAAADABAAAAMAEIAiAAEACEQBwXMYMBiQAAAAFGlzcGUAAAAAAAAAQAAAAEAAAAAoY2xhcAAAAAgAAAABAAAACAAAAAH////IAAAAAv///8gAAAACAAAAEHBpeGkAAAAAAwgICAAAABdpcG1hAAAAAAAAAAEAAQSBAgSDAAAAMm1kYXQAAAAmKAGvBjISHaCJv6Vy0sUS96Bjx8lnMepvoF96dF99Z5AfdJ3v6rs=',
		'avif' => 'AAAAHGZ0eXBhdmlmAAAAAGF2aWZtaWYxbWlhZgAAANZtZXRhAAAAAAAAACFoZGxyAAAAAAAAAABwaWN0AAAAAAAAAAAAAAAAAAAAAA5waXRtAAAAAAABAAAAImlsb2MAAAAAREAAAQABAAAAAAD6AAEAAAAAAAAAFQAAACNpaW5mAAAAAAABAAAAFWluZmUCAAAAAAEAAGF2MDEAAAAAVmlwcnAAAAA4aXBjbwAAAAxhdjFDgSACAAAAABRpc3BlAAAAAAAAAAgAAAAIAAAAEHBpeGkAAAAAAwgICAAAABZpcG1hAAAAAAAAAAEAAQOBAgMAAAAdbWRhdBIACgg4CL9hAQ0GkDIHEYAAAFAFQA==',
	];

	/**
	 * MIME types whose decoder is probed with DECODE_SAMPLES.
	 *
	 * @var array<string, string>
	 */
	private const DECODE_PROBES = [
		'image/avif'          => 'avif',
		'image/heic'          => 'heic',
		'image/heif'          => 'heic',
		'image/heic-sequence' => 'heic',
		'image/heif-sequence' => 'heic',
	];


	/**
	 * Encoder implementations by MIME type.
	 *
	 * @var array<string, Format>
	 */
	private array $formats;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->formats = [
			'image/jpeg' => new Jpeg(),
			'image/jpg'  => new Jpeg(),
			'image/png'  => new Png(),
			'image/webp' => new Webp(),
			'image/avif' => new Avif(),
			'image/heic' => new Heif(),
			'image/heif' => new Heif(),
			'image/tiff' => new Tiff(),
			'image/tif'  => new Tiff(),
			'image/gif'  => new Gif(),
		];
	}

	/**
	 * Resolves the effective quality for the given output format.
	 *
	 * The output format's encoder decides (e.g. lossless WebP resolves to 100).
	 * MIME types without an encoder keep the requested quality.
	 *
	 * @param int    $quality          Requested quality.
	 * @param string $source_file      Source file.
	 * @param string $source_mime_type Source MIME type.
	 * @param string $output_mime_type Output MIME type.
	 * @return int
	 */
	public function resolve_quality(
		int $quality,
		string $source_file,
		string $source_mime_type,
		string $output_mime_type
	): int {
		$output_mime_type = strtolower( $output_mime_type );

		if ( ! isset( $this->formats[ $output_mime_type ] ) ) {
			return $quality;
		}

		$context = new Encoding_Context(
			$quality,
			false,
			$source_file,
			strtolower( $source_mime_type ),
			$output_mime_type
		);

		return $this->formats[ $output_mime_type ]->resolve_quality( $context );
	}

	/**
	 * Whether the output format can store animation.
	 *
	 * @param string $mime_type Output MIME type.
	 * @return bool
	 */
	public function supports_animation( string $mime_type ): bool {
		$mime_type = strtolower( $mime_type );

		return isset( $this->formats[ $mime_type ] )
			&& $this->formats[ $mime_type ]->supports_animation();
	}

	/**
	 * Writes an image to a filesystem path or stream.
	 *
	 * The saver is chosen by libvips from the filename suffix.
	 *
	 * @param Image            $image      Image to encode.
	 * @param string           $filename   Destination.
	 * @param string           $extension  Output extension.
	 * @param string           $mime_type  Output MIME type.
	 * @param Encoding_Context $context    Encoding context.
	 * @return void
	 * @throws \RuntimeException On encoding failure.
	 */
	public function write(
		Image $image,
		string $filename,
		string $extension,
		string $mime_type,
		Encoding_Context $context
	): void {
		if ( wp_is_stream( $filename ) ) {
			$buffer = $this->buffer(
				$image,
				'.' . ltrim( $extension, '.' ),
				$mime_type,
				$context
			);

			$handle = fopen( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
				$filename,
				'wb'
			);

			if ( false === $handle ) {
				throw new \RuntimeException(
					sprintf(
						'Could not open output stream: %s',
						esc_html( $filename )
					)
				);
			}

			try {
				$length  = strlen( $buffer );
				$written = 0;

				while ( $written < $length ) {
					$chunk = fwrite( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
						$handle,
						substr( $buffer, $written )
					);

					if ( false === $chunk || 0 === $chunk ) {
						throw new \RuntimeException(
							sprintf(
								'Could not write output stream: %s',
								esc_html( $filename )
							)
						);
					}

					$written += $chunk;
				}
			} finally {
				fclose( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					$handle
				);
			}

			return;
		}

		$prepared = $this->prepare( $image, $context );

		$prepared->image->writeToFile(
			$filename,
			$this->get_options(
				$prepared,
				$mime_type,
				$context
			)
		);
	}

	/**
	 * Encodes an image into an in-memory buffer.
	 *
	 * @param Image            $image      Image to encode.
	 * @param string           $extension  Output extension.
	 * @param string           $mime_type  Output MIME type.
	 * @param Encoding_Context $context    Encoding context.
	 * @return string
	 * @throws \Throwable On encoding failure.
	 */
	public function buffer(
		Image $image,
		string $extension,
		string $mime_type,
		Encoding_Context $context
	): string {
		$extension = '.' . ltrim( $extension, '.' );

		// Not every libvips picks a saver from `.avif`; `compression => av1` still yields AVIF.
		if ( '.avif' === $extension ) {
			$extension = '.heif';
		}

		$prepared = $this->prepare( $image, $context );

		return $prepared->image->writeToBuffer(
			$extension,
			$this->get_options(
				$prepared,
				$mime_type,
				$context
			)
		);
	}

	/**
	 * Prepares an image for encoding.
	 *
	 * Lets the output format prepare the pixels and choose the metadata to keep
	 * ({@see Image_Preparer}), then strips metadata by hand on libvips < 8.16.
	 *
	 * @param Image            $image   Image to encode.
	 * @param Encoding_Context $context Encoding context.
	 * @return Prepared_Image
	 */
	private function prepare(
		Image $image,
		Encoding_Context $context
	): Prepared_Image {
		$format = $this->formats[ strtolower(
			$context->output_mime_type
		) ] ?? null;

		$prepared = $format instanceof Image_Preparer
			? $format->prepare_image( $image, $context )
			: new Prepared_Image( $image );

		$keep_icc_only = $this->keep_icc_only( $context->output_mime_type );

		if (
			( $context->strip_metadata || $keep_icc_only )
			&& ! self::has_complete_keep_option()
		) {
			$prepared = new Prepared_Image(
				$this->strip_metadata_legacy(
					$prepared->image,
					$keep_icc_only
						? ForeignKeep::ICC
						: ( $prepared->keep ?? self::KEEP_WHEN_STRIPPING )
				),
				$prepared->options,
				$prepared->keep
			);
		}

		return $prepared;
	}

	/**
	 * Builds saver options.
	 *
	 * @param Prepared_Image   $prepared   Image being encoded, with the format's decisions.
	 * @param string           $mime_type  Output MIME type.
	 * @param Encoding_Context $context    Encoding context.
	 * @return array<string, mixed>
	 */
	private function get_options(
		Prepared_Image $prepared,
		string $mime_type,
		Encoding_Context $context
	): array {
		$mime_type = strtolower( $mime_type );

		$format = $this->get_format( $mime_type );

		$options = array_merge(
			$format->get_options(
				$prepared->image,
				$context
			),
			$prepared->options
		);

		$keep_icc_only = $this->keep_icc_only( $mime_type );

		if (
			( $context->strip_metadata || $keep_icc_only )
			&& self::has_keep_option()
		) {
			$options['keep'] = $keep_icc_only
				? ForeignKeep::ICC
				: ( $prepared->keep ?? self::KEEP_WHEN_STRIPPING );
		}

		return $options;
	}

	/**
	 * Whether output keeps only the ICC profile, dropping EXIF, XMP and IPTC
	 * even when `image_strip_meta` is false.
	 *
	 * @param string $mime_type Output MIME type.
	 * @return bool
	 */
	private function keep_icc_only( string $mime_type ): bool {
		/**
		 * Filters whether output keeps only the ICC colour profile, dropping
		 * EXIF, XMP and IPTC. Independent of and takes priority over
		 * `image_strip_meta`.
		 *
		 * @param bool   $keep_icc_only Whether to keep only ICC. Default false.
		 * @param string $mime_type     Output MIME type.
		 */
		return (bool) apply_filters(
			'imagination_keep_icc_only',
			false,
			$mime_type
		);
	}

	/**
	 * Whether the libvips savers support the `keep` option (libvips 8.15+).
	 *
	 * @return bool
	 */
	private static function has_keep_option(): bool {
		return Config::atLeast( 8, 15 );
	}

	/**
	 * Whether the savers' `keep` option removes all the metadata it excludes.
	 * libvips 8.15 keeps PNG text comments and ImageMagick profiles whatever
	 * the mask.
	 *
	 * @return bool
	 */
	private static function has_complete_keep_option(): bool {
		return Config::atLeast( 8, 16 );
	}

	/**
	 * Removes the metadata fields a `keep` mask excludes.
	 *
	 * Covers what vips_foreign_save_remove_metadata() removes (`*-data`
	 * blobs, `png-comment-*`, `magickprofile-*`, `image-description`) and,
	 * when EXIF is excluded, the parsed `exif-ifd*` fields, from which the
	 * savers would otherwise build a new EXIF block. Structural and animation
	 * fields (`page-height`, `delay`, `loop`, `orientation`) stay.
	 *
	 * @param Image $image Image to strip.
	 * @param int   $keep  ForeignKeep mask of the metadata to keep.
	 * @return Image A copy of $image without the removed fields.
	 */
	private function strip_metadata_legacy( Image $image, int $keep ): Image {
		$image = $image->copy();
		$kept  = [];

		foreach ( self::KEEP_FIELDS as $flag => $field ) {
			if ( $keep & $flag ) {
				$kept[] = $field;
			}
		}

		$keeps_exif = 0 !== ( $keep & ForeignKeep::EXIF );

		foreach ( $image->getFields() as $field ) {
			$is_metadata = 0 === strpos( $field, 'png-comment-' )
				|| 0 === strpos( $field, 'magickprofile-' )
				|| 'image-description' === $field
				|| '-data' === substr( $field, -5 );

			if (
				( $is_metadata && ! in_array( $field, $kept, true ) )
				|| ( ! $keeps_exif && 0 === strpos( $field, 'exif-ifd' ) )
			) {
				$image->remove( $field );
			}
		}

		return $image;
	}

	/**
	 * Gets the format encoder for a MIME type.
	 *
	 * @param string $mime_type MIME type.
	 * @return Format
	 * @throws \InvalidArgumentException On unsupported MIME type.
	 */
	private function get_format(
		string $mime_type
	): Format {
		if ( ! isset( $this->formats[ $mime_type ] ) ) {
			throw new \InvalidArgumentException(
				sprintf(
					'Unsupported output MIME type: %s',
					esc_html( $mime_type )
				)
			);
		}

		return $this->formats[ $mime_type ];
	}

	/**
	 * Checks whether the installed Libvips build can both decode and encode
	 * a MIME type.
	 *
	 * @param string $mime_type MIME type.
	 * @return bool
	 */
	public function supports( string $mime_type ): bool {
		return $this->can_encode( $mime_type )
			&& $this->can_decode( $mime_type );
	}

	/**
	 * Checks whether the installed Libvips build can encode a MIME type.
	 *
	 * The result is kept in the Capability_Cache.
	 *
	 * @param string $mime_type MIME type.
	 * @return bool
	 */
	public function can_encode( string $mime_type ): bool {
		$mime_type = strtolower( $mime_type );

		if ( ! isset( self::MIME_FORMATS[ $mime_type ] ) ) {
			return false;
		}

		return (bool) Capability_Cache::get(
			'encode:' . $mime_type,
			function () use ( $mime_type ): bool {
				try {
					$this->encode_test_image(
						Image::black( 1, 1 ),
						self::MIME_FORMATS[ $mime_type ]
					);
				} catch ( \Throwable $e ) {
					return false;
				}

				return true;
			}
		);
	}

	/**
	 * Checks whether the installed Libvips build can decode a MIME type.
	 *
	 * Only the formats in DECODE_PROBES are probed. The other loaders are
	 * built in. The result is kept in the Capability_Cache.
	 *
	 * @param string $mime_type MIME type.
	 * @return bool
	 */
	public function can_decode( string $mime_type ): bool {
		$mime_type = strtolower( $mime_type );

		if ( ! isset( self::DECODE_PROBES[ $mime_type ] ) ) {
			return isset( self::MIME_FORMATS[ $mime_type ] );
		}

		$format = self::DECODE_PROBES[ $mime_type ];

		return (bool) Capability_Cache::get(
			'decode:' . $format,
			static function () use ( $format ): bool {
				try {
					// avg() forces an actual decode, not just a header read.
					$average = Image::newFromBuffer(
						base64_decode( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
							self::DECODE_SAMPLES[ $format ]
						)
					)->avg();
				} catch ( \Throwable $e ) {
					return false;
				}

				// Libvips blacks out a tile it fails to decode.
				return $average > 64;
			}
		);
	}

	/**
	 * Gets the MIME types this encoder knows about, whether or not the
	 * installed Libvips build can decode or encode them.
	 *
	 * @return array<string, string> MIME type => short format name.
	 */
	public function get_known_mime_types(): array {
		return self::MIME_FORMATS;
	}

	/**
	 * Test-encodes a 1x1 image using a specific Libvips saver.
	 *
	 * @param Image  $image  Throwaway test image.
	 * @param string $format Short format name.
	 * @return void
	 * @throws \LogicException On unavailable encoder.
	 */
	private function encode_test_image( Image $image, string $format ): void {
		switch ( $format ) {
			case 'jpeg':
				$image->jpegsave_buffer();
				break;

			case 'png':
				$image->pngsave_buffer();
				break;

			case 'webp':
				$image->webpsave_buffer();
				break;

			case 'avif':
				$image->heifsave_buffer( [ 'compression' => 'av1' ] );
				break;

			case 'heic':
			case 'heif':
				$image->heifsave_buffer();
				break;

			case 'tiff':
				$image->tiffsave_buffer( [ 'compression' => 'lzw' ] );
				break;

			case 'gif':
				$image->gifsave_buffer();
				break;

			default:
				throw new \LogicException(
					'Unsupported Libvips format: ' . esc_html( $format )
				);
		}
	}
}
