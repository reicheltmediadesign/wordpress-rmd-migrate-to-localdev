<?php
/**
 * The local XAMPP install an export is prepared for, with all export options.
 * The only place that defines the stored shape; everything read from the
 * database or a form goes through Target::parse().
 *
 * @package RMD\MigrateToLocaldev
 */

namespace RMD\MigrateToLocaldev\Domain;

// WordPress-free layer: wp_parse_url() is not available here.
// phpcs:disable WordPress.WP.AlternativeFunctions.parse_url_parse_url

final class Target {

	public const DEFAULT_HTDOCS_PATH = 'C:/xampp/htdocs';
	public const DEFAULT_BASE_URL    = 'http://localhost';

	/** Database access of a stock XAMPP install: root without a password. */
	public const DEFAULT_DB_USER = 'root';
	public const DB_HOST         = 'localhost';

	public const DEFAULT_EXCLUDES = [
		'.git/',
		'.svn/',
		'node_modules/',
		'/wp-content/cache/',
		'/wp-content/upgrade/',
		'/wp-content/upgrade-temp-backup/',
		'/wp-content/advanced-cache.php',
		'/wp-content/object-cache.php',
		'/wp-content/ai1wm-backups/',
		'/wp-content/updraft/',
		'/wp-content/backups-dup-lite/',
		'/wp-content/uploads/backwpup-*/',
		'/wp-content/wflogs/',
		'*.log',
		'error_log',
		'.DS_Store',
		'Thumbs.db',
	];

	/** Tables (without prefix) exported without rows because the plugins rebuild them. */
	public const DEFAULT_EMPTY_TABLES = [
		'actionscheduler_logs',
		'yoast_indexable',
		'yoast_indexable_hierarchy',
		'yoast_seo_links',
	];

	/**
	 * @param list<string> $exclude_patterns
	 * @param list<string> $empty_tables
	 */
	public function __construct(
		public readonly string $folder,
		public readonly string $htdocs_path,
		public readonly string $base_url,
		public readonly string $db_name,
		public readonly string $db_user,
		public readonly string $db_password,
		public readonly bool $include_files,
		public readonly bool $include_uploads,
		public readonly array $exclude_patterns,
		public readonly array $empty_tables,
		public readonly bool $root_relative_links,
		public readonly bool $skip_revisions,
		public readonly bool $skip_spam_comments,
		public readonly bool $discourage_search_engines
	) {}

