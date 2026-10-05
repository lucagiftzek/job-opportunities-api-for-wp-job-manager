<?php
/**
 * Option storage helpers.
 *
 * Everything the plugin stores lives in a handful of options. The API key,
 * saved searches, run state and activity log are stored with autoload off so
 * they are never loaded on front-end requests.
 *
 * @package JOAWPJM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Static accessors for the plugin options.
 */
class JOAWPJM_Settings {

	const OPTION             = 'joawpjm_settings';
	const KEY_OPTION         = 'joawpjm_api_key';
	const SEARCHES_OPTION    = 'joawpjm_searches';
	const STATE_OPTION       = 'joawpjm_state';
	const LOG_OPTION         = 'joawpjm_log';
	const KEY_INVALID_OPTION = 'joawpjm_key_invalid';
	const LOCK_OPTION        = 'joawpjm_lock';
	const CRON_HOOK          = 'joawpjm_cron_import';
	const ME_TRANSIENT       = 'joawpjm_me';
	const NO_FEED_TRANSIENT  = 'joawpjm_no_expired_feed';
	const RETRY_TRANSIENT    = 'joawpjm_retry_after';
	const LOG_SIZE           = 50;

	/**
	 * Every option name the plugin may create (used by uninstall).
	 *
	 * @return string[]
	 */
	public static function option_names() {
		return array( self::OPTION, self::KEY_OPTION, self::SEARCHES_OPTION, self::STATE_OPTION, self::LOG_OPTION, self::KEY_INVALID_OPTION, self::LOCK_OPTION );
	}

	/**
	 * Default general settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'schedule'            => 'twicedaily',
			'post_status'         => 'publish',
			'duration'            => 30,
			'keep_descriptions'   => 1,
			'attribution'         => 0,
			'delete_on_uninstall' => 0,
			'closure_checks'      => 25,
		);
	}

	/**
	 * Reads the general settings, or one of them.
	 *
	 * @param string|null $key Optional setting name.
	 * @return mixed
	 */
	public static function get( $key = null ) {
		$settings = get_option( self::OPTION, array() );
		$settings = wp_parse_args( is_array( $settings ) ? $settings : array(), self::defaults() );
		if ( null === $key ) {
			return $settings;
		}
		return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
	}

	/**
	 * Saves the general settings (autoload off).
	 *
	 * @param array $settings Sanitized settings.
	 * @return void
	 */
	public static function update( array $settings ) {
		update_option( self::OPTION, wp_parse_args( $settings, self::defaults() ), false );
	}

	/**
	 * Allowed schedules and their labels.
	 *
	 * @return array
	 */
	public static function schedules() {
		return array(
			'hourly'     => __( 'Hourly', 'job-opportunities-api-for-wp-job-manager' ),
			'twicedaily' => __( 'Twice daily', 'job-opportunities-api-for-wp-job-manager' ),
			'daily'      => __( 'Daily', 'job-opportunities-api-for-wp-job-manager' ),
		);
	}

	/**
	 * (Re)schedules the recurring import event.
	 *
	 * @param string $schedule One of the keys of schedules().
	 * @return void
	 */
	public static function reschedule( $schedule ) {
		if ( ! array_key_exists( $schedule, self::schedules() ) ) {
			$schedule = 'twicedaily';
		}
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_schedule_event( time() + MINUTE_IN_SECONDS, $schedule, self::CRON_HOOK );
	}

	/**
	 * The stored API key ('' when none).
	 *
	 * @return string
	 */
	public static function get_api_key() {
		$key = get_option( self::KEY_OPTION, '' );
		return is_string( $key ) ? $key : '';
	}

	/**
	 * Stores the API key (autoload off) and resets everything derived from the old key.
	 *
	 * @param string $key New key, '' to remove.
	 * @return void
	 */
	public static function set_api_key( $key ) {
		update_option( self::KEY_OPTION, (string) $key, false );
		delete_option( self::KEY_INVALID_OPTION );
		delete_transient( self::ME_TRANSIENT );
		delete_transient( self::NO_FEED_TRANSIENT );
		delete_transient( self::RETRY_TRANSIENT );
	}

	/**
	 * The last four characters of the key, for display. Never the full key.
	 *
	 * @return string
	 */
	public static function key_last4() {
		$key = self::get_api_key();
		if ( '' === $key ) {
			return '';
		}
		return substr( $key, -4 );
	}

	/**
	 * Default values of a saved search.
	 *
	 * @return array
	 */
	public static function search_defaults() {
		return array(
			'id'                   => '',
			'label'                => '',
			'enabled'              => 1,
			'q'                    => '',
			'title'                => '',
			'description_contains' => '',
			'country'              => '',
			'city'                 => '',
			'remote'               => 'any',
			'employment_type'      => '',
			'seniority'            => '',
			'category'             => '',
			'company'              => '',
			'company_domain'       => '',
			'has_salary'           => 0,
			'max_per_run'          => 50,
			'job_type'             => '',
			'job_category'         => 0,
			'author'               => 0,
		);
	}

	/**
	 * Fields of a search that change which jobs the API returns. Editing any of
	 * them restarts the incremental window of that search.
	 *
	 * @return string[]
	 */
	public static function search_filter_fields() {
		return array( 'q', 'title', 'description_contains', 'country', 'city', 'remote', 'employment_type', 'seniority', 'category', 'company', 'company_domain', 'has_salary' );
	}

	/**
	 * All saved searches, keyed by id.
	 *
	 * @return array
	 */
	public static function get_searches() {
		$searches = get_option( self::SEARCHES_OPTION, array() );
		if ( ! is_array( $searches ) ) {
			return array();
		}
		foreach ( $searches as $id => $search ) {
			$searches[ $id ] = wp_parse_args( is_array( $search ) ? $search : array(), self::search_defaults() );
		}
		return $searches;
	}

	/**
	 * One saved search, or null.
	 *
	 * @param string $id Search id.
	 * @return array|null
	 */
	public static function get_search( $id ) {
		$searches = self::get_searches();
		return isset( $searches[ $id ] ) ? $searches[ $id ] : null;
	}

	/**
	 * Saves all searches (autoload off).
	 *
	 * @param array $searches Searches keyed by id.
	 * @return void
	 */
	public static function save_searches( array $searches ) {
		update_option( self::SEARCHES_OPTION, $searches, false );
	}

	/**
	 * Run state: per-search last success time and the closure feed cursor.
	 *
	 * @return array
	 */
	public static function get_state() {
		$state = get_option( self::STATE_OPTION, array() );
		$state = is_array( $state ) ? $state : array();
		return wp_parse_args(
			$state,
			array(
				'searches'      => array(),
				'expired_since' => '',
			)
		);
	}

	/**
	 * Saves the run state.
	 *
	 * @param array $state State.
	 * @return void
	 */
	public static function update_state( array $state ) {
		update_option( self::STATE_OPTION, $state, false );
	}

	/**
	 * Activity log, newest first.
	 *
	 * @return array
	 */
	public static function get_log() {
		$log = get_option( self::LOG_OPTION, array() );
		return is_array( $log ) ? $log : array();
	}

	/**
	 * Prepends one entry to the activity log and keeps the newest LOG_SIZE.
	 *
	 * @param array $entry Log entry.
	 * @return void
	 */
	public static function add_log_entry( array $entry ) {
		$log = self::get_log();
		array_unshift( $log, $entry );
		update_option( self::LOG_OPTION, array_slice( $log, 0, self::LOG_SIZE ), false );
	}
}
