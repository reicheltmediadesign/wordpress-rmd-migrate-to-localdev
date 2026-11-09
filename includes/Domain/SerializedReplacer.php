<?php
/**
 * Search and replace that keeps PHP-serialized data intact.
 *
 * Serialized values are parsed byte by byte instead of unserialize(), so no
 * objects are instantiated and classes that are not loaded survive unchanged.
 * Only string values are rewritten; their length prefix (s:N:) is recomputed.
 * Strings that themselves contain serialized data are handled recursively.
 *
 * @package RMD\MigrateToLocaldev
 */

namespace RMD\MigrateToLocaldev\Domain;

final class SerializedReplacer {

	private const MAX_DEPTH = 64;

	/** @var array<string, string> */
	private array $pairs;

	/** @var list<string> */
	private array $needles;

	private int $unparsable = 0;

	/**
	 * @param array<string, string> $pairs Search => replacement. Applied in a single pass, longest match first (strtr).
	 */
	public function __construct( array $pairs ) {
		unset( $pairs[''] );
		$this->pairs   = $pairs;
		$this->needles = array_map( 'strval', array_keys( $pairs ) );
	}

	/**
	 * Number of values that looked serialized but could not be parsed since the last reset. They are left unchanged.
	 */
	public function unparsable(): int {
		return $this->unparsable;
	}

	public function reset(): void {
		$this->unparsable = 0;
	}

	public function replace( string $value ): string {
		return $this->replace_value( $value, 0 );
	}

	private function replace_value( string $value, int $depth ): string {
		if ( '' === $value || ! $this->contains_needle( $value ) ) {
			return $value;
		}

		if ( ! self::looks_serialized( $value ) ) {
			return strtr( $value, $this->pairs );
		}

		if ( $depth < self::MAX_DEPTH ) {
			$offset = 0;
			$result = $this->parse( $value, $offset, true, $depth + 1 );
			if ( null !== $result && '' === trim( substr( $value, $offset ) ) ) {
				return $result . substr( $value, $offset );
			}
		}

		++$this->unparsable;
		return $value;
	}

	public static function looks_serialized( string $value ): bool {
		return 1 === preg_match( '/^(?:[aOCE]:\d+:[{"]|s:\d+:"|i:-?\d+;|d:[^;]+;|b:[01];|N;)/', $value );
	}

	private function contains_needle( string $value ): bool {
		foreach ( $this->needles as $needle ) {
			if ( str_contains( $value, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Parses one serialized value starting at $offset and returns it re-serialized
	 * with replacements applied, or null if the input is malformed.
	 */
	private function parse( string $data, int &$offset, bool $replace, int $depth ): ?string {
		$type = $data[ $offset ] ?? '';

		switch ( $type ) {
			case 'N':
				if ( 'N;' !== substr( $data, $offset, 2 ) ) {
					return null;
				}
				$offset += 2;
				return 'N;';

			case 'b':
			case 'i':
			case 'd':
			case 'r':
			case 'R':
				return self::scalar( $data, $offset );

			case 's':
				if ( ! preg_match( '/\Gs:(\d+):"/', $data, $match, 0, $offset ) ) {
					return null;
				}
				$string = self::fixed_length( $data, $offset + strlen( $match[0] ), (int) $match[1], '";' );
				if ( null === $string ) {
					return null;
				}
				$offset += strlen( $match[0] ) + strlen( $string ) + 2;
				if ( $replace ) {
					$string = $this->replace_value( $string, $depth );
				}
				return 's:' . strlen( $string ) . ':"' . $string . '";';

			case 'a':
				if ( ! preg_match( '/\Ga:(\d+):\{/', $data, $match, 0, $offset ) ) {
					return null;
				}
				$offset += strlen( $match[0] );
				$body    = $this->members( $data, $offset, (int) $match[1], $depth );
				return null === $body ? null : $match[0] . $body;

			case 'O':
				if ( ! preg_match( '/\GO:(\d+):"/', $data, $match, 0, $offset ) ) {
					return null;
				}
				$class = self::fixed_length( $data, $offset + strlen( $match[0] ), (int) $match[1], '":' );
				if ( null === $class ) {
					return null;
				}
				$head = $match[0] . $class . '":';
				if ( ! preg_match( '/\G(\d+):\{/', $data, $count, 0, $offset + strlen( $head ) ) ) {
					return null;
				}
				$head   .= $count[0];
				$offset += strlen( $head );
				$body    = $this->members( $data, $offset, (int) $count[1], $depth );
				return null === $body ? null : $head . $body;

			case 'C':
				// Custom serialization (Serializable): the payload is opaque, copy it verbatim.
				if ( ! preg_match( '/\GC:(\d+):"/', $data, $match, 0, $offset ) ) {
					return null;
				}
				$class = self::fixed_length( $data, $offset + strlen( $match[0] ), (int) $match[1], '":' );
				if ( null === $class ) {
					return null;
				}
				$head = $match[0] . $class . '":';
				if ( ! preg_match( '/\G(\d+):\{/', $data, $length, 0, $offset + strlen( $head ) ) ) {
					return null;
				}
				$head   .= $length[0];
				$payload = self::fixed_length( $data, $offset + strlen( $head ), (int) $length[1], '}' );
				if ( null === $payload ) {
					return null;
				}
				$offset += strlen( $head ) + strlen( $payload ) + 1;
				return $head . $payload . '}';

			case 'E':
				if ( ! preg_match( '/\GE:(\d+):"/', $data, $match, 0, $offset ) ) {
					return null;
				}
				$name = self::fixed_length( $data, $offset + strlen( $match[0] ), (int) $match[1], '";' );
				if ( null === $name ) {
					return null;
				}
				$offset += strlen( $match[0] ) + strlen( $name ) + 2;
				return $match[0] . $name . '";';
		}

		return null;
	}

	/**
	 * Parses $count key/value pairs and the closing brace. Keys are copied verbatim.
	 */
	private function members( string $data, int &$offset, int $count, int $depth ): ?string {
		$out = '';
		for ( $i = 0; $i < $count; $i++ ) {
			$key_type = $data[ $offset ] ?? '';
			if ( 'i' !== $key_type && 's' !== $key_type ) {
				return null;
			}
			$key = $this->parse( $data, $offset, false, $depth );
			if ( null === $key ) {
				return null;
			}
			$value = $this->parse( $data, $offset, true, $depth );
			if ( null === $value ) {
				return null;
			}
			$out .= $key . $value;
		}
		if ( '}' !== ( $data[ $offset ] ?? '' ) ) {
			return null;
		}
		++$offset;
		return $out . '}';
	}

	private static function scalar( string $data, int &$offset ): ?string {
		if ( ! preg_match( '/\G(?:b:[01]|i:-?\d+|d:(?:-?INF|NAN|-?[0-9.]+(?:E[+-]?\d+)?)|[rR]:\d+);/', $data, $match, 0, $offset ) ) {
			return null;
		}
		$offset += strlen( $match[0] );
		return $match[0];
	}

	/**
	 * Reads $length bytes at $start that must be followed by $terminator.
	 */
	private static function fixed_length( string $data, int $start, int $length, string $terminator ): ?string {
		if ( $start + $length + strlen( $terminator ) > strlen( $data ) ) {
			return null;
		}
		if ( substr( $data, $start + $length, strlen( $terminator ) ) !== $terminator ) {
			return null;
		}
		return substr( $data, $start, $length );
	}
}
