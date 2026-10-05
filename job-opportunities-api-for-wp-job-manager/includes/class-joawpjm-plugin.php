<?php
/**
 * Plugin bootstrap: activation, cron wiring and component loading.
 *
 * @package JOAWPJM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin components together.
 */
class JOAWPJM_Plugin {

	/**
	 * Hooks everything up. Runs on plugins_loaded.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( JOAWPJM_Settings::CRON_HOOK, array( __CLASS__, 'run_cron' ) );
		add_action( 'init', array( __CLASS__, 'ensure_schedule' ) );

		JOAWPJM_Frontend::init();

		if ( is_admin() ) {
			JOAWPJM_Admin::init();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once JOAWPJM_DIR . 'includes/class-joawpjm-cli.php';
			WP_CLI::add_command( 'joawpjm', 'JOAWPJM_CLI' );
		}
	}

	/**
	 * Whether WP Job Manager is loaded and its listing post type exists.
	 *
	 * @return bool
	 */
	public static function wpjm_active() {
		return class_exists( 'WP_Job_Manager' ) || post_type_exists( 'job_listing' );
	}

	/**
	 * Activation: create non-autoloaded options and schedule the import.
	 *
	 * @return void
	 */
	public static function activate() {
		add_option( JOAWPJM_Settings::OPTION, JOAWPJM_Settings::defaults(), '', false );
		add_option( JOAWPJM_Settings::KEY_OPTION, '', '', false );
		add_option( JOAWPJM_Settings::SEARCHES_OPTION, array(), '', false );
		add_option( JOAWPJM_Settings::STATE_OPTION, array(), '', false );
		add_option( JOAWPJM_Settings::LOG_OPTION, array(), '', false );
		JOAWPJM_Settings::reschedule( JOAWPJM_Settings::get( 'schedule' ) );
	}

	/**
	 * Deactivation: clear the scheduled import and the run lock.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( JOAWPJM_Settings::CRON_HOOK );
		JOAWPJM_Importer::release_lock();
	}

	/**
	 * Re-creates the recurring event if it went missing (for example after a
	 * cron table reset). Cheap: wp_next_scheduled() reads an autoloaded option.
	 *
	 * @return void
	 */
	public static function ensure_schedule() {
		if ( ! wp_next_scheduled( JOAWPJM_Settings::CRON_HOOK ) ) {
			JOAWPJM_Settings::reschedule( JOAWPJM_Settings::get( 'schedule' ) );
		}
	}

	/**
	 * WP-Cron callback.
	 *
	 * @return void
	 */
	public static function run_cron() {
		if ( ! self::wpjm_active() || '' === JOAWPJM_Settings::get_api_key() ) {
			return;
		}
		$importer = new JOAWPJM_Importer();
		$importer->run(
			array(
				'trigger' => 'cron',
				/** This filter is documented in includes/class-joawpjm-admin.php */
				'budget'  => (int) apply_filters( 'joawpjm_time_budget', 50, 'cron' ),
			)
		);
	}
}
