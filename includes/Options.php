<?php
/**
 * Plugin options storage.
 *
 * @package Imagination
 */

declare(strict_types=1);

namespace Indigit\Imagination;

use Indigit\Imagination\Vendor\Jcupitt\Vips\Kernel;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the plugin's stored options.
 *
 * @see Options_Data
 */
final class Options {

	/**
	 * Option name in the wp_options table.
	 *
	 * @var string
	 */
	public const OPTION_NAME = 'imagination_options';

	/**
	 * Resampling kernels options for the settings page.
	 *
	 * @var string[]
	 */
	public const RESAMPLING_KERNELS = [
		Kernel::LINEAR,
		Kernel::LANCZOS3,
	];

	/**
	 * Default big-image threshold, matching core's own default.
	 *
	 * @var int
	 */
	public const DEFAULT_BIG_IMAGE_SIZE_THRESHOLD = 2560;

	/**
	 * Builds the settings-page field name for a stored option key.
	 *
	 * @param string $key Option key, e.g. 'resampling_kernel'.
	 * @return string e.g. 'imagination_options[resampling_kernel]'.
	 */
	public static function field_name( string $key ): string {
		return self::OPTION_NAME . '[' . $key . ']';
	}

	/**
	 * Gets all stored options merged with defaults.
	 *
	 * @return Options_Data
	 */
	public function get(): Options_Data {
		$stored = get_option( self::OPTION_NAME, [] );

		return Options_Data::from_stored( is_array( $stored ) ? $stored : [] );
	}

	/**
	 * Updates plugin options.
	 *
	 * @param array<string, mixed> $options Options to store.
	 * @return bool
	 */
	public function update( array $options ): bool {
		return update_option(
			self::OPTION_NAME,
			$options
		);
	}

	/**
	 * Deletes all plugin options.
	 *
	 * @return bool
	 */
	public function delete(): bool {
		return delete_option( self::OPTION_NAME );
	}
}
