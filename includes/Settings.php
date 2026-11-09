<?php
/**
 * Stored settings: the local target of the last export and the name of the
 * export folder.
 *
 * @package RMD\MigrateToLocaldev
 */

namespace RMD\MigrateToLocaldev;

use RMD\MigrateToLocaldev\Domain\Target;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const TARGET_OPTION = 'rmd_mtl_target';
	public const KEY_OPTION    = 'rmd_mtl_export_key';

	/** Every option of this plugin starts with this; they are left out of the dump. */
	public const OPTION_PREFIX = 'rmd_mtl_';

	/**
	 * The export folder inside wp-content. Its name ends with a random key, so
	 * the exports cannot be guessed on servers that ignore the .htaccess in it.
	 */
	public static function export_dir(): string {
		$key = get_option( self::KEY_OPTION, '' );
		if ( ! is_string( $key ) || ! preg_match( '/^[a-z0-9]{24}$/', $key ) ) {
			$key = strtolower( wp_generate_password( 24, false ) );
			update_option( self::KEY_OPTION, $key, false );
		}
		return wp_normalize_path( WP_CONTENT_DIR ) . '/rmd-migrate-to-localdev-' . $key;
	}

	/**
	 * Values for the form: the target of the last export, or defaults with the
	 * host name of this site as folder name.
	 *
	 * @return array<string, mixed>
	 */
	public static function target_values(): array {
		$stored = get_option( self::TARGET_OPTION, null );
		if ( is_array( $stored ) ) {
			return Target::parse( $stored )['target']->to_array();
		}

		$host   = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$folder = (string) preg_replace( '/^www\./i', '', $host );
		return [ 'folder' => Target::is_name( $folder ) ? $folder : '' ] + Target::defaults();
	}

	public static function save_target( Target $target ): void {
		update_option( self::TARGET_OPTION, $target->to_array(), false );
	}

	public static function error_message( string $code ): string {
		$messages = [
			'folder_required'     => __( 'Enter the name of the folder in htdocs.', 'rmd-migrate-to-localdev' ),
			'invalid_folder'      => __( 'The folder name may only contain letters, digits, dots, hyphens and underscores, and no further subfolder.', 'rmd-migrate-to-localdev' ),
			'invalid_htdocs_path' => __( 'The htdocs path must be absolute, e.g. C:/xampp/htdocs.', 'rmd-migrate-to-localdev' ),
			'invalid_base_url'    => __( 'The local address must start with http:// or https:// and must not contain a query or fragment.', 'rmd-migrate-to-localdev' ),
			'invalid_db_name'     => __( 'The database name may only contain letters, digits, dots, hyphens and underscores.', 'rmd-migrate-to-localdev' ),
			'invalid_db_user'     => __( 'The database user contains characters that are not allowed.', 'rmd-migrate-to-localdev' ),
			'invalid_db_password' => __( 'The database password is too long or contains line breaks.', 'rmd-migrate-to-localdev' ),
		];
		return $messages[ $code ] ?? $code;
	}
}
