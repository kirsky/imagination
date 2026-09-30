<?php
/**
 * Libvips-backed WordPress image editor.
 *
 * Implements the {@see \WP_Image_Editor} contract using libvips
 * (via the php-vips FFI binding).
 *
 * @package Imagination
 * @see https://github.com/libvips/php-vips
 */

declare(strict_types=1);

namespace Indigit\Imagination;

defined( 'ABSPATH' ) || exit;

use Indigit\Imagination\Encoder\Encoding_Context;
use Indigit\Imagination\Error\{Log, Log_Entry};
use Indigit\Imagination\Libvips\{Capability_Cache, Native, Requirements};
use Indigit\Imagination\Vendor\Jcupitt\Vips\{
	BandFormat,
	Config,
	FailOn,
	FFI,
	Image,
	Interpretation,
	Kernel,
	OperationRound
};

/**
 * WordPress Image Editor Class for Image Manipulation through libvips.
 *
 * @see \WP_Image_Editor
 *
 * @phpstan-type Crop bool|array{0: string, 1: string}
 * @phpstan-type Size_Data array{width?: int|null, height?: int|null, crop?: bool|array{0: string, 1: string}}
 * @phpstan-type Saved_Image array{path: string, file: string, width: int, height: int, mime-type: string, filesize: int}
 */
class Image_Editor_Libvips extends \WP_Image_Editor {

	/**
	 * MIME types of the HEIF family (HEIC/HEIF images and sequences).
	 *
	 * @var string[]
	 */
	public const HEIF_MIME_TYPES = [
		'image/heic',
		'image/heif',
		'image/heic-sequence',
		'image/heif-sequence',
	];

	/**
	 * Allowed libvips loaders (class name without the File/Buffer/Source
	 * suffix) mapped to the MIME type they produce. Checked before decoding.
	 * The HEIF loader also reads AVIF.
	 *
	 * @see refine_mime_type()
	 * @var array<string, string>
	 */
	private const LOADERS = [
		'VipsForeignLoadJpeg'  => 'image/jpeg',
		'VipsForeignLoadPng'   => 'image/png',
		'VipsForeignLoadWebp'  => 'image/webp',
		'VipsForeignLoadTiff'  => 'image/tiff',
		'VipsForeignLoadNsgif' => 'image/gif',
		'VipsForeignLoadGif'   => 'image/gif',
		'VipsForeignLoadHeif'  => 'image/heif',
	];

	/**
	 * Source formats whose animation can be preserved.
	 *
	 * @var string[]
	 */
	private const ANIMATED_MIME_TYPES = [
		'image/gif',
		'image/webp',
	];

	/**
	 * Kernels accepted from `imagination_resampling_kernel` (plus MKS2013/MKS2021 on 8.17+).
	 *
	 * @var string[]
	 */
	private const KERNELS = [
		Kernel::NEAREST,
		Kernel::LINEAR,
		Kernel::CUBIC,
		Kernel::MITCHELL,
		Kernel::LANCZOS2,
		Kernel::LANCZOS3,
	];

	/**
	 * Default alpha visibility threshold on a 0-255 scale: after resizing,
	 * pixels at or below it are made fully transparent.
	 */
	private const ALPHA_VISIBILITY_THRESHOLD = 4;

	/**
	 * Default maximum number of pixels (all frames) the editor accepts:
	 * 16383 x 16383, as in sharp. See the `imagination_max_pixels` filter.
	 */
	private const MAX_PIXELS = 268402689;

	/**
	 * The loaded libvips image.
	 *
	 * @var Image|null
	 */
	protected ?Image $image = null;

	/**
	 * Encoder.
	 *
	 * @var Libvips_Encoder
	 */
	protected Libvips_Encoder $encoder;

	/**
	 * Whether metadata should be stripped.
	 *
	 * @var bool
	 */
	private bool $strip_meta = false;

	/**
	 * Constructs a new Libvips-backed image editor.
	 *
	 * @param string $file Absolute path or WordPress stream URL to the source image.
	 */
	public function __construct( $file ) {
		parent::__construct( $file );

		$this->encoder = new Libvips_Encoder();
	}

	/**
	 * Checks whether the current environment supports Libvips.
	 *
	 * @param array<string, mixed> $args Arguments supplied by WordPress.
	 * @return bool
	 */
	public static function test( $args = [] ): bool {
		return null === Requirements::get_unmet_requirement();
	}

	/**
	 * Checks whether this editor can encode the specified MIME type.
	 *
	 * @param string $mime_type MIME type.
	 * @return bool
	 */
	public static function supports_mime_type( $mime_type ): bool {
		return ( new Libvips_Encoder() )->supports( $mime_type );
	}

