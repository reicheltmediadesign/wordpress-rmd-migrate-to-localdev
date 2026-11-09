<?php
/**
 * Builds the search => replace pairs for one export: every spelling of the
 * live URL (http/https, with and without www, protocol-relative, JSON-escaped,
 * URL-encoded), root-relative links and the file system path of the server.
 *
 * @package RMD\MigrateToLocaldev
 */

namespace RMD\MigrateToLocaldev\Domain;

// WordPress-free layer: wp_parse_url() is not available here.
// phpcs:disable WordPress.WP.AlternativeFunctions.parse_url_parse_url

final class ReplacementPlan {

	/** Characters that open a root-relative link: href="/wp-content/…", url(/wp-content/…), JSON "\/wp-content\/…". */
	private const LINK_OPENERS = [ '"', "'", '(' ];

	/**
	 * Root-relative links that can be told apart from other text when the live
	 * site sits in the web root: only these WordPress folders are rewritten.
	 */
	private const ROOT_FOLDERS = [ '/wp-content/', '/wp-includes/', '/wp-admin/' ];

	/**
	 * @param array<string, string> $pairs   Search => replacement.
	 * @param list<string>          $needles Strings that should not remain after the export (reported, not replaced).
	 * @param list<string>          $shields Replacements that contain a needle; they are not leftovers.
	 */
	private function __construct(
		public readonly array $pairs,
		public readonly array $needles,
		private readonly array $shields
	) {}

	/**
	 * @param array<string, string> $urls          Live URL => local URL. The first entry is the site address (home).
	 * @param list<string>          $source_paths  Spellings of ABSPATH on the server (e.g. as configured and with symlinks resolved).
	 * @param string                $target_path   Local WordPress folder; empty = do not replace paths.
	 * @param bool                  $root_relative Also rewrite links like "/wp-content/…".
	 */
	public static function build( array $urls, array $source_paths, string $target_path, bool $root_relative ): self {
		$pairs = [];

		foreach ( $urls as $from => $to ) {
			$from = rtrim( (string) $from, '/' );
			$to   = rtrim( $to, '/' );
			if ( '' === $from || $from === $to ) {
				continue;
			}
			$from_rest = self::strip_scheme( $from );
			$to_rest   = self::strip_scheme( $to );

			self::add_url( $pairs, 'http:' . $from_rest, $to );
			self::add_url( $pairs, 'https:' . $from_rest, $to );
			self::add_url( $pairs, $from_rest, $to_rest );

			// Without scheme ("example.com/site", e.g. a displayed web address). Only with a
			// path, otherwise a bare host name would be replaced in unrelated text and e-mail addresses.
			if ( '' !== self::url_path( $from ) ) {
				self::add_url( $pairs, substr( $from_rest, 2 ), substr( $to_rest, 2 ) );
			}
		}

		$first       = (string) array_key_first( $urls );
		$source_base = self::url_path( $first );
		$target_base = self::url_path( (string) ( $urls[ $first ] ?? '' ) );
		if ( $root_relative && $source_base !== $target_base ) {
			$folders = '' === $source_base ? self::ROOT_FOLDERS : [ '/' ];
			foreach ( self::LINK_OPENERS as $opener ) {
				foreach ( $folders as $folder ) {
					self::add( $pairs, $opener . $source_base . $folder, $opener . $target_base . $folder );
					self::add( $pairs, $opener . self::json_slashes( $source_base . $folder ), $opener . self::json_slashes( $target_base . $folder ) );
				}
			}
		}

		$needles = [];
		$host    = (string) parse_url( $first, PHP_URL_HOST );
		if ( '' !== $host ) {
			$needles[] = $host;
		}

		$target = rtrim( str_replace( '\\', '/', $target_path ), '/' );
		foreach ( $source_paths as $source_path ) {
			$source = rtrim( str_replace( '\\', '/', $source_path ), '/' );
			// A single segment like "/htdocs" also occurs in addresses; replacing it would damage them.
			if ( substr_count( $source, '/' ) < 2 ) {
				continue;
			}
			$paths = [ $source ];
			if ( preg_match( '/^[A-Za-z]:/', $source ) ) {
				$paths[] = ctype_upper( $source[0] ) ? lcfirst( $source ) : ucfirst( $source );
			}
			foreach ( $paths as $path ) {
				if ( '' === $target || $path === $target ) {
					continue;
				}
				$pairs[ $path ]                       = $target;
				$pairs[ self::json_slashes( $path ) ] = self::json_slashes( $target );
				if ( preg_match( '/^[A-Za-z]:/', $path ) ) {
					$backslashed                                        = str_replace( '/', '\\', $path );
					$pairs[ $backslashed ]                              = $target;
					$pairs[ str_replace( '\\', '\\\\', $backslashed ) ] = self::json_slashes( $target );
				}
			}
			$needles[] = $source;
		}
		$needles = array_values( array_unique( $needles ) );

		$shields = [];
		foreach ( array_unique( array_values( $pairs ) ) as $replacement ) {
			if ( self::any_contains( [ $replacement ], $needles ) ) {
				$shields[] = $replacement;
			}
		}
		usort( $shields, static fn( string $a, string $b ): int => strlen( $b ) <=> strlen( $a ) );

		return new self( $pairs, $needles, $shields );
	}

