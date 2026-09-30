<?php
/**
 * Result of a format's pre-save preparation.
 *
 * @package Imagination
 */

declare( strict_types=1 );

namespace Indigit\Imagination\Encoder;

defined( 'ABSPATH' ) || exit;

use Indigit\Imagination\Vendor\Jcupitt\Vips\Image;

/**
 * An image ready to be encoded, plus what the format decided while preparing it.
 *
 * Returned by {@see Image_Preparer::prepare_image()}.
 */
final class Prepared_Image {

	/**
	 * The image to encode. May be the image that was passed in.
	 *
	 * @var Image
	 */
	public Image $image;

	/**
	 * Saver options that override the ones from Format::get_options().
	 *
	 * @var array<string, mixed>
	 */
	public array $options;

	/**
	 * ForeignKeep mask of the metadata to keep when metadata is stripped, or
	 * null for the default policy (Libvips_Encoder::KEEP_WHEN_STRIPPING).
	 * Note that ForeignKeep::NONE is 0, so test with `null !==`.
	 *
	 * @var int|null
	 */
	public ?int $keep;

	/**
	 * Constructor.
	 *
	 * @param Image                $image   Image to encode.
	 * @param array<string, mixed> $options Saver options overriding the format's.
	 * @param int|null             $keep    ForeignKeep mask, or null for the default policy.
	 */
	public function __construct(
		Image $image,
		array $options = [],
		?int $keep = null
	) {
		$this->image   = $image;
		$this->options = $options;
		$this->keep    = $keep;
	}
}
