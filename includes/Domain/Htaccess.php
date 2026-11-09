<?php
/**
 * The .htaccess for the local copy. The file of the live site is not reused:
 * HTTPS redirects, PHP handlers of the host and cache rules in it break a
 * XAMPP install. Only the WordPress rewrite block is written.
 *
 * @package RMD\MigrateToLocaldev
 */

namespace RMD\MigrateToLocaldev\Domain;

final class Htaccess {

	/**
	 * @param string $path URL path of the local site without trailing slash ("/example.com", "" for the web root).
	 */
	public static function for_local( string $path ): string {
		$base = rtrim( $path, '/' ) . '/';

		return implode(
			"\n",
			[
				'# BEGIN WordPress',
				'<IfModule mod_rewrite.c>',
				'RewriteEngine On',
				'RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]',
				'RewriteBase ' . $base,
				'RewriteRule ^index\.php$ - [L]',
				'RewriteCond %{REQUEST_FILENAME} !-f',
				'RewriteCond %{REQUEST_FILENAME} !-d',
				'RewriteRule . ' . $base . 'index.php [L]',
				'</IfModule>',
				'# END WordPress',
				'',
			]
		);
	}
}
