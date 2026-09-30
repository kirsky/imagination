<?php
/**
 * An entry of the error log.
 *
 * @package Imagination
 */

declare(strict_types=1);

namespace Indigit\Imagination\Error;

defined( 'ABSPATH' ) || exit;

use Indigit\Imagination\Vendor\Jcupitt\Vips\Config;

/**
 * An image processing error and the context it happened in. It never holds
 * a file name: paths in the message become `<file>.ext` or `<path>`.
 *
 * @see Log
 */
final class Log_Entry {

	/**
	 * Maximum length of a message, in characters.
	 *
	 * @var int
	 */
	private const MAX_MESSAGE_LENGTH = 300;

	/**
	 * Matches an absolute path, quoted or not. An unquoted path may contain
	 * spaces when a slash follows them, and ends at a colon, a quote or a
	 * line break.
	 *
	 * @var string
	 */
	private const PATH_PATTERN = '#"(?:[A-Za-z]:)?[\\\\/][^"]*"|(?<![\w.])(?:[A-Za-z]:)?[\\\\/](?:[^\s"\':]|\s(?=[^"\':\n]*[\\\\/]))*#';

	/**
	 * When the error happened (Unix time).
	 *
	 * @var int
	 */
	public int $time;

	/**
	 * Editor method that failed, e.g. `load`.
	 *
	 * @var string
	 */
	public string $operation;

	/**
	 * WP_Error code.
	 *
	 * @var string
	 */
	public string $code;

	/**
	 * Error message, without paths.
	 *
	 * @var string
	 */
	public string $message;

	/**
	 * MIME type of the image, or empty when unknown.
	 *
	 * @var string
	 */
	public string $mime_type = '';

	/**
	 * MIME type being written, or empty when nothing was written.
	 *
	 * @var string
	 */
	public string $output_mime_type = '';

	/**
	 * Width of the image, or 0 when unknown.
	 *
	 * @var int
	 */
	public int $width = 0;

	/**
	 * Height of the image, or 0 when unknown.
	 *
	 * @var int
	 */
	public int $height = 0;

	/**
	 * PHP SAPI, e.g. `fpm-fcgi`.
	 *
	 * @var string
	 */
	public string $sapi;

	/**
	 * Type of request: `cli`, `cron`, `rest`, `ajax`, `admin` or `front`.
	 *
	 * @var string
	 */
	public string $context;

	/**
	 * Libvips version, or empty when libvips cannot be loaded.
	 *
	 * @var string
	 */
	public string $libvips_version;

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	public string $plugin_version;

	/**
	 * Number of times the error happened in one request.
	 *
	 * @var int
	 */
	public int $count = 1;

	/**
	 * Private: build instances with from_wp_error() or from_stored().
	 */
	private function __construct() {}

	/**
	 * Builds an entry for an error of the current request.
	 *
	 * @param \WP_Error $error     Error.
	 * @param string    $operation Editor method that failed.
	 * @return self
	 */
	public static function from_wp_error(
		\WP_Error $error,
		string $operation
	): self {
		$instance = new self();

		$instance->time            = time();
		$instance->operation       = $operation;
		$instance->code            = (string) $error->get_error_code();
		$instance->message         = self::redact( $error->get_error_message() );
		$instance->sapi            = PHP_SAPI;
		$instance->context         = self::get_context();
		$instance->libvips_version = self::get_libvips_version();
		$instance->plugin_version  = defined( 'IMAGINATION_VERSION' )
			? IMAGINATION_VERSION
			: '';

		return $instance;
	}

	/**
	 * Builds an entry from a raw, untrusted stored array.
	 *
	 * @param mixed $stored Raw stored entry.
	 * @return self|null Null when the entry is not valid.
	 */
	public static function from_stored( $stored ): ?self {
		if (
			! is_array( $stored )
			|| ! is_int( $stored['time'] ?? null )
			|| ! is_string( $stored['code'] ?? null )
		) {
			return null;
		}

		$instance = new self();

		$instance->time             = $stored['time'];
		$instance->code             = $stored['code'];
		$instance->operation        = (string) ( $stored['operation'] ?? '' );
		$instance->message          = (string) ( $stored['message'] ?? '' );
		$instance->mime_type        = (string) ( $stored['mime_type'] ?? '' );
		$instance->output_mime_type = (string) ( $stored['output_mime_type'] ?? '' );
		$instance->width            = (int) ( $stored['width'] ?? 0 );
		$instance->height           = (int) ( $stored['height'] ?? 0 );
		$instance->sapi             = (string) ( $stored['sapi'] ?? '' );
		$instance->context          = (string) ( $stored['context'] ?? '' );
		$instance->libvips_version  = (string) ( $stored['libvips_version'] ?? '' );
		$instance->plugin_version   = (string) ( $stored['plugin_version'] ?? '' );
		$instance->count            = max( 1, (int) ( $stored['count'] ?? 1 ) );

		return $instance;
	}

	/**
	 * Converts the entry to the array WordPress stores.
	 *
	 * @return array<string, int|string>
	 */
	public function to_array(): array {
		return [
			'time'             => $this->time,
			'operation'        => $this->operation,
			'code'             => $this->code,
			'message'          => $this->message,
			'mime_type'        => $this->mime_type,
			'output_mime_type' => $this->output_mime_type,
			'width'            => $this->width,
			'height'           => $this->height,
			'sapi'             => $this->sapi,
			'context'          => $this->context,
			'libvips_version'  => $this->libvips_version,
			'plugin_version'   => $this->plugin_version,
			'count'            => $this->count,
		];
	}

	/**
	 * Checks whether another entry is the same error. The time, count and
	 * message may differ: when libvips fails again on the same image, its
	 * message is empty.
	 *
	 * @param self $other Other entry.
	 * @return bool
	 */
	public function is_same( self $other ): bool {
		$ignored = [
			'time'    => null,
			'count'   => null,
			'message' => null,
		];

		return array_diff_key( $this->to_array(), $ignored )
			=== array_diff_key( $other->to_array(), $ignored );
	}

	/**
	 * Replaces the paths in a message, collapses its whitespace and cuts it
	 * to MAX_MESSAGE_LENGTH characters.
	 *
	 * @param string $message Message.
	 * @return string
	 */
	public static function redact( string $message ): string {
		$message = (string) preg_replace_callback(
			self::PATH_PATTERN,
			static function ( array $found ): string {
				$path      = trim( $found[0], '"' );
				$extension = pathinfo( $path, PATHINFO_EXTENSION );
				$quote     = '"' === $found[0][0] ? '"' : '';
				$redacted  = preg_match( '/^[A-Za-z0-9]{1,5}$/', $extension )
					? '<file>.' . $extension
					: '<path>';

				return $quote . $redacted . $quote;
			},
			$message
		);

		$message = trim( (string) preg_replace( '/\s+/', ' ', $message ) );

		return mb_substr( $message, 0, self::MAX_MESSAGE_LENGTH );
	}

	/**
	 * Gets the type of the current request.
	 *
	 * @return string
	 */
	private static function get_context(): string {
		if ( 'cli' === PHP_SAPI ) {
			return 'cli';
		}

		if ( wp_doing_cron() ) {
			return 'cron';
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return 'rest';
		}

		if ( wp_doing_ajax() ) {
			return 'ajax';
		}

		return is_admin() ? 'admin' : 'front';
	}

	/**
	 * Gets the libvips version.
	 *
	 * @return string Empty when libvips cannot be loaded.
	 */
	private static function get_libvips_version(): string {
		try {
			return Config::version();
		} catch ( \Throwable $e ) {
			return '';
		}
	}
}
