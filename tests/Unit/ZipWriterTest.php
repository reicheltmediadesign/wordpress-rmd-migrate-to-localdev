<?php
declare(strict_types=1);

namespace RMD\MigrateToLocaldev\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RMD\MigrateToLocaldev\Domain\ZipWriter;
use ZipArchive;

/**
 * The archives are read back with ZipArchive (libzip), which checks sizes and CRC sums.
 *
 * @requires extension zip
 */
final class ZipWriterTest extends TestCase {

	private string $dir;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/rmd-mtl-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->dir );
	}

	protected function tearDown(): void {
		foreach ( (array) glob( $this->dir . '/*' ) as $file ) {
			unlink( (string) $file );
		}
		rmdir( $this->dir );
	}

	/**
	 * @return array{resource, resource}
	 */
	private function open( int $zip_length = 0, int $central_length = 0 ): array {
		$handles = [];
		foreach ( [ 'files.zip' => $zip_length, 'central' => $central_length ] as $name => $length ) {
			$handle = fopen( $this->dir . '/' . $name, 'c+b' );
			self::assertIsResource( $handle );
			ftruncate( $handle, $length );
			fseek( $handle, 0, SEEK_END );
			$handles[] = $handle;
		}
		return $handles;
	}

	private function source( string $name, string $content ): string {
		$path = $this->dir . '/' . $name;
		file_put_contents( $path, $content );
		touch( $path, 1790000000 );
		return $path;
	}

	private function archive(): ZipArchive {
		$zip = new ZipArchive();
		self::assertTrue( $zip->open( $this->dir . '/files.zip', ZipArchive::CHECKCONS ) );
		return $zip;
	}

	public function test_files_strings_and_folders(): void {
		$text   = str_repeat( "Lorem ipsum dolor sit amet.\n", 5000 );
		$binary = random_bytes( 3 * 1024 * 1024 + 17 );

		[ $zip, $central ] = $this->open();
		$writer            = new ZipWriter( $zip, $central );
		$writer->add_directory( 'site/wp-content/uploads', 1790000000 );
		self::assertSame( strlen( $text ), $writer->add_file( 'site/readme.html', $this->source( 'a', $text ) ) );
		self::assertSame( strlen( $binary ), $writer->add_file( 'site/wp-content/uploads/Foto Übersicht.jpg', $this->source( 'b', $binary ) ) );
		self::assertSame( 0, $writer->add_file( 'site/empty.txt', $this->source( 'c', '' ) ) );
		$writer->add_string( 'site/.htaccess', "# BEGIN WordPress\n", 1790000000 );
		$writer->finish();
		self::assertSame( 5, $writer->entries() );
		fclose( $zip );
		fclose( $central );

		$archive = $this->archive();
		self::assertSame( 5, $archive->numFiles );
		self::assertSame( $text, $archive->getFromName( 'site/readme.html' ) );
		self::assertSame( $binary, $archive->getFromName( 'site/wp-content/uploads/Foto Übersicht.jpg' ) );
		self::assertSame( '', $archive->getFromName( 'site/empty.txt' ) );
		self::assertSame( "# BEGIN WordPress\n", $archive->getFromName( 'site/.htaccess' ) );
		self::assertNotFalse( $archive->statName( 'site/wp-content/uploads/' ) );

		$stat = $archive->statName( 'site/readme.html' );
		self::assertSame( ZipArchive::CM_DEFLATE, $stat['comp_method'] );
		self::assertLessThan( strlen( $text ) / 10, $stat['comp_size'] );
		// Zip times are local times without a zone, and libzip reads them in the zone of the machine.
		self::assertEqualsWithDelta( 1790000000, $stat['mtime'], 14 * 3600 );
		self::assertSame( ZipArchive::CM_STORE, $archive->statName( 'site/wp-content/uploads/Foto Übersicht.jpg' )['comp_method'] );
		$archive->close();
	}

	public function test_resume_after_an_interrupted_entry(): void {
		[ $zip, $central ] = $this->open();
		$writer            = new ZipWriter( $zip, $central );
		$writer->add_file( 'site/one.txt', $this->source( 'a', 'one' ) );
		$state = [ ftell( $zip ), ftell( $central ), $writer->entries() ];
		// The next entry is written, but the request dies before the state is saved.
		$writer->add_file( 'site/lost.txt', $this->source( 'b', str_repeat( 'lost', 1000 ) ) );
		fclose( $zip );
		fclose( $central );

		[ $zip, $central ] = $this->open( $state[0], $state[1] );
		$writer            = new ZipWriter( $zip, $central, $state[2] );
		$writer->add_file( 'site/two.txt', $this->source( 'c', 'two' ) );
		$writer->finish();
		fclose( $zip );
		fclose( $central );

		$archive = $this->archive();
		self::assertSame( 2, $archive->numFiles );
		self::assertSame( 'one', $archive->getFromName( 'site/one.txt' ) );
		self::assertSame( 'two', $archive->getFromName( 'site/two.txt' ) );
		self::assertFalse( $archive->locateName( 'site/lost.txt' ) );
		$archive->close();
	}

	public function test_more_entries_than_the_classic_format_allows(): void {
		[ $zip, $central ] = $this->open();
		$writer            = new ZipWriter( $zip, $central );
		for ( $i = 0; $i < 66000; $i++ ) {
			$writer->add_string( 'site/' . $i . '.txt', (string) $i, 1790000000 );
		}
		$writer->finish();
		fclose( $zip );
		fclose( $central );

		$archive = $this->archive();
		self::assertSame( 66000, $archive->numFiles );
		self::assertSame( '65999', $archive->getFromName( 'site/65999.txt' ) );
		$archive->close();
	}
}
