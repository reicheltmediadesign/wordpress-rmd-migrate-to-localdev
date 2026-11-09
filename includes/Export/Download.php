<?php
/**
 * Sends a file of a finished export to the browser. The export folder itself
 * is closed to the web; downloads only go through this handler, for
 * administrators with a valid nonce. Range requests allow resuming.
 *
 * @package RMD\MigrateToLocaldev
 */

namespace RMD\MigrateToLocaldev\Export;

use RMD\MigrateToLocaldev\Domain\ByteRange;
use RMD\MigrateToLocaldev\Plugin;
use Throwable;

defined( 'ABSPATH' ) || exit;

// Streams a file of up to several gigabytes; WP_Filesystem reads whole files into memory.
// phpcs:disable WordPress.WP.AlternativeFunctions

final class Download {

	private const CHUNK_BYTES = 1048576;

	public static function init(): void {
		add_action( 'admin_post_rmd_mtl_download', [ self::class, 'handle' ] );
	}

	public static function handle(): void {
		if ( ! current_user_can( Plugin::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'rmd-migrate-to-localdev' ), 403 );
		}
		$export = isset( $_GET['export'] ) ? Exports::clean_name( sanitize_text_field( wp_unslash( $_GET['export'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked below.
		$key    = isset( $_GET['file'] ) ? sanitize_key( wp_unslash( $_GET['file'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked below.
		check_admin_referer( 'rmd_mtl_download_' . $export );

		try {
			$file = Exports::file( $export, $key );
		} catch ( Throwable $e ) {
			$file = null;
		}
		$handle = null === $file ? false : fopen( $file['path'], 'rb' );
		if ( null === $file || false === $handle ) {
			wp_die( esc_html__( 'This file does not exist any more. Exports are deleted automatically; create a new one.', 'rmd-migrate-to-localdev' ), 404 );
		}

		$size  = (int) filesize( $file['path'] );
		$range = ByteRange::parse( isset( $_SERVER['HTTP_RANGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ) : '', $size );

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 );
		}
		// Compression by PHP would hide the length from the browser and buffer the file.
		ini_set( 'zlib.output_compression', '0' ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Accel-Buffering: no' );
		header( 'Accept-Ranges: bytes' );

		if ( false === $range ) {
			fclose( $handle );
			status_header( 416 );
			header( 'Content-Range: bytes */' . $size );
			exit;
		}

		$start = null === $range ? 0 : $range['start'];
		$end   = null === $range ? $size - 1 : $range['end'];
		if ( null !== $range ) {
			status_header( 206 );
			header( 'Content-Range: bytes ' . $start . '-' . $end . '/' . $size );
		}
		header( 'Content-Type: ' . $file['type'] );
		header( 'Content-Disposition: attachment; filename="' . $file['name'] . '"' );
		header( 'Content-Length: ' . ( $end - $start + 1 ) );

		fseek( $handle, $start );
		$left = $end - $start + 1;
		while ( $left > 0 && ! feof( $handle ) && ! connection_aborted() ) {
			$chunk = fread( $handle, min( self::CHUNK_BYTES, $left ) );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			$left -= strlen( $chunk );
			echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary file content.
			flush();
		}
		fclose( $handle );
		exit;
	}
}
