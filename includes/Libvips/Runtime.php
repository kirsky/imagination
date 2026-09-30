<?php
/**
 * Libvips runtime information.
 *
 * @package Imagination
 */

declare(strict_types=1);

namespace Indigit\Imagination\Libvips;

defined( 'ABSPATH' ) || exit;

use Indigit\Imagination\Libvips_Encoder;
use Indigit\Imagination\Vendor\Jcupitt\Vips\Config;

/**
 * Reports whether the libvips editor can run here and describes the
 * installed libvips: versions, formats, threads, SIMD, libheif codecs and
 * the image libraries loaded into this process.
 */
final class Runtime {

	/**
	 * Compression formats whose libheif codecs are reported.
	 *
	 * @var array<string, int> Values of libheif's `enum heif_compression_format`, keyed by name.
	 */
	private const HEIF_COMPRESSION_FORMATS = [
		'HEVC' => 1,
		'AV1'  => 4,
	];

	/**
	 * Maximum number of libheif codecs listed per compression format.
	 *
	 * @var int
	 */
	private const MAX_HEIF_CODECS = 16;

	/**
	 * Matches the file names of the libraries get_loaded_libraries() reports.
	 *
	 * @var string
	 */
	private const LIBRARY_PATTERN = '/^(?:libvips|vips-|libheif|libjpeg|libturbojpeg|libspng|libpng|libwebp|libsharpyuv|libimagequant|libcgif|libtiff|libexif|liblcms2|libhwy|liborc-|libaom|libdav1d|libde265|libx265|libSvtAv1|librav1e|libkvazaar|libopenh264|libz\.|libz-ng)/';

	/**
	 * Encoder used to probe actual format support.
	 *
	 * @var Libvips_Encoder
	 */
	private Libvips_Encoder $encoder;

	/**
	 * Constructor.
	 *
	 * @param Libvips_Encoder $encoder Encoder used to probe format support.
	 */
	public function __construct( Libvips_Encoder $encoder ) {
		$this->encoder = $encoder;
	}

	/**
	 * Checks the installed libvips again, including every known format when
	 * it can run, and stores the results in the Capability_Cache for later
	 * requests.
	 *
	 * @return void
	 */
	public function refresh(): void {
		Capability_Cache::clear();

		if ( null === Requirements::get_unmet_requirement() ) {
			$this->get_input_formats();
			$this->get_output_formats();
		}
	}

	/**
	 * Describes the first unmet runtime requirement of the libvips editor.
	 *
	 * @return string|null Translated description, or null when all requirements are met.
	 */
	public function describe_unmet_requirement(): ?string {
		switch ( Requirements::get_unmet_requirement() ) {
			case Requirements::REQUIREMENT_FFI_EXTENSION:
				return __(
					'The PHP FFI extension is not loaded.',
					'imagination'
				);

			case Requirements::REQUIREMENT_FFI_ENABLE:
				return sprintf(
					/* translators: 1: Current value of the ffi.enable setting, 2: PHP SAPI name, e.g. fpm-fcgi. */
					__(
						'The PHP setting ffi.enable is "%1$s" for %2$s. It must be "true".',
						'imagination'
					),
					(string) ini_get( 'ffi.enable' ),
					PHP_SAPI
				);

			case Requirements::REQUIREMENT_PHP_VIPS:
				return __(
					'The php-vips library is missing. Reinstall the plugin.',
					'imagination'
				);

			case Requirements::REQUIREMENT_LIBVIPS:
				return sprintf(
					/* translators: %s: Error message from php-vips. */
					__( 'libvips could not be loaded: %s', 'imagination' ),
					$this->get_load_error()
				);

			case Requirements::REQUIREMENT_LIBVIPS_VERSION:
				return sprintf(
					/* translators: 1: Installed libvips version, 2: Minimum libvips version. */
					__(
						'libvips %1$s is installed. Version %2$s or newer is needed.',
						'imagination'
					),
					$this->get_version(),
					Requirements::MIN_LIBVIPS_VERSION
				);

			default:
				return null;
		}
	}

	/**
	 * Gets the code of the first unmet requirement of the libvips editor,
	 * with the values it was checked against, in English.
	 *
	 * @return string|null E.g. `ffi_enable (ffi.enable=0, fpm-fcgi)`, or null when all requirements are met.
	 */
	public function get_unmet_requirement_details(): ?string {
		$requirement = Requirements::get_unmet_requirement();

		if ( null === $requirement ) {
			return null;
		}

		switch ( $requirement ) {
			case Requirements::REQUIREMENT_FFI_ENABLE:
				$details = sprintf(
					'ffi.enable=%s, %s',
					(string) ini_get( 'ffi.enable' ),
					PHP_SAPI
				);
				break;

			case Requirements::REQUIREMENT_LIBVIPS:
				$details = $this->get_load_error();
				break;

			case Requirements::REQUIREMENT_LIBVIPS_VERSION:
				$details = $this->get_version();
				break;

			default:
				$details = '';
		}

		return '' === $details
			? $requirement
			: sprintf( '%s (%s)', $requirement, $details );
	}

