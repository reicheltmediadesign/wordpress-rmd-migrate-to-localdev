<?php
/**
 * REST endpoints the admin screen uses to run an export step by step.
 *
 *   GET    /rmd-migrate-to-localdev/v1/export        status of the current export
 *   POST   /rmd-migrate-to-localdev/v1/export        start an export { folder, db_name, … }
 *   POST   /rmd-migrate-to-localdev/v1/export/step   do the next few seconds of work
 *   DELETE /rmd-migrate-to-localdev/v1/export        cancel (or forget a finished export)
 *
 * @package RMD\MigrateToLocaldev
 */

namespace RMD\MigrateToLocaldev\Rest;

use RMD\MigrateToLocaldev\Domain\Target;
use RMD\MigrateToLocaldev\Export\Job;
use RMD\MigrateToLocaldev\Plugin;
use RMD\MigrateToLocaldev\Settings;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

final class ExportController {

	public const NAMESPACE = 'rmd-migrate-to-localdev/v1';

	public static function init(): void {
		add_action( 'rest_api_init', [ self::class, 'routes' ] );
	}

	public static function routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/export',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ self::class, 'status' ],
					'permission_callback' => [ self::class, 'permission' ],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ self::class, 'start' ],
					'permission_callback' => [ self::class, 'permission' ],
				],
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ self::class, 'cancel' ],
					'permission_callback' => [ self::class, 'permission' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/export/step',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ self::class, 'step' ],
				'permission_callback' => [ self::class, 'permission' ],
			]
		);
	}

	/**
	 * @return true|WP_Error
	 */
	public static function permission() {
		if ( ! current_user_can( Plugin::CAP ) ) {
			return new WP_Error( 'rest_forbidden', __( 'You are not allowed to export this site.', 'rmd-migrate-to-localdev' ), [ 'status' => rest_authorization_required_code() ] );
		}
		return true;
	}

	public static function status(): WP_REST_Response {
		$job = Job::current();
		return new WP_REST_Response( null === $job ? null : Job::status( $job ) );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public static function start( WP_REST_Request $request ) {
		// Only the known fields are read; Target::parse() normalizes every value.
		$result = Target::parse( array_intersect_key( (array) $request->get_json_params(), Target::defaults() ) );
		if ( [] !== $result['errors'] ) {
			return new WP_Error(
				'rmd_mtl_invalid_target',
				implode( ' ', array_map( [ Settings::class, 'error_message' ], $result['errors'] ) ),
				[ 'status' => 400 ]
			);
		}

		$target = $result['target'];
		Settings::save_target( $target );
		return self::guarded( static fn(): array => Job::status( Job::start( $target ) ) );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public static function step() {
		return self::guarded( static fn(): array => Job::status( Job::step() ) );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public static function cancel() {
		return self::guarded(
			static function (): ?array {
				Job::cancel();
				return null;
			}
		);
	}

	/**
	 * Turns exceptions into a REST error with the message, so the admin screen can show it.
	 *
	 * @param callable(): ?array<string, mixed> $callback
	 * @return WP_REST_Response|WP_Error
	 */
	private static function guarded( callable $callback ) {
		try {
			return new WP_REST_Response( $callback() );
		} catch ( Throwable $e ) {
			// Messages are escaped when thrown; the admin screen inserts them as text.
			return new WP_Error( 'rmd_mtl_export_failed', wp_specialchars_decode( $e->getMessage(), ENT_QUOTES ), [ 'status' => 500 ] );
		}
	}
}
