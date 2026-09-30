<?php
/**
 * HEIC/HEIF image editor adapter.
 *
 * This class is intentionally a subclass of WP_Image_Editor_Imagick even
 * though it does not use Imagick internally.
 *
 * WordPress has a special HEIC/HEIF fallback in wp_getimagesize() which
 * accepts the image dimensions only when the selected editor is an
 * instance of WP_Image_Editor_Imagick. Extending WP_Image_Editor_Imagick
 * allows our libvips-backed editor to satisfy that compatibility check
 * while delegating all actual image processing to Image_Editor_Libvips.
 *
 * @package Imagination
 */

declare(strict_types=1);

namespace Indigit\Imagination;

defined( 'ABSPATH' ) || exit;

/**
 * Libvips-backed adapter for HEIC/HEIF images.
 *
 * @see \WP_Image_Editor
 * @see \WP_Image_Editor_Imagick
 * @see Image_Editor_Libvips
 *
 * @phpstan-import-type Crop from Image_Editor_Libvips
 * @phpstan-import-type Size_Data from Image_Editor_Libvips
 * @phpstan-import-type Saved_Image from Image_Editor_Libvips
 */
class Image_Editor_Libvips_Heif extends \WP_Image_Editor_Imagick {

	/**
	 * The actual libvips-backed image editor.
	 *
	 * @var Image_Editor_Libvips|null
	 */
	private ?Image_Editor_Libvips $libvips = null;

	/**
	 * Checks whether this adapter can process the requested image.
	 *
	 * WordPress passes the input MIME type to test() before checking
	 * supports_mime_type(). This adapter must only participate for HEIC/HEIF
	 * input; otherwise it could become a candidate for JPEG, PNG, WebP, etc.
	 *
	 * The adapter may nevertheless report support for other MIME types through
	 * supports_mime_type(), because those types can be required as output
	 * formats when processing HEIC/HEIF.
	 *
	 * @param array<string, mixed> $args Arguments used by WordPress to select an image editor.
	 * @return bool True if the adapter can process the requested image.
	 */
	public static function test( $args = [] ): bool {
		$mime_type = $args['mime_type'] ?? null;

		/*
		 * Restrict this adapter to HEIC/HEIF input (including sequences).
		 *
		 * This is intentionally checked here rather than only in
		 * supports_mime_type(). WordPress uses supports_mime_type() for both
		 * input and output MIME types during editor selection.
		 */
		if ( ! in_array(
			$mime_type,
			Image_Editor_Libvips::HEIF_MIME_TYPES,
			true
		) ) {
			return false;
		}

		/*
		 * The adapter only exists for core's HEIC handling added in WordPress
		 * 6.7 (the wp_getimagesize() fallback and the default HEIC to JPEG
		 * output mapping). Older versions do not process HEIC uploads.
		 */
		if ( ! function_exists( 'wp_is_heic_image_mime_type' ) ) {
			return false;
		}

		return Image_Editor_Libvips::test( $args )
			&& ( new Libvips_Encoder() )->can_decode( $mime_type );
	}

	/**
	 * Checks whether the editor supports a MIME type.
	 *
	 * HEIC/HEIF are the input formats handled by this adapter; they are
	 * supported when libvips can decode them (the HEVC decoder is a separate
	 * libheif plugin). Other MIME types are delegated to the actual libvips
	 * editor because WordPress may require one of them as the output format.
	 *
	 * For example, WordPress maps HEIC/HEIF to JPEG by default, so JPEG must
	 * be reported as supported for the adapter to be selected. The output
	 * format mapping is filterable, therefore delegating non-HEIC/HEIF MIME
	 * types is more robust than hard-coding only image/jpeg.
	 *
	 * @param string $mime_type MIME type to check.
	 * @return bool True if the MIME type is supported.
	 */
	public static function supports_mime_type( $mime_type ): bool {
		if ( in_array(
			$mime_type,
			Image_Editor_Libvips::HEIF_MIME_TYPES,
			true
		) ) {
			return ( new Libvips_Encoder() )->can_decode( $mime_type );
		}

		return Image_Editor_Libvips::supports_mime_type( $mime_type );
	}

	/**
	 * Loads the image through libvips.
	 *
	 * The parent Imagick::load() must never run because this adapter does not
	 * have an Imagick image object. The libvips editor owns the actual image
	 * resource and image-processing state.
	 *
	 * @return true|\WP_Error True if loaded; WP_Error on failure.
	 */
	public function load() {
		$this->libvips = new Image_Editor_Libvips( $this->file );

		$result = $this->libvips->load();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->sync_state();

		return true;
	}

	/**
	 * Synchronizes state maintained by the WP_Image_Editor facade.
	 *
	 * The adapter and the delegated libvips editor are separate objects, so
	 * each has its own WP_Image_Editor state. Keep the state used by inherited
	 * helper methods such as get_suffix() and get_quality() synchronized with
	 * the real editor.
	 *
	 * @return void
	 */
	private function sync_state(): void {
		$this->size    = $this->libvips->get_size();
		$this->quality = $this->libvips->get_quality();

		/*
		 * wp_get_image_mime() has native/fallback detection for HEIC/HEIF.
		 * The inner editor resolves its MIME type the same way, so both agree.
		 */
		$mime_type = wp_get_image_mime( $this->file );

		if ( false !== $mime_type ) {
			$this->mime_type = $mime_type;
		}
	}

	/**
	 * Gets image dimensions.
	 *
	 * Keep the facade's $size property synchronized because inherited
	 * WP_Image_Editor methods such as get_suffix() use that property.
	 *
	 * @return array{width:int,height:int} Image dimensions.
	 */
	public function get_size() {
		$this->size = $this->libvips->get_size();

		return $this->size;
	}

