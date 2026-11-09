<?php
/**
 * Writes the SQL dump table by table in small chunks. Every text value runs
 * through the serialization-safe replacer on the way out; the database of the
 * live site is only read, never modified.
 *
 * @package RMD\MigrateToLocaldev
 */

namespace RMD\MigrateToLocaldev\Export;

use RMD\MigrateToLocaldev\Domain\Target;
use RMD\MigrateToLocaldev\Domain\ReplacementPlan;
use RMD\MigrateToLocaldev\Domain\SerializedReplacer;
use RMD\MigrateToLocaldev\Domain\Sql;
use RMD\MigrateToLocaldev\Settings;
use RMD\MigrateToLocaldev\Updater;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

final class DatabaseExport {

	private const ROWS_PER_QUERY   = 200;
	private const STATEMENT_BYTES  = 512 * 1024;
	private const SAMPLES_PER_SPOT = 3;

	/**
	 * Initial database state of a job: the list of tables to export.
	 *
	 * @return array<string, mixed>
	 */
	public static function init( Target $target ): array {
		global $wpdb;

		$prefix = $wpdb->prefix;
		$rows   = $wpdb->get_results( 'SHOW FULL TABLES', ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$tables = [];

		foreach ( (array) $rows as $row ) {
			$name = (string) $row[0];
			if ( 'BASE TABLE' !== strtoupper( (string) ( $row[1] ?? '' ) ) || ! str_starts_with( $name, $prefix ) ) {
				continue;
			}
			$base     = substr( $name, strlen( $prefix ) );
			$tables[] = [
				'name' => $name,
				'base' => $base,
				'data' => ! in_array( $base, $target->empty_tables, true ),
			];
		}

		if ( [] === $tables ) {
			throw new RuntimeException( esc_html__( 'No tables with the prefix of this site were found.', 'rmd-migrate-to-localdev' ) );
		}

		return [
			'state'  => 'pending',
			'tables' => $tables,
			'index'  => 0,
			'bytes'  => 0,
			'table'  => null,
		];
	}

	/**
	 * @param array<string, mixed> $job
	 */
	public static function run( array &$job, Target $target, ReplacementPlan $plan, float $deadline ): void {
		global $wpdb;

		$db     = &$job['db'];
		$handle = Exports::open_append( $job['dir'] . '/' . Job::SQL_FILE, (int) $db['bytes'] );

		// Dump TIMESTAMP values in UTC; the header sets the importing session to UTC as well.
		$wpdb->query( "SET time_zone = '+00:00'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		try {
			if ( 'pending' === $db['state'] ) {
				self::write( $handle, self::header( $job, $target ) );
				$db['state'] = 'tables';
			}

			$replacer = new SerializedReplacer( $plan->pairs );

			$total = count( $db['tables'] );

			while ( $db['index'] < $total && microtime( true ) < $deadline ) {
				$table = $db['tables'][ $db['index'] ];

				if ( null === $db['table'] ) {
					$db['table'] = self::start_table( $handle, $table );
				}

				$finished = ! $table['data'] || self::export_rows( $job, $handle, $table, $target, $plan, $replacer, $deadline );
				if ( $finished ) {
					self::write( $handle, "\n" );
					$db['table'] = null;
					++$db['index'];
				}
				$db['bytes'] = (int) ftell( $handle );
			}

			if ( $db['index'] >= $total ) {
				self::write( $handle, "SET FOREIGN_KEY_CHECKS = 1;\n" );
				$db['state'] = 'done';
			}
			$db['bytes'] = (int) ftell( $handle );
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
	}

	/**
	 * @param array<string, mixed> $job
	 */
	private static function header( array $job, Target $target ): string {
		$database = Sql::identifier( $target->database() );

		return implode(
			'
',
			[
				'-- RMD Migrate to Localdev ' . RMD_MTL_VERSION,
				'-- Created: ' . wp_date( 'Y-m-d H:i:s' ),
				'-- Source:  ' . $job['source']['home'],
				'-- Target:  ' . $target->url(),
				'',
				'/*!40101 SET NAMES utf8mb4 */;',
				"SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';",
				"SET time_zone = '+00:00';",
				'SET FOREIGN_KEY_CHECKS = 0;',
				'',
				// Import without selecting a database first: the dump creates and selects it.
				'CREATE DATABASE IF NOT EXISTS ' . $database . ' DEFAULT CHARACTER SET utf8mb4 COLLATE ' . Sql::PORTABLE_COLLATION . ';',
				'USE ' . $database . ';',
				'',
				'',
			]
		);
	}

	/**
	 * Writes DROP/CREATE and returns the state for reading the rows.
	 *
	 * @param resource             $handle
	 * @param array<string, mixed> $table
	 * @return array<string, mixed>
	 */
	private static function start_table( $handle, array $table ): array {
		global $wpdb;

		$create = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE TABLE %i', $table['name'] ), ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! is_array( $create ) || ! isset( $create[1] ) ) {
			throw new RuntimeException( esc_html( sprintf( 'SHOW CREATE TABLE failed for %s: %s', $table['name'], $wpdb->last_error ) ) );
		}

		self::write( $handle, '-- Table ' . $table['name'] . "\n" );
		self::write( $handle, 'DROP TABLE IF EXISTS ' . Sql::identifier( $table['name'] ) . ";\n" );
		self::write( $handle, Sql::rewrite_create( (string) $create[1], [], true ) . ";\n" );

		$columns = [];
		$keys    = [];
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SHOW FULL COLUMNS FROM %i', $table['name'] ), ARRAY_A ) as $column ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( preg_match( '/\b(?:VIRTUAL|STORED|PERSISTENT) GENERATED\b/i', (string) $column['Extra'] ) ) {
				continue;
			}
			$columns[] = [
				'name' => (string) $column['Field'],
				'kind' => Sql::kind( (string) $column['Type'] ),
			];
			if ( 'PRI' === $column['Key'] ) {
				$keys[] = [
					'name' => (string) $column['Field'],
					'int'  => (bool) preg_match( '/^(?:tiny|small|medium|big)?int\b/i', (string) $column['Type'] ),
				];
			}
		}

