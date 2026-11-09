<?php
/**
 * MIGRATION.txt: what the export contains, how to set up the local copy and
 * what to check.
 *
 * @package RMD\MigrateToLocaldev
 */

namespace RMD\MigrateToLocaldev\Export;

use RMD\MigrateToLocaldev\Domain\ReplacementPlan;
use RMD\MigrateToLocaldev\Domain\Target;

defined( 'ABSPATH' ) || exit;

final class Report {

	/**
	 * @param array<string, mixed> $job
	 */
	public static function render( array $job, Target $target, ReplacementPlan $plan ): string {
		$source  = (array) $job['source'];
		$report  = (array) $job['report'];
		$dir     = (string) $job['dir'];
		$gzip    = file_exists( $dir . '/' . Job::SQL_FILE . '.gz' );
		$dump    = $target->folder . ( $gzip ? '.sql.gz' : '.sql' );
		$archive = $target->folder . '.zip';
		$lines   = [];

		$lines[] = 'RMD Migrate to Localdev ' . RMD_MTL_VERSION;
		$lines[] = str_repeat( '=', 40 );
		$lines[] = '';
		$lines[] = self::field( __( 'Created', 'rmd-migrate-to-localdev' ), wp_date( 'Y-m-d H:i', (int) $job['started'] ) );
		$lines[] = self::field( __( 'Source', 'rmd-migrate-to-localdev' ), $source['home'] . '  (' . $source['abspath'] . ')' );
		$lines[] = self::field( __( 'Local copy', 'rmd-migrate-to-localdev' ), $target->url() . '  (' . $target->path() . ')' );
		$lines[] = self::field( __( 'Database', 'rmd-migrate-to-localdev' ), $target->database() );
		$lines[] = self::field( __( 'Table prefix', 'rmd-migrate-to-localdev' ), (string) $source['prefix'] );
		$lines[] = '';

		$lines[] = self::heading( __( 'Contents', 'rmd-migrate-to-localdev' ) );
		if ( $target->include_files ) {
			$lines[] = '- ' . $archive . '  ' . sprintf(
				/* translators: 1: folder name, 2: number of files, 3: total size */
				__( 'The site in a folder named %1$s (%2$s files, %3$s), with a wp-config.php and .htaccess for the local copy.', 'rmd-migrate-to-localdev' ),
				$target->folder,
				number_format_i18n( (int) $job['zip']['files'] ),
				size_format( (int) $job['zip']['size'] )
			);
		}
		$lines[] = '- ' . $dump . '  ' . sprintf(
			/* translators: %s: database name */
			__( 'Database dump for phpMyAdmin. It creates the database %s if it does not exist; tables with the same names are replaced.', 'rmd-migrate-to-localdev' ),
			$target->database()
		);
		$lines[] = '';

		$lines[] = self::heading( __( 'Next steps', 'rmd-migrate-to-localdev' ) );
		$steps   = [];
		if ( $target->include_files ) {
			$steps[] = sprintf(
				/* translators: 1: zip file name, 2: htdocs path, 3: resulting folder path */
				__( 'Unpack %1$s into %2$s, so that the site is in %3$s.', 'rmd-migrate-to-localdev' ),
				$archive,
				$target->htdocs_path,
				$target->path()
			);
		}
		$steps[] = sprintf(
			/* translators: 1: address of phpMyAdmin, 2: file name of the dump */
			__( 'Start Apache and MySQL in XAMPP, open %1$s and import %2$s on the Import tab without selecting a database first.', 'rmd-migrate-to-localdev' ),
			$target->base_url . '/phpmyadmin',
			$dump
		);
		$steps[] = sprintf(
			/* translators: 1: path of the mysql program, 2: database user, 3: file name of the unpacked dump */
			__( 'If the dump is too large for phpMyAdmin, unpack it and import it on the command line: "%1$s" -u %2$s -p < %3$s', 'rmd-migrate-to-localdev' ),
			str_replace( '/', '\\', dirname( $target->htdocs_path ) . '/mysql/bin/mysql.exe' ),
			$target->db_user,
			$target->folder . '.sql'
		);
		$steps[] = sprintf(
			/* translators: %s: address of the local site */
			__( 'Open %s and log in with the users and passwords of the live site.', 'rmd-migrate-to-localdev' ),
			$target->url() . '/wp-admin/'
		);
		$steps[] = __( 'Save Settings → Permalinks once.', 'rmd-migrate-to-localdev' );
		foreach ( $steps as $i => $step ) {
			$lines[] = ( $i + 1 ) . '. ' . $step;
		}
		$lines[] = '';

		$lines[] = self::heading( __( 'Good to know', 'rmd-migrate-to-localdev' ) );
		$notes   = [];
		if ( $target->include_files ) {
			$notes[] = sprintf(
				/* translators: 1: database name, 2: database user */
				__( 'wp-config.php uses the database %1$s with the user %2$s and the password you entered, has new security keys and WP_ENVIRONMENT_TYPE "local". Other secrets of the live site in it (e.g. API keys) are unchanged.', 'rmd-migrate-to-localdev' ),
				$target->database(),
				$target->db_user
			);
			$notes[] = sprintf(
				/* translators: %s: file name */
				__( '.htaccess only contains the WordPress rules. The file of the live site is included as %s for reference; its redirects and server settings usually do not work with XAMPP.', 'rmd-migrate-to-localdev' ),
				Finisher::LIVE_HTACCESS
			);
			if ( ! $target->include_uploads ) {
				$notes[] = __( 'The content of the uploads folder was left out, so images and other media are missing in the local copy.', 'rmd-migrate-to-localdev' );
			}
		}
		if ( $target->discourage_search_engines ) {
			$notes[] = __( 'Search engines are discouraged from indexing the copy (Settings → Reading).', 'rmd-migrate-to-localdev' );
		}
		$notes[] = __( 'This plugin is not part of the copy. All other plugins are active as on the live site: cache and security plugins may need to be deactivated, and plugins that send e-mails or orders to third parties keep doing so from the copy.', 'rmd-migrate-to-localdev' );
		if ( $source['home'] !== $source['siteurl'] ) {
			$notes[] = __( 'WordPress address and site address differ on the live site. Files outside the WordPress folder (e.g. an index.php one level above) are not part of the archive.', 'rmd-migrate-to-localdev' );
		}
		foreach ( $notes as $note ) {
			$lines[] = '- ' . $note;
		}
		$lines[] = '';

		$lines[] = self::heading( __( 'Database', 'rmd-migrate-to-localdev' ) );
		foreach ( (array) $job['db']['tables'] as $table ) {
			$stats   = $report['tables'][ $table['name'] ] ?? null;
			$lines[] = str_pad( (string) $table['name'], 40 ) . ' ' . ( null === $stats
				? __( 'structure only', 'rmd-migrate-to-localdev' )
				: sprintf(
					/* translators: 1: number of rows, 2: number of changed values */
					__( '%1$s rows, %2$s values changed', 'rmd-migrate-to-localdev' ),
					number_format_i18n( (int) $stats['rows'] ),
					number_format_i18n( (int) $stats['changed'] )
				) );
		}
		$lines[] = '';

		if ( [] !== $report['leftovers'] ) {
			$lines[] = self::heading( __( 'Please check: references to the live site remain', 'rmd-migrate-to-localdev' ) );
			$lines[] = sprintf(
				/* translators: %s: list of search terms */
				__( 'These values still contain %s after the replacement (e-mail addresses are not counted). Often harmless (e.g. a sender name or an SMTP host), sometimes a spelling the plugin does not know.', 'rmd-migrate-to-localdev' ),
				'"' . implode( '", "', $plan->needles ) . '"'
			);
			foreach ( (array) $report['leftovers'] as $spot => $entry ) {
				$lines[] = sprintf( '- %s: %d × (%s)', $spot, (int) $entry['count'], implode( ', ', (array) $entry['samples'] ) );
			}
			$lines[] = '';
		}

		if ( [] !== $report['unparsable'] ) {
			$lines[] = self::heading( __( 'Serialized values that could not be read', 'rmd-migrate-to-localdev' ) );
			$lines[] = __( 'These values look like serialized PHP data but are malformed. They were exported unchanged.', 'rmd-migrate-to-localdev' );
			foreach ( (array) $report['unparsable'] as $spot => $count ) {
				$lines[] = sprintf( '- %s: %d', $spot, (int) $count );
			}
			$lines[] = '';
		}

		if ( [] !== $report['warnings'] ) {
			$lines[] = self::heading( __( 'Warnings', 'rmd-migrate-to-localdev' ) );
			foreach ( (array) $report['warnings'] as $warning ) {
				$lines[] = '- ' . $warning;
			}
			$lines[] = '';
		}

		return implode( "\r\n", $lines );
	}

	private static function heading( string $text ): string {
		return $text . "\r\n" . str_repeat( '-', max( 3, mb_strlen( $text ) ) );
	}

	private static function field( string $label, string $value ): string {
		return str_pad( $label . ':', 16 ) . $value;
	}
}
