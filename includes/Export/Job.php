<?php
/**
 * One export run. The state lives in an option so the export can be split into
 * short steps (one REST request each) and survives time limits and reloads.
 *
 * Phases: database → scan → zip (only with files) → finalize → done.
 *
 * @package RMD\MigrateToLocaldev
 */

namespace RMD\MigrateToLocaldev\Export;

use RMD\MigrateToLocaldev\Domain\ReplacementPlan;
use RMD\MigrateToLocaldev\Domain\Target;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

final class Job {

	public const OPTION = 'rmd_mtl_job';
	private const LOCK  = 'rmd_mtl_lock';

	/** Seconds of work per request, kept well below common time limits. */
	private const STEP_SECONDS = 12;

	public const SQL_FILE      = 'database.sql';
	public const ZIP_FILE      = 'files.zip';
	public const CENTRAL_FILE  = '.central';
	public const MANIFEST_FILE = '.manifest';
	public const REPORT_FILE   = 'MIGRATION.txt';

	/**
	 * @return array<string, mixed>|null
	 */
	public static function current(): ?array {
		$job = get_option( self::OPTION, null );
		return is_array( $job ) && isset( $job['phase'], $job['dir'], $job['target'] ) ? $job : null;
	}

	public static function is_running(): bool {
		$job = self::current();
		return null !== $job && 'done' !== $job['phase'];
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function start( Target $target ): array {
		global $wpdb;

		if ( is_multisite() ) {
			throw new RuntimeException( esc_html__( 'Multisite networks are not supported.', 'rmd-migrate-to-localdev' ) );
		}
		if ( self::is_running() ) {
			throw new RuntimeException( esc_html__( 'Another export is still running. Continue or cancel it first.', 'rmd-migrate-to-localdev' ) );
		}
		Exports::purge_expired();

		$root = Exports::prepare_root();
		$dir  = $root . '/' . wp_date( 'Y-m-d_His' ) . '_' . $target->folder;
		if ( ! wp_mkdir_p( $dir ) ) {
			throw new RuntimeException( esc_html__( 'The export folder could not be created.', 'rmd-migrate-to-localdev' ) );
		}

		$abspath = untrailingslashit( wp_normalize_path( ABSPATH ) );
		$real    = realpath( ABSPATH );
		$uploads = wp_upload_dir( null, false );

		$job = [
			'id'       => basename( $dir ),
			'dir'      => $dir,
			'target'   => $target->to_array(),
			'source'   => [
				'home'     => untrailingslashit( home_url() ),
				'siteurl'  => untrailingslashit( site_url() ),
				'abspath'  => $abspath,
				'realpath' => false === $real ? $abspath : untrailingslashit( wp_normalize_path( $real ) ),
				'uploads'  => untrailingslashit( wp_normalize_path( (string) $uploads['basedir'] ) ),
				'prefix'   => $wpdb->prefix,
			],
			'phase'    => 'database',
			'started'  => time(),
			'finished' => 0,
			'db'       => DatabaseExport::init( $target ),
			'scan'     => [
				'stack' => [ '' ],
				'bytes' => 0,
				'files' => 0,
				'size'  => 0,
			],
			'zip'      => [
				'offset'  => 0,
				'bytes'   => 0,
				'central' => 0,
				'entries' => 0,
				'files'   => 0,
				'size'    => 0,
			],
			'report'   => [
				'tables'     => [],
				'leftovers'  => [],
				'unparsable' => [],
				'warnings'   => [],
				'failed'     => 0,
			],
		];

		update_option( self::OPTION, $job, false );
		return $job;
	}

	/**
	 * Runs work for a few seconds and returns the updated job.
	 *
	 * @return array<string, mixed>
	 */
	public static function step(): array {
		$job = self::current();
		if ( null === $job ) {
			throw new RuntimeException( esc_html__( 'There is no export to continue.', 'rmd-migrate-to-localdev' ) );
		}
		if ( 'done' === $job['phase'] ) {
			return $job;
		}
		if ( get_transient( self::LOCK ) ) {
			throw new RuntimeException( esc_html__( 'The export is busy with the previous step. Please wait a moment.', 'rmd-migrate-to-localdev' ) );
		}
		set_transient( self::LOCK, 1, 5 * MINUTE_IN_SECONDS );

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 5 * MINUTE_IN_SECONDS );
		}
		$deadline = microtime( true ) + self::STEP_SECONDS;

		try {
			$target = self::target( $job );
			switch ( $job['phase'] ) {
				case 'database':
					DatabaseExport::run( $job, $target, self::plan( $job, $target ), $deadline );
					if ( 'done' === $job['db']['state'] ) {
						$job['phase'] = $target->include_files ? 'scan' : 'finalize';
					}
					break;

				case 'scan':
					FileExport::scan( $job, $target, $deadline );
					if ( [] === $job['scan']['stack'] ) {
						$job['phase'] = 'zip';
					}
					break;

				case 'zip':
					if ( FileExport::zip( $job, $target, $deadline ) ) {
						$job['phase'] = 'finalize';
					}
					break;

				case 'finalize':
					Finisher::run( $job, $target );
					$job['phase']    = 'done';
					$job['finished'] = time();
					break;
			}
		} finally {
			update_option( self::OPTION, $job, false );
			delete_transient( self::LOCK );
		}

