<?php
/**
 * Last export step: adds wp-config.php and .htaccess for the local copy to the
 * archive and closes it, compresses the dump and writes the MIGRATION.txt
 * report. Can be repeated after an error.
 *
 * @package RMD\MigrateToLocaldev
 */

namespace RMD\MigrateToLocaldev\Export;

use RMD\MigrateToLocaldev\Domain\Htaccess;
use RMD\MigrateToLocaldev\Domain\ReplacementPlan;
use RMD\MigrateToLocaldev\Domain\Target;
use RMD\MigrateToLocaldev\Domain\WpConfig;
use RMD\MigrateToLocaldev\Domain\ZipWriter;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

// Files of the export folder; see Exports.
// phpcs:disable WordPress.WP.AlternativeFunctions

final class Finisher {

	/** The .htaccess of the live site, kept for reference next to the new one. */
	public const LIVE_HTACCESS = '.htaccess.live';

	/**
	 * @param array<string, mixed> $job
	 */
	public static function run( array &$job, Target $target ): void {
		$dir = (string) $job['dir'];

		// The flag keeps a repeated step from appending the directory to a finished archive.
		if ( $target->include_files && empty( $job['zip']['closed'] ) ) {
			self::close_archive( $job, $target );
			$job['zip']['closed'] = true;
		}
		foreach ( [ Job::MANIFEST_FILE, Job::CENTRAL_FILE ] as $file ) {
			if ( file_exists( $dir . '/' . $file ) ) {
				wp_delete_file( $dir . '/' . $file );
			}
		}

		if ( file_exists( $dir . '/' . Job::SQL_FILE ) && function_exists( 'gzopen' ) ) {
			self::gzip( $dir . '/' . Job::SQL_FILE );
		}

		$job['finished'] = time();
		self::put( $dir . '/' . Job::REPORT_FILE, Report::render( $job, $target, Job::plan( $job, $target ) ) );
	}

	/**
	 * @param array<string, mixed> $job
	 */
	private static function close_archive( array &$job, Target $target ): void {
		$dir  = (string) $job['dir'];
		$root = (string) $job['source']['abspath'];
		$now  = time();

		// Cut back to the state after the last packed file, in case this step runs again.
		$archive = Exports::open_append( $dir . '/' . Job::ZIP_FILE, (int) $job['zip']['bytes'] );
		$central = Exports::open_append( $dir . '/' . Job::CENTRAL_FILE, (int) $job['zip']['central'] );
		$writer  = new ZipWriter( $archive, $central, (int) $job['zip']['entries'], wp_timezone() );

		try {
			$config = self::find_wp_config( $root );
			if ( null === $config ) {
				Job::warn( $job, __( 'wp-config.php was not found. Create one for the local copy yourself.', 'rmd-migrate-to-localdev' ) );
			} else {
				$writer->add_string( $target->folder . '/wp-config.php', self::local_config( $job, $target, (string) file_get_contents( $config ) ), $now );
			}

			$writer->add_string( $target->folder . '/.htaccess', Htaccess::for_local( ReplacementPlan::url_path( $target->url() ) ), $now );
			if ( is_readable( $root . '/.htaccess' ) ) {
				$writer->add_file( $target->folder . '/' . self::LIVE_HTACCESS, $root . '/.htaccess' );
			}

			$writer->finish();
		} finally {
			fclose( $archive );
			fclose( $central );
		}
	}

	/**
	 * @param array<string, mixed> $job
	 */
	private static function local_config( array &$job, Target $target, string $source ): string {
		$salts = [];
		foreach ( WpConfig::SALT_KEYS as $key ) {
			$salts[ $key ] = wp_generate_password( 64, true, true );
		}
		$database = [
			'DB_NAME'     => $target->database(),
			'DB_USER'     => $target->db_user,
			'DB_PASSWORD' => $target->db_password,
			'DB_HOST'     => Target::DB_HOST,
		];

		// Addresses and paths in constants like WP_HOME; link rewriting is for markup only.
		$config = strtr( $source, Job::plan( $job, $target, false )->pairs );
		$config = WpConfig::render( $config, $database, $salts );

		foreach ( array_keys( $database ) as $constant ) {
			if ( ! WpConfig::defines( $config, $constant ) ) {
				Job::warn(
					$job,
					sprintf(
						/* translators: %s: name of a PHP constant */
						__( 'wp-config.php does not define %s in the usual way. Check the database access in the local copy.', 'rmd-migrate-to-localdev' ),
						$constant
					)
				);
			}
		}
		return $config;
	}

	private static function gzip( string $path ): void {
		$source = fopen( $path, 'rb' );
		$target = gzopen( $path . '.gz', 'wb6' );
		if ( false === $source || false === $target ) {
			throw new RuntimeException( esc_html__( 'The database dump could not be compressed.', 'rmd-migrate-to-localdev' ) );
		}
		try {
			while ( ! feof( $source ) ) {
				$chunk = fread( $source, 1024 * 1024 );
				if ( false === $chunk || ( '' !== $chunk && false === gzwrite( $target, $chunk ) ) ) {
					throw new RuntimeException( esc_html__( 'The database dump could not be compressed.', 'rmd-migrate-to-localdev' ) );
				}
			}
		} finally {
			fclose( $source );
			gzclose( $target );
		}
		wp_delete_file( $path );
	}

	/**
	 * WordPress also finds wp-config.php one level above ABSPATH.
	 */
	private static function find_wp_config( string $root ): ?string {
		if ( is_readable( $root . '/wp-config.php' ) ) {
			return $root . '/wp-config.php';
		}
		$above = dirname( $root ) . '/wp-config.php';
		return is_readable( $above ) && ! file_exists( dirname( $root ) . '/wp-settings.php' ) ? $above : null;
	}

	private static function put( string $path, string $content ): void {
		if ( false === file_put_contents( $path, $content ) ) {
			throw new RuntimeException(
				esc_html(
					sprintf(
						/* translators: %s: file path */
						__( 'Could not write %s.', 'rmd-migrate-to-localdev' ),
						$path
					)
				)
			);
		}
	}
}
