<?php
/**
 * Turns the wp-config.php of the live site into one for the local copy: local
 * database access, new salts, environment type "local", and the switches that
 * do not work without HTTPS or the cache drop-ins are turned off.
 *
 * @package RMD\MigrateToLocaldev
 */

namespace RMD\MigrateToLocaldev\Domain;

final class WpConfig {

	public const SALT_KEYS = [ 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ];

	public const ENVIRONMENT_TYPE = 'local';

	/** A quoted PHP string literal, honouring escaped quotes. */
	private const STRING_VALUE = '(?<q>[\'"])(?:\\\\.|(?!\k<q>).)*\k<q>';

	/** Anything else up to the end of the statement, e.g. getenv( 'DB_NAME' ). */
	private const ANY_VALUE = '[^\r\n]+?';

	/** FORCE_SSL_ADMIN redirects to HTTPS, WP_CACHE loads advanced-cache.php (not part of the copy). */
	private const SWITCHED_OFF = [ 'FORCE_SSL_ADMIN', 'WP_CACHE' ];

	/**
	 * @param array<string, string> $database DB_NAME, DB_USER, DB_PASSWORD, DB_HOST => local value.
	 * @param array<string, string> $salts    Salt constant => new value.
	 */
	public static function render( string $source, array $database, array $salts ): string {
		$config = $source;

		foreach ( $database + $salts as $constant => $value ) {
			$config = self::set_string( $config, $constant, $value );
		}
		foreach ( self::SWITCHED_OFF as $constant ) {
			$config = (string) preg_replace( self::define_pattern( $constant, 'true' ), '${1}false${2}', $config );
		}

		if ( preg_match( self::define_pattern( 'WP_ENVIRONMENT_TYPE', self::ANY_VALUE ), $config ) ) {
			return self::set_string( $config, 'WP_ENVIRONMENT_TYPE', self::ENVIRONMENT_TYPE );
		}

		$line   = "define( 'WP_ENVIRONMENT_TYPE', '" . self::ENVIRONMENT_TYPE . "' );\n";
		$marker = preg_match( '/^.*\bdefined\(\s*[\'"]ABSPATH[\'"]\s*\).*$/m', $config, $m, PREG_OFFSET_CAPTURE ) ? $m[0][1] : null;
		return null === $marker ? rtrim( $config ) . "\n" . $line : substr_replace( $config, $line . "\n", $marker, 0 );
	}

	/**
	 * Whether the constant is defined in the file, with any value.
	 */
	public static function defines( string $config, string $constant ): bool {
		return 1 === preg_match( self::define_pattern( $constant, self::ANY_VALUE ), $config );
	}

	/**
	 * Replaces the value of define( 'NAME', … ). A string literal is preferred;
	 * values read from the environment of the host are replaced as a whole.
	 */
	private static function set_string( string $config, string $constant, string $value ): string {
		$literal  = "'" . str_replace( [ '\\', "'" ], [ '\\\\', "\\'" ], $value ) . "'";
		$callback = static fn( array $m ): string => $m['pre'] . $literal . $m['post'];

		foreach ( [ self::STRING_VALUE, self::ANY_VALUE ] as $pattern ) {
			$result = preg_replace_callback( self::define_pattern( $constant, $pattern ), $callback, $config, 1, $count );
			if ( $count > 0 ) {
				return (string) $result;
			}
		}
		return $config;
	}

	/**
	 * Matches define( 'NAME', <value> ); with the groups "pre" (everything before
	 * the value, group 1) and "post" (everything after it, group 2 for numbered use).
	 */
	private static function define_pattern( string $constant, string $value ): string {
		return '/(?<pre>define\(\s*[\'"]' . preg_quote( $constant, '/' ) . '[\'"]\s*,\s*)' . $value . '(?<post>\s*\)\s*;)/i';
	}
}
