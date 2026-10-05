<?php
/**
 * WP-CLI commands.
 *
 * @package JOAWPJM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Imports jobs from the Job Opportunities API (JOA) into WP Job Manager.
 */
class JOAWPJM_CLI {

	/**
	 * Runs an import now.
	 *
	 * ## OPTIONS
	 *
	 * [--search=<id>]
	 * : Only run this saved search (ids are listed by "wp joawpjm status"). Closure checks are skipped.
	 *
	 * [--dry-run]
	 * : Fetch and compare, but do not create, update or expire any listing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp joawpjm import
	 *     wp joawpjm import --search=k3j9x0a1bc --dry-run
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 * @return void
	 */
	public function import( $args, $assoc_args ) {
		$dry_run  = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$importer = new JOAWPJM_Importer();
		$summary  = $importer->run(
			array(
				'search'  => isset( $assoc_args['search'] ) ? sanitize_key( $assoc_args['search'] ) : '',
				'dry_run' => $dry_run,
				'trigger' => 'cli',
				'budget'  => 0,
			)
		);

		if ( '' !== $summary['message'] ) {
			WP_CLI::error( $summary['message'] );
		}

		$rows = array();
		foreach ( $summary['entries'] as $entry ) {
			$rows[] = array(
				'search'    => $entry['search'],
				'fetched'   => $entry['fetched'],
				'created'   => $entry['created'],
				'updated'   => $entry['updated'],
				'unchanged' => $entry['unchanged'],
				'skipped'   => $entry['skipped'],
				'expired'   => $entry['expired'],
				'errors'    => implode( ' | ', array_merge( $entry['errors'], $entry['notes'] ) ),
			);
		}
		if ( $rows ) {
			\WP_CLI\Utils\format_items( 'table', $rows, array_keys( $rows[0] ) );
		} else {
			WP_CLI::warning( 'No enabled saved searches.' );
		}
		if ( $dry_run ) {
			WP_CLI::log( 'Dry run: nothing was written.' );
		}
		if ( $summary['ok'] ) {
			WP_CLI::success( 'Import finished.' );
		} else {
			WP_CLI::warning( 'Import finished with errors.' );
		}
	}

	/**
	 * Shows the configuration, saved searches and recent runs.
	 *
	 * ## EXAMPLES
	 *
	 *     wp joawpjm status
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 * @return void
	 */
	public function status( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WP-CLI command signature.
		$settings = JOAWPJM_Settings::get();
		$last4    = JOAWPJM_Settings::key_last4();
		$next     = wp_next_scheduled( JOAWPJM_Settings::CRON_HOOK );

		WP_CLI::log( 'WP Job Manager:   ' . ( JOAWPJM_Plugin::wpjm_active() ? 'active' : 'NOT active' ) );
		WP_CLI::log( 'API base:         ' . JOAWPJM_API::base_url() );
		WP_CLI::log( 'API key:          ' . ( '' === $last4 ? 'not set' : 'saved (ending ' . $last4 . ')' ) . ( get_option( JOAWPJM_Settings::KEY_INVALID_OPTION ) ? ' - REJECTED by the API' : '' ) );
		WP_CLI::log( 'Schedule:         ' . $settings['schedule'] . ', next run ' . ( $next ? gmdate( 'Y-m-d H:i:s', $next ) . ' UTC' : 'not scheduled' ) );
		WP_CLI::log( 'Closure feed:     ' . ( get_transient( JOAWPJM_Settings::NO_FEED_TRANSIENT ) ? 'not in plan (per-listing checks)' : 'used when available' ) );
		WP_CLI::log( 'Run lock:         ' . ( JOAWPJM_Importer::is_locked() ? 'held' : 'free' ) );

		$counts = array();
		foreach ( array( 'publish', 'pending', 'expired', 'draft', 'trash' ) as $status ) {
			$query             = new WP_Query(
				array(
					'post_type'      => 'job_listing',
					'post_status'    => $status,
					'posts_per_page' => 1,
					'fields'         => 'ids',
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- CLI status only.
					'meta_key'       => '_joawpjm_id',
				)
			);
			$counts[ $status ] = $query->found_posts;
		}
		WP_CLI::log( 'Imported listings: ' . wp_json_encode( $counts ) );

		$state = JOAWPJM_Settings::get_state();
		$rows  = array();
		foreach ( JOAWPJM_Settings::get_searches() as $search ) {
			$last   = isset( $state['searches'][ $search['id'] ]['last_success'] ) ? (int) $state['searches'][ $search['id'] ]['last_success'] : 0;
			$rows[] = array(
				'id'           => $search['id'],
				'label'        => $search['label'],
				'enabled'      => $search['enabled'] ? 'yes' : 'no',
				'max_per_run'  => $search['max_per_run'],
				'last_success' => $last ? gmdate( 'Y-m-d H:i:s', $last ) . ' UTC' : 'never',
			);
		}
		if ( $rows ) {
			\WP_CLI\Utils\format_items( 'table', $rows, array_keys( $rows[0] ) );
		} else {
			WP_CLI::log( 'No saved searches.' );
		}

		$log = array_slice( JOAWPJM_Settings::get_log(), 0, 5 );
		if ( $log ) {
			WP_CLI::log( 'Recent runs:' );
			$items = array();
			foreach ( $log as $e ) {
				$items[] = array(
					'time'    => gmdate( 'Y-m-d H:i:s', (int) $e['time'] ),
					'trigger' => $e['trigger'],
					'search'  => $e['search'],
					'fetched' => $e['fetched'],
					'created' => $e['created'],
					'updated' => $e['updated'],
					'expired' => $e['expired'],
					'errors'  => count( $e['errors'] ),
				);
			}
			\WP_CLI\Utils\format_items( 'table', $items, array_keys( $items[0] ) );
		}
	}
}
