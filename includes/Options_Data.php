<?php
/**
 * Typed snapshot of the plugin's stored options.
 *
 * @package Imagination
 */

declare(strict_types=1);

namespace Indigit\Imagination;

defined( 'ABSPATH' ) || exit;

/**
 * Value object returned by Options::get().
 *
 * A null value means the admin has not chosen one: the matching filter keeps
 * the value it receives.
 *
 * @see Options
 */
final class Options_Data {

	/**
	 * Resampling kernel used for resizing, or null when not chosen.
	 *
	 * @var string|null One of Options::RESAMPLING_KERNELS.
	 */
	public ?string $resampling_kernel;

	/**
	 * Pixel threshold above which core scales an upload down to a `-scaled`
	 * copy (0 disables scaling), or null when not chosen.
	 *
	 * @var int|null
	 */
	public ?int $big_image_size_threshold;

	/**
	 * Whether resized output strips metadata, keeping only ICC/EXIF/XMP/IPTC,
	 * or null when not chosen.
	 *
	 * @var bool|null
	 */
	public ?bool $strip_meta;

	/**
	 * Whether output keeps only the ICC profile, dropping EXIF, XMP and IPTC
	 * even when $strip_meta is false.
	 *
	 * @var bool
	 */
	public bool $keep_icc_only;

	/**
	 * Whether WordPress's client-side media processing is turned off, so
	 * that the server processes every upload.
	 *
	 * @var bool
	 */
	public bool $disable_client_side_processing;

	/**
	 * Whether image processing errors are logged.
	 *
	 * @var bool
	 */
	public bool $log_errors;

	/**
	 * Private: build instances through defaults() or from_stored().
	 */
	private function __construct() {}

	/**
	 * Builds the plugin's default option values.
	 *
	 * @return self
	 */
	public static function defaults(): self {
		$instance = new self();

		$instance->resampling_kernel              = null;
		$instance->big_image_size_threshold       = null;
		$instance->strip_meta                     = null;
		$instance->keep_icc_only                  = false;
		$instance->disable_client_side_processing = false;
		$instance->log_errors                     = false;

		return $instance;
	}

	/**
	 * Builds an instance from a raw, untrusted stored array (e.g. straight
	 * out of get_option()), falling back field-by-field to defaults() when a
	 * value is missing or invalid.
	 *
	 * @param array<string, mixed> $stored Raw stored option value.
	 * @return self
	 */
	public static function from_stored( array $stored ): self {
		$defaults = self::defaults();
		$instance = new self();

		$instance->resampling_kernel = in_array(
			$stored['resampling_kernel'] ?? null,
			Options::RESAMPLING_KERNELS,
			true
		)
			? $stored['resampling_kernel']
			: $defaults->resampling_kernel;

		$instance->big_image_size_threshold = is_int(
			$stored['big_image_size_threshold'] ?? null
		) && $stored['big_image_size_threshold'] >= 0
			? $stored['big_image_size_threshold']
			: $defaults->big_image_size_threshold;

		$instance->strip_meta = is_bool( $stored['strip_meta'] ?? null )
			? $stored['strip_meta']
			: $defaults->strip_meta;

		$instance->keep_icc_only = is_bool( $stored['keep_icc_only'] ?? null )
			? $stored['keep_icc_only']
			: $defaults->keep_icc_only;

		$instance->disable_client_side_processing = is_bool(
			$stored['disable_client_side_processing'] ?? null
		)
			? $stored['disable_client_side_processing']
			: $defaults->disable_client_side_processing;

		$instance->log_errors = is_bool( $stored['log_errors'] ?? null )
			? $stored['log_errors']
			: $defaults->log_errors;

		return $instance;
	}

	/**
	 * Converts back to the plain array WordPress stores in wp_options.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return [
			'resampling_kernel'              => $this->resampling_kernel,
			'big_image_size_threshold'       => $this->big_image_size_threshold,
			'strip_meta'                     => $this->strip_meta,
			'keep_icc_only'                  => $this->keep_icc_only,
			'disable_client_side_processing' => $this->disable_client_side_processing,
			'log_errors'                     => $this->log_errors,
		];
	}
}
