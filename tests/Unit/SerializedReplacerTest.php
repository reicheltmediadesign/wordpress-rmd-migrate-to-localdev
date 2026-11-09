<?php
declare(strict_types=1);

namespace RMD\MigrateToLocaldev\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RMD\MigrateToLocaldev\Domain\SerializedReplacer;

final class SerializedReplacerTest extends TestCase {

	private const FROM = 'http://localhost/marleninflow.de';
	private const TO   = 'https://marleninflow.de';

	private function replacer(): SerializedReplacer {
		return new SerializedReplacer( [ self::FROM => self::TO ] );
	}

	public function test_plain_text(): void {
		self::assertSame(
			'<a href="https://marleninflow.de/kontakt/">Kontakt</a>',
			$this->replacer()->replace( '<a href="http://localhost/marleninflow.de/kontakt/">Kontakt</a>' )
		);
	}

	public function test_untouched_value_is_returned_as_is(): void {
		$value = serialize( [ 'a' => 'b' ] );
		self::assertSame( $value, $this->replacer()->replace( $value ) );
	}

	public function test_serialized_array_lengths_are_recomputed(): void {
		$input = serialize(
			[
				'logo'  => self::FROM . '/wp-content/uploads/logo.png',
				'count' => 3,
				'ratio' => 1.5,
				'on'    => true,
				'none'  => null,
				'list'  => [ self::FROM, 'other' ],
			]
		);

		$output = $this->replacer()->replace( $input );

		self::assertSame(
			[
				'logo'  => self::TO . '/wp-content/uploads/logo.png',
				'count' => 3,
				'ratio' => 1.5,
				'on'    => true,
				'none'  => null,
				'list'  => [ self::TO, 'other' ],
			],
			unserialize( $output )
		);
	}

	public function test_multibyte_strings_use_byte_lengths(): void {
		$input  = serialize( [ 'text' => 'Größe: ' . self::FROM . ' – ✓' ] );
		$output = $this->replacer()->replace( $input );

		self::assertSame( [ 'text' => 'Größe: ' . self::TO . ' – ✓' ], unserialize( $output ) );
	}

	public function test_objects_keep_private_properties(): void {
		$input  = serialize( new SerializedFixture( self::FROM . '/a', self::FROM . '/b' ) );
		$output = $this->replacer()->replace( $input );

		$restored = unserialize( $output );
		self::assertInstanceOf( SerializedFixture::class, $restored );
		self::assertSame( self::TO . '/a', $restored->public_url );
		self::assertSame( self::TO . '/b', $restored->private_url() );
	}

	public function test_unknown_classes_are_rewritten_without_loading_them(): void {
		$url    = static fn( string $u ): string => 's:' . strlen( $u ) . ':"' . $u . '";';
		$class  = 'Some\\Unknown\\Widget';
		$input  = 'O:19:"' . $class . '":2:{s:10:"public_url";' . $url( self::FROM . '/a' ) . "s:32:\"\0" . $class . "\0private_url\";" . $url( self::FROM . '/b' ) . '}';
		$output = $this->replacer()->replace( $input );

		self::assertSame(
			'O:19:"' . $class . '":2:{s:10:"public_url";' . $url( self::TO . '/a' ) . "s:32:\"\0" . $class . "\0private_url\";" . $url( self::TO . '/b' ) . '}',
			$output
		);
		self::assertFalse( class_exists( $class, false ) );
	}

	public function test_nested_serialized_string(): void {
		$inner  = serialize( [ 'url' => self::FROM ] );
		$input  = serialize( [ 'payload' => $inner ] );
		$output = $this->replacer()->replace( $input );

		$outer = unserialize( $output );
		self::assertIsArray( $outer );
		self::assertSame( [ 'url' => self::TO ], unserialize( $outer['payload'] ) );
	}

	public function test_json_inside_serialized_value(): void {
		$replacer = new SerializedReplacer(
			[
				self::FROM                          => self::TO,
				str_replace( '/', '\\/', self::FROM ) => str_replace( '/', '\\/', self::TO ),
			]
		);
		$json     = json_encode( [ 'src' => self::FROM . '/x.jpg' ] );
		$output   = $replacer->replace( serialize( [ 'json' => $json ] ) );

		$data = unserialize( $output );
		self::assertIsArray( $data );
		self::assertSame( [ 'src' => self::TO . '/x.jpg' ], json_decode( $data['json'], true ) );
	}

	public function test_keys_are_not_replaced(): void {
		$input  = serialize( [ self::FROM => self::FROM ] );
		$output = $this->replacer()->replace( $input );

		self::assertSame( [ self::FROM => self::TO ], unserialize( $output ) );
	}

	public function test_references_and_enums_are_copied(): void {
		$shared = [ 'url' => self::FROM ];
		$object = new \stdClass();
		$object->a = $shared;
		$object->b = &$object->a;
		$input  = serialize( [ $object, $object ] );
		$output = $this->replacer()->replace( $input );

		$data = unserialize( $output );
		self::assertIsArray( $data );
		self::assertSame( self::TO, $data[0]->a['url'] );
		self::assertSame( $data[0], $data[1] );
	}

	public function test_broken_serialized_value_is_left_unchanged_and_counted(): void {
		$replacer = $this->replacer();
		$broken   = 'a:1:{s:3:"url";s:5:"' . self::FROM . '";}';

		self::assertSame( $broken, $replacer->replace( $broken ) );
		self::assertSame( 1, $replacer->unparsable() );

		$replacer->reset();
		self::assertSame( 0, $replacer->unparsable() );
	}

	public function test_text_that_only_starts_like_serialized_data(): void {
		$replacer = $this->replacer();
		$value    = 'i:1; see ' . self::FROM;

		self::assertSame( $value, $replacer->replace( $value ) );
		self::assertSame( 1, $replacer->unparsable() );
	}

	public function test_floats_and_booleans(): void {
		$input  = serialize( [ -1.25e-7, INF, false, -3, self::FROM ] );
		$output = $this->replacer()->replace( $input );

		self::assertSame( [ -1.25e-7, INF, false, -3, self::TO ], unserialize( $output ) );
	}

	public function test_custom_serializable_payload_is_copied_verbatim(): void {
		$input  = 'a:2:{i:0;C:11:"ArrayObject":21:{x:i:0;a:0:{};m:a:0:{}}i:1;s:' . strlen( self::FROM ) . ':"' . self::FROM . '";}';
		$output = $this->replacer()->replace( $input );

		self::assertSame( 'a:2:{i:0;C:11:"ArrayObject":21:{x:i:0;a:0:{};m:a:0:{}}i:1;s:' . strlen( self::TO ) . ':"' . self::TO . '";}', $output );
	}

	public function test_single_pass_does_not_chain_replacements(): void {
		$replacer = new SerializedReplacer(
			[
				'a' => 'b',
				'b' => 'c',
			]
		);
		self::assertSame( 'bc', $replacer->replace( 'ab' ) );
	}
}

final class SerializedFixture {

	public function __construct(
		public string $public_url,
		private string $private_url
	) {}

	public function private_url(): string {
		return $this->private_url;
	}
}
