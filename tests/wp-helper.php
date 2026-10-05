<?php
/**
 * Test helper for tests/run-e2e.sh, run with: wp eval-file /tests/wp-helper.php <command> [args...]
 *
 * @package JOAWPJM
 */

// phpcs:ignoreFile -- test tooling, not shipped.

$cmd = isset( $args[0] ) ? $args[0] : '';

switch ( $cmd ) {
	case 'reset':
		global $wpdb;
		$ids = $wpdb->get_col( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_joawpjm_id'" );
		foreach ( $ids as $id ) {
			wp_delete_post( (int) $id, true );
		}
		foreach ( array( 'joawpjm_state', 'joawpjm_log', 'joawpjm_searches', 'joawpjm_key_invalid', 'joawpjm_lock' ) as $o ) {
			delete_option( $o );
		}
		foreach ( array( 'joawpjm_me', 'joawpjm_no_expired_feed', 'joawpjm_retry_after' ) as $t ) {
			delete_transient( $t );
		}
		JOAWPJM_Settings::update( JOAWPJM_Settings::defaults() );
		JOAWPJM_Settings::set_api_key( 'test-key-123' );
		echo 'reset';
		break;

	case 'search': // search <id> <json overrides>
		$searches            = JOAWPJM_Settings::get_searches();
		$s                   = array_merge( JOAWPJM_Settings::search_defaults(), json_decode( $args[2], true ) );
		$s['id']             = $args[1];
		$s['author']         = 1;
		$searches[ $args[1] ] = $s;
		JOAWPJM_Settings::save_searches( $searches );
		echo 'ok';
		break;

	case 'count':
		global $wpdb;
		echo (int) $wpdb->get_var( "SELECT COUNT(DISTINCT m.post_id) FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = '_joawpjm_id' AND p.post_status <> 'trash'" );
		break;

	case 'pid':
		$r = JOAWPJM_Importer::find_posts_by_meta( '_joawpjm_id', array( $args[1] ) );
		echo isset( $r[ $args[1] ] ) ? $r[ $args[1] ] : '';
		break;

	case 'entry': // entry <search label> <field>  (newest entry with that label)
		foreach ( JOAWPJM_Settings::get_log() as $e ) {
			if ( $e['search'] === $args[1] ) {
				$v = $e[ $args[2] ];
				echo is_array( $v ) ? implode( ' | ', $v ) : $v;
				break;
			}
		}
		break;

	case 'logsize':
		echo count( JOAWPJM_Settings::get_log() );
		break;

	case 'set':
		$s             = JOAWPJM_Settings::get();
		$s[ $args[1] ] = is_numeric( $args[2] ) ? (int) $args[2] : $args[2];
		JOAWPJM_Settings::update( $s );
		echo 'ok';
		break;

	case 'terms':
		echo implode( ',', wp_get_post_terms( (int) $args[1], $args[2], array( 'fields' => 'names' ) ) );
		break;

	case 'transient':
		echo (string) get_transient( $args[1] );
		break;

	case 'lock':
		global $wpdb;
		$wpdb->replace( $wpdb->options, array( 'option_name' => 'joawpjm_lock', 'option_value' => (string) ( time() - (int) $args[1] ), 'autoload' => 'no' ) );
		echo 'locked';
		break;

	case 'unlock':
		JOAWPJM_Importer::release_lock();
		echo 'unlocked';
		break;

	case 'sanitize':
		wp_set_current_user( 1 );
		$warnings = array();
		$s        = JOAWPJM_Admin::sanitize_search( json_decode( $args[1], true ), $warnings );
		echo wp_json_encode( array( 'search' => $s, 'warnings' => $warnings ) );
		break;

	case 'option':
		$v = get_option( $args[1], '__missing__' );
		echo is_scalar( $v ) ? $v : wp_json_encode( $v );
		break;

	case 'cron':
		echo wp_next_scheduled( 'joawpjm_cron_import' ) ? 'scheduled' : 'none';
		break;

	case 'state':
		echo wp_json_encode( JOAWPJM_Settings::get_state() );
		break;
}
