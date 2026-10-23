<?php
/**
 * Admin screens: settings, saved searches, activity log, notices and the
 * listing meta box.
 *
 * @package JOAWPJM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin UI.
 */
class JOAWPJM_Admin {

	const PAGE = 'joawpjm';

	/**
	 * Hook suffix of the settings page.
	 *
	 * @var string
	 */
	private static $hook = '';

	/**
	 * Registers admin hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_init', array( 'JOAWPJM_Settings', 'maybe_upgrade' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'add_meta_boxes_job_listing', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post_job_listing', array( __CLASS__, 'save_meta_box' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( JOAWPJM_FILE ), array( __CLASS__, 'action_links' ) );

		foreach ( array( 'save_settings', 'test_connection', 'run_now', 'save_search', 'delete_search', 'clear_log', 'credit_on', 'credit_off' ) as $action ) {
			add_action( 'admin_post_joawpjm_' . $action, array( __CLASS__, 'handle_' . $action ) );
		}
	}

	/**
	 * Adds the settings page: under "Job Listings" when WP Job Manager is
	 * active, under "Settings" otherwise (so the key can still be managed).
	 *
	 * @return void
	 */
	public static function menu() {
		$title = __( 'Job Opportunities API', 'job-opportunities-api-for-wp-job-manager' );
		if ( JOAWPJM_Plugin::wpjm_active() ) {
			self::$hook = (string) add_submenu_page( 'edit.php?post_type=job_listing', $title, __( 'JOA Import', 'job-opportunities-api-for-wp-job-manager' ), 'manage_options', self::PAGE, array( __CLASS__, 'render_page' ) );
		} else {
			self::$hook = (string) add_options_page( $title, __( 'JOA Import', 'job-opportunities-api-for-wp-job-manager' ), 'manage_options', self::PAGE, array( __CLASS__, 'render_page' ) );
		}
	}

	/**
	 * URL of the settings page.
	 *
	 * @param array $args Extra query args.
	 * @return string
	 */
	public static function page_url( array $args = array() ) {
		$base = JOAWPJM_Plugin::wpjm_active() ? 'edit.php?post_type=job_listing&page=' . self::PAGE : 'options-general.php?page=' . self::PAGE;
		return add_query_arg( $args, admin_url( $base ) );
	}

