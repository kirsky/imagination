<?php
/**
 * Context for a single Libvips encoding operation.
 *
 * @package Imagination
 */

declare( strict_types=1 );

namespace Indigit\Imagination\Encoder;

defined( 'ABSPATH' ) || exit;

/**
 * Encoding context.
 */
final class Encoding_Context {

	/**
	 * WordPress quality value (1-100).
	 *
	 * @var int
	 */
	public int $quality;

	/**
	 * Whether metadata should be stripped.
	 *
	 * @var bool
	 */
	public bool $strip_metadata;

	/**
	 * Source image path.
	 *
	 * @var string
	 */
	public string $source_file;

	/**
	 * Source image MIME type.
	 *
	 * @var string
	 */
	public string $source_mime_type;

	/**
	 * Output image MIME type.
	 *
	 * @var string
	 */
	public string $output_mime_type;

	/**
	 * Constructor.
	 *
	 * @param int    $quality          WordPress quality value.
	 * @param bool   $strip_metadata   Whether metadata should be stripped.
	 * @param string $source_file      Source image path.
	 * @param string $source_mime_type Source image MIME type.
	 * @param string $output_mime_type Output image MIME type.
	 */
	public function __construct(
		int $quality,
		bool $strip_metadata,
		string $source_file,
		string $source_mime_type,
		string $output_mime_type
	) {
		$this->quality          = $quality;
		$this->strip_metadata   = $strip_metadata;
		$this->source_file      = $source_file;
		$this->source_mime_type = $source_mime_type;
		$this->output_mime_type = $output_mime_type;
	}
}
