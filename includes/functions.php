<?php
/**
 * Functions
 *
 * @package Imagination
 */

namespace Indigit\Imagination;

/**
 * Functions
 *
 * @package Imagination
 *
 * phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fread
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose
 */

/**
 * Inspects a WebP file's compression type, alpha and animation flags by
 * walking its RIFF chunks.
 *
 * Unlike core's wp_get_webp_info(), which reports every VP8X file as
 * 'animated-alpha' and wraps VP8L heights above 4096, it reads the VP8X flags
 * and the real bitstream chunk (or the first animation frame).
 *
 * `lossless` is null only for a malformed or truncated file. `mixed_frame_types`
 * is true for animations, whose later frames may use the other codec.
 *
 * @param string $filename Path to a WebP file.
 * @return array{
 *     width: int|false,
 *     height: int|false,
 *     type: string|false,
 *     lossless: bool|null,
 *     has_alpha: bool,
 *     is_animated: bool,
 *     mixed_frame_types: bool
 * }
 */
function get_webp_info_precise( $filename ) {
	$result = [
		'width'             => false,
		'height'            => false,
		'type'              => false,
		'lossless'          => null,
		'has_alpha'         => false,
		'is_animated'       => false,
		'mixed_frame_types' => false,
	];

	$fh = @fopen( $filename, 'rb' );
	if ( false === $fh ) {
		return $result;
	}

	$header = fread( $fh, 12 );
	if ( false === $header || strlen( $header ) < 12
		|| 'RIFF' !== substr( $header, 0, 4 )
		|| 'WEBP' !== substr( $header, 8, 4 )
	) {
		fclose( $fh );
		return $result;
	}

	while ( ! feof( $fh ) ) {
		$chunk_header = fread( $fh, 8 );
		if ( false === $chunk_header || strlen( $chunk_header ) < 8 ) {
			break; // Truncated or EOF.
		}

		$fourcc      = substr( $chunk_header, 0, 4 );
		$size_parts  = unpack( 'V', substr( $chunk_header, 4, 4 ) );
		$chunk_size  = $size_parts[1];
		$chunk_start = ftell( $fh );
		$chunk_end   = $chunk_start + $chunk_size + ( $chunk_size % 2 ); // RIFF chunks pad to even size.

		switch ( $fourcc ) {

			case 'VP8 ': // Simple lossy — only possible as the very first chunk.
				$result['lossless'] = false;
				$body               = fread( $fh, min( $chunk_size, 10 ) );
				if ( strlen( $body ) >= 10 ) {
					$dims             = unpack( 'v2', substr( $body, 6, 4 ) );
					$result['width']  = $dims[1] & 0x3FFF;
					$result['height'] = $dims[2] & 0x3FFF;
				}
				break 2; // Nothing else to learn.

			case 'VP8L': // Simple lossless — only possible as the very first chunk.
				$result['lossless'] = true;
				$body               = fread( $fh, min( $chunk_size, 5 ) );
				if ( strlen( $body ) >= 5 && "\x2f" === $body[0] ) {
					// 14 bits width-1, 14 bits height-1, 1 bit alpha_is_used, 3 bits version.
					$bits             = unpack( 'C4', substr( $body, 1, 4 ) );
					$result['width']  = ( $bits[1] | ( ( $bits[2] & 0x3F ) << 8 ) ) + 1;
					$result['height'] = ( ( ( $bits[2] & 0xC0 ) >> 6 ) | ( $bits[3] << 2 ) | ( ( $bits[4] & 0x0F ) << 10 ) ) + 1;
					// alpha_is_used is bit 28 of the packed header = bit 4 of this last byte.
					$result['has_alpha'] = (bool) ( ( $bits[4] >> 4 ) & 0x01 );
				}
				break 2;

			case 'VP8X': // Extended container — flags are authoritative for alpha/animation.
				$body = fread( $fh, min( $chunk_size, 10 ) );
				if ( strlen( $body ) >= 10 ) {
					$flags                 = ord( $body[0] );
					$result['has_alpha']   = (bool) ( $flags & 0x10 );
					$result['is_animated'] = (bool) ( $flags & 0x02 );
					$w                     = unpack(
						'V',
						substr( $body, 4, 3 ) . "\x00"
					);
					$h                     = unpack(
						'V',
						substr( $body, 7, 3 ) . "\x00"
					);
					$result['width']       = ( $w[1] & 0xFFFFFF ) + 1;
					$result['height']      = ( $h[1] & 0xFFFFFF ) + 1;
				}
				// Still need lossy/lossless — keep walking to find the real bitstream.
				fseek( $fh, $chunk_end );
				continue 2;

			case 'ANIM':
				// Background color + loop count — nothing we need, skip.
				fseek( $fh, $chunk_end );
				continue 2;

			case 'ANMF':
				// First animation frame. Classify from it; flag that later frames
				// could theoretically use the other codec (VP8/VP8L can be mixed
				// across frames per the WebP spec — genuinely rare in practice).
				$result['mixed_frame_types'] = true;
				fseek(
					$fh,
					$chunk_start + 16
				); // Skip the fixed 16-byte frame header.
				while ( ftell( $fh ) < $chunk_end ) {
					$sub_header = fread( $fh, 8 );
					if ( false === $sub_header || strlen( $sub_header ) < 8 ) {
						break;
					}
					$sub_fourcc = substr( $sub_header, 0, 4 );
					$sub_size   = unpack( 'V', substr( $sub_header, 4, 4 ) );
					$sub_start  = ftell( $fh );
					if ( 'VP8 ' === $sub_fourcc ) {
						$result['lossless'] = false;
						break;
					}
					if ( 'VP8L' === $sub_fourcc ) {
						$result['lossless'] = true;
						break;
					}
					// ALPH (separate alpha for a lossy frame) or anything else — skip.
					fseek(
						$fh,
						$sub_start + $sub_size[1] + ( $sub_size[1] % 2 )
					);
				}
				break 2;

			case 'ALPH':
				// Separate alpha chunk for a still lossy image — flag already told us
				// this; the real VP8 bitstream chunk immediately follows.
				fseek( $fh, $chunk_end );
				continue 2;

			default:
				// ICCP, EXIF, XMP, or anything unrecognized — irrelevant, skip.
				fseek( $fh, $chunk_end );
				continue 2;
		}
	}

	fclose( $fh );

	if ( null !== $result['lossless'] ) {
		$result['type'] = $result['lossless'] ? 'lossless' : 'lossy';
	}

	return $result;
}
