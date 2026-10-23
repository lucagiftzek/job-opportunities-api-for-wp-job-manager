<?php
/**
 * Plugin Name:       Job Opportunities API for WP Job Manager
 * Plugin URI:        https://jobopportunitiesapi.org/integrations/wordpress
 * Description:       Imports employer-direct job postings from the Job Opportunities API (JOA) into WP Job Manager listings on a schedule.
 * Version:           1.0.2
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Requires Plugins:  wp-job-manager
 * Author:            Loukas Tzekos
 * Author URI:        https://jobopportunitiesapi.org
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       job-opportunities-api-for-wp-job-manager
 * Domain Path:       /languages
 *
 * @package JOAWPJM
 */

defined( 'ABSPATH' ) || exit;

define( 'JOAWPJM_VERSION', '1.0.2' );
define( 'JOAWPJM_FILE', __FILE__ );
define( 'JOAWPJM_DIR', plugin_dir_path( __FILE__ ) );
define( 'JOAWPJM_URL', plugin_dir_url( __FILE__ ) );

require_once JOAWPJM_DIR . 'includes/class-joawpjm-settings.php';
require_once JOAWPJM_DIR . 'includes/class-joawpjm-api.php';
require_once JOAWPJM_DIR . 'includes/class-joawpjm-importer.php';
require_once JOAWPJM_DIR . 'includes/class-joawpjm-frontend.php';
require_once JOAWPJM_DIR . 'includes/class-joawpjm-admin.php';
require_once JOAWPJM_DIR . 'includes/class-joawpjm-plugin.php';

register_activation_hook( __FILE__, array( 'JOAWPJM_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'JOAWPJM_Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'JOAWPJM_Plugin', 'init' ) );