		return [
			'columns' => $columns,
			'keys'    => array_column( $keys, 'name' ),
			'keyset'  => 1 === count( $keys ) && $keys[0]['int'] ? $keys[0]['name'] : '',
			'last'    => null,
			'offset'  => 0,
		];
	}

	/**
	 * Exports rows until the table is complete (true) or the time is up (false).
	 *
	 * @param array<string, mixed> $job
	 * @param resource             $handle
	 * @param array<string, mixed> $table
	 */
	private static function export_rows( array &$job, $handle, array $table, Target $target, ReplacementPlan $plan, SerializedReplacer $replacer, float $deadline ): bool {
		global $wpdb;

		$state                                = &$job['db']['table'];
		$columns                              = $state['columns'];
		$select                               = implode( ', ', array_map( static fn( array $c ): string => Sql::identifier( $c['name'] ), $columns ) );
		$insert                               = 'INSERT INTO ' . Sql::identifier( $table['name'] ) . ' (' . $select . ') VALUES ';
		$where                                = self::filter( $table['base'], $target );
		$report                               = &$job['report'];
		$report['tables'][ $table['name'] ] ??= [
			'rows'    => 0,
			'changed' => 0,
		];

		do {
			$conditions = $where;
			if ( '' !== $state['keyset'] && null !== $state['last'] ) {
				$conditions[] = $wpdb->prepare( '%i > %d', $state['keyset'], $state['last'] );
			}
			$sql = 'SELECT ' . $select . ' FROM ' . Sql::identifier( $table['name'] )
				. ( [] === $conditions ? '' : ' WHERE ' . implode( ' AND ', $conditions ) )
				. ( [] === $state['keys'] ? '' : ' ORDER BY ' . implode( ', ', array_map( [ Sql::class, 'identifier' ], $state['keys'] ) ) )
				. ' LIMIT ' . self::ROWS_PER_QUERY
				. ( '' === $state['keyset'] ? ' OFFSET ' . (int) $state['offset'] : '' );

			$rows = $wpdb->get_results( $sql, ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Identifiers are quoted, values prepared above.
			if ( '' !== $wpdb->last_error ) {
				throw new RuntimeException( esc_html( sprintf( 'Reading %s failed: %s', $table['name'], $wpdb->last_error ) ) );
			}

			$values = [];
			$length = 0;
			foreach ( (array) $rows as $row ) {
				$row      = self::transform( $row, $columns, $table, $target, $plan, $replacer, $report );
				$literals = [];
				foreach ( $columns as $i => $column ) {
					$literals[] = Sql::literal( $row[ $i ], $column['kind'] );
				}
				$values[] = '(' . implode( ',', $literals ) . ')';
				$length  += strlen( end( $values ) );
				if ( $length >= self::STATEMENT_BYTES ) {
					self::write( $handle, $insert . "\n" . implode( ",\n", $values ) . ";\n" );
					$values = [];
					$length = 0;
				}
			}
			if ( [] !== $values ) {
				self::write( $handle, $insert . "\n" . implode( ",\n", $values ) . ";\n" );
			}

			$count                                       = count( (array) $rows );
			$report['tables'][ $table['name'] ]['rows'] += $count;
			if ( $count > 0 && '' !== $state['keyset'] ) {
				$key_index     = (int) array_search( $state['keyset'], array_column( $columns, 'name' ), true );
				$state['last'] = (int) end( $rows )[ $key_index ];
			}
			$state['offset']   += $count;
			$job['db']['bytes'] = (int) ftell( $handle );

			if ( $count < self::ROWS_PER_QUERY ) {
				return true;
			}
		} while ( microtime( true ) < $deadline );

		return false;
	}

	/**
	 * Replaces in text columns, records what remains of the live site and applies
	 * the fixes for the local copy (own plugin deactivated, search engines kept out).
	 *
	 * @param array<int, string|null>                 $row
	 * @param list<array{name: string, kind: string}> $columns
	 * @param array<string, mixed>                    $table
	 * @param array<string, mixed>                    $report
	 * @return array<int, string|null>
	 */
	private static function transform( array $row, array $columns, array $table, Target $target, ReplacementPlan $plan, SerializedReplacer $replacer, array &$report ): array {
		$names = array_column( $columns, 'name' );
		$named = array_combine( $names, $row );
		$name  = $table['name'];

		if ( 'options' === $table['base'] && 'active_plugins' === $named['option_name'] ) {
			$row[ array_search( 'option_value', $names, true ) ] = self::without_this_plugin( (string) $named['option_value'] );
		}
		if ( 'options' === $table['base'] && 'blog_public' === $named['option_name'] && $target->discourage_search_engines ) {
			$row[ array_search( 'option_value', $names, true ) ] = '0';
		}

		foreach ( $columns as $i => $column ) {
			$value = $row[ $i ];
			if ( null === $value || Sql::KIND_TEXT !== $column['kind'] ) {
				continue;
			}
			$new = $replacer->replace( $value );
			if ( $new !== $value ) {
				++$report['tables'][ $name ]['changed'];
				$row[ $i ] = $new;
				$value     = $new;
			}
			if ( $replacer->unparsable() > 0 ) {
				$spot                          = $name . '.' . $column['name'];
				$report['unparsable'][ $spot ] = ( $report['unparsable'][ $spot ] ?? 0 ) + $replacer->unparsable();
				$replacer->reset();
			}
			if ( $plan->has_leftover( $value ) ) {
				self::remember_leftover( $report, $name . '.' . $column['name'], self::row_label( $named, $table['base'] ) );
			}
		}

		return $row;
	}

	/**
	 * WHERE conditions that leave out caches, this plugin's own data and optional content.
	 *
	 * @return list<string>
	 */
	private static function filter( string $base, Target $target ): array {
		global $wpdb;

		$posts    = Sql::identifier( $wpdb->posts );
		$comments = Sql::identifier( $wpdb->comments );

		switch ( $base ) {
			case 'options':
				return [
					$wpdb->prepare(
						'option_name NOT LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s AND option_name <> %s',
						$wpdb->esc_like( '_transient_' ) . '%',
						$wpdb->esc_like( '_site_transient_' ) . '%',
						$wpdb->esc_like( Settings::OPTION_PREFIX ) . '%',
						'external_updates-' . Updater::SLUG
					),
				];
			case 'posts':
				return $target->skip_revisions ? [ "post_type <> 'revision'" ] : [];
			case 'postmeta':
				return $target->skip_revisions ? [ "post_id NOT IN (SELECT ID FROM {$posts} WHERE post_type = 'revision')" ] : [];
			case 'comments':
				return $target->skip_spam_comments ? [ "comment_approved NOT IN ('spam', 'trash')" ] : [];
			case 'commentmeta':
				return $target->skip_spam_comments ? [ "comment_id NOT IN (SELECT comment_ID FROM {$comments} WHERE comment_approved IN ('spam', 'trash'))" ] : [];
		}
		return [];
	}

	/**
	 * This plugin is not part of the copy, so it must not be listed as active there.
	 */
	private static function without_this_plugin( string $value ): string {
		$plugins = unserialize( $value, [ 'allowed_classes' => false ] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Plain array of strings, classes disallowed.
		if ( ! is_array( $plugins ) ) {
			return $value;
		}
		return serialize( array_values( array_filter( $plugins, static fn( $plugin ): bool => RMD_MTL_BASENAME !== $plugin ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
	}

	/**
	 * @param array<string, mixed> $report
	 */
	private static function remember_leftover( array &$report, string $spot, string $label ): void {
		$entry = $report['leftovers'][ $spot ] ?? [
			'count'   => 0,
			'samples' => [],
		];
		++$entry['count'];
		if ( count( $entry['samples'] ) < self::SAMPLES_PER_SPOT && ! in_array( $label, $entry['samples'], true ) ) {
			$entry['samples'][] = $label;
		}
		$report['leftovers'][ $spot ] = $entry;
	}

	/**
	 * Human-readable row identifier for the report.
	 *
	 * @param array<string, string|null> $row
	 */
	private static function row_label( array $row, string $base ): string {
		foreach ( [ 'option_name', 'meta_key' ] as $column ) {
			if ( isset( $row[ $column ] ) ) {
				$owner = $row['post_id'] ?? $row['user_id'] ?? $row['term_id'] ?? $row['comment_id'] ?? null;
				return $column . '=' . $row[ $column ] . ( null === $owner ? '' : ' (' . $owner . ')' );
			}
		}
		$first = array_key_first( $row );
		return 'posts' === $base && isset( $row['ID'] ) ? 'ID=' . $row['ID'] : $first . '=' . $row[ $first ];
	}

	/**
	 * @param resource $handle
	 */
	private static function write( $handle, string $data ): void {
		if ( false === fwrite( $handle, $data ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			throw new RuntimeException( esc_html__( 'Writing the database dump failed. Is the disk full?', 'rmd-migrate-to-localdev' ) );
		}
	}
}