	/**
	 * Adds the spelling with or without "www." for every live address, because
	 * older content often still uses the other one.
	 *
	 * @param array<string, string> $urls Live URL => local URL.
	 * @return array<string, string>
	 */
	public static function with_www_variants( array $urls ): array {
		$result = $urls;
		foreach ( $urls as $from => $to ) {
			$host = (string) parse_url( (string) $from, PHP_URL_HOST );
			if ( ! str_contains( $host, '.' ) || false !== filter_var( trim( $host, '[]' ), FILTER_VALIDATE_IP ) ) {
				continue;
			}
			$other   = str_starts_with( strtolower( $host ), 'www.' ) ? substr( $host, 4 ) : 'www.' . $host;
			$variant = (string) preg_replace( '#^([a-z][a-z0-9+.-]*://)' . preg_quote( $host, '#' ) . '#i', '${1}' . $other, (string) $from );
			if ( ! isset( $result[ $variant ] ) ) {
				$result[ $variant ] = $to;
			}
		}
		return $result;
	}

	/**
	 * Whether a value still refers to the live site after the replacement.
	 * E-mail addresses (info@example.com) and the local address itself (it may
	 * contain the live host, e.g. http://localhost/example.com) do not count.
	 */
	public function has_leftover( string $value ): bool {
		if ( ! self::any_contains( [ $value ], $this->needles ) ) {
			return false;
		}
		if ( [] !== $this->shields ) {
			$value = str_replace( $this->shields, ' ', $value );
		}
		foreach ( $this->needles as $needle ) {
			$offset = 0;
			for ( $position = strpos( $value, $needle ); false !== $position; $position = strpos( $value, $needle, $offset ) ) {
				if ( 0 === $position || '@' !== $value[ $position - 1 ] ) {
					return true;
				}
				$offset = $position + 1;
			}
		}
		return false;
	}

	/**
	 * Path part of a URL without trailing slash ("" for a site in the web root).
	 */
	public static function url_path( string $url ): string {
		return rtrim( (string) parse_url( $url, PHP_URL_PATH ), '/' );
	}

	/**
	 * @param array<string, string> $pairs
	 */
	private static function add_url( array &$pairs, string $from, string $to ): void {
		self::add( $pairs, $from, $to );
		self::add( $pairs, self::json_slashes( $from ), self::json_slashes( $to ) );
		self::add( $pairs, rawurlencode( $from ), rawurlencode( $to ) );
	}

	/**
	 * @param array<string, string> $pairs
	 */
	private static function add( array &$pairs, string $from, string $to ): void {
		if ( '' !== $from && $from !== $to ) {
			$pairs[ $from ] = $to;
		}
	}

	private static function strip_scheme( string $url ): string {
		return (string) preg_replace( '#^[a-z][a-z0-9+.-]*:(?=//)#i', '', $url );
	}

	private static function json_slashes( string $value ): string {
		return str_replace( '/', '\\/', $value );
	}

	/**
	 * @param list<string> $haystacks
	 * @param list<string> $needles
	 */
	private static function any_contains( array $haystacks, array $needles ): bool {
		foreach ( $haystacks as $haystack ) {
			foreach ( $needles as $needle ) {
				if ( str_contains( $haystack, $needle ) ) {
					return true;
				}
			}
		}
		return false;
	}
}