	/**
	 * Gets the current image quality.
	 *
	 * Do not use the inherited implementation because it reads the adapter's
	 * own $quality property while the actual quality is maintained by the
	 * delegated libvips editor.
	 *
	 * @return int Compression quality.
	 */
	public function get_quality() {
		$this->quality = $this->libvips->get_quality();

		return $this->quality;
	}

	/**
	 * Sets image compression quality.
	 *
	 * The quality is applied by the libvips editor and then mirrored into the
	 * adapter's WP_Image_Editor state.
	 *
	 * @param int|null             $quality Compression quality, 1-100.
	 * @param array<string, mixed> $dims    Optional image dimensions.
	 * @return true|\WP_Error True on success; WP_Error on failure.
	 */
	public function set_quality( $quality = null, $dims = [] ) {
		$result = $this->libvips->set_quality( $quality, $dims );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->quality = $this->libvips->get_quality();

		return true;
	}

	/**
	 * Creates an image sub-size.
	 *
	 * This must be proxied because the inherited Imagick implementation
	 * operates directly on $this->image.
	 *
	 * @param Size_Data $size_data Size data.
	 * @return Saved_Image|\WP_Error Image metadata or WP_Error.
	 */
	public function make_subsize( $size_data ) {
		$result = $this->libvips->make_subsize( $size_data );

		$this->get_size();

		return $result;
	}

	/**
	 * Resizes the current image.
	 *
	 * @param int|null $max_w Maximum width.
	 * @param int|null $max_h Maximum height.
	 * @param Crop     $crop  Crop behavior.
	 * @return true|\WP_Error True on success; WP_Error on failure.
	 */
	public function resize( $max_w, $max_h, $crop = false ) {
		$result = $this->libvips->resize( $max_w, $max_h, $crop );

		$this->get_size();

		return $result;
	}

	/**
	 * Crops the current image.
	 *
	 * @param int  $src_x   Source X coordinate.
	 * @param int  $src_y   Source Y coordinate.
	 * @param int  $src_w   Source width.
	 * @param int  $src_h   Source height.
	 * @param int  $dst_w   Optional destination width.
	 * @param int  $dst_h   Optional destination height.
	 * @param bool $src_abs Whether source coordinates are absolute.
	 * @return true|\WP_Error True on success; WP_Error on failure.
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
		$result = $this->libvips->crop(
			$src_x,
			$src_y,
			$src_w,
			$src_h,
			$dst_w,
			$dst_h,
			$src_abs
		);

		$this->get_size();

		return $result;
	}

	/**
	 * Rotates the current image counter-clockwise.
	 *
	 * @param float $angle Rotation angle in degrees.
	 * @return true|\WP_Error True on success; WP_Error on failure.
	 */
	public function rotate( $angle ) {
		$result = $this->libvips->rotate( $angle );

		$this->get_size();

		return $result;
	}

	/**
	 * Flips the current image.
	 *
	 * @param bool $horz Flip horizontally.
	 * @param bool $vert Flip vertically.
	 * @return true|\WP_Error True on success; WP_Error on failure.
	 */
	public function flip( $horz, $vert ) {
		$result = $this->libvips->flip( $horz, $vert );

		$this->get_size();

		return $result;
	}

	/**
	 * Creates multiple image sizes from the current image.
	 *
	 * @param array<string, Size_Data> $sizes Requested image sizes.
	 * @return array<string, Saved_Image> Created image metadata keyed by size.
	 */
	public function multi_resize( $sizes ) {
		return $this->libvips->multi_resize( $sizes );
	}

	/**
	 * Applies EXIF orientation handling through libvips.
	 *
	 * This method must be overridden because WP_Image_Editor_Imagick's
	 * implementation operates on its protected Imagick object.
	 *
	 * _wp_make_subsizes() calls maybe_exif_rotate() when stored image metadata
	 * contains EXIF data.
	 *
	 * @return bool|\WP_Error True if rotated, false if no rotation was needed,
	 *                        WP_Error on failure.
	 */
	public function maybe_exif_rotate() {
		$result = $this->libvips->maybe_exif_rotate();

		$this->get_size();

		return $result;
	}

	/**
	 * Saves the current image.
	 *
	 * The actual save is performed by libvips. After a successful save,
	 * synchronize the facade's file, MIME type, dimensions, and quality so
	 * inherited WP_Image_Editor helper methods continue to see the current
	 * state.
	 *
	 * @param string|null $destfilename Destination filename.
	 * @param string|null $mime_type    Output MIME type.
	 * @return Saved_Image|\WP_Error Save metadata or WP_Error.
	 */
	public function save( $destfilename = null, $mime_type = null ) {
		$saved = $this->libvips->save( $destfilename, $mime_type );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$this->file      = $saved['path'];
		$this->mime_type = $saved['mime-type'];
		$this->size      = [
			'width'  => $saved['width'],
			'height' => $saved['height'],
		];

		$this->quality = $this->libvips->get_quality();

		/*
		 * A successful save has produced the current file format, so there is
		 * no pending output-format conversion on the facade.
		 */
		$this->output_mime_type = null;

		return $saved;
	}

	/**
	 * Streams the current image.
	 *
	 * @param string|null $mime_type Output MIME type.
	 * @return true|\WP_Error True on success; WP_Error on failure.
	 */
	public function stream( $mime_type = null ) {
		return $this->libvips->stream( $mime_type );
	}
}