	/**
	 * Gets the error php-vips reports when it initializes libvips.
	 *
	 * @return string Empty when libvips loads.
	 */
	private function get_load_error(): string {
		try {
			Config::version();
		} catch ( \Throwable $e ) {
			return trim( $e->getMessage() );
		}

		return '';
	}

	/**
	 * Gets the installed Libvips library version.
	 *
	 * @return string
	 */
	public function get_version(): string {
		return Config::version();
	}

	/**
	 * Gets the installed php-vips (jcupitt/vips) package version.
	 *
	 * @return string|null Null when Composer does not know the package.
	 */
	public function get_php_vips_version(): ?string {
		if ( ! \Composer\InstalledVersions::isInstalled( 'jcupitt/vips' ) ) {
			return null;
		}

		return \Composer\InstalledVersions::getVersion( 'jcupitt/vips' );
	}

	/**
	 * Gets the image formats the installed Libvips build can decode.
	 *
	 * @return list<string> Short format names (e.g. 'jpeg', 'avif').
	 */
	public function get_input_formats(): array {
		return $this->get_formats( [ $this->encoder, 'can_decode' ] );
	}

	/**
	 * Gets the image formats the installed Libvips build can encode.
	 *
	 * @return list<string> Short format names (e.g. 'jpeg', 'avif').
	 */
	public function get_output_formats(): array {
		return $this->get_formats( [ $this->encoder, 'can_encode' ] );
	}

	/**
	 * Gets the short names of the known formats whose MIME type passes a test.
	 *
	 * @param callable $test Takes a MIME type, returns whether it passes.
	 * @return list<string>
	 */
	private function get_formats( callable $test ): array {
		$formats = [];

		foreach ( $this->encoder->get_known_mime_types() as $mime_type => $format ) {
			if ( $test( $mime_type ) ) {
				$formats[] = $format;
			}
		}

		return array_values( array_unique( $formats ) );
	}

	/**
	 * Gets the number of worker threads libvips runs per image.
	 *
	 * @return int|null Null when libvips cannot be loaded.
	 */
	public function get_concurrency(): ?int {
		$ffi = Native::declare_functions( 'int vips_concurrency_get(void);' );

		return null === $ffi
			? null
			: Native::call( $ffi, 'vips_concurrency_get' );
	}

	/**
	 * Gets the number of processors this process may run on, as GLib counts
	 * them.
	 *
	 * @return int|null Null when libvips cannot be loaded.
	 */
	public function get_processor_count(): ?int {
		$ffi = Native::declare_functions(
			'unsigned int g_get_num_processors(void);'
		);

		return null === $ffi
			? null
			: Native::call( $ffi, 'g_get_num_processors' );
	}

	/**
	 * Checks whether libvips uses SIMD instructions.
	 *
	 * @return bool|null Null when libvips cannot be loaded.
	 */
	public function is_vector_enabled(): ?bool {
		$ffi = Native::declare_functions( 'int vips_vector_isenabled(void);' );

		return null === $ffi
			? null
			: 0 !== Native::call( $ffi, 'vips_vector_isenabled' );
	}

	/**
	 * Gets the SIMD instruction set that libvips uses: the best one it was
	 * built with that this CPU supports.
	 *
	 * @return string|null E.g. `AVX2`, or null without Highway (libvips < 8.15, or built with Orc).
	 */
	public function get_vector_target(): ?string {
		$ffi = Native::declare_functions(
			'int64_t vips_vector_get_builtin_targets(void);'
			. 'int64_t vips_vector_get_supported_targets(void);'
			. 'const char *vips_vector_target_name(int64_t target);'
		);

		if ( null === $ffi ) {
			return null;
		}

		$builtin   = Native::call( $ffi, 'vips_vector_get_builtin_targets' );
		$supported = Native::call( $ffi, 'vips_vector_get_supported_targets' );
		$targets   = $builtin & $supported;

		if ( 0 === $targets ) {
			return null;
		}

		// Highway numbers better targets with lower bits.
		$best = $targets & -$targets;

		return Native::call( $ffi, 'vips_vector_target_name', $best );
	}

	/**
	 * Gets the libvips (`VIPS_*`) and glibc malloc (`MALLOC_ARENA_MAX`)
	 * variables of this process's environment.
	 *
	 * @return array<string, string> Values keyed by variable name.
	 */
	public function get_environment(): array {
		$variables = [];

		foreach ( getenv() as $name => $value ) {
			$is_vips = 0 === strpos( $name, 'VIPS_' );

			if ( $is_vips || 'MALLOC_ARENA_MAX' === $name ) {
				$variables[ $name ] = $value;
			}
		}

		ksort( $variables );

		return $variables;
	}

