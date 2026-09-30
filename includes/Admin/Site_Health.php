<?php
/**
 * Site Health integration.
 *
 * @package Imagination
 */

declare(strict_types=1);

namespace Indigit\Imagination\Admin;

defined( 'ABSPATH' ) || exit;

use Indigit\Imagination\Error\{Log, Log_Entry};
use Indigit\Imagination\Libvips\Runtime;

/**
 * Adds the plugin's section to the Site Health "Info" tab and its libvips
 * check to the "Status" tab.
 */
final class Site_Health {

	/**
	 * Identifier of the Status tab check.
	 *
	 * @var string
	 */
	private const TEST = 'imagination_libvips';

	/**
	 * Runtime this reports on.
	 *
	 * @var Runtime
	 */
	private Runtime $runtime;

	/**
	 * Constructor.
	 *
	 * @param Runtime $runtime Runtime this reports on.
	 */
	public function __construct( Runtime $runtime ) {
		$this->runtime = $runtime;
	}

	/**
	 * Registers the `debug_information` and `site_status_tests` filters.
	 */
	public function register(): void {
		add_filter(
			'debug_information',
			[ $this, 'add_debug_information' ]
		);

		add_filter(
			'site_status_tests',
			[ $this, 'add_status_test' ]
		);
	}

	/**
	 * Adds the plugin's info to Site Health.
	 *
	 * Each field whose value is translated also has an English `debug` value.
	 *
	 * @param array<string, mixed> $info Debug information.
	 * @return array<string, mixed>
	 */
	public function add_debug_information( array $info ): array {
		$this->runtime->refresh();

		$unmet            = $this->runtime->describe_unmet_requirement();
		$php_vips_version = $this->runtime->get_php_vips_version();

		$fields = [
			'status'           => [
				'label' => __( 'libvips image editor', 'imagination' ),
				'value' => null === $unmet
					? __( 'Available', 'imagination' )
					: sprintf(
						/* translators: %s: Why the editor cannot run. */
						__( 'Not available: %s', 'imagination' ),
						$unmet
					),
				'debug' => null === $unmet
					? 'available'
					: 'not available: ' . $this->runtime->get_unmet_requirement_details(),
			],

			'php_vips_version' => [
				'label' => __( 'php-vips version', 'imagination' ),
				'value' => $php_vips_version ?? __( 'Unknown', 'imagination' ),
				'debug' => $php_vips_version ?? 'unknown',
			],
		];

		if ( null === $unmet ) {
			$fields += $this->get_runtime_fields();
		}

		$fields['recent_errors'] = $this->get_errors_field();

		$info['imagination'] = [
			'label'       => __( 'Imagination', 'imagination' ),
			'description' => __(
				'Image processing information provided by the Imagination plugin.',
				'imagination'
			),
			'fields'      => $fields,
		];

		return $info;
	}

	/**
	 * Gets the Info fields that describe a libvips runtime the editor can use.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_runtime_fields(): array {
		$fields = [
			'libvips_version' => [
				'label' => __( 'libvips version', 'imagination' ),
				'value' => $this->runtime->get_version(),
			],
			'input_formats'   => $this->get_list_field(
				__( 'Input formats', 'imagination' ),
				$this->runtime->get_input_formats()
			),
			'output_formats'  => $this->get_list_field(
				__( 'Output formats', 'imagination' ),
				$this->runtime->get_output_formats()
			),
			'threads'         => [
				'label' => __( 'Worker threads', 'imagination' ),
				'value' => $this->runtime->get_concurrency(),
			],
			'processors'      => [
				'label' => __( 'Processors', 'imagination' ),
				'value' => $this->runtime->get_processor_count(),
			],
			'vector'          => $this->get_vector_field(),
			'environment'     => $this->get_map_field(
				__( 'Environment variables', 'imagination' ),
				$this->runtime->get_environment()
			),
			'libheif_version' => [
				'label' => __( 'libheif version', 'imagination' ),
				'value' => $this->runtime->get_heif_version(),
			],
			'heif_decoders'   => $this->get_codecs_field(
				__( 'libheif decoders', 'imagination' ),
				$this->runtime->get_heif_decoders()
			),
			'heif_encoders'   => $this->get_codecs_field(
				__( 'libheif encoders', 'imagination' ),
				$this->runtime->get_heif_encoders()
			),
			'libraries'       => $this->get_libraries_field(),
		];

		return array_filter(
			$fields,
			static function ( array $field ): bool {
				return null !== $field['value'];
			}
		);
	}

	/**
	 * Builds the field that lists the logged errors, newest first, one per
	 * line.
	 *
	 * @return array<string, mixed>
	 */
	private function get_errors_field(): array {
		$label = __( 'Recent errors', 'imagination' );

		if ( ! Log::is_enabled() ) {
			return [
				'label' => $label,
				'value' => __( 'Error logging is off', 'imagination' ),
				'debug' => 'logging off',
			];
		}

		$errors = [];

		foreach ( Log::get() as $index => $error ) {
			$errors[ (string) ( $index + 1 ) ] = $this->describe_error( $error );
		}

		return $this->get_map_field( $label, $errors );
	}

	/**
	 * Describes a logged error in one line of English.
	 *
	 * @param Log_Entry $error Error.
	 * @return string
	 */
	private function describe_error( Log_Entry $error ): string {
		$image = $error->mime_type;

		if ( $error->width > 0 ) {
			$image .= sprintf( ' %dx%d', $error->width, $error->height );
		}

		if ( '' !== $error->output_mime_type ) {
			$image .= ' -> ' . $error->output_mime_type;
		}

		$parts = [
			gmdate( 'Y-m-d H:i:s', $error->time ) . ' UTC',
			$error->operation . '()',
			$error->code . ': ' . $error->message,
			trim( $image ),
			$error->sapi . ', ' . $error->context,
			sprintf(
				'libvips %s, Imagination %s',
				$error->libvips_version,
				$error->plugin_version
			),
		];

		if ( $error->count > 1 ) {
			$parts[] = $error->count . ' times';
		}

		return implode(
			' | ',
			array_filter(
				$parts,
				static function ( string $part ): bool {
					return '' !== $part;
				}
			)
		);
	}

