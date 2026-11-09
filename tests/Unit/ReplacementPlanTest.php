<?php
declare(strict_types=1);

namespace RMD\MigrateToLocaldev\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RMD\MigrateToLocaldev\Domain\ReplacementPlan;
use RMD\MigrateToLocaldev\Domain\SerializedReplacer;

final class ReplacementPlanTest extends TestCase {

	private const LIVE_PATH  = '/mnt/web123/a1/23/51234567/htdocs/marleninflow';
	private const LOCAL_PATH = 'C:/xampp/htdocs/marleninflow.de';

	private function plan( bool $root_relative = true ): ReplacementPlan {
		return ReplacementPlan::build(
			ReplacementPlan::with_www_variants( [ 'https://marleninflow.de' => 'http://localhost/marleninflow.de' ] ),
			[ self::LIVE_PATH . '/' ],
			self::LOCAL_PATH,
			$root_relative
		);
	}

	private function apply( ReplacementPlan $plan, string $value ): string {
		return ( new SerializedReplacer( $plan->pairs ) )->replace( $value );
	}

	/**
	 * @return iterable<string, array{string, string}>
	 */
	public static function variants(): iterable {
		yield 'plain' => [ 'https://marleninflow.de/kontakt/', 'http://localhost/marleninflow.de/kontakt/' ];
		yield 'http' => [ 'http://marleninflow.de/', 'http://localhost/marleninflow.de/' ];
		yield 'www' => [ 'https://www.marleninflow.de/x', 'http://localhost/marleninflow.de/x' ];
		yield 'protocol-relative' => [ 'src="//marleninflow.de/a.js"', 'src="//localhost/marleninflow.de/a.js"' ];
		yield 'json' => [ '{"url":"https:\/\/marleninflow.de\/x"}', '{"url":"http:\/\/localhost\/marleninflow.de\/x"}' ];
		yield 'urlencoded' => [ 'u=https%3A%2F%2Fmarleninflow.de%2Fx', 'u=http%3A%2F%2Flocalhost%2Fmarleninflow.de%2Fx' ];
		yield 'root-relative href' => [ '<img src="/wp-content/uploads/a.png">', '<img src="/marleninflow.de/wp-content/uploads/a.png">' ];
		yield 'root-relative css' => [ "background:url(/wp-content/a.png) url('/wp-includes/b.png')", "background:url(/marleninflow.de/wp-content/a.png) url('/marleninflow.de/wp-includes/b.png')" ];
		yield 'root-relative json' => [ '{"src":"\/wp-content\/a.png"}', '{"src":"\/marleninflow.de\/wp-content\/a.png"}' ];
		yield 'other root-relative links stay' => [ '<a href="/kontakt/">', '<a href="/kontakt/">' ];
		yield 'foreign address stays' => [ 'src="https://cdn.example.org/wp-content/a.js"', 'src="https://cdn.example.org/wp-content/a.js"' ];
		yield 'replaced address is not rewritten again' => [ 'src="https://marleninflow.de/wp-content/a.js"', 'src="http://localhost/marleninflow.de/wp-content/a.js"' ];
		yield 'path' => [ self::LIVE_PATH . '/wp-content/uploads', self::LOCAL_PATH . '/wp-content/uploads' ];
		yield 'path json' => [ '"\/mnt\/web123\/a1\/23\/51234567\/htdocs\/marleninflow\/x"', '"C:\/xampp\/htdocs\/marleninflow.de\/x"' ];
		yield 'bare host stays' => [ 'Visit marleninflow.de today', 'Visit marleninflow.de today' ];
		yield 'e-mail stays' => [ 'info@marleninflow.de', 'info@marleninflow.de' ];
	}

	/**
	 * @dataProvider variants
	 */
	public function test_variants( string $input, string $expected ): void {
		self::assertSame( $expected, $this->apply( $this->plan(), $input ) );
	}

	public function test_serialized_lengths_follow_the_replacement(): void {
		$input    = serialize( [ 'logo' => 'https://marleninflow.de/wp-content/logo.png' ] );
		$expected = serialize( [ 'logo' => 'http://localhost/marleninflow.de/wp-content/logo.png' ] );

		self::assertSame( $expected, $this->apply( $this->plan(), $input ) );
	}

	public function test_root_relative_can_be_disabled(): void {
		self::assertSame( 'src="/wp-content/a.png"', $this->apply( $this->plan( false ), 'src="/wp-content/a.png"' ) );
	}

