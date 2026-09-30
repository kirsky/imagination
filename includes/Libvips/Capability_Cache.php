<?php
/**
 * Facts about the libvips runtime, kept across requests.
 *
 * @package Imagination
 */

declare(strict_types=1);

namespace Indigit\Imagination\Libvips;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps facts about the libvips runtime (whether it loads, which formats it
 * encodes and decodes) across requests, so that answering whether the editor
 * supports a format does not load libvips.
 *
 * Facts are stored per environment (server, PHP SAPI and version, FFI
 * settings, plugin version) in an autoloaded option, expire after a day and
 * are written once, at shutdown.
 */
final class Capability_Cache {

	/**
	 * Option that holds the facts of every environment.
	 *
	 * @var string
	 */
	public const OPTION_NAME = 'imagination_capabilities';

	/**
	 * Lifetime of an environment's facts, in seconds.
	 *
	 * @var int
	 */
	private const TTL = 86400;

	/**
	 * Most environments kept in the option.
	 *
	 * @var int
	 */
	private const MAX_ENTRIES = 8;

	/**
	 * Facts of this environment, or null until they are read.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $facts = null;

	/**
	 * When the facts of this environment expire (Unix time).
	 *
	 * @var int
	 */
	private static int $expires = 0;

	/**
	 * Whether the facts changed since they were read.
	 *
	 * @var bool
	 */
	private static bool $changed = false;

	/**
	 * Gets a fact, computing and remembering it when it is not known yet.
	 *
	 * @param string   $name    Fact name.
	 * @param callable $compute Computes the fact.
	 * @return mixed
	 */
	public static function get( string $name, callable $compute ) {
		self::read();

		if ( ! array_key_exists( $name, (array) self::$facts ) ) {
			self::$facts[ $name ] = $compute();
			self::mark_changed();
		}

		return self::$facts[ $name ];
	}

	/**
	 * Sets a fact.
	 *
	 * @param string $name  Fact name.
	 * @param mixed  $value Fact value.
	 * @return void
	 */
	public static function set( string $name, $value ): void {
		self::read();

		if (
			! array_key_exists( $name, (array) self::$facts )
			|| self::$facts[ $name ] !== $value
		) {
			self::$facts[ $name ] = $value;
			self::mark_changed();
		}
	}

	/**
	 * Forgets the facts of this environment. Each is computed again when
	 * it is next needed.
	 *
	 * @return void
	 */
	public static function clear(): void {
		self::$facts   = [];
		self::$expires = time() + self::TTL;
		self::mark_changed();
	}

	/**
	 * Stores the facts of this environment when they changed.
	 *
	 * @return void
	 */
	public static function save(): void {
		if ( ! self::$changed ) {
			return;
		}

		self::$changed = false;
		$now           = time();
		$entries       = [];

		foreach ( self::get_stored() as $key => $entry ) {
			if ( ( $entry['expires'] ?? 0 ) > $now ) {
				$entries[ $key ] = $entry;
			}
		}

		$entries[ self::get_key() ] = [
			'expires' => self::$expires,
			'facts'   => (array) self::$facts,
		];

		uasort(
			$entries,
			static function ( array $a, array $b ): int {
				return $b['expires'] <=> $a['expires'];
			}
		);

		update_option(
			self::OPTION_NAME,
			array_slice( $entries, 0, self::MAX_ENTRIES, true ),
			true
		);
	}

	/**
	 * Reads the facts of this environment once per request. Expired facts
	 * are ignored.
	 *
	 * @return void
	 */
	private static function read(): void {
		if ( null !== self::$facts ) {
			return;
		}

		$entry = self::get_stored()[ self::get_key() ] ?? null;

		if (
			is_array( $entry )
			&& is_array( $entry['facts'] ?? null )
			&& is_int( $entry['expires'] ?? null )
			&& $entry['expires'] > time()
		) {
			self::$facts   = $entry['facts'];
			self::$expires = $entry['expires'];
			return;
		}

		self::$facts   = [];
		self::$expires = time() + self::TTL;
	}

	/**
	 * Gets the stored entries of every environment.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function get_stored(): array {
		$stored = get_option( self::OPTION_NAME, [] );

		return is_array( $stored )
			? array_filter( $stored, 'is_array' )
			: [];
	}

	/**
	 * Gets the key of this environment.
	 *
	 * @return string
	 */
	private static function get_key(): string {
		return md5(
			implode(
				'|',
				[
					(string) gethostname(),
					PHP_SAPI,
					PHP_VERSION,
					extension_loaded( 'ffi' ) ? 'ffi' : '',
					(string) ini_get( 'ffi.enable' ),
					defined( 'IMAGINATION_VERSION' ) ? IMAGINATION_VERSION : '',
				]
			)
		);
	}

	/**
	 * Marks the facts as changed and saves them at shutdown.
	 *
	 * @return void
	 */
	private static function mark_changed(): void {
		self::$changed = true;

		add_action( 'shutdown', [ self::class, 'save' ] );
	}
}