	/**
	 * Loads the source image into Libvips.
	 *
	 * @return true|\WP_Error
	 */
	public function load() {
		if ( $this->image instanceof Image ) {
			return true;
		}

		if ( ! is_file( $this->file ) && ! wp_is_stream( $this->file ) ) {
			return new \WP_Error(
				'error_loading_image',
				__(
					'File does not exist?',
					// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
					'default'
				),
				$this->file
			);
		}

		wp_raise_memory_limit( 'image' );

		try {
			Requirements::init_libvips();

			// Libvips keeps the messages of failures that did not throw, such as
			// findLoad() returning null, and prepends them to the next error.
			Native::call( FFI::vips(), 'vips_error_clear' );

			$buffer = null;

			if ( wp_is_stream( $this->file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$buffer = file_get_contents(
					$this->file
				);

				if ( false === $buffer ) {
					return $this->log_error(
						new \WP_Error(
							'error_loading_image',
							__( 'Could not read image stream.', 'imagination' ),
							$this->file
						),
						__FUNCTION__
					);
				}

				$loader = Image::findLoadBuffer( $buffer );
			} else {
				$loader = Image::findLoad( $this->file );
			}

			$mime_type = $this->loader_to_mime_type( (string) $loader );

			if ( ! $mime_type ) {
				return $this->log_error(
					new \WP_Error(
						'invalid_image',
						__( 'Unsupported image format.', 'imagination' ),
						$this->file
					),
					__FUNCTION__
				);
			}

			$options = $this->get_load_options( $mime_type );

			// A big upload is decoded once for both editors core uses (see Source_Cache).
			$shared = null === $buffer ? Source_Cache::take(
				$this->file,
				$options
			) : null;

			if ( null !== $shared ) {
				$this->image     = $shared['image'];
				$this->mime_type = $shared['mime_type'];
			} else {
				$this->image = null === $buffer
					? Image::newFromFile( $this->file, $options )
					: Image::newFromBuffer( $buffer, '', $options );

				$this->mime_type = $this->refine_mime_type(
					$this->image,
					$mime_type
				);

				if (
					null === $buffer
					&& ! $this->exceeds_max_pixels( $this->image )
					&& Source_Cache::is_expected( $this->file )
					&& $this->image->width * $this->image->height <= Source_Cache::MAX_PIXELS
				) {
					// Decode now so the second editor can reuse the pixels.
					$this->image = $this->image->copyMemory();
					Source_Cache::put(
						$this->file,
						$options,
						$this->image,
						$this->mime_type
					);
				}
			}
		} catch ( \Throwable $e ) {
			$this->image = null;

			// A cached "libvips works" may be out of date.
			Capability_Cache::set(
				'unmet',
				Requirements::find_unmet_libvips_requirement()
			);

			return $this->log_error(
				new \WP_Error(
					'invalid_image',
					$e->getMessage(),
					$this->file
				),
				__FUNCTION__
			);
		}

		if ( $this->exceeds_max_pixels( $this->image ) ) {
			$max_pixels = $this->get_max_pixels();
			$pixels     = $this->image->width * $this->image->height;
			$error      = new \WP_Error(
				'image_too_large',
				sprintf(
					/* translators: 1: Number of pixels in the image, 2: Maximum number of pixels. */
					__(
						'The image is too large to process: %1$s pixels (the limit is %2$s).',
						'imagination'
					),
					number_format_i18n( $pixels ),
					number_format_i18n( $max_pixels )
				),
				$this->file
			);

			$this->log_error( $error, __FUNCTION__ );
			$this->image = null;

			return $error;
		}

		$updated_size = $this->update_size();

		if ( is_wp_error( $updated_size ) ) {
			return $updated_size;
		}

		return $this->set_quality();
	}

	/**
	 * Records an error of this editor in the error log, when logging is on.
	 *
	 * @param \WP_Error $error            Error.
	 * @param string    $operation        Editor method that failed.
	 * @param string    $output_mime_type MIME type being written, if any.
	 * @return \WP_Error The same error.
	 */
	private function log_error(
		\WP_Error $error,
		string $operation,
		string $output_mime_type = ''
	): \WP_Error {
		if ( ! Log::is_enabled() ) {
			return $error;
		}

		$entry    = Log_Entry::from_wp_error( $error, $operation );
		$filetype = wp_check_filetype( $this->file );

		$entry->mime_type        = (string) (
			$this->mime_type ? $this->mime_type : $filetype['type']
		);
		$entry->output_mime_type = $output_mime_type;

		if ( $this->image instanceof Image ) {
			$entry->width  = $this->image->width;
			$entry->height = Frames::page_height( $this->image );
		} elseif ( is_array( $this->size ) ) {
			$entry->width  = (int) $this->size['width'];
			$entry->height = (int) $this->size['height'];
		}

		Log::record( $entry );

		return $error;
	}

	/**
	 * Maps a libvips loader class name to a MIME type, if the loader is allowed.
	 *
	 * @param string $loader Loader class name, e.g. "VipsForeignLoadJpegFile".
	 * @return string|false
	 */
	private function loader_to_mime_type( string $loader ) {
		$base = (string) preg_replace( '/(File|Buffer|Source)$/', '', $loader );

		return self::LOADERS[ $base ] ?? false;
	}

	/**
	 * Refines the loader MIME type using the loaded image.
	 *
	 * The HEIF loader reads AVIF, HEIC and HEIF: AV1 means AVIF, otherwise the
	 * type WordPress detects is used, as in core.
	 *
	 * @param Image  $image     Loaded image.
	 * @param string $mime_type MIME type derived from the loader.
	 * @return string
	 */
	private function refine_mime_type( Image $image, string $mime_type ): string {
		if ( 'image/heif' !== $mime_type ) {
			return $mime_type;
		}

		$compression = 0 !== $image->getType( 'heif-compression' )
			? (string) $image->get( 'heif-compression' )
			: '';

		if ( 'av1' === $compression ) {
			return 'image/avif';
		}

		$detected = wp_get_image_mime( $this->file );

		if ( in_array( $detected, self::HEIF_MIME_TYPES, true ) ) {
			return $detected;
		}

		return 'hevc' === $compression ? 'image/heic' : 'image/heif';
	}

	/**
	 * Builds the libvips load options.
	 *
	 * @param string $mime_type Source MIME type.
	 * @return array<string, mixed>
	 */
	private function get_load_options( string $mime_type ): array {
		$options = [
			'access'  => 'sequential',
			'fail_on' => $this->get_fail_on( $mime_type ),
		];

		// Load every frame (`n => -1`) only when the animation will be kept.
		if ( $this->should_preserve_animation( $mime_type ) ) {
			$options['n'] = -1;
		}

		return $options;
	}

	/**
	 * Gets how strictly libvips treats damaged input (the `fail_on` load option).
	 *
	 * JPEG damage is filled in by default (FailOn::NONE). Other formats fail
	 * on a truncated file (FailOn::TRUNCATED), so no sub-sizes are made from it.
	 *
	 * @param string $mime_type Source MIME type.
	 * @return string A FailOn value.
	 */
	private function get_fail_on( string $mime_type ): string {
		$default = 'image/jpeg' === $mime_type
			? FailOn::NONE
			: FailOn::TRUNCATED;

		/**
		 * Filters how strictly libvips treats damaged input images.
		 *
		 * @param string $fail_on   One of 'none', 'truncated', 'error', 'warning'.
		 *                          Default 'none' for JPEG, 'truncated' for other formats.
		 * @param string $file      Path to the image.
		 * @param string $mime_type Source MIME type.
		 */
		$fail_on = apply_filters(
			'imagination_fail_on',
			$default,
			$this->file,
			$mime_type
		);

		$allowed = [ FailOn::NONE, FailOn::TRUNCATED, FailOn::ERROR, FailOn::WARNING ];

		return in_array(
			$fail_on,
			$allowed,
			true
		) ? $fail_on : $default;
	}

	/**
	 * Gets the maximum number of pixels the editor accepts.
	 *
	 * Memory used by libvips is allocated outside PHP's memory_limit, so this guards against decompression bombs.
	 *
	 * @return int Maximum pixels, or 0 for no limit.
	 */
	private function get_max_pixels(): int {
		/**
		 * Filters the maximum number of pixels (width x height, all frames)
		 * an image may have to be processed by libvips.
		 *
		 * Images above the limit fail to load (no sub-sizes). 0 disables it.
		 *
		 * @param int    $max_pixels Maximum number of pixels. Default 268402689 (16383 x 16383).
		 * @param string $file       Path to the image.
		 */
		return max(
			0,
			(int) apply_filters(
				'imagination_max_pixels',
				self::MAX_PIXELS,
				$this->file
			)
		);
	}

	/**
	 * Whether an image has more pixels (all loaded frames) than the editor
	 * accepts.
	 *
	 * @param Image $image Loaded image.
	 * @return bool
	 */
	private function exceeds_max_pixels( Image $image ): bool {
		$max_pixels = $this->get_max_pixels();

		return $max_pixels > 0 && $image->width * $image->height > $max_pixels;
	}

	/**
	 * Whether an animated GIF/WebP should keep its animation.
	 *
	 * Off by default, as in core (static first-frame output). When enabled all
	 * frames are processed if the output format can store animation.
	 *
	 * @param string $mime_type Source MIME type.
	 * @return bool
	 */
	private function should_preserve_animation( string $mime_type ): bool {
		if ( ! in_array( $mime_type, self::ANIMATED_MIME_TYPES, true ) ) {
			return false;
		}

		/**
		 * Filters whether animated GIF and WebP images keep their animation
		 * in sub-sizes and edited copies.
		 *
		 * @param bool   $preserve  Whether to keep the animation. Default false (core behavior).
		 * @param string $mime_type Source MIME type.
		 * @param string $file      Path to the image.
		 */
		if ( ! apply_filters(
			'imagination_preserve_animation',
			false,
			$mime_type,
			$this->file
		) ) {
			return false;
		}

		return $this->encoder->supports_animation(
			$this->get_mapped_output_mime_type( $mime_type )
		);
	}

	/**
	 * Gets the output MIME type WordPress maps a source MIME type to.
	 *
	 * @param string $mime_type Source MIME type.
	 * @return string
	 */
	private function get_mapped_output_mime_type( string $mime_type ): string {
		$output_format = function_exists( 'wp_get_image_editor_output_format' )
			? wp_get_image_editor_output_format( $this->file, $mime_type )
			: apply_filters(
				'image_editor_output_format', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
				[],
				$this->file,
				$mime_type
			);

		return isset( $output_format[ $mime_type ] ) && is_string(
			$output_format[ $mime_type ]
		)
			? $output_format[ $mime_type ]
			: $mime_type;
	}

	/**
	 * Sets the image compression quality.
	 *
	 * The encoder of the output format (the source format without a
	 * conversion) resolves format-specific quality.
	 *
	 * @param int|null             $quality Compression quality. Range: [1,100].
	 * @param array<string, mixed> $dims    Optional image dimensions.
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	public function set_quality( $quality = null, $dims = [] ) {
		$quality_result = parent::set_quality( $quality, $dims );

		if ( is_wp_error( $quality_result ) ) {
			return $quality_result;
		}

		// Filters may hand back numeric strings or floats.
		$quality          = (int) $this->quality;
		$output_mime_type = $this->output_mime_type ? $this->output_mime_type : $this->mime_type;

		try {
			$quality = $this->encoder->resolve_quality(
				$quality,
				(string) $this->file,
				(string) $this->mime_type,
				(string) $output_mime_type
			);
		} catch ( \Throwable $e ) {
			return $this->log_error(
				new \WP_Error(
					'image_quality_error',
					$e->getMessage()
				),
				__FUNCTION__
			);
		}

		$this->quality = $quality;

		return true;
	}

	/**
	 * Sets or updates the current image size.
	 *
	 * @param int|null $width  Width; defaults to the current Libvips width.
	 * @param int|null $height Height; defaults to the current frame height.
	 * @return true|\WP_Error
	 */
	protected function update_size( $width = null, $height = null ) {
		if ( ! $width ) {
			$width = $this->image->width;
		}

		if ( ! $height ) {
			$height = Frames::page_height( $this->image );
		}

		return parent::update_size( $width, $height );
	}

	/**
	 * Efficiently resizes the current image.
	 *
	 * @param int    $dst_w      Destination width.
	 * @param int    $dst_h      Destination height.
	 * @param string $algo_name  Libvips kernel name.
	 * @param bool   $strip_meta Whether to strip metadata.
	 * @return true|\WP_Error
	 */
	protected function thumbnail_image(
		$dst_w,
		$dst_h,
		$algo_name,
		$strip_meta = true
	) {
		$this->strip_meta = (bool) apply_filters(
			'image_strip_meta', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			$strip_meta
		);

		try {
			$this->image = $this->resize_image(
				$this->image,
				(int) $dst_w,
				(int) $dst_h,
				$algo_name,
				$this->reduces_to_8_bit( $this->image )
			);
		} catch ( \Throwable $e ) {
			return $this->log_error(
				new \WP_Error(
					'image_thumbnail_error',
					$e->getMessage(),
					$this->file
				),
				__FUNCTION__
			);
		}

		return true;
	}

	/**
	 * Resizes every frame of an image to exact dimensions.
	 *
	 * @param Image  $image    Source image.
	 * @param int    $dst_w    Destination frame width.
	 * @param int    $dst_h    Destination frame height.
	 * @param string $kernel   Libvips resampling kernel.
	 * @param bool   $to_8_bit Whether to reduce 16-bit samples to 8 bits.
	 * @return Image
	 */
	private function resize_image(
		Image $image,
		int $dst_w,
		int $dst_h,
		string $kernel,
		bool $to_8_bit
	): Image {
		return Frames::map(
			$image,
			function ( Image $frame ) use ( $dst_w, $dst_h, $kernel, $to_8_bit ): Image {
				return $this->resize_frame(
					$frame,
					$dst_w,
					$dst_h,
					$kernel,
					$to_8_bit
				);
			}
		);
	}

	/**
	 * Resizes a single frame to exact dimensions.
	 *
	 * Images with alpha are resized premultiplied. The result is rounded
	 * before the cast back to the source format, because `cast()` truncates.
	 *
	 * @param Image  $frame    Single frame.
	 * @param int    $dst_w    Destination width.
	 * @param int    $dst_h    Destination height.
	 * @param string $kernel   Libvips resampling kernel.
	 * @param bool   $to_8_bit Whether to reduce 16-bit samples to 8 bits.
	 * @return Image
	 */
	private function resize_frame(
		Image $frame,
		int $dst_w,
		int $dst_h,
		string $kernel,
		bool $to_8_bit
	): Image {
		$scale_x       = $dst_w / $frame->width;
		$scale_y       = $dst_h / $frame->height;
		$has_alpha     = $frame->hasAlpha();
		$source_format = $frame->format;

		if ( $has_alpha ) {
			$frame = $frame->premultiply();
		}

		$frame = $frame->resize(
			$scale_x,
			[
				'vscale' => $scale_y,
				'kernel' => $kernel,
			]
		);

		if ( $has_alpha ) {
			$frame = $frame->unpremultiply();
		}

		if ( $frame->format !== $source_format ) {
			if ( in_array(
				$source_format,
				[ BandFormat::UCHAR, BandFormat::USHORT ],
				true
			) ) {
				$frame = $frame->round( OperationRound::RINT );
			}

			$frame = $frame->cast( $source_format );
		}

		if ( $has_alpha ) {
			$frame = $this->clear_invisible_pixels( $frame );
		}

		if ( $to_8_bit ) {
			$frame = $this->to_8_bit( $frame );
		}

		return $frame;
	}

	/**
	 * Whether a resize of the image reduces it from 16 to 8 bits per sample,
	 * as the `image_max_bit_depth` filter asks (a value of 8 or less).
	 * Applies to 16-bit RGB and grey images, with or without alpha.
	 *
	 * @param Image $image Image about to be resized.
	 * @return bool
	 */
	private function reduces_to_8_bit( Image $image ): bool {
		$depths = [
			BandFormat::UCHAR  => 8,
			BandFormat::USHORT => 16,
		];
		$depth  = $depths[ $image->format ] ?? null;

		if ( null === $depth ) {
			return false;
		}

		/** This filter is documented in wp-includes/class-wp-image-editor-imagick.php */
		$max_depth = (int) apply_filters(
			'image_max_bit_depth', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			$depth,
			$depth
		);

		return 16 === $depth
			&& $max_depth >= 1
			&& $max_depth <= 8
			&& in_array(
				$image->interpretation,
				[ Interpretation::RGB16, Interpretation::GREY16 ],
				true
			);
	}

	/**
	 * Converts a 16-bit RGB or grey image (alpha included) to 8 bits per
	 * sample, rounding to the nearest level.
	 *
	 * @param Image $image 16-bit RGB16 or GREY16 image.
	 * @return Image
	 */
	private function to_8_bit( Image $image ): Image {
		return $image->linear( 1 / 257, 0 )
			->round( OperationRound::RINT )
			->cast( BandFormat::UCHAR )
			->copy(
				[
					'interpretation' => Interpretation::RGB16 === $image->interpretation
						? Interpretation::SRGB
						: Interpretation::B_W,
				]
			);
	}

	/**
	 * Sets all bands of pixels at or below the alpha visibility threshold to 0.
	 *
	 * @param Image $image Image with an alpha band.
	 * @return Image
	 */
	private function clear_invisible_pixels( Image $image ): Image {
		/**
		 * Filters the alpha visibility threshold (0-255 scale): pixels at or
		 * below it become fully transparent after resizing. 0 disables.
		 *
		 * @param int    $threshold Threshold on a 0-255 scale. Default 4.
		 * @param string $mime_type Source MIME type.
		 */
		$threshold = (int) apply_filters(
			'imagination_alpha_visibility_threshold',
			self::ALPHA_VISIBILITY_THRESHOLD,
			$this->mime_type
		);
		$threshold = max( 0, min( 255, $threshold ) );

		if ( 0 === $threshold ) {
			return $image;
		}

		$alpha      = $image->extract_band( $image->bands - 1, [ 'n' => 1 ] );
		$is_visible = $alpha->more(
			$threshold * $this->get_max_alpha( $image ) / 255
		);

		return $is_visible->ifthenelse( $image, $image->newFromImage( 0 ) );
	}

	/**
	 * Gets the value of a fully opaque alpha for an image.
	 *
	 * Mirrors vips_interpretation_max_alpha().
	 *
	 * @param Image $image Libvips image.
	 * @return float
	 */
	private function get_max_alpha( Image $image ): float {
		switch ( $image->interpretation ) {
			case Interpretation::GREY16:
			case Interpretation::RGB16:
				return 65535.0;

			case Interpretation::SCRGB:
				return 1.0;

			default:
				return 255.0;
		}
	}

	/**
	 * Gets the resampling kernel for resizing.
	 *
	 * @return string A Kernel value.
	 */
	private function get_resampling_kernel(): string {
		/**
		 * Filters the libvips resampling kernel used for resizing.
		 *
		 * @param string $kernel    Kernel name (a value of the php-vips Kernel class). Default 'linear'.
		 * @param string $mime_type Source MIME type.
		 */
		$kernel = apply_filters(
			'imagination_resampling_kernel',
			Kernel::LINEAR,
			$this->mime_type
		);

		$allowed = self::KERNELS;

		if ( Config::atLeast( 8, 17 ) ) {
			$allowed[] = Kernel::MKS2013;
			$allowed[] = Kernel::MKS2021;
		}

		return in_array( $kernel, $allowed, true ) ? $kernel : Kernel::LINEAR;
	}

	/**
	 * Resizes multiple images from a single source.
	 *
	 * @param array<string, Size_Data> $sizes Size definitions keyed by size name.
	 * @return array<string, Saved_Image>
	 */
	public function multi_resize( $sizes ): array {
		$metadata = [];

		foreach ( $sizes as $size => $size_data ) {
			$meta = $this->make_subsize( $size_data );

			if ( ! is_wp_error( $meta ) ) {
				$metadata[ $size ] = $meta;
			}
		}

		return $metadata;
	}

	/**
	 * Creates an image sub-size and returns its attachment metadata.
	 *
	 * @param Size_Data $size_data Size definition.
	 * @return Saved_Image|\WP_Error
	 */
	public function make_subsize( $size_data ) {
		if (
			! isset( $size_data['width'] ) &&
			! isset( $size_data['height'] )
		) {
			return new \WP_Error(
				'image_subsize_create_error',
				__(
					'Cannot resize the image. Both width and height are not set.',
					// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
					'default'
				),
				$this->file
			);
		}

		if ( ! isset( $size_data['width'] ) ) {
			$size_data['width'] = null;
		}

		if ( ! isset( $size_data['height'] ) ) {
			$size_data['height'] = null;
		}

		if ( ! isset( $size_data['crop'] ) ) {
			$size_data['crop'] = false;
		}

		if (
			$this->size['width'] === $size_data['width']
			&& $this->size['height'] === $size_data['height']
		) {
			return new \WP_Error(
				'image_subsize_create_error',
				__(
					'The image already has the requested size.',
					// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
					'default'
				),
				$this->file
			);
		}

		// Sequential access can be read only once: materialize the source.
		// Damaged input fails here.
		try {
			$this->image = $this->image->copyMemory();
		} catch ( \Throwable $e ) {
			return $this->log_error(
				new \WP_Error(
					'image_subsize_create_error',
					$e->getMessage(),
					$this->file
				),
				__FUNCTION__
			);
		}

		$orig_size       = $this->size;
		$orig_strip_meta = $this->strip_meta;
		$orig_image      = $this->image;

		$resized = $this->resize(
			$size_data['width'],
			$size_data['height'],
			$size_data['crop']
		);

		if ( is_wp_error( $resized ) ) {
			$saved = $resized;
		} else {
			// Unlike save(), _save() leaves $this->file and $this->mime_type alone.
			$saved = $this->_save( $this->image, null, null, $this->strip_meta );
		}

		$this->image      = $orig_image;
		$this->size       = $orig_size;
		$this->strip_meta = $orig_strip_meta;

		if ( ! is_wp_error( $saved ) ) {
			unset( $saved['path'] );
		}

		return $saved;
	}

	/**
	 * Crops the current image.
	 *
	 * Follows core WP_Image_Editor_Imagick: the area is clipped to the image
	 * and a missing destination side falls back to the clipped source side.
	 * Every frame of an animation is cropped.
	 *
	 * @param int      $src_x   Source X.
	 * @param int      $src_y   Source Y.
	 * @param int      $src_w   Source width.
	 * @param int      $src_h   Source height.
	 * @param int|null $dst_w   Optional destination width.
	 * @param int|null $dst_h   Optional destination height.
	 * @param bool     $src_abs Whether source width/height are absolute end coordinates.
	 * @return true|\WP_Error
	 */
	public function crop(
		$src_x,
		$src_y,
		$src_w,
		$src_h,
		$dst_w = null,
		$dst_h = null,
		$src_abs = false
	) {
		if ( $src_abs ) {
			$src_w -= $src_x;
			$src_h -= $src_y;
		}

		// Clip the requested area to the frame, as GD and Imagick do.
		$left   = max( 0, (int) $src_x );
		$top    = max( 0, (int) $src_y );
		$width  = min(
			$this->image->width,
			(int) $src_x + (int) $src_w
		) - $left;
		$height = min(
			Frames::page_height( $this->image ),
			(int) $src_y + (int) $src_h
		) - $top;

		if ( $width < 1 || $height < 1 ) {
			return new \WP_Error(
				'image_crop_error',
				__( 'The crop area is outside the image.', 'imagination' ),
				$this->file
			);
		}

		$scale = $dst_w || $dst_h;

		if ( $scale ) {
			$dst_w = $dst_w ? (int) $dst_w : $width;
			$dst_h = $dst_h ? (int) $dst_h : $height;
		}

		try {
			$kernel   = '';
			$to_8_bit = false;

			if ( $scale ) {
				$this->strip_meta = (bool) apply_filters(
					'image_strip_meta', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
					true
				);
				$kernel           = $this->get_resampling_kernel();
				$to_8_bit         = $this->reduces_to_8_bit( $this->image );
			}

			$this->image = Frames::map(
				$this->image,
				function ( Image $frame ) use ( $left, $top, $width, $height, $scale, $dst_w, $dst_h, $kernel, $to_8_bit ): Image {
					$frame = $frame->crop( $left, $top, $width, $height );

					return $scale
						? $this->resize_frame(
							$frame,
							$dst_w,
							$dst_h,
							$kernel,
							$to_8_bit
						)
						: $frame;
				}
			);
		} catch ( \Throwable $e ) {
			return $this->log_error(
				new \WP_Error(
					'image_crop_error',
					$e->getMessage(),
					$this->file
				),
				__FUNCTION__
			);
		}

		return $this->update_size(
			$scale ? $dst_w : $width,
			$scale ? $dst_h : $height
		);
	}

	/**
	 * Rotates the current image counter-clockwise by $angle degrees.
	 *
	 * Multiples of 90 degrees use the exact rot90/180/270. Other angles use
	 * rotate() (clockwise in libvips, hence the negation) with a transparent
	 * or black background.
	 *
	 * @param float $angle Angle in degrees.
	 * @return true|\WP_Error
	 */
	public function rotate( $angle ) {
		$angle = fmod( (float) $angle, 360.0 );

		if ( $angle < 0 ) {
			$angle += 360.0;
		}

		$quarter_turns = (int) round( $angle / 90 );
		$right_angle   = abs( $angle - 90 * $quarter_turns ) < 1e-6;
		$quarter_turns = $quarter_turns % 4;

		try {
			// Rotation needs random access, not the sequential pipeline.
			if ( ! $right_angle ) {
				$this->image = Frames::map(
					$this->image->copyMemory(),
					static function ( Image $frame ) use ( $angle ): Image {
						return $frame->rotate(
							-$angle,
							[ 'background' => array_fill( 0, $frame->bands, 0 ) ]
						);
					}
				);
			} elseif ( 0 !== $quarter_turns ) {
				$this->image = Frames::map(
					$this->image->copyMemory(),
					static function ( Image $frame ) use ( $quarter_turns ): Image {
						switch ( $quarter_turns ) {
							case 1:
								return $frame->rot270(); // 90 counter-clockwise.
							case 2:
								return $frame->rot180();
							default:
								return $frame->rot90(); // 270 counter-clockwise.
						}
					}
				);
			}

			$this->normalize_orientation();

			$result = $this->update_size();

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		} catch ( \Throwable $e ) {
			return $this->log_error(
				new \WP_Error(
					'image_rotate_error',
					$e->getMessage(),
					$this->file
				),
				__FUNCTION__
			);
		}

		return true;
	}

	/**
	 * Flips the current image.
	 *
	 * WordPress names the flip by its axis: `$horz` maps to `flipver()`
	 * (per frame) and `$vert` to `fliphor()`.
	 *
	 * @param bool $horz Flip around the horizontal axis (top-to-bottom).
	 * @param bool $vert Flip around the vertical axis (left-to-right).
	 * @return true|\WP_Error
	 */
	public function flip( $horz, $vert ) {
		try {
			if ( $vert ) {
				$this->image = $this->image->fliphor();
			}

			if ( $horz ) {
				$this->image = Frames::map(
					$this->image->copyMemory(),
					static function ( Image $frame ): Image {
						return $frame->flipver();
					}
				);
			}

			$this->normalize_orientation();
		} catch ( \Throwable $e ) {
			return $this->log_error(
				new \WP_Error(
					'image_flip_error',
					$e->getMessage(),
					$this->file
				),
				__FUNCTION__
			);
		}

		return true;
	}

	/**
	 * Normalizes orientation metadata after changing the actual pixels.
	 *
	 * @return void
	 */
	private function normalize_orientation(): void {
		$has_orientation      = $this->image->getType( 'orientation' ) !== 0;
		$has_exif_orientation = $this->image->getType(
			'exif-ifd0-Orientation'
		) !== 0;

		if ( ! $has_orientation && ! $has_exif_orientation ) {
			return;
		}

		$image = $this->image->copy();

		if ( $has_orientation ) {
			// 1 = normal.
			$image->set( 'orientation', 1 );
		}

		if ( $has_exif_orientation ) {
			// Stops a later consumer from applying the old transform again.
			$image->remove( 'exif-ifd0-Orientation' );
		}

		$this->image = $image;
	}

	/**
	 * Saves the current in-memory image to a file or stream.
	 *
	 * @param string|null $destfilename Destination filename.
	 * @param string|null $mime_type    Output MIME type.
	 * @return Saved_Image|\WP_Error
	 */
	public function save( $destfilename = null, $mime_type = null ) {
		// Sequential access can be read only once, and the destination may be the source.
		try {
			$this->image = $this->image->copyMemory();
		} catch ( \Throwable $e ) {
			return $this->log_error(
				new \WP_Error(
					'image_save_error',
					$e->getMessage(),
					$this->file
				),
				__FUNCTION__
			);
		}

		$saved = $this->_save(
			$this->image,
			$destfilename,
			$mime_type,
			$this->strip_meta
		);

		if ( ! is_wp_error( $saved ) ) {
			$this->file      = $saved['path'];
			$this->mime_type = $saved['mime-type'];
		}

		return $saved;
	}

	/**
	 * Internal save used by make_subsize(). Unlike save(), this does not change
	 * the editor's current source file or MIME type.
	 *
	 * @param Image       $image      Image to save.
	 * @param string|null $filename   Destination filename.
	 * @param string|null $mime_type  Output MIME type.
	 * @param bool        $strip_meta Whether to strip metadata.
	 * @return Saved_Image|\WP_Error
	 */
	protected function _save( // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore
		$image,
		$filename = null,
		$mime_type = null,
		bool $strip_meta = false
	) {
		[ $filename, $extension, $mime_type ] = $this->get_output_format(
			$filename,
			$mime_type
		);

		if ( ! $filename ) {
			$filename = $this->generate_filename( null, null, $extension );
		}

		$is_stream = wp_is_stream( $filename );

		if ( ! $is_stream ) {
			$dirname = dirname( $filename );

			if ( ! wp_mkdir_p( $dirname ) ) {
				return $this->log_error(
					new \WP_Error(
						'image_save_error',
						sprintf(
							/* translators: %s: Directory path. */
							__(
								'Unable to create directory %s. Is its parent directory writable by the server?',
								// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
								'default'
							),
							esc_html( $dirname )
						)
					),
					__FUNCTION__,
					(string) $mime_type
				);
			}
		}

		// Their savers would otherwise write the whole frame strip.
		if ( ! $this->encoder->supports_animation( (string) $mime_type ) ) {
			$image = Frames::first( $image );
		}

		$context = new Encoding_Context(
			(int) $this->get_quality(),
			$strip_meta,
			(string) $this->file,
			(string) $this->mime_type,
			(string) $mime_type
		);

		// Written under a temporary name, then renamed: a failed save leaves an
		// existing file as it was.
		$target = $is_stream ? $filename : $this->get_temporary_filename(
			$filename
		);

		try {
			$this->encoder->write(
				$image,
				$target,
				(string) $extension,
				(string) $mime_type,
				$context
			);
		} catch ( \Throwable $e ) {
			if ( $target !== $filename && file_exists( $target ) ) {
				wp_delete_file( $target );
			}

			return $this->log_error(
				new \WP_Error(
					'image_save_error',
					$e->getMessage(),
					$filename
				),
				__FUNCTION__,
				(string) $mime_type
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		if ( $target !== $filename && ! rename( $target, $filename ) ) {
			wp_delete_file( $target );

			return $this->log_error(
				new \WP_Error(
					'image_save_error',
					__( 'Could not save the image file.', 'imagination' ),
					$filename
				),
				__FUNCTION__,
				(string) $mime_type
			);
		}

		if ( ! $is_stream ) {
			$stat = stat( dirname( $filename ) );

			if ( false !== $stat ) {
				$perms = $stat['mode'] & 0000666;

				chmod( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
					$filename,
					$perms
				);
			}
		}

		return [
			'path'      => $filename,
			'file'      => wp_basename(
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
				apply_filters( 'image_make_intermediate_size', $filename )
			),
			'width'     => (int) $image->width,
			'height'    => Frames::page_height( $image ),
			'mime-type' => $mime_type,
			'filesize'  => wp_filesize( $filename ),
		];
	}

	/**
	 * Gets a unique temporary name next to a file, with the same extension.
	 *
	 * @param string $filename Destination path.
	 * @return string
	 */
	private function get_temporary_filename( string $filename ): string {
		$extension = pathinfo( $filename, PATHINFO_EXTENSION );

		return sprintf(
			'%1$s/.%2$s.%3$s.tmp.%4$s',
			dirname( $filename ),
			wp_basename( $filename, '.' . $extension ),
			wp_generate_password( 12, false ),
			$extension
		);
	}

	/**
	 * Resizes the current image using WordPress' calculated geometry.
	 *
	 * @param int|null $max_w Maximum width.
	 * @param int|null $max_h Maximum height.
	 * @param Crop     $crop  Crop behavior.
	 * @return true|\WP_Error
	 */
	public function resize( $max_w, $max_h, $crop = false ) {
		if (
			$this->size['width'] === $max_w
			&& $this->size['height'] === $max_h
		) {
			return true;
		}

		$dims = image_resize_dimensions(
			$this->size['width'],
			$this->size['height'],
			$max_w,
			$max_h,
			$crop
		);

		if ( ! $dims ) {
			return new \WP_Error(
				'error_getting_dimensions',
				__(
					'Could not calculate resized image dimensions',
					// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
					'default'
				),
				$this->file
			);
		}

		[
			,
			,
			$src_x,
			$src_y,
			$dst_w,
			$dst_h,
			$src_w,
			$src_h,
		] = $dims;

		// As in core, hard crops go straight to crop().
		if ( $crop ) {
			return $this->crop(
				$src_x,
				$src_y,
				$src_w,
				$src_h,
				$dst_w,
				$dst_h
			);
		}

		$quality_result = $this->set_quality(
			null,
			[
				'width'  => $dst_w,
				'height' => $dst_h,
			]
		);

		if ( is_wp_error( $quality_result ) ) {
			return $quality_result;
		}

		$result = $this->thumbnail_image(
			$dst_w,
			$dst_h,
			$this->get_resampling_kernel()
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->update_size( $dst_w, $dst_h );
	}

	/**
	 * Streams the current image to the browser.
	 *
	 * @param string|null $mime_type Output MIME type.
	 * @return true|\WP_Error
	 */
	public function stream( $mime_type = null ) {
		if ( ! $this->image instanceof Image ) {
			return new \WP_Error(
				'image_stream_error',
				__( 'Image is not loaded.', 'imagination' ),
				$this->file
			);
		}

		[ , $extension, $mime_type ] = $this->get_output_format(
			null,
			$mime_type
		);

		$context = new Encoding_Context(
			(int) $this->get_quality(),
			false,
			(string) $this->file,
			(string) $this->mime_type,
			(string) $mime_type
		);

		try {
			// Sequential access can be read only once.
			$this->image = $this->image->copyMemory();

			$image = $this->encoder->supports_animation( (string) $mime_type )
				? $this->image
				: Frames::first( $this->image );

			$buffer = $this->encoder->buffer(
				$image,
				'.' . $extension,
				(string) $mime_type,
				$context
			);
		} catch ( \Throwable $e ) {
			return $this->log_error(
				new \WP_Error(
					'image_stream_error',
					$e->getMessage(),
					$this->file
				),
				__FUNCTION__,
				(string) $mime_type
			);
		}

		header( 'Content-Type: ' . $mime_type );
		header( 'Content-Length: ' . strlen( $buffer ) );

		echo $buffer; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		return true;
	}
}
