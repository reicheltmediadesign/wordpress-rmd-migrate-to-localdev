<?php
/**
 * Tools → Migrate to localdev: the form for the local target, the running
 * export and the finished exports with their downloads.
 *
 * @package RMD\MigrateToLocaldev
 */

namespace RMD\MigrateToLocaldev\Admin;

use RMD\MigrateToLocaldev\Domain\Target;
use RMD\MigrateToLocaldev\Export\Exports;
use RMD\MigrateToLocaldev\Export\Job;
use RMD\MigrateToLocaldev\Plugin;
use RMD\MigrateToLocaldev\Rest\ExportController;
use RMD\MigrateToLocaldev\Settings;
use Throwable;

defined( 'ABSPATH' ) || exit;

final class Page {

	public const SLUG = 'rmd-migrate-to-localdev';

	private const ERROR_TRANSIENT = 'rmd_mtl_error_';

	public static function init(): void {
		add_action( 'admin_menu', [ self::class, 'menu' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'assets' ] );
		add_action( 'admin_post_rmd_mtl_delete_export', [ self::class, 'delete_export' ] );
		add_filter( 'plugin_action_links_' . RMD_MTL_BASENAME, [ self::class, 'action_links' ] );
	}

	public static function menu(): void {
		add_management_page(
			__( 'Migrate to localdev', 'rmd-migrate-to-localdev' ),
			__( 'Migrate to localdev', 'rmd-migrate-to-localdev' ),
			Plugin::CAP,
			self::SLUG,
			[ self::class, 'render' ]
		);
	}