	public function test_live_site_in_subfolder(): void {
		$plan = ReplacementPlan::build( [ 'https://example.com/blog' => 'http://localhost/blog-dev' ], [], '', true );

		self::assertSame(
			'href="/blog-dev/x" http://localhost/blog-dev/y Web: localhost/blog-dev',
			$this->apply( $plan, 'href="/blog/x" https://example.com/blog/y Web: example.com/blog' )
		);
	}

	public function test_site_address_and_wordpress_address(): void {
		$plan = ReplacementPlan::build(
			[
				'https://example.com'    => 'http://localhost/site',
				'https://example.com/wp' => 'http://localhost/site/wp',
			],
			[],
			'',
			true
		);

		self::assertSame( 'http://localhost/site/wp/wp-admin http://localhost/site/about', $this->apply( $plan, 'https://example.com/wp/wp-admin https://example.com/about' ) );
	}

	public function test_www_variants(): void {
		self::assertSame(
			[
				'https://www.example.com/blog' => 'http://localhost/x',
				'https://example.com/blog'     => 'http://localhost/x',
			],
			ReplacementPlan::with_www_variants( [ 'https://www.example.com/blog' => 'http://localhost/x' ] )
		);
		self::assertSame( [ 'http://127.0.0.1' => 'a' ], ReplacementPlan::with_www_variants( [ 'http://127.0.0.1' => 'a' ] ) );
		self::assertSame( [ 'http://intranet' => 'a' ], ReplacementPlan::with_www_variants( [ 'http://intranet' => 'a' ] ) );
	}

	public function test_several_spellings_of_the_server_path(): void {
		$plan = ReplacementPlan::build( [], [ '/home/www/site', '/mnt/disk7/www/site' ], self::LOCAL_PATH, true );

		self::assertSame( self::LOCAL_PATH . '/a ' . self::LOCAL_PATH . '/b', $this->apply( $plan, '/home/www/site/a /mnt/disk7/www/site/b' ) );
	}

	public function test_short_server_path_is_not_replaced(): void {
		$plan = ReplacementPlan::build( [ 'https://example.com' => 'http://localhost/x' ], [ '/htdocs' ], self::LOCAL_PATH, true );

		self::assertSame( 'http://localhost/x/htdocs/a.pdf', $this->apply( $plan, 'https://example.com/htdocs/a.pdf' ) );
		self::assertNotContains( '/htdocs', $plan->needles );
	}

	public function test_windows_server_path(): void {
		$plan = ReplacementPlan::build( [], [ 'D:\\inetpub\\site' ], self::LOCAL_PATH, true );

		self::assertSame( self::LOCAL_PATH . '\\wp-content', $this->apply( $plan, 'D:\\inetpub\\site\\wp-content' ) );
		self::assertSame( self::LOCAL_PATH . '/wp-content', $this->apply( $plan, 'd:/inetpub/site/wp-content' ) );
	}

	/**
	 * @return iterable<string, array{string, bool}>
	 */
	public static function leftovers(): iterable {
		yield 'bare host' => [ 'Visit marleninflow.de today', true ];
		yield 'subdomain' => [ 'smtp.marleninflow.de', true ];
		yield 'e-mail address' => [ 'info@marleninflow.de', false ];
		yield 'local address contains the host' => [ 'http://localhost/marleninflow.de/kontakt/', false ];
		yield 'local address json' => [ '{"u":"http:\/\/localhost\/marleninflow.de\/x"}', false ];
		yield 'local path contains the host' => [ self::LOCAL_PATH . '/wp-content', false ];
		yield 'local address and a leftover' => [ 'http://localhost/marleninflow.de/ or marleninflow.de', true ];
		yield 'e-mail address and a leftover' => [ 'info@marleninflow.de, marleninflow.de', true ];
		yield 'server path' => [ self::LIVE_PATH . '-old/x', true ];
		yield 'unrelated' => [ 'Hello world', false ];
	}

	/**
	 * @dataProvider leftovers
	 */
	public function test_leftovers( string $value, bool $expected ): void {
		self::assertSame( $expected, $this->plan()->has_leftover( $value ) );
	}

	public function test_needles(): void {
		self::assertSame( [ 'marleninflow.de', self::LIVE_PATH ], $this->plan()->needles );
	}
}
