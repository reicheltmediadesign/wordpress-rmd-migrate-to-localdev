<?php
/**
 * Writes a zip archive strictly by appending, so an export can stop after any
 * entry and continue in the next request: the archive and a side file with the
 * central directory records are simply cut back to the last completed entry.
 * ZipArchive cannot do that; it rewrites the whole archive on every close().
 *
 * Files are streamed in chunks (deflate, or stored if already compressed).
 * Zip64 records are written only where needed (more than 65,535 entries,
 * files or archives of 4 GB and more).
 *
 * @package RMD\MigrateToLocaldev
 */

namespace RMD\MigrateToLocaldev\Domain;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

// Plain streams of local files; this layer has no WordPress dependency.
// phpcs:disable WordPress.WP.AlternativeFunctions

final class ZipWriter {

	private const CHUNK_BYTES = 1048576;
	private const MAX_32      = 0xFFFFFFFF;
	private const MAX_16      = 0xFFFF;

	/** Sizes from here on get Zip64 fields; leaves room for deflate making incompressible data slightly larger. */
	private const ZIP64_FILE_BYTES = 0xFFF00000;

	private const METHOD_STORE   = 0;
	private const METHOD_DEFLATE = 8;
	private const FLAG_UTF8      = 0x0800;
	private const VERSION        = 20;
	private const VERSION_ZIP64  = 45;
	private const ATTR_DIRECTORY = 0x10;

	/** Compressing these again costs time and saves nothing. */
	private const STORED_EXTENSIONS = [ 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'heic', 'mp3', 'mp4', 'm4a', 'm4v', 'mov', 'webm', 'ogg', 'zip', 'gz', 'bz2', 'xz', '7z', 'rar', 'woff', 'woff2', 'pdf', 'docx', 'xlsx', 'pptx' ];

	/**
	 * @param resource          $zip      Archive, opened for reading and writing, positioned at its end.
	 * @param resource          $central  Side file with the central directory records, positioned at its end.
	 * @param int               $entries  Number of entries already in the archive.
	 * @param DateTimeZone|null $timezone Zip stores local times without a zone; null = UTC.
	 */
	public function __construct(
		private $zip,
		private $central,
		private int $entries = 0,
		private ?DateTimeZone $timezone = null
	) {}

	public function entries(): int {
		return $this->entries;
	}