	/**
	 * Settings link on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Settings', 'job-opportunities-api-for-wp-job-manager' ) . '</a>' );
		return $links;
	}

	/**
	 * Loads the admin stylesheet on the plugin page only.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public static function enqueue( $hook ) {
		if ( self::$hook && $hook === self::$hook ) {
			wp_enqueue_style( 'joawpjm-admin', JOAWPJM_URL . 'assets/css/admin.css', array(), JOAWPJM_VERSION );
		}
	}

	/**
	 * Whether the current admin screen is the plugin's own page or one of the
	 * WP Job Manager job_listing screens.
	 *
	 * @return bool
	 */
	private static function is_plugin_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return false;
		}
		return ( self::$hook && self::$hook === $screen->id ) || 'job_listing' === $screen->post_type;
	}

	/**
	 * Site-wide admin notices: WP Job Manager missing, key rejected. On the
	 * plugin's own screens only: the credit decision while it is undecided.
	 *
	 * @return void
	 */
	public static function notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( self::is_plugin_screen() ) {
			$screen = get_current_screen();
			if ( self::$hook !== $screen->id ) {
				// The plugin page prints these itself; elsewhere (after a credit decision) print them here.
				self::render_flashes();
			}
			if ( JOAWPJM_Settings::CREDIT_UNDECIDED === JOAWPJM_Settings::credit() ) {
				self::render_credit_notice();
			}
		}
		if ( ! JOAWPJM_Plugin::wpjm_active() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Job Opportunities API for WP Job Manager needs the WP Job Manager plugin. Install and activate WP Job Manager to import jobs.', 'job-opportunities-api-for-wp-job-manager' ) . '</p></div>';
		}
		if ( get_option( JOAWPJM_Settings::KEY_INVALID_OPTION ) ) {
			printf(
				'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
				esc_html__( 'Job Opportunities API: invalid key.', 'job-opportunities-api-for-wp-job-manager' ),
				esc_html__( 'The API rejected the saved key, so imports are paused.', 'job-opportunities-api-for-wp-job-manager' ),
				esc_url( self::page_url() ),
				esc_html__( 'Update the key', 'job-opportunities-api-for-wp-job-manager' )
			);
		}
	}

	/**
	 * Notice asking the site owner to decide about the credit.
	 *
	 * @return void
	 */
	private static function render_credit_notice() {
		echo '<div class="notice notice-info joawpjm-credit-notice"><p><strong>' . esc_html__( 'Job Opportunities API: show the data credit?', 'job-opportunities-api-for-wp-job-manager' ) . '</strong> ';
		echo esc_html__( 'The JOA terms ask for a visible “Data: Job Opportunities API” credit, linking to jobopportunitiesapi.org, when you display listings publicly. The plugin can add it under imported listings, but only after you switch it on.', 'job-opportunities-api-for-wp-job-manager' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><p>';
		wp_nonce_field( 'joawpjm_credit_decision' );
		echo '<button type="submit" class="button button-primary" name="action" value="joawpjm_credit_on">' . esc_html__( 'Show the credit', 'job-opportunities-api-for-wp-job-manager' ) . '</button> ';
		echo '<button type="submit" class="button" name="action" value="joawpjm_credit_off">' . esc_html__( 'Not now', 'job-opportunities-api-for-wp-job-manager' ) . '</button> ';
		echo '<a href="' . esc_url( self::page_url() . '#joawpjm-credit' ) . '">' . esc_html__( 'Credit setting', 'job-opportunities-api-for-wp-job-manager' ) . '</a>';
		echo '</p></form></div>';
	}

	// Flash messages (per user, shown once after a redirect).

	/**
	 * Queues a message for the next page load.
	 *
	 * @param string $type    success|error|warning|info.
	 * @param string $message Plain text.
	 * @param array  $details Optional plain-text lines.
	 * @return void
	 */
	private static function flash( $type, $message, array $details = array() ) {
		$key      = 'joawpjm_flash_' . get_current_user_id();
		$messages = get_transient( $key );
		$messages = is_array( $messages ) ? $messages : array();

		$messages[] = array(
			'type'    => $type,
			'message' => $message,
			'details' => $details,
		);
		set_transient( $key, $messages, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Prints and clears queued messages.
	 *
	 * @return void
	 */
	private static function render_flashes() {
		$key      = 'joawpjm_flash_' . get_current_user_id();
		$messages = get_transient( $key );
		if ( ! is_array( $messages ) ) {
			return;
		}
		delete_transient( $key );
		foreach ( $messages as $m ) {
			$type = in_array( $m['type'], array( 'success', 'error', 'warning', 'info' ), true ) ? $m['type'] : 'info';
			echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $m['message'] ) . '</p>';
			if ( ! empty( $m['details'] ) ) {
				echo '<ul class="joawpjm-details">';
				foreach ( (array) $m['details'] as $line ) {
					echo '<li>' . esc_html( $line ) . '</li>';
				}
				echo '</ul>';
			}
			echo '</div>';
		}
	}

	// Page rendering.

	/**
	 * Renders the settings page.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switch.
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';
		$tabs = array(
			'settings' => __( 'Settings', 'job-opportunities-api-for-wp-job-manager' ),
			'searches' => __( 'Saved searches', 'job-opportunities-api-for-wp-job-manager' ),
			'log'      => __( 'Activity log', 'job-opportunities-api-for-wp-job-manager' ),
		);
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'settings';
		}
		echo '<div class="wrap joawpjm-wrap">';
		echo '<h1>' . esc_html__( 'Job Opportunities API for WP Job Manager', 'job-opportunities-api-for-wp-job-manager' ) . '</h1>';
		self::render_flashes();
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $slug => $label ) {
			printf(
				'<a href="%1$s" class="nav-tab%2$s">%3$s</a>',
				esc_url( self::page_url( array( 'tab' => $slug ) ) ),
				$slug === $tab ? ' nav-tab-active' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';

		if ( 'searches' === $tab ) {
			self::render_searches_tab();
		} elseif ( 'log' === $tab ) {
			self::render_log_tab();
		} else {
			self::render_settings_tab();
		}
		echo '</div>';
	}

	/**
	 * Opens an admin-post form.
	 *
	 * @param string $action Action suffix.
	 * @return void
	 */
	private static function form_open( $action ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( 'joawpjm_' . $action ) . '">';
		wp_nonce_field( 'joawpjm_' . $action );
	}

	/**
	 * Settings tab.
	 *
	 * @return void
	 */
	private static function render_settings_tab() {
		$s     = JOAWPJM_Settings::get();
		$last4 = JOAWPJM_Settings::key_last4();

		self::render_status_box();

		self::form_open( 'save_settings' );

		echo '<div class="joawpjm-credit-box" id="joawpjm-credit">';
		echo '<p><label><input type="checkbox" name="joawpjm[credit]" value="1"' . checked( JOAWPJM_Settings::CREDIT_ON, $s['credit'], false ) . '> <strong>' . esc_html__( 'Show "Data: Job Opportunities API" credit (required by the JOA terms for public display)', 'job-opportunities-api-for-wp-job-manager' ) . '</strong></label></p>';
		echo '<p class="description">' . esc_html__( 'When ticked, a small “Data: Job Opportunities API” line linking to jobopportunitiesapi.org is shown under the description on each imported listing’s page; it stays off until you tick it, and the JOA terms ask for it when you display listings publicly.', 'job-opportunities-api-for-wp-job-manager' ) . '</p>';
		echo '</div>';

		echo '<h2>' . esc_html__( 'Connection', 'job-opportunities-api-for-wp-job-manager' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="joawpjm-api-key">' . esc_html__( 'API key', 'job-opportunities-api-for-wp-job-manager' ) . '</label></th><td>';
		echo '<input type="password" id="joawpjm-api-key" name="joawpjm_api_key" value="" class="regular-text" autocomplete="new-password" spellcheck="false">';
		if ( '' !== $last4 ) {
			/* translators: %s: last four characters of the saved API key. */
			echo '<p class="description">' . esc_html( sprintf( __( 'A key ending in %s is saved. Leave the field empty to keep it.', 'job-opportunities-api-for-wp-job-manager' ), '…' . $last4 ) ) . '</p>';
			echo '<p><label><input type="checkbox" name="joawpjm_remove_key" value="1"> ' . esc_html__( 'Remove the saved key', 'job-opportunities-api-for-wp-job-manager' ) . '</label></p>';
		} else {
			echo '<p class="description">';
			printf(
				/* translators: %s: link to the Job Opportunities API sign-up page. */
				esc_html__( 'Get a free key (no card required) at %s.', 'job-opportunities-api-for-wp-job-manager' ),
				'<a href="https://jobopportunitiesapi.org/register" target="_blank" rel="noopener">jobopportunitiesapi.org</a>'
			);
			echo '</p>';
		}
		echo '</td></tr>';
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Import', 'job-opportunities-api-for-wp-job-manager' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="joawpjm-schedule">' . esc_html__( 'Schedule', 'job-opportunities-api-for-wp-job-manager' ) . '</label></th><td><select id="joawpjm-schedule" name="joawpjm[schedule]">';
		foreach ( JOAWPJM_Settings::schedules() as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $s['schedule'], $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select><p class="description">' . esc_html__( 'Runs with WP-Cron, so it needs site traffic or a real cron job calling wp-cron.php.', 'job-opportunities-api-for-wp-job-manager' ) . '</p></td></tr>';

		$allowance = self::record_allowance_line();
		if ( '' !== $allowance ) {
			echo '<tr><th scope="row">' . esc_html__( 'Record allowance', 'job-opportunities-api-for-wp-job-manager' ) . '</th><td><p class="description">' . esc_html( $allowance ) . '</p></td></tr>';
		}

		echo '<tr><th scope="row"><label for="joawpjm-post-status">' . esc_html__( 'Status of new listings', 'job-opportunities-api-for-wp-job-manager' ) . '</label></th><td><select id="joawpjm-post-status" name="joawpjm[post_status]">';
		echo '<option value="publish"' . selected( $s['post_status'], 'publish', false ) . '>' . esc_html__( 'Published', 'job-opportunities-api-for-wp-job-manager' ) . '</option>';
		echo '<option value="pending"' . selected( $s['post_status'], 'pending', false ) . '>' . esc_html__( 'Pending review', 'job-opportunities-api-for-wp-job-manager' ) . '</option>';
		echo '</select></td></tr>';

		echo '<tr><th scope="row"><label for="joawpjm-duration">' . esc_html__( 'Listing duration (days)', 'job-opportunities-api-for-wp-job-manager' ) . '</label></th><td>';
		echo '<input type="number" min="1" max="365" id="joawpjm-duration" name="joawpjm[duration]" value="' . esc_attr( (string) $s['duration'] ) . '" class="small-text">';
		echo '<p class="description">' . esc_html__( 'Used when the API gives no expiry date for a role.', 'job-opportunities-api-for-wp-job-manager' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Descriptions', 'job-opportunities-api-for-wp-job-manager' ) . '</th><td><label><input type="checkbox" name="joawpjm[keep_descriptions]" value="1"' . checked( $s['keep_descriptions'], 1, false ) . '> ' . esc_html__( 'Import the full advert text', 'job-opportunities-api-for-wp-job-manager' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'When off, listings show a short line linking to the employer’s advert.', 'job-opportunities-api-for-wp-job-manager' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="joawpjm-closure-checks">' . esc_html__( 'Closure checks per run', 'job-opportunities-api-for-wp-job-manager' ) . '</label></th><td>';
		echo '<input type="number" min="0" max="500" id="joawpjm-closure-checks" name="joawpjm[closure_checks]" value="' . esc_attr( (string) $s['closure_checks'] ) . '" class="small-text">';
		echo '<p class="description">' . esc_html__( 'On plans without the closure feed, this many imported listings are re-checked each run (oldest check first) and expired when the employer closed the role.', 'job-opportunities-api-for-wp-job-manager' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Uninstall', 'job-opportunities-api-for-wp-job-manager' ) . '</th><td><label><input type="checkbox" name="joawpjm[delete_on_uninstall]" value="1"' . checked( $s['delete_on_uninstall'], 1, false ) . '> ' . esc_html__( 'Delete imported listings when the plugin is uninstalled', 'job-opportunities-api-for-wp-job-manager' ) . '</label></td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Save settings', 'job-opportunities-api-for-wp-job-manager' ) );
		echo '</form>';

		echo '<div class="joawpjm-actions">';
		self::form_open( 'test_connection' );
		submit_button( __( 'Test connection', 'job-opportunities-api-for-wp-job-manager' ), 'secondary', 'submit', false, '' === $last4 ? array( 'disabled' => 'disabled' ) : null );
		echo '</form>';
		self::form_open( 'run_now' );
		submit_button( __( 'Run import now', 'job-opportunities-api-for-wp-job-manager' ), 'secondary', 'submit', false, ( '' === $last4 || ! JOAWPJM_Plugin::wpjm_active() ) ? array( 'disabled' => 'disabled' ) : null );
		echo '</form>';
		echo '</div>';

		echo '<h2>' . esc_html__( 'Recent activity', 'job-opportunities-api-for-wp-job-manager' ) . '</h2>';
		self::render_log_table( array_slice( JOAWPJM_Settings::get_log(), 0, 5 ) );
		echo '<p><a href="' . esc_url( self::page_url( array( 'tab' => 'log' ) ) ) . '">' . esc_html__( 'Full activity log', 'job-opportunities-api-for-wp-job-manager' ) . '</a></p>';
	}

	/**
	 * Small status overview.
	 *
	 * @return void
	 */
	private static function render_status_box() {
		$rows = array();

		$rows[ __( 'API key', 'job-opportunities-api-for-wp-job-manager' ) ] = ( '' === JOAWPJM_Settings::key_last4() )
			? __( 'Not set', 'job-opportunities-api-for-wp-job-manager' )
			: ( get_option( JOAWPJM_Settings::KEY_INVALID_OPTION ) ? __( 'Rejected by the API', 'job-opportunities-api-for-wp-job-manager' ) : __( 'Saved', 'job-opportunities-api-for-wp-job-manager' ) );

		$next = wp_next_scheduled( JOAWPJM_Settings::CRON_HOOK );
		$rows[ __( 'Next scheduled import', 'job-opportunities-api-for-wp-job-manager' ) ] = $next ? self::format_time( $next ) : __( 'Not scheduled', 'job-opportunities-api-for-wp-job-manager' );

		$searches = JOAWPJM_Settings::get_searches();
		$enabled  = count(
			array_filter(
				$searches,
				function ( $s ) {
					return ! empty( $s['enabled'] );
				}
			)
		);
		/* translators: 1: enabled searches, 2: all searches. */
		$rows[ __( 'Saved searches', 'job-opportunities-api-for-wp-job-manager' ) ] = sprintf( __( '%1$d enabled of %2$d', 'job-opportunities-api-for-wp-job-manager' ), $enabled, count( $searches ) );

		$rows[ __( 'Closure tracking', 'job-opportunities-api-for-wp-job-manager' ) ] = get_transient( JOAWPJM_Settings::NO_FEED_TRANSIENT )
			? __( 'Per-listing checks (your plan has no closure feed)', 'job-opportunities-api-for-wp-job-manager' )
			: __( 'Closure feed when your plan includes it, otherwise per-listing checks', 'job-opportunities-api-for-wp-job-manager' );

		$retry = (int) get_transient( JOAWPJM_Settings::RETRY_TRANSIENT );
		if ( $retry > time() ) {
			$rows[ __( 'Rate limit', 'job-opportunities-api-for-wp-job-manager' ) ] = sprintf(
				/* translators: %s: date and time. */
				__( 'Paused until %s', 'job-opportunities-api-for-wp-job-manager' ),
				self::format_time( $retry )
			);
		}
		if ( JOAWPJM_Importer::is_locked() ) {
			$rows[ __( 'Import', 'job-opportunities-api-for-wp-job-manager' ) ] = __( 'Running now', 'job-opportunities-api-for-wp-job-manager' );
		}

		echo '<div class="joawpjm-status"><table class="widefat striped"><tbody>';
		foreach ( $rows as $label => $value ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Saved searches tab: list or editor.
	 *
	 * @return void
	 */
	private static function render_searches_tab() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view switch.
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		$id     = isset( $_GET['search'] ) ? sanitize_key( wp_unslash( $_GET['search'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( 'new' === $action ) {
			self::render_search_editor( JOAWPJM_Settings::search_defaults() );
			return;
		}
		if ( 'edit' === $action && $id ) {
			$search = JOAWPJM_Settings::get_search( $id );
			if ( $search ) {
				self::render_search_editor( $search );
				return;
			}
		}

		$searches = JOAWPJM_Settings::get_searches();
		$state    = JOAWPJM_Settings::get_state();

		echo '<p><a class="button button-primary" href="' . esc_url(
			self::page_url(
				array(
					'tab'    => 'searches',
					'action' => 'new',
				)
			)
		) . '">' . esc_html__( 'Add search', 'job-opportunities-api-for-wp-job-manager' ) . '</a></p>';

		if ( empty( $searches ) ) {
			echo '<p>' . esc_html__( 'No saved searches yet. Add one to start importing jobs.', 'job-opportunities-api-for-wp-job-manager' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped joawpjm-searches"><thead><tr>';
		foreach ( array( __( 'Search', 'job-opportunities-api-for-wp-job-manager' ), __( 'Filters', 'job-opportunities-api-for-wp-job-manager' ), __( 'Status', 'job-opportunities-api-for-wp-job-manager' ), __( 'Max jobs per run', 'job-opportunities-api-for-wp-job-manager' ), __( 'Last successful run', 'job-opportunities-api-for-wp-job-manager' ), __( 'ID', 'job-opportunities-api-for-wp-job-manager' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $searches as $search ) {
			$edit   = self::page_url(
				array(
					'tab'    => 'searches',
					'action' => 'edit',
					'search' => $search['id'],
				)
			);
			$delete = wp_nonce_url(
				add_query_arg(
					array(
						'action' => 'joawpjm_delete_search',
						'search' => $search['id'],
					),
					admin_url( 'admin-post.php' )
				),
				'joawpjm_delete_search'
			);
			$last   = isset( $state['searches'][ $search['id'] ]['last_success'] ) ? (int) $state['searches'][ $search['id'] ]['last_success'] : 0;

			echo '<tr><td><strong><a href="' . esc_url( $edit ) . '">' . esc_html( $search['label'] ) . '</a></strong>';
			echo '<div class="row-actions"><span class="edit"><a href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit', 'job-opportunities-api-for-wp-job-manager' ) . '</a> | </span>';
			echo '<span class="delete"><a class="submitdelete" href="' . esc_url( $delete ) . '" onclick="return confirm(\'' . esc_js( __( 'Delete this saved search? Imported listings are kept.', 'job-opportunities-api-for-wp-job-manager' ) ) . '\');">' . esc_html__( 'Delete', 'job-opportunities-api-for-wp-job-manager' ) . '</a></span></div></td>';
			echo '<td>' . esc_html( self::describe_filters( $search ) ) . '</td>';
			echo '<td>' . ( $search['enabled'] ? esc_html__( 'Enabled', 'job-opportunities-api-for-wp-job-manager' ) : esc_html__( 'Disabled', 'job-opportunities-api-for-wp-job-manager' ) ) . '</td>';
			echo '<td>' . esc_html( (string) (int) $search['max_per_run'] ) . '</td>';
			echo '<td>' . esc_html( $last ? self::format_time( $last ) : __( 'Never', 'job-opportunities-api-for-wp-job-manager' ) ) . '</td>';
			echo '<td><code>' . esc_html( $search['id'] ) . '</code></td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * One-line summary of a search's filters.
	 *
	 * @param array $search Saved search.
	 * @return string
	 */
	private static function describe_filters( array $search ) {
		$labels = array(
			'q'                    => __( 'Keywords', 'job-opportunities-api-for-wp-job-manager' ),
			'title'                => __( 'Title', 'job-opportunities-api-for-wp-job-manager' ),
			'description_contains' => __( 'Description', 'job-opportunities-api-for-wp-job-manager' ),
			'country'              => __( 'Countries', 'job-opportunities-api-for-wp-job-manager' ),
			'city'                 => __( 'City', 'job-opportunities-api-for-wp-job-manager' ),
			'remote'               => __( 'Workplace', 'job-opportunities-api-for-wp-job-manager' ),
			'employment_type'      => __( 'Employment type', 'job-opportunities-api-for-wp-job-manager' ),
			'seniority'            => __( 'Seniority', 'job-opportunities-api-for-wp-job-manager' ),
			'category'             => __( 'Category', 'job-opportunities-api-for-wp-job-manager' ),
			'company'              => __( 'Companies', 'job-opportunities-api-for-wp-job-manager' ),
			'company_domain'       => __( 'Company domains', 'job-opportunities-api-for-wp-job-manager' ),
		);
		$parts  = array();
		foreach ( $labels as $field => $label ) {
			$value = isset( $search[ $field ] ) ? (string) $search[ $field ] : '';
			if ( '' !== $value && ! ( 'remote' === $field && 'any' === $value ) ) {
				$parts[] = $label . ': ' . $value;
			}
		}
		if ( ! empty( $search['has_salary'] ) ) {
			$parts[] = __( 'With salary', 'job-opportunities-api-for-wp-job-manager' );
		}
		return empty( $parts ) ? __( 'All jobs', 'job-opportunities-api-for-wp-job-manager' ) : implode( '; ', $parts );
	}

	/**
	 * Saved search editor.
	 *
	 * @param array $search Search values.
	 * @return void
	 */
	private static function render_search_editor( array $search ) {
		$is_new = ( '' === $search['id'] );
		echo '<h2>' . ( $is_new ? esc_html__( 'Add search', 'job-opportunities-api-for-wp-job-manager' ) : esc_html__( 'Edit search', 'job-opportunities-api-for-wp-job-manager' ) ) . '</h2>';

		self::form_open( 'save_search' );
		echo '<input type="hidden" name="search_id" value="' . esc_attr( $search['id'] ) . '">';
		echo '<table class="form-table" role="presentation"><tbody>';

		self::text_row( 'label', __( 'Label', 'job-opportunities-api-for-wp-job-manager' ), $search['label'], __( 'For your own reference, for example “Remote engineering, Germany”.', 'job-opportunities-api-for-wp-job-manager' ), true );

		echo '<tr><th scope="row">' . esc_html__( 'Enabled', 'job-opportunities-api-for-wp-job-manager' ) . '</th><td><label><input type="checkbox" name="search[enabled]" value="1"' . checked( $search['enabled'], 1, false ) . '> ' . esc_html__( 'Include in scheduled and manual imports', 'job-opportunities-api-for-wp-job-manager' ) . '</label></td></tr>';

		echo '<tr class="joawpjm-section"><th colspan="2"><h3>' . esc_html__( 'Which jobs', 'job-opportunities-api-for-wp-job-manager' ) . '</h3></th></tr>';
		self::text_row( 'q', __( 'Keywords', 'job-opportunities-api-for-wp-job-manager' ), $search['q'], __( 'Matches job title, company name and location.', 'job-opportunities-api-for-wp-job-manager' ) );
		self::text_row( 'title', __( 'Title words', 'job-opportunities-api-for-wp-job-manager' ), $search['title'], __( 'Words that must appear in the job title. Comma-separate alternatives.', 'job-opportunities-api-for-wp-job-manager' ) );
		self::text_row( 'description_contains', __( 'Description contains', 'job-opportunities-api-for-wp-job-manager' ), $search['description_contains'], __( 'Words that must appear in the advert text.', 'job-opportunities-api-for-wp-job-manager' ) );
		self::text_row( 'country', __( 'Countries', 'job-opportunities-api-for-wp-job-manager' ), $search['country'], __( 'Two-letter ISO codes, comma-separated, for example DE, AT, CH.', 'job-opportunities-api-for-wp-job-manager' ) );
		self::text_row( 'city', __( 'City', 'job-opportunities-api-for-wp-job-manager' ), $search['city'], '' );

		echo '<tr><th scope="row"><label for="joawpjm-remote">' . esc_html__( 'Workplace', 'job-opportunities-api-for-wp-job-manager' ) . '</label></th><td><select id="joawpjm-remote" name="search[remote]">';
		foreach ( self::remote_options() as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $search['remote'], $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></td></tr>';

		self::text_row( 'employment_type', __( 'Employment type', 'job-opportunities-api-for-wp-job-manager' ), $search['employment_type'], __( 'For example Full-time, Part-time, Contract, Internship.', 'job-opportunities-api-for-wp-job-manager' ) );
		self::text_row( 'seniority', __( 'Seniority', 'job-opportunities-api-for-wp-job-manager' ), $search['seniority'], __( 'For example Junior, Mid, Senior, Lead.', 'job-opportunities-api-for-wp-job-manager' ) );
		self::text_row( 'category', __( 'Category', 'job-opportunities-api-for-wp-job-manager' ), $search['category'], __( 'A JOA job category, for example Engineering.', 'job-opportunities-api-for-wp-job-manager' ) );
		self::text_row( 'company', __( 'Company slugs', 'job-opportunities-api-for-wp-job-manager' ), $search['company'], __( 'Comma-separated company slugs as used by the API.', 'job-opportunities-api-for-wp-job-manager' ) );
		self::text_row( 'company_domain', __( 'Company domains', 'job-opportunities-api-for-wp-job-manager' ), $search['company_domain'], __( 'Comma-separated bare domains, for example example.com.', 'job-opportunities-api-for-wp-job-manager' ) );

		echo '<tr><th scope="row">' . esc_html__( 'Salary', 'job-opportunities-api-for-wp-job-manager' ) . '</th><td><label><input type="checkbox" name="search[has_salary]" value="1"' . checked( $search['has_salary'], 1, false ) . '> ' . esc_html__( 'Only jobs that state a salary', 'job-opportunities-api-for-wp-job-manager' ) . '</label></td></tr>';

		echo '<tr><th scope="row"><label for="joawpjm-max">' . esc_html__( 'Max jobs per run', 'job-opportunities-api-for-wp-job-manager' ) . '</label></th><td><input type="number" min="1" max="500" id="joawpjm-max" name="search[max_per_run]" value="' . esc_attr( (string) (int) $search['max_per_run'] ) . '" class="small-text">';
		echo '<p class="description">' . esc_html__( 'Each returned job counts towards your plan’s monthly record allowance. After the first run only recently posted jobs are requested.', 'job-opportunities-api-for-wp-job-manager' ) . '</p></td></tr>';

		echo '<tr class="joawpjm-section"><th colspan="2"><h3>' . esc_html__( 'How listings are created', 'job-opportunities-api-for-wp-job-manager' ) . '</h3></th></tr>';

		echo '<tr><th scope="row"><label for="joawpjm-job-type">' . esc_html__( 'Job type', 'job-opportunities-api-for-wp-job-manager' ) . '</label></th><td><select id="joawpjm-job-type" name="search[job_type]">';
		echo '<option value=""' . selected( $search['job_type'], '', false ) . '>' . esc_html__( 'None', 'job-opportunities-api-for-wp-job-manager' ) . '</option>';
		echo '<option value="auto"' . selected( $search['job_type'], 'auto', false ) . '>' . esc_html__( 'Map from the employment type automatically', 'job-opportunities-api-for-wp-job-manager' ) . '</option>';
		$types = taxonomy_exists( 'job_listing_type' ) ? get_terms(
			array(
				'taxonomy'   => 'job_listing_type',
				'hide_empty' => false,
			)
		) : array();
		if ( ! is_wp_error( $types ) ) {
			foreach ( $types as $term ) {
				echo '<option value="' . esc_attr( (string) $term->term_id ) . '"' . selected( (string) $search['job_type'], (string) $term->term_id, false ) . '>' . esc_html( $term->name ) . '</option>';
			}
		}
		echo '</select><p class="description">' . esc_html__( 'Automatic mapping matches Full-time, Part-time, Contract, Temporary, Internship and Freelance to your existing job types by name.', 'job-opportunities-api-for-wp-job-manager' ) . '</p></td></tr>';

		if ( taxonomy_exists( 'job_listing_category' ) && get_option( 'job_manager_enable_categories' ) ) {
			echo '<tr><th scope="row"><label for="joawpjm-job-category">' . esc_html__( 'Job category', 'job-opportunities-api-for-wp-job-manager' ) . '</label></th><td><select id="joawpjm-job-category" name="search[job_category]">';
			echo '<option value="0">' . esc_html__( 'None', 'job-opportunities-api-for-wp-job-manager' ) . '</option>';
			$cats = get_terms(
				array(
					'taxonomy'   => 'job_listing_category',
					'hide_empty' => false,
				)
			);
			if ( ! is_wp_error( $cats ) ) {
				foreach ( $cats as $term ) {
					echo '<option value="' . esc_attr( (string) $term->term_id ) . '"' . selected( (int) $search['job_category'], (int) $term->term_id, false ) . '>' . esc_html( $term->name ) . '</option>';
				}
			}
			echo '</select></td></tr>';
		}

		echo '<tr><th scope="row"><label for="joawpjm-author">' . esc_html__( 'Listing author', 'job-opportunities-api-for-wp-job-manager' ) . '</label></th><td>';
		wp_dropdown_users(
			array(
				'name'       => 'search[author]',
				'id'         => 'joawpjm-author',
				'selected'   => $search['author'] ? (int) $search['author'] : get_current_user_id(),
				'capability' => array( 'edit_posts' ),
			)
		);
		echo '</td></tr>';

		echo '</tbody></table>';
		submit_button( $is_new ? __( 'Add search', 'job-opportunities-api-for-wp-job-manager' ) : __( 'Save search', 'job-opportunities-api-for-wp-job-manager' ) );
		echo '<p><a href="' . esc_url( self::page_url( array( 'tab' => 'searches' ) ) ) . '">' . esc_html__( 'Back to saved searches', 'job-opportunities-api-for-wp-job-manager' ) . '</a></p>';
		echo '</form>';
	}

	/**
	 * A text input row of the search editor.
	 *
	 * @param string $field       Field name.
	 * @param string $label       Label.
	 * @param string $value       Value.
	 * @param string $description Help text.
	 * @param bool   $required    Required field.
	 * @return void
	 */
	private static function text_row( $field, $label, $value, $description, $required = false ) {
		$id = 'joawpjm-' . str_replace( '_', '-', $field );
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<input type="text" class="regular-text" id="' . esc_attr( $id ) . '" name="search[' . esc_attr( $field ) . ']" value="' . esc_attr( $value ) . '"' . ( $required ? ' required' : '' ) . '>';
		if ( '' !== $description ) {
			echo '<p class="description">' . esc_html( $description ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * Workplace options.
	 *
	 * @return array
	 */
	private static function remote_options() {
		return array(
			'any'     => __( 'Any', 'job-opportunities-api-for-wp-job-manager' ),
			'remote'  => __( 'Remote', 'job-opportunities-api-for-wp-job-manager' ),
			'hybrid'  => __( 'Hybrid', 'job-opportunities-api-for-wp-job-manager' ),
			'on_site' => __( 'On-site', 'job-opportunities-api-for-wp-job-manager' ),
		);
	}

	/**
	 * Activity log tab.
	 *
	 * @return void
	 */
	private static function render_log_tab() {
		$log = JOAWPJM_Settings::get_log();
		/* translators: %d: number of runs kept. */
		echo '<p>' . esc_html( sprintf( __( 'The last %d runs, newest first. Each saved search and the closure checks get their own row.', 'job-opportunities-api-for-wp-job-manager' ), JOAWPJM_Settings::LOG_SIZE ) ) . '</p>';
		self::render_log_table( $log );
		if ( ! empty( $log ) ) {
			self::form_open( 'clear_log' );
			submit_button( __( 'Clear log', 'job-opportunities-api-for-wp-job-manager' ), 'secondary' );
			echo '</form>';
		}
	}

	/**
	 * Log table.
	 *
	 * @param array $log Log entries.
	 * @return void
	 */
	private static function render_log_table( array $log ) {
		if ( empty( $log ) ) {
			echo '<p>' . esc_html__( 'No imports have run yet.', 'job-opportunities-api-for-wp-job-manager' ) . '</p>';
			return;
		}
		$triggers = array(
			'cron'   => __( 'Schedule', 'job-opportunities-api-for-wp-job-manager' ),
			'manual' => __( 'Manual', 'job-opportunities-api-for-wp-job-manager' ),
			'cli'    => __( 'WP-CLI', 'job-opportunities-api-for-wp-job-manager' ),
		);
		$columns  = array(
			__( 'Time', 'job-opportunities-api-for-wp-job-manager' ),
			__( 'Trigger', 'job-opportunities-api-for-wp-job-manager' ),
			__( 'Search', 'job-opportunities-api-for-wp-job-manager' ),
			__( 'Fetched', 'job-opportunities-api-for-wp-job-manager' ),
			__( 'Created', 'job-opportunities-api-for-wp-job-manager' ),
			__( 'Updated', 'job-opportunities-api-for-wp-job-manager' ),
			__( 'Unchanged', 'job-opportunities-api-for-wp-job-manager' ),
			__( 'Skipped', 'job-opportunities-api-for-wp-job-manager' ),
			__( 'Expired', 'job-opportunities-api-for-wp-job-manager' ),
			__( 'Errors and notes', 'job-opportunities-api-for-wp-job-manager' ),
		);
		echo '<table class="widefat striped joawpjm-log"><thead><tr>';
		foreach ( $columns as $column ) {
			echo '<th scope="col">' . esc_html( $column ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $log as $e ) {
			$trigger = isset( $e['trigger'] ) && isset( $triggers[ $e['trigger'] ] ) ? $triggers[ $e['trigger'] ] : '';
			echo '<tr>';
			echo '<td>' . esc_html( self::format_time( isset( $e['time'] ) ? (int) $e['time'] : 0 ) ) . '</td>';
			echo '<td>' . esc_html( $trigger ) . '</td>';
			echo '<td>' . esc_html( isset( $e['search'] ) ? (string) $e['search'] : '' ) . '</td>';
			foreach ( array( 'fetched', 'created', 'updated', 'unchanged', 'skipped', 'expired' ) as $counter ) {
				echo '<td class="num">' . esc_html( number_format_i18n( isset( $e[ $counter ] ) ? (int) $e[ $counter ] : 0 ) ) . '</td>';
			}
			echo '<td>';
			foreach ( isset( $e['errors'] ) ? (array) $e['errors'] : array() as $error ) {
				echo '<div class="joawpjm-error">' . esc_html( (string) $error ) . '</div>';
			}
			foreach ( isset( $e['notes'] ) ? (array) $e['notes'] : array() as $note ) {
				echo '<div class="joawpjm-note">' . esc_html( (string) $note ) . '</div>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Formats a timestamp in the site's timezone.
	 *
	 * @param int $timestamp Unix time.
	 * @return string
	 */
	private static function format_time( $timestamp ) {
		if ( ! $timestamp ) {
			return '';
		}
		return (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $timestamp );
	}

	/**
	 * One line about the key's monthly record allowance, from the last value
	 * the API reported. '' when no allowance is known yet. Not escaped: the
	 * caller echoes it, and only this page does.
	 *
	 * @return string
	 */
	private static function record_allowance_line() {
		$state   = JOAWPJM_Settings::get_state();
		$records = ( isset( $state['records'] ) && is_array( $state['records'] ) ) ? $state['records'] : null;
		if ( ! is_array( $records ) || ! isset( $records['limit'] ) ) {
			return '';
		}
		if ( -1 === (int) $records['limit'] ) {
			return __( 'This key: unlimited records this month.', 'job-opportunities-api-for-wp-job-manager' );
		}
		$remaining = ( isset( $records['remaining'] ) && is_numeric( $records['remaining'] ) ) ? (int) $records['remaining'] : null;
		if ( null === $remaining ) {
			return '';
		}
		$reset = ( isset( $records['reset'] ) && is_numeric( $records['reset'] ) ) ? (int) $records['reset'] : 0;
		if ( $reset > time() ) {
			$line = sprintf(
				/* translators: 1: records left this month, 2: monthly record limit, 3: date and time the allowance resets. */
				__( 'This key: %1$d of %2$d records left this month (resets %3$s). Scheduled runs share them evenly until then.', 'job-opportunities-api-for-wp-job-manager' ),
				$remaining,
				(int) $records['limit'],
				self::format_time( $reset )
			);
		} else {
			$line = sprintf(
				/* translators: 1: records left this month, 2: monthly record limit. */
				__( 'This key: %1$d of %2$d records left this month. Scheduled runs share them evenly.', 'job-opportunities-api-for-wp-job-manager' ),
				$remaining,
				(int) $records['limit']
			);
		}
		return $line;
	}

	// Form handlers (admin-post.php).

	/**
	 * Capability check shared by all handlers (each handler verifies its own nonce).
	 *
	 * @return void
	 */
	private static function require_capability() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'job-opportunities-api-for-wp-job-manager' ), 403 );
		}
	}

	/**
	 * Redirects back to the plugin page and exits.
	 *
	 * @param array $args Query args.
	 * @return void
	 */
	private static function back( array $args = array() ) {
		wp_safe_redirect( self::page_url( $args ) );
		exit;
	}

	/**
	 * Saves the general settings and the API key.
	 *
	 * @return void
	 */
	public static function handle_save_settings() {
		self::require_capability();
		check_admin_referer( 'joawpjm_save_settings' );

		$old   = JOAWPJM_Settings::get();
		$input = isset( $_POST['joawpjm'] ) && is_array( $_POST['joawpjm'] ) ? map_deep( wp_unslash( $_POST['joawpjm'] ), 'sanitize_text_field' ) : array();

		$new = array(
			'schedule'            => ( isset( $input['schedule'] ) && array_key_exists( $input['schedule'], JOAWPJM_Settings::schedules() ) ) ? $input['schedule'] : 'twicedaily',
			'post_status'         => ( isset( $input['post_status'] ) && 'pending' === $input['post_status'] ) ? 'pending' : 'publish',
			'duration'            => isset( $input['duration'] ) ? max( 1, min( 365, absint( $input['duration'] ) ) ) : 30,
			'keep_descriptions'   => empty( $input['keep_descriptions'] ) ? 0 : 1,
			'credit'              => empty( $input['credit'] ) ? JOAWPJM_Settings::CREDIT_OFF : JOAWPJM_Settings::CREDIT_ON,
			'delete_on_uninstall' => empty( $input['delete_on_uninstall'] ) ? 0 : 1,
			'closure_checks'      => isset( $input['closure_checks'] ) ? min( 500, absint( $input['closure_checks'] ) ) : 25,
		);
		JOAWPJM_Settings::update( $new );

		if ( $new['schedule'] !== $old['schedule'] || ! wp_next_scheduled( JOAWPJM_Settings::CRON_HOOK ) ) {
			JOAWPJM_Settings::reschedule( $new['schedule'] );
		}

		$key = isset( $_POST['joawpjm_api_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['joawpjm_api_key'] ) ) ) : '';
		if ( '' !== $key ) {
			JOAWPJM_Settings::set_api_key( $key );
			self::flash( 'success', __( 'Settings saved. The new API key is stored; use “Test connection” to check it.', 'job-opportunities-api-for-wp-job-manager' ) );
		} elseif ( ! empty( $_POST['joawpjm_remove_key'] ) ) {
			JOAWPJM_Settings::set_api_key( '' );
			self::flash( 'success', __( 'Settings saved. The API key was removed.', 'job-opportunities-api-for-wp-job-manager' ) );
		} else {
			self::flash( 'success', __( 'Settings saved.', 'job-opportunities-api-for-wp-job-manager' ) );
		}
		self::back();
	}

	/**
	 * "Show the credit" button of the credit notice.
	 *
	 * @return void
	 */
	public static function handle_credit_on() {
		self::credit_decision( true );
	}

	/**
	 * "Not now" button of the credit notice.
	 *
	 * @return void
	 */
	public static function handle_credit_off() {
		self::credit_decision( false );
	}

	/**
	 * Records the credit decision and returns to the screen the notice was on.
	 *
	 * @param bool $show Whether to show the credit.
	 * @return void
	 */
	private static function credit_decision( $show ) {
		self::require_capability();
		check_admin_referer( 'joawpjm_credit_decision' );

		JOAWPJM_Settings::set_credit( $show );
		self::flash(
			'success',
			$show
				? __( 'The “Data: Job Opportunities API” credit is now shown on imported listings. You can change this in the plugin settings.', 'job-opportunities-api-for-wp-job-manager' )
				: __( 'The credit stays off. You can switch it on any time in the plugin settings.', 'job-opportunities-api-for-wp-job-manager' )
		);
		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : self::page_url() );
		exit;
	}

	/**
	 * Calls GET /v1/me and reports plan, page size and usage.
	 *
	 * @return void
	 */
	public static function handle_test_connection() {
		self::require_capability();
		check_admin_referer( 'joawpjm_test_connection' );

		$api    = new JOAWPJM_API();
		$result = $api->me();
		if ( $result['ok'] ) {
			$me = $result['data'];
			delete_option( JOAWPJM_Settings::KEY_INVALID_OPTION );
			set_transient( JOAWPJM_Settings::ME_TRANSIENT, $me, 12 * HOUR_IN_SECONDS );
			$plan  = isset( $me['plan'] ) && is_scalar( $me['plan'] ) ? (string) $me['plan'] : '';
			$state = isset( $me['status'] ) && is_scalar( $me['status'] ) ? (string) $me['status'] : '';
			$max   = isset( $me['limits']['max_page_size'] ) ? (int) $me['limits']['max_page_size'] : 0;
			$usage = isset( $me['usage_today'] ) && is_scalar( $me['usage_today'] ) ? (string) $me['usage_today'] : '';
			self::flash(
				'success',
				__( 'Connected to the Job Opportunities API.', 'job-opportunities-api-for-wp-job-manager' ),
				array(
					/* translators: %s: plan name. */
					sprintf( __( 'Plan: %s', 'job-opportunities-api-for-wp-job-manager' ), $plan ),
					/* translators: %s: key status. */
					sprintf( __( 'Key status: %s', 'job-opportunities-api-for-wp-job-manager' ), $state ),
					/* translators: %d: maximum page size. */
					sprintf( __( 'Max page size: %d', 'job-opportunities-api-for-wp-job-manager' ), $max ),
					/* translators: %s: requests used today. */
					sprintf( __( 'Usage today: %s', 'job-opportunities-api-for-wp-job-manager' ), $usage ),
				)
			);
		} else {
			if ( 401 === $result['code'] ) {
				update_option( JOAWPJM_Settings::KEY_INVALID_OPTION, 1, false );
			}
			/* translators: 1: HTTP status code, 2: error message. */
			self::flash( 'error', sprintf( __( 'Connection failed (HTTP %1$d): %2$s', 'job-opportunities-api-for-wp-job-manager' ), (int) $result['code'], $result['message'] ) );
		}
		self::back();
	}

	/**
	 * Runs an import synchronously with a 25 second budget.
	 *
	 * @return void
	 */
	public static function handle_run_now() {
		self::require_capability();
		check_admin_referer( 'joawpjm_run_now' );

		$importer = new JOAWPJM_Importer();
		$summary  = $importer->run(
			array(
				'trigger' => 'manual',
				/**
				 * Filters the time budget of an import run in seconds.
				 *
				 * @param int    $seconds Budget.
				 * @param string $trigger cron|manual.
				 */
				'budget'  => (int) apply_filters( 'joawpjm_time_budget', 25, 'manual' ),
			)
		);

		if ( '' !== $summary['message'] ) {
			self::flash( 'error', $summary['message'] );
			self::back();
		}

		$totals  = array(
			'fetched' => 0,
			'created' => 0,
			'updated' => 0,
			'expired' => 0,
		);
		$details = array();
		foreach ( $summary['entries'] as $entry ) {
			foreach ( $totals as $k => $v ) {
				$totals[ $k ] += (int) $entry[ $k ];
			}
			foreach ( array_merge( $entry['errors'], $entry['notes'] ) as $line ) {
				$details[] = $entry['search'] . ': ' . $line;
			}
		}
		$message = sprintf(
			/* translators: 1: jobs fetched, 2: listings created, 3: listings updated, 4: listings expired. */
			__( 'Import finished: %1$d fetched, %2$d created, %3$d updated, %4$d expired.', 'job-opportunities-api-for-wp-job-manager' ),
			$totals['fetched'],
			$totals['created'],
			$totals['updated'],
			$totals['expired']
		);
		if ( empty( $summary['entries'] ) ) {
			$message = __( 'Nothing to import: add and enable a saved search first.', 'job-opportunities-api-for-wp-job-manager' );
		}
		self::flash( $summary['ok'] ? 'success' : 'warning', $message, $details );
		self::back();
	}

	/**
	 * Creates or updates a saved search.
	 *
	 * @return void
	 */
	public static function handle_save_search() {
		self::require_capability();
		check_admin_referer( 'joawpjm_save_search' );

		$id    = isset( $_POST['search_id'] ) ? sanitize_key( wp_unslash( $_POST['search_id'] ) ) : '';
		$input = isset( $_POST['search'] ) && is_array( $_POST['search'] ) ? map_deep( wp_unslash( $_POST['search'] ), 'sanitize_text_field' ) : array();

		$searches = JOAWPJM_Settings::get_searches();
		$old      = ( '' !== $id && isset( $searches[ $id ] ) ) ? $searches[ $id ] : null;
		if ( null === $old ) {
			$id = strtolower( wp_generate_password( 10, false, false ) );
		}

		$warnings = array();
		$search   = self::sanitize_search( $input, $warnings );

		$search['id']    = $id;
		$searches[ $id ] = $search;
		JOAWPJM_Settings::save_searches( $searches );

		// Different filters = a different result set: restart the incremental window.
		if ( $old ) {
			foreach ( JOAWPJM_Settings::search_filter_fields() as $field ) {
				if ( (string) $old[ $field ] !== (string) $search[ $field ] ) {
					$state = JOAWPJM_Settings::get_state();
					unset( $state['searches'][ $id ] );
					JOAWPJM_Settings::update_state( $state );
					break;
				}
			}
		}

		self::flash( 'success', __( 'Search saved.', 'job-opportunities-api-for-wp-job-manager' ) );
		foreach ( $warnings as $warning ) {
			self::flash( 'warning', $warning );
		}
		self::back( array( 'tab' => 'searches' ) );
	}

	/**
	 * Validates and normalises a saved search.
	 *
	 * @param array $input    Raw (already text-sanitized) input.
	 * @param array $warnings Collected warnings.
	 * @return array
	 */
	public static function sanitize_search( array $input, array &$warnings ) {
		$d   = JOAWPJM_Settings::search_defaults();
		$get = function ( $key ) use ( $input ) {
			return isset( $input[ $key ] ) ? trim( (string) $input[ $key ] ) : '';
		};

		$s                         = $d;
		$s['label']                = $get( 'label' );
		$s['enabled']              = empty( $input['enabled'] ) ? 0 : 1;
		$s['q']                    = $get( 'q' );
		$s['title']                = $get( 'title' );
		$s['description_contains'] = $get( 'description_contains' );
		$s['city']                 = $get( 'city' );
		$s['employment_type']      = $get( 'employment_type' );
		$s['seniority']            = $get( 'seniority' );
		$s['category']             = $get( 'category' );
		$s['has_salary']           = empty( $input['has_salary'] ) ? 0 : 1;
		$s['max_per_run']          = max( 1, min( 500, absint( '' === $get( 'max_per_run' ) ? 50 : $get( 'max_per_run' ) ) ) );
		$s['remote']               = array_key_exists( $get( 'remote' ), self::remote_options() ) ? $get( 'remote' ) : 'any';

		// Countries: ISO 3166 alpha-2 codes.
		$countries = array();
		$invalid   = array();
		foreach ( array_filter( array_map( 'trim', explode( ',', strtoupper( $get( 'country' ) ) ) ) ) as $code ) {
			if ( preg_match( '/^[A-Z]{2}$/', $code ) ) {
				$countries[] = $code;
			} else {
				$invalid[] = $code;
			}
		}
		$s['country'] = implode( ',', array_unique( $countries ) );
		if ( $invalid ) {
			/* translators: %s: rejected values. */
			$warnings[] = sprintf( __( 'Ignored invalid country codes (use two-letter ISO codes): %s', 'job-opportunities-api-for-wp-job-manager' ), implode( ', ', $invalid ) );
		}

		// Company slugs.
		$s['company'] = implode( ',', array_unique( array_filter( array_map( 'sanitize_title', explode( ',', $get( 'company' ) ) ) ) ) );

		// Company domains: bare host names.
		$domains = array();
		$invalid = array();
		foreach ( array_filter( array_map( 'trim', explode( ',', strtolower( $get( 'company_domain' ) ) ) ) ) as $domain ) {
			$domain = preg_replace( '#^https?://#', '', $domain );
			$domain = preg_replace( '#^www\.#', '', $domain );
			$domain = strtok( $domain, '/' );
			if ( is_string( $domain ) && preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $domain ) ) {
				$domains[] = $domain;
			} elseif ( is_string( $domain ) && '' !== $domain ) {
				$invalid[] = $domain;
			}
		}
		$s['company_domain'] = implode( ',', array_unique( $domains ) );
		if ( $invalid ) {
			/* translators: %s: rejected values. */
			$warnings[] = sprintf( __( 'Ignored invalid company domains: %s', 'job-opportunities-api-for-wp-job-manager' ), implode( ', ', $invalid ) );
		}

		// Job type: '', 'auto' or an existing term id.
		$job_type = $get( 'job_type' );
		if ( 'auto' === $job_type ) {
			$s['job_type'] = 'auto';
		} elseif ( absint( $job_type ) && taxonomy_exists( 'job_listing_type' ) && term_exists( absint( $job_type ), 'job_listing_type' ) ) {
			$s['job_type'] = (string) absint( $job_type );
		} else {
			$s['job_type'] = '';
		}

		$category          = absint( $get( 'job_category' ) );
		$s['job_category'] = ( $category && taxonomy_exists( 'job_listing_category' ) && term_exists( $category, 'job_listing_category' ) ) ? $category : 0;

		$author      = absint( $get( 'author' ) );
		$s['author'] = ( $author && user_can( $author, 'edit_posts' ) ) ? $author : get_current_user_id();

		if ( '' === $s['label'] ) {
			$s['label'] = self::describe_filters( $s );
		}
		return $s;
	}

	/**
	 * Deletes a saved search (imported listings are kept).
	 *
	 * @return void
	 */
	public static function handle_delete_search() {
		self::require_capability();
		check_admin_referer( 'joawpjm_delete_search' );
		$id = isset( $_GET['search'] ) ? sanitize_key( wp_unslash( $_GET['search'] ) ) : '';

		$searches = JOAWPJM_Settings::get_searches();
		if ( isset( $searches[ $id ] ) ) {
			unset( $searches[ $id ] );
			JOAWPJM_Settings::save_searches( $searches );
			$state = JOAWPJM_Settings::get_state();
			unset( $state['searches'][ $id ] );
			JOAWPJM_Settings::update_state( $state );
			self::flash( 'success', __( 'Search deleted. Listings it imported are kept.', 'job-opportunities-api-for-wp-job-manager' ) );
		}
		self::back( array( 'tab' => 'searches' ) );
	}

	/**
	 * Clears the activity log.
	 *
	 * @return void
	 */
	public static function handle_clear_log() {
		self::require_capability();
		check_admin_referer( 'joawpjm_clear_log' );
		update_option( JOAWPJM_Settings::LOG_OPTION, array(), false );
		self::flash( 'success', __( 'Activity log cleared.', 'job-opportunities-api-for-wp-job-manager' ) );
		self::back( array( 'tab' => 'log' ) );
	}

	// Listing meta box.

	/**
	 * Adds the source meta box to imported listings.
	 *
	 * @param WP_Post $post Listing.
	 * @return void
	 */
	public static function add_meta_box( $post ) {
		if ( ! $post || '' === (string) get_post_meta( $post->ID, '_joawpjm_id', true ) ) {
			return;
		}
		add_meta_box( 'joawpjm-source', __( 'Job Opportunities API', 'job-opportunities-api-for-wp-job-manager' ), array( __CLASS__, 'render_meta_box' ), 'job_listing', 'side' );
	}

	/**
	 * Renders the meta box.
	 *
	 * @param WP_Post $post Listing.
	 * @return void
	 */
	public static function render_meta_box( $post ) {
		wp_nonce_field( 'joawpjm_lock_' . $post->ID, 'joawpjm_lock_nonce' );
		$rows        = array(
			__( 'JOA id', 'job-opportunities-api-for-wp-job-manager' )    => get_post_meta( $post->ID, '_joawpjm_id', true ),
			__( 'Source', 'job-opportunities-api-for-wp-job-manager' )    => get_post_meta( $post->ID, '_joawpjm_source', true ),
			__( 'Status', 'job-opportunities-api-for-wp-job-manager' )    => get_post_meta( $post->ID, '_joawpjm_status', true ),
			__( 'Last seen', 'job-opportunities-api-for-wp-job-manager' ) => self::format_time( (int) get_post_meta( $post->ID, '_joawpjm_last_seen', true ) ),
		);
		$attribution = (string) get_post_meta( $post->ID, '_joawpjm_attribution', true );
		if ( '' !== $attribution ) {
			$rows[ __( 'Attribution', 'job-opportunities-api-for-wp-job-manager' ) ] = $attribution;
		}
		echo '<ul class="joawpjm-meta">';
		foreach ( $rows as $label => $value ) {
			echo '<li><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( (string) $value ) . '</li>';
		}
		echo '</ul>';
		echo '<p><label><input type="checkbox" name="joawpjm_locked" value="1"' . checked( (bool) get_post_meta( $post->ID, '_joawpjm_locked', true ), true, false ) . '> ' . esc_html__( 'Lock: keep my edits (do not update from the API)', 'job-opportunities-api-for-wp-job-manager' ) . '</label></p>';
		echo '<p class="description">' . esc_html__( 'A locked listing still expires when the employer closes the role.', 'job-opportunities-api-for-wp-job-manager' ) . '</p>';
	}

	/**
	 * Saves the lock flag.
	 *
	 * @param int $post_id Listing id.
	 * @return void
	 */
	public static function save_meta_box( $post_id ) {
		if ( ! isset( $_POST['joawpjm_lock_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['joawpjm_lock_nonce'] ) ), 'joawpjm_lock_' . $post_id ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( ! empty( $_POST['joawpjm_locked'] ) ) {
			update_post_meta( $post_id, '_joawpjm_locked', 1 );
		} else {
			delete_post_meta( $post_id, '_joawpjm_locked' );
		}
	}
}