	/**
	 * Gets the version of libheif, which libvips uses to read and write HEIC
	 * and AVIF.
	 *
	 * @return string|null Null when libheif is not loaded.
	 */
	public function get_heif_version(): ?string {
		$ffi = Native::declare_functions(
			'const char *heif_get_version(void);'
		);

		return null === $ffi ? null : Native::call( $ffi, 'heif_get_version' );
	}

	/**
	 * Gets the names of the HEVC and AV1 decoders available to libheif.
	 *
	 * @return array<string, list<string>>|null Names keyed by compression format, or null when libheif cannot list them (before 1.15).
	 */
	public function get_heif_decoders(): ?array {
		$ffi = Native::declare_functions(
			'struct heif_decoder_descriptor;'
			. 'int heif_get_decoder_descriptors(int format, const struct heif_decoder_descriptor **out, int count);'
			. 'const char *heif_decoder_descriptor_get_name(const struct heif_decoder_descriptor *descriptor);'
		);

		if ( null === $ffi ) {
			return null;
		}

		$decoders = [];

		foreach ( self::HEIF_COMPRESSION_FORMATS as $name => $format ) {
			$descriptors = $this->new_descriptor_array( $ffi, 'decoder' );
			$count       = Native::call(
				$ffi,
				'heif_get_decoder_descriptors',
				$format,
				$descriptors,
				self::MAX_HEIF_CODECS
			);

			$decoders[ $name ] = [];

			for ( $i = 0; $i < $count; $i++ ) {
				$decoders[ $name ][] = Native::call(
					$ffi,
					'heif_decoder_descriptor_get_name',
					$descriptors[ $i ]
				);
			}
		}

		return $decoders;
	}

	/**
	 * Gets the names of the HEVC and AV1 encoders available to libheif.
	 * libheif 1.17 lists a plugin encoder only after its first use.
	 *
	 * @return array<string, list<string>>|null Names keyed by compression format, or null when libheif cannot list them (before 1.15).
	 */
	public function get_heif_encoders(): ?array {
		$ffi = Native::declare_functions(
			'struct heif_encoder_descriptor;'
			. 'int heif_get_encoder_descriptors(int format, const char *name, const struct heif_encoder_descriptor **out, int count);'
			. 'const char *heif_encoder_descriptor_get_name(const struct heif_encoder_descriptor *descriptor);'
		);

		if ( null === $ffi ) {
			return null;
		}

		$encoders = [];

		foreach ( self::HEIF_COMPRESSION_FORMATS as $name => $format ) {
			$descriptors = $this->new_descriptor_array( $ffi, 'encoder' );
			$count       = Native::call(
				$ffi,
				'heif_get_encoder_descriptors',
				$format,
				null,
				$descriptors,
				self::MAX_HEIF_CODECS
			);

			$encoders[ $name ] = [];

			for ( $i = 0; $i < $count; $i++ ) {
				$encoders[ $name ][] = Native::call(
					$ffi,
					'heif_encoder_descriptor_get_name',
					$descriptors[ $i ]
				);
			}
		}

		return $encoders;
	}

	/**
	 * Gets the image libraries mapped into this process, including libvips'
	 * modules and libheif's plugins.
	 *
	 * @return array<string, list<string>>|null File names keyed by directory, or null when the memory map of this process cannot be read (outside Linux, or with open_basedir set).
	 */
	public function get_loaded_libraries(): ?array {
		if (
			'' !== (string) ini_get( 'open_basedir' )
			|| ! is_readable( '/proc/self/maps' )
		) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$maps = file_get_contents( '/proc/self/maps' );

		if ( false === $maps ) {
			return null;
		}

		$libraries = [];

		foreach ( explode( "\n", $maps ) as $line ) {
			if ( ! preg_match( '#\s(/\S+)$#', $line, $match ) ) {
				continue;
			}

			$name = basename( $match[1] );

			if ( preg_match( self::LIBRARY_PATTERN, $name ) ) {
				$libraries[ dirname( $match[1] ) ][ $name ] = true;
			}
		}

		ksort( $libraries );

		return array_map(
			static function ( array $names ): array {
				$names = array_keys( $names );
				sort( $names );

				return $names;
			},
			$libraries
		);
	}

	/**
	 * Allocates an array for libheif codec descriptors.
	 *
	 * @param \FFI   $ffi  FFI instance that declares the `heif_{$kind}_descriptor` struct.
	 * @param string $kind `decoder` or `encoder`.
	 * @return mixed C array of MAX_HEIF_CODECS descriptor pointers.
	 */
	private function new_descriptor_array( \FFI $ffi, string $kind ) {
		return $ffi->new(
			sprintf(
				'const struct heif_%s_descriptor *[%d]',
				$kind,
				self::MAX_HEIF_CODECS
			)
		);
	}
}
