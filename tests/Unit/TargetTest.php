<?php
declare(strict_types=1);

namespace RMD\MigrateToLocaldev\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RMD\MigrateToLocaldev\Domain\Target;

final class TargetTest extends TestCase {

	public function test_valid_form_data(): void {
		$result = Target::parse(
			[
				'folder'           => ' marleninflow.de/ ',
				'htdocs_path'      => 'D:\\xampp\\htdocs\\',
				'base_url'         => 'http://localhost:8080/',
				'db_name'          => 'marlen_dev',
				'db_user'          => ' dev ',
				'db_password'      => ' pa\'ss word ',
				'include_uploads'  => '0',
				'exclude_patterns' => ".git/\n\n  *.log  \n.git/",
				'empty_tables'     => "yoast_indexable\nbad table;",
				'skip_revisions'   => '1',
			]
		);
		$target = $result['target'];

		self::assertSame( [], $result['errors'] );
		self::assertSame( 'marleninflow.de', $target->folder );
		self::assertSame( 'D:/xampp/htdocs', $target->htdocs_path );
		self::assertSame( 'http://localhost:8080/marleninflow.de', $target->url() );
		self::assertSame( 'D:/xampp/htdocs/marleninflow.de', $target->path() );
		self::assertSame( 'marlen_dev', $target->database() );
		self::assertSame( 'dev', $target->db_user );
		self::assertSame( ' pa\'ss word ', $target->db_password );
		self::assertTrue( $target->include_files );
		self::assertFalse( $target->include_uploads );
		self::assertSame( [ '.git/', '*.log' ], $target->exclude_patterns );
		self::assertSame( [ 'yoast_indexable' ], $target->empty_tables );
		self::assertTrue( $target->skip_revisions );
		self::assertTrue( $target->is_complete() );
	}

	public function test_defaults(): void {
		$result = Target::parse( [ 'folder' => 'site' ] );
		$target = $result['target'];

		self::assertSame( [], $result['errors'] );
		self::assertSame( 'http://localhost/site', $target->url() );
		self::assertSame( 'C:/xampp/htdocs/site', $target->path() );
		self::assertSame( 'site', $target->database() );
		self::assertSame( 'root', $target->db_user );
		self::assertSame( '', $target->db_password );
		self::assertTrue( $target->include_files );
		self::assertTrue( $target->include_uploads );
		self::assertTrue( $target->discourage_search_engines );
		self::assertSame( Target::DEFAULT_EXCLUDES, $target->exclude_patterns );
		self::assertSame( Target::DEFAULT_EMPTY_TABLES, $target->empty_tables );
	}

	public function test_folder_is_required(): void {
		$result = Target::parse( [] );

		self::assertSame( [ 'folder_required' ], $result['errors'] );
		self::assertFalse( $result['target']->is_complete() );
	}

	public function test_invalid_values_fall_back(): void {
		$result = Target::parse(
			[
				'folder'      => 'sub/site',
				'htdocs_path' => 'xampp/htdocs',
				'base_url'    => 'localhost',
				'db_name'     => 'my`db',
				'db_user'     => 'ro ot',
				'db_password' => "a\nb",
			]
		);

		self::assertSame( [ 'invalid_folder', 'invalid_htdocs_path', 'invalid_base_url', 'invalid_db_name', 'invalid_db_user', 'invalid_db_password' ], $result['errors'] );
		self::assertSame( '', $result['target']->folder );
		self::assertSame( Target::DEFAULT_HTDOCS_PATH, $result['target']->htdocs_path );
		self::assertSame( Target::DEFAULT_BASE_URL, $result['target']->base_url );
		self::assertSame( '', $result['target']->db_name );
		self::assertSame( 'root', $result['target']->db_user );
		self::assertSame( '', $result['target']->db_password );
	}

	public function test_names(): void {
		self::assertTrue( Target::is_name( 'marleninflow.de' ) );
		self::assertTrue( Target::is_name( 'my-site_2' ) );
		self::assertTrue( Target::is_name( 'a' ) );
		self::assertFalse( Target::is_name( '..' ) );
		self::assertFalse( Target::is_name( '.hidden' ) );
		self::assertFalse( Target::is_name( 'trailing.' ) );
		self::assertFalse( Target::is_name( 'with space' ) );
		self::assertFalse( Target::is_name( 'a/b' ) );
		self::assertFalse( Target::is_name( str_repeat( 'a', 65 ) ) );
	}

	public function test_round_trip(): void {
		$target = Target::parse(
			[
				'folder'          => 'site',
				'db_name'         => 'other',
				'db_password'     => 'secret',
				'include_uploads' => false,
			]
		)['target'];

		self::assertEquals( $target, Target::parse( $target->to_array() )['target'] );
	}
}
