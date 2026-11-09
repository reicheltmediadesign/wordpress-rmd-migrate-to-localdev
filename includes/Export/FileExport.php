<?php
/**
 * Packs the WordPress installation into the export's zip archive in two
 * resumable passes: scan (writes a manifest) and zip (works through it).
 *
 * @package RMD\MigrateToLocaldev
 */

namespace RMD\MigrateToLocaldev\Export;

use RMD\MigrateToLocaldev\Domain\PathFilter;
use RMD\MigrateToLocaldev\Domain\Target;
use RMD\MigrateToLocaldev\Domain\ZipWriter;
use RMD\MigrateToLocaldev\Settings;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

// Reading thousands of files of the installation itself; WP_Filesystem adds nothing here.
// phpcs:disable WordPress.WP.AlternativeFunctions

final class FileExport {

	/**
	 * @param array<string, mixed> $job
	 */
	public static function scan( array &$job, Target $target, float $deadline ): void {
		$scan     = &$job['scan'];
		$root     = (string) $job['source']['abspath'];
		$uploads  = strtolower( (string) $job['source']['uploads'] );
		$filter   = PathFilter::from_lines( $target->exclude_patterns );
		$excluded = self::hard_excludes( $job );
		$handle   = Exports::open_append( $job['dir'] . '/' . Job::MANIFEST_FILE, (int) $scan['bytes'] );

		try {
			while ( [] !== $scan['stack'] && microtime( true ) < $deadline ) {
				$dir     = (string) array_pop( $scan['stack'] );
				$abs_dir = '' === $dir ? $root : $root . '/' . $dir;
				$entries = scandir( $abs_dir );
				if ( false === $entries ) {
					Job::warn(
						$job,
						sprintf(
							/* translators: %s: folder path */
							__( 'Folder could not be read and was skipped: %s', 'rmd-migrate-to-localdev' ),
							$abs_dir
						)
					);
					continue;
				}
				// The uploads folder itself stays in the copy, only its content is left out.
				if ( ! $target->include_uploads && strtolower( $abs_dir ) === $uploads ) {
					continue;
				}

				foreach ( $entries as $name ) {
					if ( '.' === $name || '..' === $name ) {
						continue;
					}
					$rel    = '' === $dir ? $name : $dir . '/' . $name;
					$abs    = $abs_dir . '/' . $name;
					$is_dir = is_dir( $abs );

					if ( in_array( strtolower( $abs ), $excluded, true ) || $filter->matches( $rel, $is_dir ) ) {
						continue;
					}

					if ( ! $is_dir ) {
						self::write( $handle, "F\t" . $rel . "\n" );
						++$scan['files'];
						$scan['size'] += (int) filesize( $abs );
						continue;
					}

					// A linked folder may point anywhere, even back into the installation.
					if ( is_link( $abs ) ) {
						Job::warn(
							$job,
							sprintf(
								/* translators: %s: folder path */
								__( 'Linked folder was skipped: %s', 'rmd-migrate-to-localdev' ),
								$abs
							)
						);
						continue;
					}

					self::write( $handle, "D\t" . $rel . "\n" );
					$scan['stack'][] = $rel;
				}
				$scan['bytes'] = (int) ftell( $handle );
			}
			$scan['bytes'] = (int) ftell( $handle );
		} finally {
			fclose( $handle );
		}
	}

	/**
	 * Packs manifest entries until the time is up. Returns true when all are done.
	 *
	 * @param array<string, mixed> $job
	 */
	public static function zip( array &$job, Target $target, float $deadline ): bool {
		$state    = &$job['zip'];
		$root     = (string) $job['source']['abspath'];
		$manifest = fopen( $job['dir'] . '/' . Job::MANIFEST_FILE, 'rb' );
		if ( false === $manifest ) {
			throw new RuntimeException( esc_html__( 'The file list of this export is missing.', 'rmd-migrate-to-localdev' ) );
		}
		$archive = Exports::open_append( $job['dir'] . '/' . Job::ZIP_FILE, (int) $state['bytes'] );
		$central = Exports::open_append( $job['dir'] . '/' . Job::CENTRAL_FILE, (int) $state['central'] );
		$writer  = new ZipWriter( $archive, $central, (int) $state['entries'], wp_timezone() );

		try {
			fseek( $manifest, (int) $state['offset'] );
			while ( microtime( true ) < $deadline ) {
				$line = fgets( $manifest );
				if ( false === $line ) {
					return true;
				}
				$parts = explode( "\t", rtrim( $line, "\n" ), 2 );
				$rel   = $parts[1] ?? '';
				$abs   = $root . '/' . $rel;
				$name  = $target->folder . '/' . $rel;

				if ( 'D' === $parts[0] ) {
					$writer->add_directory( $name, (int) filemtime( $abs ) );
				} elseif ( 'F' === $parts[0] ) {
					if ( is_file( $abs ) && is_readable( $abs ) ) {
						$state['size'] += $writer->add_file( $name, $abs );
						++$state['files'];
					} else {
						++$job['report']['failed'];
						Job::warn(
							$job,
							sprintf(
								/* translators: %s: file path */
								__( 'File could not be read and is missing in the archive: %s', 'rmd-migrate-to-localdev' ),
								$abs
							)
						);
					}
				}

				$state['offset']  = (int) ftell( $manifest );
				$state['bytes']   = (int) ftell( $archive );
				$state['central'] = (int) ftell( $central );
				$state['entries'] = $writer->entries();
			}
			return false;
		} finally {
			fclose( $manifest );
			fclose( $archive );
			fclose( $central );
		}
	}

	/**
	 * Paths that are never packed: wp-config.php and .htaccess (versions for the
	 * local copy are written instead), the export folder and this plugin.
	 *
	 * @param array<string, mixed> $job
	 * @return list<string> Lower-case absolute paths.
	 */
	private static function hard_excludes( array $job ): array {
		$root = (string) $job['source']['abspath'];
		return array_map(
			'strtolower',
			[
				$root . '/wp-config.php',
				$root . '/.htaccess',
				untrailingslashit( Settings::export_dir() ),
				untrailingslashit( wp_normalize_path( RMD_MTL_DIR ) ),
				wp_normalize_path( WP_PLUGIN_DIR ) . '/' . dirname( RMD_MTL_BASENAME ),
			]
		);
	}

	/**
	 * @param resource $handle
	 */
	private static function write( $handle, string $data ): void {
		if ( false === fwrite( $handle, $data ) ) {
			throw new RuntimeException( esc_html__( 'Writing the file list failed. Is the disk full?', 'rmd-migrate-to-localdev' ) );
		}
	}
}
