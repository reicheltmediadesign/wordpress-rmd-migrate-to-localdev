<?php
/**
 * Plugin bootstrap: wires all services to WordPress hooks.
 *
 * @package RMD\MigrateToLocaldev
 */

namespace RMD\MigrateToLocaldev;

use RMD\MigrateToLocaldev\Export\Exports;
use RMD\MigrateToLocaldev\Export\Job;
use Throwable;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	/** Everything the plugin does needs full access to the site's data and files. */
	public const CAP = 'manage_options';

	/** Removes expired exports; they contain the whole database and must not stay on a public server. */
	public const CLEANUP_HOOK = 'rmd_mtl_cleanup';

	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		add_action( 'init', [ self::class, 'load_textdomain' ], 1 );
		add_action( 'init', [ self::class, 'schedule_cleanup' ] );
		add_action( self::CLEANUP_HOOK, [ self::class, 'cleanup' ] );

		Updater::init();
		Rest\ExportController::init();

		if ( is_admin() ) {
			Admin\Page::init();
			Export\Download::init();
		}
	}

	public static function load_textdomain(): void {
		load_plugin_textdomain( 'rmd-migrate-to-localdev', false, dirname( RMD_MTL_BASENAME ) . '/languages' );
	}

	public static function activate(): void {
		self::schedule_cleanup();
	}

	/**
	 * A deactivated plugin can no longer clean up, so nothing is left behind.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CLEANUP_HOOK );
		Job::cancel();
		try {
			Exports::delete_all();
		} catch ( Throwable $e ) {
			// Deactivation must not fail; the message ends up in the PHP error log.
			error_log( 'RMD Migrate to Localdev: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	public static function schedule_cleanup(): void {
		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CLEANUP_HOOK );
		}
	}

	public static function cleanup(): void {
		try {
			Exports::purge_expired();
		} catch ( Throwable $e ) {
			error_log( 'RMD Migrate to Localdev: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