	/**
	 * @param array<string, string> $links
	 * @return array<string, string>
	 */
	public static function action_links( array $links ): array {
		return [ 'rmd-mtl' => '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Export', 'rmd-migrate-to-localdev' ) . '</a>' ] + $links;
	}

	/**
	 * @param array<string, string> $args
	 */
	public static function url( array $args = [] ): string {
		return add_query_arg( [ 'page' => self::SLUG ] + $args, admin_url( 'tools.php' ) );
	}

	public static function assets( string $hook ): void {
		if ( 'tools_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'rmd-mtl-admin', RMD_MTL_URL . 'assets/css/admin.css', [], RMD_MTL_VERSION );
		wp_enqueue_script( 'rmd-mtl-admin', RMD_MTL_URL . 'assets/js/admin.js', [ 'wp-api-fetch' ], RMD_MTL_VERSION, true );

		$job = Job::current();
		wp_localize_script(
			'rmd-mtl-admin',
			'rmdMtlAdmin',
			[
				'path'    => '/' . ExportController::NAMESPACE . '/export',
				'status'  => null === $job ? null : Job::status( $job ),
				'strings' => [
					'confirmCancel' => __( 'Cancel the export and delete its unfinished files?', 'rmd-migrate-to-localdev' ),
					'confirmDelete' => __( 'Delete this export permanently?', 'rmd-migrate-to-localdev' ),
					'starting'      => __( 'Starting export …', 'rmd-migrate-to-localdev' ),
					'failed'        => __( 'The export stopped with an error:', 'rmd-migrate-to-localdev' ),
					'retry'         => __( 'Continue', 'rmd-migrate-to-localdev' ),
					'cancel'        => __( 'Cancel export', 'rmd-migrate-to-localdev' ),
					'dismiss'       => __( 'Dismiss', 'rmd-migrate-to-localdev' ),
					'warnings'      => __( 'Warnings', 'rmd-migrate-to-localdev' ),
					'leftovers'     => __( 'Some values still refer to the live site. See MIGRATION.txt.', 'rmd-migrate-to-localdev' ),
					'finished'      => __( 'Done. Download the files and follow MIGRATION.txt.', 'rmd-migrate-to-localdev' ),
					'download'      => self::download_labels(),
				],
			]
		);
	}

	public static function render(): void {
		if ( ! current_user_can( Plugin::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'rmd-migrate-to-localdev' ) );
		}
		global $wpdb;

		// WP-Cron may be switched off; expired exports must not stay on the server.
		Plugin::cleanup();

		echo '<div class="wrap rmd-mtl">';
		echo '<h1>' . esc_html__( 'Migrate to localdev', 'rmd-migrate-to-localdev' ) . '</h1>';

		self::render_notices();

		echo '<p>' . esc_html__( 'Creates a copy of this site for local development with XAMPP: a zip archive of all files and a database dump in which every address and path of this site is replaced by that of the local copy, safe for serialized data. This site is not changed.', 'rmd-migrate-to-localdev' ) . '</p>';
		echo '<table class="rmd-mtl-source"><tbody>';
		self::source_row( __( 'Address', 'rmd-migrate-to-localdev' ), untrailingslashit( home_url() ) );
		self::source_row( __( 'Server path', 'rmd-migrate-to-localdev' ), untrailingslashit( wp_normalize_path( ABSPATH ) ) );
		self::source_row( __( 'Table prefix', 'rmd-migrate-to-localdev' ), $wpdb->prefix );
		echo '</tbody></table>';

		if ( is_multisite() ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Multisite networks are not supported.', 'rmd-migrate-to-localdev' ) . '</p></div>';
		}

		echo '<div id="rmd-mtl-progress" class="rmd-mtl-progress" hidden></div>';

		self::render_exports();
		self::render_form();
		echo '</div>';
	}

	private static function source_row( string $label, string $value ): void {
		printf( '<tr><th scope="row">%s</th><td><code>%s</code></td></tr>', esc_html( $label ), esc_html( $value ) );
	}

	private static function render_form(): void {
		$values = Settings::target_values();

		echo '<form id="rmd-mtl-form" class="rmd-mtl-form">';

		echo '<h2>' . esc_html__( 'Local copy', 'rmd-migrate-to-localdev' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		self::text_field( 'folder', __( 'Folder in htdocs', 'rmd-migrate-to-localdev' ), (string) $values['folder'], __( 'Name of the folder the site will live in, e.g. example.com. The archive contains a folder with this name, and the local address ends with it.', 'rmd-migrate-to-localdev' ), 'regular-text code', true );
		self::text_field( 'db_name', __( 'Database name', 'rmd-migrate-to-localdev' ), (string) $values['db_name'], __( 'Name of the local database. Empty = same as the folder. It is written into wp-config.php and created by the dump.', 'rmd-migrate-to-localdev' ), 'regular-text code', false, (string) $values['folder'] );
		self::text_field( 'db_user', __( 'Database user', 'rmd-migrate-to-localdev' ), (string) $values['db_user'], __( 'User of the local MySQL server for wp-config.php. XAMPP default: root.', 'rmd-migrate-to-localdev' ), 'regular-text code', false, Target::DEFAULT_DB_USER );
		self::text_field( 'db_password', __( 'Database password', 'rmd-migrate-to-localdev' ), (string) $values['db_password'], __( 'Password of that local user for wp-config.php. XAMPP default: empty. It is saved on this site for the next export, so only enter the password of your local development server here.', 'rmd-migrate-to-localdev' ), 'regular-text code', false, '', 'password' );
		self::text_field( 'htdocs_path', __( 'htdocs path', 'rmd-migrate-to-localdev' ), (string) $values['htdocs_path'], __( 'Path of the htdocs folder of XAMPP. Server paths stored in the database are replaced by this path plus the folder.', 'rmd-migrate-to-localdev' ), 'regular-text code', false, Target::DEFAULT_HTDOCS_PATH );
		self::text_field( 'base_url', __( 'Local address', 'rmd-migrate-to-localdev' ), (string) $values['base_url'], __( 'Address of the local web server without the folder. Change it if Apache uses another port, e.g. http://localhost:8080.', 'rmd-migrate-to-localdev' ), 'regular-text code', false, Target::DEFAULT_BASE_URL );
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Files', 'rmd-migrate-to-localdev' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		self::checkbox_field( 'include_files', __( 'Files', 'rmd-migrate-to-localdev' ), (bool) $values['include_files'], __( 'Pack the complete WordPress folder. Without it only the database is exported.', 'rmd-migrate-to-localdev' ) );
		self::checkbox_field( 'include_uploads', __( 'Uploads', 'rmd-migrate-to-localdev' ), (bool) $values['include_uploads'], __( 'Include the media library (wp-content/uploads). Without it the archive is much smaller, but images are missing in the local copy.', 'rmd-migrate-to-localdev' ) );
		self::textarea_field( 'exclude_patterns', __( 'Leave out', 'rmd-migrate-to-localdev' ), (array) $values['exclude_patterns'], __( 'One rule per line, like .gitignore: "name" matches everywhere, "/name" only in the WordPress folder, a trailing "/" only folders, "*" is a wildcard. wp-config.php and .htaccess are replaced by versions for the local copy; the export folder and this plugin are always left out.', 'rmd-migrate-to-localdev' ) );
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Database', 'rmd-migrate-to-localdev' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		self::textarea_field( 'empty_tables', __( 'Tables without data', 'rmd-migrate-to-localdev' ), (array) $values['empty_tables'], __( 'Table names without prefix, one per line. They are created empty, e.g. logs or indexes that plugins rebuild.', 'rmd-migrate-to-localdev' ), 4 );
		self::checkbox_field( 'root_relative_links', __( 'Relative links', 'rmd-migrate-to-localdev' ), (bool) $values['root_relative_links'], __( 'Adjust links without a domain like "/wp-content/…" to the local folder.', 'rmd-migrate-to-localdev' ) );
		self::checkbox_field( 'skip_revisions', __( 'Revisions', 'rmd-migrate-to-localdev' ), (bool) $values['skip_revisions'], __( 'Leave out post revisions.', 'rmd-migrate-to-localdev' ) );
		self::checkbox_field( 'skip_spam_comments', __( 'Spam', 'rmd-migrate-to-localdev' ), (bool) $values['skip_spam_comments'], __( 'Leave out spam and trashed comments.', 'rmd-migrate-to-localdev' ) );
		self::checkbox_field( 'discourage_search_engines', __( 'Search engines', 'rmd-migrate-to-localdev' ), (bool) $values['discourage_search_engines'], __( 'Discourage search engines from indexing the copy (Settings → Reading), in case it is ever reachable from outside.', 'rmd-migrate-to-localdev' ) );
		echo '</tbody></table>';

		printf(
			'<p class="submit"><button type="submit" class="button button-primary rmd-mtl-export" %s>%s</button></p>',
			disabled( is_multisite(), true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed attribute string.
			esc_html__( 'Create export', 'rmd-migrate-to-localdev' )
		);
		echo '</form>';
	}

	private static function render_exports(): void {
		$exports = Exports::all();
		if ( [] === $exports ) {
			return;
		}

		$running = Job::is_running() ? (string) Job::current()['id'] : '';
		$labels  = self::download_labels();

		echo '<h2>' . esc_html__( 'Exports', 'rmd-migrate-to-localdev' ) . '</h2>';
		echo '<p>' . esc_html(
			sprintf(
				/* translators: %s: number of hours */
				__( 'Exports contain the complete database including user accounts. They are deleted automatically %s hours after they were created, and when the plugin is deactivated.', 'rmd-migrate-to-localdev' ),
				number_format_i18n( Exports::LIFETIME / HOUR_IN_SECONDS )
			)
		) . '</p>';
		echo '<table class="widefat striped rmd-mtl-exports"><thead><tr>';
		echo '<th>' . esc_html__( 'Export', 'rmd-migrate-to-localdev' ) . '</th>';
		echo '<th>' . esc_html__( 'Downloads', 'rmd-migrate-to-localdev' ) . '</th>';
		echo '<th>' . esc_html__( 'Deleted on', 'rmd-migrate-to-localdev' ) . '</th>';
		echo '<th></th></tr></thead><tbody>';

		foreach ( $exports as $export ) {
			$is_running = $running === $export['name'];
			$delete     = wp_nonce_url(
				add_query_arg(
					[
						'action' => 'rmd_mtl_delete_export',
						'export' => $export['name'],
					],
					admin_url( 'admin-post.php' )
				),
				'rmd_mtl_delete_export_' . $export['name']
			);

			echo '<tr><td><strong>' . esc_html( $export['name'] ) . '</strong>';
			if ( ! $export['complete'] ) {
				echo ' <span class="rmd-mtl-badge">' . esc_html( $is_running ? __( 'running', 'rmd-migrate-to-localdev' ) : __( 'incomplete', 'rmd-migrate-to-localdev' ) ) . '</span>';
			}
			echo '</td><td class="rmd-mtl-downloads">';
			foreach ( Exports::DOWNLOADS as $key ) {
				$file = $export['complete'] ? Exports::file( $export['name'], $key ) : null;
				if ( null === $file ) {
					continue;
				}
				printf(
					'<a href="%s" class="button">%s <span class="rmd-mtl-size">(%s)</span></a> ',
					esc_url( Exports::download_url( $export['name'], $key ) ),
					esc_html( $labels[ $key ] ),
					esc_html( size_format( (int) filesize( $file['path'] ) ) )
				);
			}
			echo '</td><td>' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $export['expires'] ) ) . '</td>';
			echo '<td class="rmd-mtl-actions">';
			if ( ! $is_running ) {
				echo '<a href="' . esc_url( $delete ) . '" class="button button-link-delete rmd-mtl-confirm">' . esc_html__( 'Delete', 'rmd-migrate-to-localdev' ) . '</a>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * @return array<string, string>
	 */
	private static function download_labels(): array {
		return [
			'files'    => __( 'Files (zip)', 'rmd-migrate-to-localdev' ),
			'database' => __( 'Database', 'rmd-migrate-to-localdev' ),
			'report'   => __( 'MIGRATION.txt', 'rmd-migrate-to-localdev' ),
		];
	}

	private static function text_field( string $key, string $label, string $value, string $description, string $css_class, bool $required = false, string $placeholder = '', string $type = 'text' ): void {
		printf(
			'<tr><th scope="row"><label for="rmd-mtl-%1$s">%2$s</label></th><td><input type="%8$s" id="rmd-mtl-%1$s" name="%1$s" value="%3$s" class="%4$s" placeholder="%5$s" autocomplete="off" %6$s><p class="description">%7$s</p></td></tr>',
			esc_attr( $key ),
			esc_html( $label ),
			esc_attr( $value ),
			esc_attr( $css_class ),
			esc_attr( $placeholder ),
			$required ? 'required' : '',
			esc_html( $description ),
			esc_attr( $type )
		);
	}

	private static function checkbox_field( string $key, string $label, bool $checked, string $description ): void {
		printf(
			'<tr><th scope="row">%2$s</th><td><label><input type="checkbox" name="%1$s" value="1" %3$s> %4$s</label></td></tr>',
			esc_attr( $key ),
			esc_html( $label ),
			checked( $checked, true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed attribute string.
			esc_html( $description )
		);
	}

	/**
	 * @param list<string> $lines
	 */
	private static function textarea_field( string $key, string $label, array $lines, string $description, int $rows = 10 ): void {
		printf(
			'<tr><th scope="row"><label for="rmd-mtl-%1$s">%2$s</label></th><td><textarea id="rmd-mtl-%1$s" name="%1$s" rows="%3$d" class="large-text code">%4$s</textarea><p class="description">%5$s</p></td></tr>',
			esc_attr( $key ),
			esc_html( $label ),
			(int) $rows,
			esc_textarea( implode( "\n", $lines ) ),
			esc_html( $description )
		);
	}

	private static function render_notices(): void {
		$notice = isset( $_GET['rmd_mtl_notice'] ) ? sanitize_key( wp_unslash( $_GET['rmd_mtl_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$error  = get_transient( self::ERROR_TRANSIENT . get_current_user_id() );
		if ( is_string( $error ) && '' !== $error ) {
			delete_transient( self::ERROR_TRANSIENT . get_current_user_id() );
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $error ) . '</p></div>';
		}
		if ( 'export_deleted' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Export deleted.', 'rmd-migrate-to-localdev' ) . '</p></div>';
		}
	}

	public static function delete_export(): void {
		if ( ! current_user_can( Plugin::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'rmd-migrate-to-localdev' ), 403 );
		}
		$name = isset( $_GET['export'] ) ? Exports::clean_name( sanitize_text_field( wp_unslash( $_GET['export'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked below.
		check_admin_referer( 'rmd_mtl_delete_export_' . $name );

		try {
			$job = Job::current();
			if ( null !== $job && $job['id'] === $name ) {
				Job::cancel();
			}
			Exports::delete( $name );
		} catch ( Throwable $e ) {
			set_transient( self::ERROR_TRANSIENT . get_current_user_id(), wp_specialchars_decode( $e->getMessage(), ENT_QUOTES ), MINUTE_IN_SECONDS );
			self::redirect( self::url() );
		}
		self::redirect( self::url( [ 'rmd_mtl_notice' => 'export_deleted' ] ) );
	}

	private static function redirect( string $url ): never {
		wp_safe_redirect( $url );
		exit;
	}
}
