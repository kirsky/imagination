<?php
/**
 * Error log.
 *
 * @package Imagination
 */

declare(strict_types=1);

namespace Indigit\Imagination\Error;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the most recent image processing errors of this site in an option
 * that is not autoloaded.
 *
 * Logging is off unless the `imagination_log_errors` filter (or the
 * Settings page) turns it on. Errors are saved once per request, at
 * shutdown. An error repeated in one request is saved once, with a count,
 * and an error saved less than a minute ago is not saved again.
 */
final class Log {

	/**
	 * Option that holds the errors.
	 *
	 * @var string
	 */
	public const OPTION_NAME = 'imagination_errors';

	/**
	 * Maximum number of errors kept.
	 *
	 * @var int
	 */
	private const MAX_ENTRIES = 10;

	/**
	 * Seconds during which an error is not saved again.
	 *
	 * @var int
	 */
	private const REPEAT_INTERVAL = 60;

	/**
	 * Errors of this request that are not saved yet, oldest first.
	 *
	 * @var Log_Entry[]
	 */
	private static array $pending = [];

	/**
	 * Checks whether errors are logged.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return (bool) apply_filters( 'imagination_log_errors', false );
	}

	/**
	 * Adds an error of this request. Only the MAX_ENTRIES newest errors of
	 * the request are kept.
	 *
	 * @param Log_Entry $entry Error.
	 * @return void
	 */
	public static function record( Log_Entry $entry ): void {
		foreach ( self::$pending as $pending ) {
			if ( $pending->is_same( $entry ) ) {
				++$pending->count;
				return;
			}
		}

		self::$pending[] = $entry;
		self::$pending   = array_slice( self::$pending, -self::MAX_ENTRIES );

		add_action( 'shutdown', [ self::class, 'save' ] );
	}

	/**
	 * Gets the saved errors.
	 *
	 * @return Log_Entry[] Newest first.
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION_NAME, [] );

		if ( ! is_array( $stored ) ) {
			return [];
		}

		$entries = array_map( [ Log_Entry::class, 'from_stored' ], $stored );

		return array_values( array_filter( $entries ) );
	}

	/**
	 * Saves the errors of this request, keeping the MAX_ENTRIES newest.
	 *
	 * @return void
	 */
	public static function save(): void {
		$saved   = self::get();
		$pending = array_filter(
			self::$pending,
			static function ( Log_Entry $entry ) use ( $saved ): bool {
				return ! self::was_saved_recently( $entry, $saved );
			}
		);

		self::$pending = [];

		if ( [] === $pending ) {
			return;
		}

		$entries = array_merge( array_reverse( $pending ), $saved );

		update_option(
			self::OPTION_NAME,
			array_map(
				static function ( Log_Entry $entry ): array {
					return $entry->to_array();
				},
				array_slice( $entries, 0, self::MAX_ENTRIES )
			),
			false
		);
	}

	/**
	 * Deletes the saved errors.
	 *
	 * @return void
	 */
	public static function clear(): void {
		delete_option( self::OPTION_NAME );
	}

	/**
	 * Checks whether an error was saved less than REPEAT_INTERVAL seconds
	 * ago.
	 *
	 * @param Log_Entry   $entry Error.
	 * @param Log_Entry[] $saved Saved errors.
	 * @return bool
	 */
	private static function was_saved_recently(
		Log_Entry $entry,
		array $saved
	): bool {
		foreach ( $saved as $saved_entry ) {
			if (
				$saved_entry->is_same( $entry )
				&& $entry->time - $saved_entry->time < self::REPEAT_INTERVAL
			) {
				return true;
			}
		}

		return false;
	}
}