	/**
	 * Adds a file from disk. Returns the number of bytes read from it.
	 */
	public function add_file( string $name, string $path ): int {
		$source = fopen( $path, 'rb' );
		if ( false === $source ) {
			throw new RuntimeException( 'Could not read ' . $path ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Shown as text.
		}

		try {
			$expected = (int) filesize( $path );
			$mtime    = (int) filemtime( $path );
			$zip64    = $expected >= self::ZIP64_FILE_BYTES;
			$stored   = 0 === $expected || ! function_exists( 'deflate_init' ) || in_array( strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ), self::STORED_EXTENSIONS, true );
			$method   = $stored ? self::METHOD_STORE : self::METHOD_DEFLATE;
			$offset   = (int) ftell( $this->zip );

			$this->write( $this->zip, $this->local_header( $name, $method, $mtime, 0, 0, 0, $zip64 ) );

			$crc        = hash_init( 'crc32b' );
			$deflate    = self::METHOD_DEFLATE === $method ? deflate_init( ZLIB_ENCODING_RAW, [ 'level' => 6 ] ) : null;
			$size       = 0;
			$compressed = 0;

			while ( ! feof( $source ) ) {
				$chunk = fread( $source, self::CHUNK_BYTES );
				if ( false === $chunk ) {
					throw new RuntimeException( 'Could not read ' . $path ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Shown as text.
				}
				if ( '' === $chunk ) {
					continue;
				}
				hash_update( $crc, $chunk );
				$size += strlen( $chunk );
				if ( null !== $deflate ) {
					$chunk = (string) deflate_add( $deflate, $chunk, ZLIB_NO_FLUSH );
				}
				$compressed += strlen( $chunk );
				$this->write( $this->zip, $chunk );
			}
			if ( null !== $deflate ) {
				$tail        = (string) deflate_add( $deflate, '', ZLIB_FINISH );
				$compressed += strlen( $tail );
				$this->write( $this->zip, $tail );
			}

			if ( ! $zip64 && max( $size, $compressed ) >= self::MAX_32 ) {
				throw new RuntimeException( 'File grew beyond 4 GB while it was read: ' . $path ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Shown as text.
			}

			$checksum = (int) unpack( 'N', hash_final( $crc, true ) )[1];
			$end      = (int) ftell( $this->zip );
			fseek( $this->zip, $offset );
			$this->write( $this->zip, $this->local_header( $name, $method, $mtime, $checksum, $compressed, $size, $zip64 ) );
			fseek( $this->zip, $end );

			$this->remember( $name, $method, $mtime, $checksum, $compressed, $size, $offset, 0 );
			return $size;
		} finally {
			fclose( $source );
		}
	}

	/**
	 * Adds a small file from memory.
	 */
	public function add_string( string $name, string $content, int $mtime ): void {
		$offset   = (int) ftell( $this->zip );
		$checksum = (int) unpack( 'N', hash( 'crc32b', $content, true ) )[1];
		$size     = strlen( $content );

		$this->write( $this->zip, $this->local_header( $name, self::METHOD_STORE, $mtime, $checksum, $size, $size, false ) . $content );
		$this->remember( $name, self::METHOD_STORE, $mtime, $checksum, $size, $size, $offset, 0 );
	}

	/**
	 * Adds a folder entry, so empty folders exist after unpacking.
	 */
	public function add_directory( string $name, int $mtime ): void {
		$name   = rtrim( $name, '/' ) . '/';
		$offset = (int) ftell( $this->zip );

		$this->write( $this->zip, $this->local_header( $name, self::METHOD_STORE, $mtime, 0, 0, 0, false ) );
		$this->remember( $name, self::METHOD_STORE, $mtime, 0, 0, 0, $offset, self::ATTR_DIRECTORY );
	}

	/**
	 * Appends the central directory from the side file and the end records.
	 */
	public function finish(): void {
		$offset = (int) ftell( $this->zip );
		rewind( $this->central );
		$size = stream_copy_to_stream( $this->central, $this->zip );
		if ( false === $size ) {
			throw new RuntimeException( 'Could not write the directory of the archive.' );
		}

		$end = '';
		if ( $this->entries > self::MAX_16 || $size >= self::MAX_32 || $offset >= self::MAX_32 ) {
			$end .= pack( 'VPvvVVPPPP', 0x06064b50, 44, self::VERSION_ZIP64, self::VERSION_ZIP64, 0, 0, $this->entries, $this->entries, $size, $offset );
			$end .= pack( 'VVPV', 0x07064b50, 0, $offset + $size, 1 );
		}
		$end .= pack(
			'VvvvvVVv',
			0x06054b50,
			0,
			0,
			min( $this->entries, self::MAX_16 ),
			min( $this->entries, self::MAX_16 ),
			min( $size, self::MAX_32 ),
			min( $offset, self::MAX_32 ),
			0
		);
		$this->write( $this->zip, $end );
	}

	private function local_header( string $name, int $method, int $mtime, int $checksum, int $compressed, int $size, bool $zip64 ): string {
		[ $time, $date ] = $this->dos_time( $mtime );
		$extra           = $zip64 ? pack( 'vvPP', 0x0001, 16, $size, $compressed ) : '';

		return pack(
			'VvvvvvVVVvv',
			0x04034b50,
			$zip64 ? self::VERSION_ZIP64 : self::VERSION,
			self::flags( $name ),
			$method,
			$time,
			$date,
			$checksum,
			$zip64 ? self::MAX_32 : $compressed,
			$zip64 ? self::MAX_32 : $size,
			strlen( $name ),
			strlen( $extra )
		) . $name . $extra;
	}

	/**
	 * Appends the central directory record of an entry to the side file.
	 */
	private function remember( string $name, int $method, int $mtime, int $checksum, int $compressed, int $size, int $offset, int $attributes ): void {
		[ $time, $date ] = $this->dos_time( $mtime );

		$fields = '';
		if ( $size >= self::MAX_32 ) {
			$fields .= pack( 'P', $size );
		}
		if ( $compressed >= self::MAX_32 ) {
			$fields .= pack( 'P', $compressed );
		}
		if ( $offset >= self::MAX_32 ) {
			$fields .= pack( 'P', $offset );
		}
		$extra = '' === $fields ? '' : pack( 'vv', 0x0001, strlen( $fields ) ) . $fields;

		$record = pack(
			'VvvvvvvVVVvvvvvVV',
			0x02014b50,
			self::VERSION_ZIP64,
			'' === $extra ? self::VERSION : self::VERSION_ZIP64,
			self::flags( $name ),
			$method,
			$time,
			$date,
			$checksum,
			min( $compressed, self::MAX_32 ),
			min( $size, self::MAX_32 ),
			strlen( $name ),
			strlen( $extra ),
			0,
			0,
			0,
			$attributes,
			min( $offset, self::MAX_32 )
		) . $name . $extra;

		$this->write( $this->central, $record );
		++$this->entries;
	}

	private static function flags( string $name ): int {
		return 1 === preg_match( '//u', $name ) ? self::FLAG_UTF8 : 0;
	}

	/**
	 * @return array{int, int} Time and date in the MS-DOS format zip uses (two-second resolution, from 1980).
	 */
	private function dos_time( int $mtime ): array {
		$local = ( new DateTimeImmutable( '@' . $mtime ) )->setTimezone( $this->timezone ?? new DateTimeZone( 'UTC' ) );
		$parts = array_map( 'intval', explode( ' ', $local->format( 'Y n j G i s' ) ) );
		if ( $parts[0] < 1980 ) {
			$parts = [ 1980, 1, 1, 0, 0, 0 ];
		}
		return [
			( $parts[3] << 11 ) | ( $parts[4] << 5 ) | intdiv( $parts[5], 2 ),
			( ( $parts[0] - 1980 ) << 9 ) | ( $parts[1] << 5 ) | $parts[2],
		];
	}

	/**
	 * @param resource $handle
	 */
	private function write( $handle, string $data ): void {
		if ( '' !== $data && strlen( $data ) !== fwrite( $handle, $data ) ) {
			throw new RuntimeException( 'Writing the archive failed. Is the disk full?' );
		}
	}
}