		return $job;
	}

	/**
	 * Stops a running export and removes its unfinished folder; forgets a finished one.
	 */
	public static function cancel(): void {
		$job = self::current();
		if ( null !== $job && 'done' !== $job['phase'] ) {
			Exports::delete( basename( (string) $job['dir'] ) );
		}
		delete_option( self::OPTION );
		delete_transient( self::LOCK );
	}

	/**
	 * @param array<string, mixed> $job
	 */
	public static function target( array $job ): Target {
		return Target::parse( (array) $job['target'] )['target'];
	}

	/**
	 * @param array<string, mixed> $job
	 * @param bool|null            $root_relative Overrides the option of the target (the wp-config.php is plain PHP, not markup).
	 */
	public static function plan( array $job, Target $target, ?bool $root_relative = null ): ReplacementPlan {
		$source = (array) $job['source'];
		$home   = (string) $source['home'];
		$urls   = [ $home => $target->url() ];

		$siteurl = (string) $source['siteurl'];
		if ( $siteurl !== $home ) {
			$urls[ $siteurl ] = str_starts_with( $siteurl, $home )
				? $target->url() . substr( $siteurl, strlen( $home ) )
				: $target->url();
		}

		return ReplacementPlan::build(
			ReplacementPlan::with_www_variants( $urls ),
			array_values( array_unique( [ (string) $source['abspath'], (string) $source['realpath'] ] ) ),
			$target->path(),
			$root_relative ?? $target->root_relative_links
		);
	}

	/**
	 * Adds a warning to the report (capped so the option stays small).
	 *
	 * @param array<string, mixed> $job
	 */
	public static function warn( array &$job, string $message ): void {
		if ( count( $job['report']['warnings'] ) < 100 ) {
			$job['report']['warnings'][] = $message;
		}
	}

	/**
	 * Data for the admin screen.
	 *
	 * @param array<string, mixed> $job
	 * @return array<string, mixed>
	 */
	public static function status( array $job ): array {
		$target = self::target( $job );
		$files  = $target->include_files;
		$db     = (array) $job['db'];
		$tables = max( 1, count( (array) $db['tables'] ) );
		$done   = 'done' === $job['phase'];

		$db_share = $files ? 0.3 : 0.95;
		$db_part  = min( 1, (int) $db['index'] / $tables );
		$zip_part = $job['scan']['size'] > 0 ? min( 1, (int) $job['zip']['size'] / (int) $job['scan']['size'] ) : 0;
		$progress = match ( $job['phase'] ) {
			'database' => $db_share * $db_part,
			'scan'     => $db_share + 0.05,
			'zip'      => $db_share + 0.05 + 0.6 * $zip_part,
			'finalize' => 0.97,
			default    => 1.0,
		};

		$message = match ( $job['phase'] ) {
			'database' => sprintf(
				/* translators: 1: table name, 2: number of the table, 3: number of tables */
				__( 'Exporting table %1$s (%2$d of %3$d) …', 'rmd-migrate-to-localdev' ),
				(string) ( $db['tables'][ $db['index'] ]['name'] ?? '' ),
				min( (int) $db['index'] + 1, $tables ),
				$tables
			),
			'scan'     => sprintf(
				/* translators: %s: number of files */
				__( 'Collecting files … %s found', 'rmd-migrate-to-localdev' ),
				number_format_i18n( (int) $job['scan']['files'] )
			),
			'zip'      => sprintf(
				/* translators: 1: packed files, 2: all files, 3: packed size, 4: total size */
				__( 'Packing files … %1$s of %2$s (%3$s of %4$s)', 'rmd-migrate-to-localdev' ),
				number_format_i18n( (int) $job['zip']['files'] ),
				number_format_i18n( (int) $job['scan']['files'] ),
				size_format( (int) $job['zip']['size'] ),
				size_format( (int) $job['scan']['size'] )
			),
			'finalize' => __( 'Compressing the database and writing the report …', 'rmd-migrate-to-localdev' ),
			default    => __( 'Export finished.', 'rmd-migrate-to-localdev' ),
		};

		$downloads = [];
		if ( $done ) {
			foreach ( Exports::DOWNLOADS as $key ) {
				$file = Exports::file( (string) $job['id'], $key );
				if ( null !== $file ) {
					$downloads[] = [
						'key'  => $key,
						'name' => $file['name'],
						'size' => size_format( (int) filesize( $file['path'] ) ),
						'url'  => Exports::download_url( (string) $job['id'], $key ),
					];
				}
			}
		}

		return [
			'id'        => (string) $job['id'],
			'phase'     => (string) $job['phase'],
			'done'      => $done,
			'progress'  => round( $progress, 3 ),
			'message'   => $message,
			'title'     => $target->url(),
			'downloads' => $downloads,
			'warnings'  => array_values( (array) $job['report']['warnings'] ),
			'leftovers' => count( (array) $job['report']['leftovers'] ),
		];
	}
}