	/**
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return [
			'folder'                    => '',
			'htdocs_path'               => self::DEFAULT_HTDOCS_PATH,
			'base_url'                  => self::DEFAULT_BASE_URL,
			'db_name'                   => '',
			'db_user'                   => self::DEFAULT_DB_USER,
			'db_password'               => '',
			'include_files'             => true,
			'include_uploads'           => true,
			'exclude_patterns'          => self::DEFAULT_EXCLUDES,
			'empty_tables'              => self::DEFAULT_EMPTY_TABLES,
			'root_relative_links'       => true,
			'skip_revisions'            => false,
			'skip_spam_comments'        => true,
			'discourage_search_engines' => true,
		];
	}

	/**
	 * Normalizes raw data (stored option or submitted form). Invalid values fall
	 * back to the default and are reported as error codes.
	 *
	 * @param array<mixed> $raw
	 * @return array{target: self, errors: list<string>}
	 */
	public static function parse( array $raw ): array {
		$defaults = self::defaults();
		$errors   = [];

		$folder = trim( str_replace( '\\', '/', trim( self::string( $raw['folder'] ?? '' ) ) ), '/' );
		if ( '' === $folder ) {
			$errors[] = 'folder_required';
		} elseif ( ! self::is_name( $folder ) ) {
			$errors[] = 'invalid_folder';
			$folder   = '';
		}

		$htdocs = rtrim( str_replace( '\\', '/', trim( self::string( $raw['htdocs_path'] ?? '' ) ) ), '/' );
		if ( '' === $htdocs ) {
			$htdocs = $defaults['htdocs_path'];
		} elseif ( ! preg_match( '#^(?:/|[A-Za-z]:/)[^/]#', $htdocs ) ) {
			$errors[] = 'invalid_htdocs_path';
			$htdocs   = $defaults['htdocs_path'];
		}

		$base_url = rtrim( trim( self::string( $raw['base_url'] ?? '' ) ), '/' );
		if ( '' === $base_url ) {
			$base_url = $defaults['base_url'];
		} elseif ( ! self::is_http_url( $base_url ) ) {
			$errors[] = 'invalid_base_url';
			$base_url = $defaults['base_url'];
		}

		$db_name = trim( self::string( $raw['db_name'] ?? '' ) );
		if ( '' !== $db_name && ! self::is_name( $db_name ) ) {
			$errors[] = 'invalid_db_name';
			$db_name  = '';
		}

		$db_user = trim( self::string( $raw['db_user'] ?? '' ) );
		if ( '' === $db_user ) {
			$db_user = $defaults['db_user'];
		} elseif ( ! preg_match( '/^[A-Za-z0-9_.@$-]{1,80}$/', $db_user ) ) {
			$errors[] = 'invalid_db_user';
			$db_user  = $defaults['db_user'];
		}

		// Not trimmed: spaces can be part of a password.
		$db_password = self::string( $raw['db_password'] ?? '' );
		if ( strlen( $db_password ) > 255 || preg_match( '/[\x00-\x1F]/', $db_password ) ) {
			$errors[]    = 'invalid_db_password';
			$db_password = '';
		}

		$target = new self(
			$folder,
			$htdocs,
			$base_url,
			$db_name,
			$db_user,
			$db_password,
			self::bool( $raw, 'include_files' ),
			self::bool( $raw, 'include_uploads' ),
			self::lines( $raw['exclude_patterns'] ?? $defaults['exclude_patterns'] ),
			array_values( array_filter( self::lines( $raw['empty_tables'] ?? $defaults['empty_tables'] ), static fn( string $t ): bool => (bool) preg_match( '/^[A-Za-z0-9_$-]+$/', $t ) ) ),
			self::bool( $raw, 'root_relative_links' ),
			self::bool( $raw, 'skip_revisions' ),
			self::bool( $raw, 'skip_spam_comments' ),
			self::bool( $raw, 'discourage_search_engines' )
		);

		return [
			'target' => $target,
			'errors' => $errors,
		];
	}

	public function is_complete(): bool {
		return '' !== $this->folder;
	}

	/**
	 * Address of the local site, e.g. http://localhost/example.com.
	 */
	public function url(): string {
		return $this->base_url . '/' . $this->folder;
	}

	/**
	 * Local WordPress folder, e.g. C:/xampp/htdocs/example.com.
	 */
	public function path(): string {
		return $this->htdocs_path . '/' . $this->folder;
	}

	/**
	 * Name of the local database; the folder name unless set otherwise.
	 */
	public function database(): string {
		return '' === $this->db_name ? $this->folder : $this->db_name;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return get_object_vars( $this );
	}

	/**
	 * Folder and database names: one path segment that is also a valid MySQL
	 * identifier when quoted (letters, digits, dot, underscore, hyphen).
	 */
	public static function is_name( string $value ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9_](?:[A-Za-z0-9._-]{0,62}[A-Za-z0-9_])?$/', $value );
	}

	public static function is_http_url( string $url ): bool {
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return false;
		}
		$host = parse_url( $url, PHP_URL_HOST );
		return is_string( $host ) && '' !== $host && null === parse_url( $url, PHP_URL_QUERY ) && null === parse_url( $url, PHP_URL_FRAGMENT );
	}

	/**
	 * @param mixed $value
	 */
	private static function string( $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * @param array<mixed> $raw
	 */
	private static function bool( array $raw, string $key ): bool {
		if ( ! array_key_exists( $key, $raw ) ) {
			return (bool) self::defaults()[ $key ];
		}
		return in_array( $raw[ $key ], [ true, 1, '1', 'yes', 'on' ], true );
	}

	/**
	 * @param mixed $value List or text with one entry per line.
	 * @return list<string>
	 */
	private static function lines( $value ): array {
		if ( is_string( $value ) ) {
			$value = preg_split( '/\R/', $value ) ?: [];
		}
		if ( ! is_array( $value ) ) {
			return [];
		}
		$lines = [];
		foreach ( $value as $line ) {
			$line = trim( self::string( $line ) );
			if ( '' !== $line ) {
				$lines[] = $line;
			}
		}
		return array_values( array_unique( $lines ) );
	}
}