	/**
	 * Builds a field that lists items on one line.
	 *
	 * @param string   $label Field label.
	 * @param string[] $items Items.
	 * @return array<string, mixed>
	 */
	private function get_list_field( string $label, array $items ): array {
		return [
			'label' => $label,
			'value' => [] === $items
				? __( 'None', 'imagination' )
				: implode( ', ', $items ),
			'debug' => [] === $items ? 'none' : implode( ', ', $items ),
		];
	}

	/**
	 * Builds a field that lists named values, one per line.
	 *
	 * @param string                    $label  Field label.
	 * @param array<int|string, string> $values Values keyed by name.
	 * @return array<string, mixed>
	 */
	private function get_map_field( string $label, array $values ): array {
		return [
			'label' => $label,
			'value' => [] === $values ? __( 'None', 'imagination' ) : $values,
			'debug' => [] === $values ? 'none' : $values,
		];
	}

	/**
	 * Builds the field that tells whether libvips uses SIMD instructions,
	 * and which ones.
	 *
	 * @return array<string, mixed> Its value is null when unknown.
	 */
	private function get_vector_field(): array {
		$enabled = $this->runtime->is_vector_enabled();
		$target  = $this->runtime->get_vector_target();
		$field   = [
			'label' => __( 'SIMD', 'imagination' ),
			'value' => null,
		];

		if ( null === $enabled ) {
			return $field;
		}

		if ( ! $enabled ) {
			return [
				'value' => __( 'Disabled', 'imagination' ),
				'debug' => 'disabled',
			] + $field;
		}

		if ( null === $target ) {
			return [
				'value' => __( 'Enabled', 'imagination' ),
				'debug' => 'enabled',
			] + $field;
		}

		return [
			'value' => sprintf(
				/* translators: %s: SIMD instruction set, e.g. AVX2. */
				__( 'Enabled (%s)', 'imagination' ),
				$target
			),
			'debug' => sprintf( 'enabled (%s)', $target ),
		] + $field;
	}

	/**
	 * Builds a field that lists libheif codecs per compression format.
	 *
	 * @param string                           $label  Field label.
	 * @param array<string, list<string>>|null $codecs Codec names keyed by compression format.
	 * @return array<string, mixed> Its value is null when $codecs is.
	 */
	private function get_codecs_field( string $label, ?array $codecs ): array {
		if ( null === $codecs ) {
			return [
				'label' => $label,
				'value' => null,
			];
		}

		$value = [];
		$debug = [];

		foreach ( $codecs as $format => $names ) {
			$list = implode( ' | ', $names );

			$value[ $format ] = '' === $list
				? __( 'None', 'imagination' )
				: $list;
			$debug[ $format ] = '' === $list ? 'none' : $list;
		}

		return [
			'label' => $label,
			'value' => $value,
			'debug' => $debug,
		];
	}

	/**
	 * Builds the field that lists the image libraries loaded into this
	 * process, one directory per line.
	 *
	 * @return array<string, mixed>
	 */
	private function get_libraries_field(): array {
		$label     = __( 'Loaded image libraries', 'imagination' );
		$libraries = $this->runtime->get_loaded_libraries();

		if ( null === $libraries ) {
			return [
				'label' => $label,
				'value' => __( 'Not readable', 'imagination' ),
				'debug' => 'not readable',
			];
		}

		return $this->get_map_field(
			$label,
			array_map(
				static function ( array $names ): string {
					return implode( ', ', $names );
				},
				$libraries
			)
		);
	}

	/**
	 * Adds the libvips check to the Site Health "Status" tab.
	 *
	 * @param array<string, mixed> $tests Site Health tests.
	 * @return array<string, mixed>
	 */
	public function add_status_test( array $tests ): array {
		$tests['direct'][ self::TEST ] = [
			'label' => __( 'libvips image editor', 'imagination' ),
			'test'  => [ $this, 'get_status_test_result' ],
		];

		return $tests;
	}

	/**
	 * Runs the libvips check.
	 *
	 * @return array<string, mixed> Site Health test result.
	 */
	public function get_status_test_result(): array {
		$this->runtime->refresh();

		$unmet  = $this->runtime->describe_unmet_requirement();
		$result = [
			'badge'   => [
				'label' => __( 'Performance', 'imagination' ),
				'color' => 'blue',
			],
			'actions' => '',
			'test'    => self::TEST,
		];

		if ( null === $unmet ) {
			return $result + [
				'label'       => __(
					'Images are processed with libvips',
					'imagination'
				),
				'status'      => 'good',
				'description' => sprintf(
					'<p>%s</p>',
					esc_html(
						sprintf(
							/* translators: %s: libvips version. */
							__(
								'Imagination processes images with libvips %s.',
								'imagination'
							),
							$this->runtime->get_version()
						)
					)
				),
			];
		}

		return $result + [
			'label'       => __(
				'Imagination cannot use libvips',
				'imagination'
			),
			'status'      => 'recommended',
			'description' => sprintf(
				'<p>%1$s</p><p>%2$s</p>',
				esc_html( $unmet ),
				esc_html__(
					'WordPress processes images with its built-in editors (Imagick or GD) until this is fixed.',
					'imagination'
				)
			),
		];
	}
}
