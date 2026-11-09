<?php
declare(strict_types=1);

namespace RMD\MigrateToLocaldev\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RMD\MigrateToLocaldev\Domain\PathFilter;
use RMD\MigrateToLocaldev\Domain\Target;

final class PathFilterTest extends TestCase {

	/**
	 * @return iterable<string, array{string, bool, bool}>
	 */
	public static function defaults(): iterable {
		yield 'git folder at any depth' => [ 'wp-content/themes/x/.git', true, true ];
		yield 'node_modules' => [ 'wp-content/plugins/x/node_modules', true, true ];
		yield 'node_modules file is not a folder' => [ 'node_modules', false, false ];
		yield 'object cache drop-in' => [ 'wp-content/object-cache.php', false, true ];
		yield 'object cache file of a plugin stays' => [ 'wp-content/plugins/x/object-cache.php', false, false ];
		yield 'error_log of the host' => [ 'wp-admin/error_log', false, true ];
		yield 'cache folder' => [ 'wp-content/cache', true, true ];
		yield 'uploads stay' => [ 'wp-content/uploads/2026/09/a.jpg', false, false ];
		yield 'backwpup folder' => [ 'wp-content/uploads/backwpup-abc123-logs', true, true ];
		yield 'log files' => [ 'wp-content/debug.log', false, true ];
		yield 'case-insensitive' => [ 'wp-content/uploads/THUMBS.DB', false, true ];
		yield 'core file' => [ 'wp-includes/version.php', false, false ];
	}

	/**
	 * @dataProvider defaults
	 */
	public function test_default_excludes( string $path, bool $is_dir, bool $expected ): void {
		self::assertSame( $expected, PathFilter::from_lines( Target::DEFAULT_EXCLUDES )->matches( $path, $is_dir ) );
	}

	public function test_distignore_semantics(): void {
		$filter = PathFilter::from_lines( "# comment\n.git\nsrc\nlanguages/*.po\n!keep\n\n" );

		self::assertTrue( $filter->matches( '.git', true ) );
		self::assertTrue( $filter->matches( 'src', true ) );
		self::assertTrue( $filter->matches( 'vendor/x/src', true ) );
		self::assertTrue( $filter->matches( 'languages/x-de_DE.po', false ) );
		self::assertFalse( $filter->matches( 'languages/x-de_DE.mo', false ) );
		self::assertFalse( $filter->matches( 'sub/languages/x.po', false ) );
		self::assertFalse( $filter->matches( 'keep', false ) );
	}

	public function test_double_star(): void {
		$filter = PathFilter::from_lines( [ 'wp-content/**/cache/', 'assets/**' ] );

		self::assertTrue( $filter->matches( 'wp-content/cache', true ) );
		self::assertTrue( $filter->matches( 'wp-content/plugins/x/cache', true ) );
		self::assertTrue( $filter->matches( 'assets/a/b.css', false ) );
		self::assertFalse( $filter->matches( 'x/assets', true ) );
	}

	public function test_empty(): void {
		self::assertTrue( PathFilter::from_lines( "\n# only comments\n" )->is_empty() );
	}
}
