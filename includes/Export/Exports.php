<?php
/**
 * The export folder: one subfolder per export, protected against web access
 * and emptied automatically, because it sits on a public server.
 *
 * @package RMD\MigrateToLocaldev
 */

namespace RMD\MigrateToLocaldev\Export;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RMD\MigrateToLocaldev\Settings;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

// The exports are written outside the uploads API and can be several
// gigabytes; WP_Filesystem is not suited.
// phpcs:disable WordPress.WP.AlternativeFunctions

final class Exports {

	/** Exports are deleted this long after they were created. */
	public const LIFETIME = DAY_IN_SECONDS;

	/** Download keys => what they are. */
	public const DOWNLOADS = [ 'files', 'database', 'report' ];

	/**
	 * Creates the export folder with deny rules and returns its path.
	 */
	public static function prepare_root(): string {
		$root = Settings::export_dir();
		if ( ! wp_mkdir_p( $root ) || ! wp_is_writable( $root ) ) {
			throw new RuntimeException(
				esc_html(
					sprintf(
						/* translators: %s: folder path */
						__( 'The export folder %s could not be created or is not writable.', 'rmd-migrate-to-localdev' ),
						$root
					)
				)
			);
		}
		if ( ! file_exists( $root . '/.htaccess' ) ) {
			file_put_contents( $root . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
		}
		if ( ! file_exists( $root . '/index.php' ) ) {
			file_put_contents( $root . '/index.php', "<?php\n// Silence is golden.\n" );
		}
		return $root;
	}

	/**
	 * Finished and unfinished exports, newest first.
	 *
	 * @return list<array{name: string, path: string, created: int, expires: int, complete: bool, files: int, database: int}>
	 */
	public static function all(): array {
		$root = Settings::export_dir();
		if ( ! is_dir( $root ) ) {
			return [];
		}

		$exports = [];
		foreach ( (array) scandir( $root ) as $name ) {
			$name = (string) $name;
			$path = $root . '/' . $name;
			if ( str_starts_with( $name, '.' ) || ! is_dir( $path ) ) {
				continue;
			}
			$complete  = file_exists( $path . '/' . Job::REPORT_FILE );
			$created   = (int) filemtime( $complete ? $path . '/' . Job::REPORT_FILE : $path );
			$dump      = self::dump_path( $path );
			$archive   = $path . '/' . Job::ZIP_FILE;
			$exports[] = [
				'name'     => $name,
				'path'     => $path,
				'created'  => $created,
				'expires'  => $created + self::LIFETIME,
				'complete' => $complete,
				'files'    => $complete && file_exists( $archive ) ? (int) filesize( $archive ) : 0,
				'database' => null !== $dump ? (int) filesize( $dump ) : 0,
			];
		}

		usort( $exports, static fn( array $a, array $b ): int => strcmp( $b['name'], $a['name'] ) );
		return $exports;
	}

	/**
	 * Path and download name of one file of a finished export, or null if it does not exist.
	 *
	 * @return array{path: string, name: string, type: string}|null
	 */
	public static function file( string $export, string $key ): ?array {
		$path = self::path( $export );
		if ( ! is_dir( $path ) || ! file_exists( $path . '/' . Job::REPORT_FILE ) ) {
			return null;
		}
		// Export folders are named "<date>_<time>_<folder>".
		$label = (string) preg_replace( '/^\d{4}-\d{2}-\d{2}_\d{6}_/', '', $export );

		switch ( $key ) {
			case 'files':
				$file = [ $path . '/' . Job::ZIP_FILE, $label . '.zip', 'application/zip' ];
				break;
			case 'database':
				$dump = self::dump_path( $path );
				$gzip = null !== $dump && str_ends_with( $dump, '.gz' );
				$file = [ (string) $dump, $label . ( $gzip ? '.sql.gz' : '.sql' ), $gzip ? 'application/gzip' : 'application/sql' ];
				break;
			case 'report':
				$file = [ $path . '/' . Job::REPORT_FILE, $label . '-' . Job::REPORT_FILE, 'text/plain; charset=utf-8' ];
				break;
			default:
				return null;
		}

		return is_file( $file[0] ) ? [
			'path' => $file[0],
			'name' => $file[1],
			'type' => $file[2],
		] : null;
	}

	/**
	 * Unescaped; use esc_url() in markup.
	 */
	public static function download_url( string $export, string $key ): string {
		return add_query_arg(
			[
				'action'   => 'rmd_mtl_download',
				'export'   => $export,
				'file'     => $key,
				'_wpnonce' => wp_create_nonce( 'rmd_mtl_download_' . $export ),
			],
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Deletes one export folder. Only direct subfolders of the export folder are accepted.
	 */
	public static function delete( string $name ): void {
		$path = self::path( $name );
		if ( is_dir( $path ) ) {
			self::remove_tree( $path );
		}
	}

	/**
	 * Deletes the export folder with everything in it.
	 */
	public static function delete_all(): void {
		$root = Settings::export_dir();
		if ( is_dir( $root ) ) {
			self::remove_tree( $root );
		}
	}

	/**
	 * Deletes exports older than LIFETIME, except the one that is being created.
	 */
	public static function purge_expired(): void {
		$running = Job::is_running() ? (string) Job::current()['id'] : '';
		foreach ( self::all() as $export ) {
			if ( $export['name'] === $running || $export['expires'] > time() ) {
				continue;
			}
			$job = Job::current();
			if ( null !== $job && $job['id'] === $export['name'] ) {
				Job::cancel();
			}
			self::delete( $export['name'] );
		}
	}

	/**
	 * Opens a file for appending after cutting it to the length recorded in the
	 * job state, so output of an interrupted step is never written twice.
	 *
	 * @return resource
	 */
	public static function open_append( string $path, int $length ) {
		$handle = fopen( $path, 'c+b' );
		if ( false === $handle ) {
			throw new RuntimeException(
				esc_html(
					sprintf(
						/* translators: %s: file path */
						__( 'Could not open %s for writing.', 'rmd-migrate-to-localdev' ),
						$path
					)
				)
			);
		}
		ftruncate( $handle, $length );
		fseek( $handle, 0, SEEK_END );
		return $handle;
	}

	/**
	 * Name of an export folder from a request; empty if it is not a valid one.
	 * sanitize_file_name() is no option, it rewrites names like "shop.com.de".
	 *
	 * @param mixed $value
	 */
	public static function clean_name( $value ): string {
		return is_string( $value ) && 1 === preg_match( '/^[A-Za-z0-9_][A-Za-z0-9._-]*$/', $value ) ? $value : '';
	}

	private static function path( string $name ): string {
		if ( '' === self::clean_name( $name ) ) {
			throw new RuntimeException( esc_html__( 'Invalid export name.', 'rmd-migrate-to-localdev' ) );
		}
		return Settings::export_dir() . '/' . $name;
	}

	private static function dump_path( string $export_path ): ?string {
		foreach ( [ Job::SQL_FILE . '.gz', Job::SQL_FILE ] as $file ) {
			if ( file_exists( $export_path . '/' . $file ) ) {
				return $export_path . '/' . $file;
			}
		}
		return null;
	}

	private static function remove_tree( string $path ): void {
		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			/** @var \SplFileInfo $item */
			$ok = $item->isDir() && ! $item->isLink() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
			if ( ! $ok ) {
				throw new RuntimeException(
					esc_html(
						sprintf(
							/* translators: %s: file path */
							__( 'Could not delete %s.', 'rmd-migrate-to-localdev' ),
							$item->getPathname()
						)
					)
				);
			}
		}
		rmdir( $path );
	}
}
