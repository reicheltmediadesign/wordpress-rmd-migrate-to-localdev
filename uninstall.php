<?php
/**
 * Removes the plugin's options. The exports themselves are already deleted
 * when the plugin is deactivated.
 *
 * @package RMD\MigrateToLocaldev
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'rmd_mtl_target' );
delete_option( 'rmd_mtl_export_key' );
delete_option( 'rmd_mtl_job' );
delete_option( 'external_updates-rmd-migrate-to-localdev' );
delete_transient( 'rmd_mtl_lock' );
