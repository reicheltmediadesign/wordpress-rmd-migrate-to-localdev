<?php
declare(strict_types=1);

namespace RMD\MigrateToLocaldev\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RMD\MigrateToLocaldev\Domain\ByteRange;
use RMD\MigrateToLocaldev\Domain\Htaccess;
use RMD\MigrateToLocaldev\Domain\WpConfig;

final class LocalFilesTest extends TestCase {

	private const DATABASE = [
		'DB_NAME'     => 'marleninflow.de',
		'DB_USER'     => 'root',
		'DB_PASSWORD' => '',
		'DB_HOST'     => 'localhost',
	];

	public function test_htaccess_for_subfolder(): void {
		$result = Htaccess::for_local( '/marleninflow.de' );

		self::assertStringStartsWith( "# BEGIN WordPress\n", $result );
		self::assertStringContainsString( "RewriteBase /marleninflow.de/\n", $result );
		self::assertStringContainsString( 'RewriteRule . /marleninflow.de/index.php [L]', $result );
		self::assertStringEndsWith( "# END WordPress\n", $result );
	}

	public function test_htaccess_for_web_root(): void {
		self::assertStringContainsString( "RewriteBase /\n", Htaccess::for_local( '' ) );
	}

	public function test_wp_config(): void {
		$source = <<<'PHP'
<?php
define( 'DB_NAME', 'db51234567' );
define( 'DB_USER', 'dbu987' );
define( 'DB_PASSWORD', 'se\'cret' );
define( 'DB_HOST', 'rdbms.strato.de' );
define( 'AUTH_KEY',         'old key' );
$table_prefix = 'l9jauo_';
define( 'WP_DEBUG', false );
define( 'WP_CACHE', true );
define('FORCE_SSL_ADMIN', true);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
require_once ABSPATH . 'wp-settings.php';
PHP;

		$result = WpConfig::render( $source, self::DATABASE, [ 'AUTH_KEY' => "new'key" ] );

		self::assertStringContainsString( "define( 'DB_NAME', 'marleninflow.de' );", $result );
		self::assertStringContainsString( "define( 'DB_USER', 'root' );", $result );
		self::assertStringContainsString( "define( 'DB_PASSWORD', '' );", $result );
		self::assertStringContainsString( "define( 'DB_HOST', 'localhost' );", $result );
		self::assertStringContainsString( "define( 'AUTH_KEY',         'new\\'key' );", $result );
		self::assertStringContainsString( "\$table_prefix = 'l9jauo_';", $result );
		self::assertStringContainsString( "define( 'WP_DEBUG', false );", $result );
		self::assertStringContainsString( "define( 'WP_CACHE', false );", $result );
		self::assertStringContainsString( "define('FORCE_SSL_ADMIN', false);", $result );
		self::assertStringContainsString( "define( 'WP_ENVIRONMENT_TYPE', 'local' );\n\nif ( ! defined( 'ABSPATH' ) )", $result );
		self::assertStringNotContainsString( 'cret', $result );
		self::assertStringNotContainsString( 'strato', $result );
		self::assertTrue( WpConfig::defines( $result, 'DB_NAME' ) );
	}

	public function test_wp_config_values_from_the_environment(): void {
		$source = "<?php\ndefine( 'DB_NAME', getenv( 'MYSQL_DATABASE' ) );\ndefine( 'DB_PASSWORD', \$_SERVER['DB_PASS'] ?? '' );\ndefine( 'WP_ENVIRONMENT_TYPE', getenv( 'WP_ENV' ) ?: 'production' );\n";
		$result = WpConfig::render( $source, self::DATABASE, [] );

		self::assertSame( "<?php\ndefine( 'DB_NAME', 'marleninflow.de' );\ndefine( 'DB_PASSWORD', '' );\ndefine( 'WP_ENVIRONMENT_TYPE', 'local' );\n", $result );
	}

	public function test_wp_config_without_database_constants(): void {
		$result = WpConfig::render( "<?php\nrequire __DIR__ . '/secrets.php';\n", self::DATABASE, [] );

		self::assertFalse( WpConfig::defines( $result, 'DB_NAME' ) );
		self::assertStringContainsString( "define( 'WP_ENVIRONMENT_TYPE', 'local' );", $result );
	}

	public function test_byte_ranges(): void {
		self::assertNull( ByteRange::parse( '', 1000 ) );
		self::assertNull( ByteRange::parse( 'items=0-5', 1000 ) );
		self::assertNull( ByteRange::parse( 'bytes=0-5,10-20', 1000 ) );
		self::assertSame( [ 'start' => 0, 'end' => 499 ], ByteRange::parse( 'bytes=0-499', 1000 ) );
		self::assertSame( [ 'start' => 500, 'end' => 999 ], ByteRange::parse( 'bytes=500-', 1000 ) );
		self::assertSame( [ 'start' => 900, 'end' => 999 ], ByteRange::parse( 'bytes=-100', 1000 ) );
		self::assertSame( [ 'start' => 0, 'end' => 999 ], ByteRange::parse( 'bytes=-5000', 1000 ) );
		self::assertSame( [ 'start' => 990, 'end' => 999 ], ByteRange::parse( 'bytes=990-2000', 1000 ) );
		self::assertFalse( ByteRange::parse( 'bytes=1000-', 1000 ) );
		self::assertFalse( ByteRange::parse( 'bytes=600-500', 1000 ) );
		self::assertFalse( ByteRange::parse( 'bytes=-0', 1000 ) );
	}
}
