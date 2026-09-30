<?php
/**
 * Shares one decoded source between two editors of the same upload.
 *
 * @package Imagination
 */

declare( strict_types=1 );

namespace Indigit\Imagination;

defined( 'ABSPATH' ) || exit;

use Indigit\Imagination\Vendor\Jcupitt\Vips\Image;

/**
 * Decodes a big upload once instead of twice.
 *
 * Core decodes an upload over the big-image threshold twice (once for the
 * `-scaled` file, once more for the sub-sizes). The first editor leaves its
 * decoded pixels here and the second takes them.
 */
final class Source_Cache {

	/** Largest image (in pixels) that is kept (64 MP, 256 MB as 8-bit RGBA). */
	public const MAX_PIXELS = 67108864;

	/**
	 * Files core is about to decode twice, by real path.
	 *
	 * @var array<string, true>
	 */
	private static array $expected = [];

	/**
	 * The one decoded source waiting for its second editor.
	 *
	 * @var array{real: string, signature: string, image: Image, mime_type: string}|null
	 */
	private static ?array $entry = null;

	/**
	 * Registers the filter that announces big uploads.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter(
			'big_image_size_threshold',
			[ self::class, 'expect' ],
			PHP_INT_MAX,
			3
		);
	}

	/**
	 * `big_image_size_threshold` callback: records a file core will scale down.
	 * Returns the threshold unchanged.
	 *
	 * @param mixed $threshold Threshold in pixels (0 or false disables scaling).
	 * @param mixed $imagesize Image width and height, as [ width, height ].
	 * @param mixed $file      Path to the uploaded image.
	 * @return mixed
	 */
	public static function expect( $threshold, $imagesize = null, $file = null ) {
		if (
			! $threshold
			|| ! is_array( $imagesize )
			|| ! isset( $imagesize[0], $imagesize[1] )
			|| ! is_string( $file )
			|| ( $imagesize[0] <= $threshold && $imagesize[1] <= $threshold )
		) {
			return $threshold;
		}

		/**
		 * Filters whether a big upload is decoded once and shared between the
		 * `-scaled` editor and the sub-size editor. False decodes it twice
		 * (streaming), which needs less memory.
		 *
		 * @param bool   $share Whether to share the decoded source. Default true.
		 * @param string $file  Path to the uploaded image.
		 */
		if ( ! apply_filters(
			'imagination_share_decoded_source',
			true,
			$file
		) ) {
			return $threshold;
		}

		$real = realpath( $file );

		if ( false !== $real ) {
			self::$expected[ $real ] = true;
		}

		return $threshold;
	}

	/**
	 * Whether core announced this file and its decoded source is not stored yet.
	 *
	 * @param string $file Path to the image.
	 * @return bool
	 */
	public static function is_expected( string $file ): bool {
		$real = realpath( $file );

		return false !== $real && isset( self::$expected[ $real ] );
	}

	/**
	 * Stores the decoded source of an announced file (the first editor).
	 *
	 * @param string               $file      Path to the image.
	 * @param array<string, mixed> $options   Load options it was decoded with.
	 * @param Image                $image     Decoded image, in memory.
	 * @param string               $mime_type Its MIME type.
	 * @return void
	 */
	public static function put(
		string $file,
		array $options,
		Image $image,
		string $mime_type
	): void {
		$signature = self::signature( $file, $options );
		$real      = realpath( $file );

		if ( null === $signature || false === $real ) {
			return;
		}

		// Announced once, stored once.
		unset( self::$expected[ $real ] );

		self::$entry = [
			'real'      => $real,
			'signature' => $signature,
			'image'     => $image,
			'mime_type' => $mime_type,
		];
	}

	/**
	 * Takes the stored source if it is the same unchanged file decoded with
	 * the same options (the second editor). Removed once taken; an editor for
	 * another file leaves it alone.
	 *
	 * @param string               $file    Path to the image.
	 * @param array<string, mixed> $options Load options.
	 * @return array{image: Image, mime_type: string}|null
	 */
	public static function take( string $file, array $options ): ?array {
		if ( null === self::$entry ) {
			return null;
		}

		$real = realpath( $file );

		if ( false === $real || self::$entry['real'] !== $real ) {
			return null;
		}

		$entry       = self::$entry;
		self::$entry = null;

		if ( self::signature( $file, $options ) !== $entry['signature'] ) {
			return null;
		}

		return [
			'image'     => $entry['image'],
			'mime_type' => $entry['mime_type'],
		];
	}

	/**
	 * Identifies a file's current content: path, size, mtime, ctime, inode and
	 * the load options.
	 *
	 * @param string               $file    Path to the image.
	 * @param array<string, mixed> $options Load options.
	 * @return string|null Null when the file can't be inspected.
	 */
	private static function signature( string $file, array $options ): ?string {
		clearstatcache( true, $file );

		$real = realpath( $file );
		$stat = false === $real ? false : stat( $real );

		if ( false === $stat ) {
			return null;
		}

		return md5(
			implode(
				'|',
				[
					$real,
					$stat['size'],
					$stat['mtime'],
					$stat['ctime'],
					$stat['ino'],
					(string) wp_json_encode( $options ),
				]
			)
		);
	}
}
