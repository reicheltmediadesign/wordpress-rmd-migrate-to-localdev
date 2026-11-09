<?php
/**
 * Parses an HTTP Range header for a download, so browsers can resume a large
 * archive after a dropped connection.
 *
 * @package RMD\MigrateToLocaldev
 */

namespace RMD\MigrateToLocaldev\Domain;

final class ByteRange {

	/**
	 * @param string $header Value of the Range header ("" if absent).
	 * @param int    $size   Size of the file.
	 * @return array{start: int, end: int}|null|false First and last byte to send, null for the whole
	 *                                                 file (no or unsupported header), false if the
	 *                                                 range cannot be satisfied (HTTP 416).
	 */
	public static function parse( string $header, int $size ) {
		if ( ! preg_match( '/^\s*bytes\s*=\s*(\d*)\s*-\s*(\d*)\s*$/i', $header, $match ) || ( '' === $match[1] && '' === $match[2] ) ) {
			return null;
		}

		if ( '' === $match[1] ) {
			$length = (int) $match[2];
			if ( 0 === $length || 0 === $size ) {
				return false;
			}
			return [
				'start' => max( 0, $size - $length ),
				'end'   => $size - 1,
			];
		}

		$start = (int) $match[1];
		$end   = '' === $match[2] ? $size - 1 : min( (int) $match[2], $size - 1 );
		if ( $start >= $size || $start > $end ) {
			return false;
		}
		return [
			'start' => $start,
			'end'   => $end,
		];
	}
}
