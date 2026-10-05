<?php
/**
 * Front-end output: attribution lines on imported listings.
 *
 * @package JOAWPJM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Front end.
 */
class JOAWPJM_Frontend {

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function init() {
		// WP Job Manager prints the description through this filter on single listings.
		add_filter( 'the_job_description', array( __CLASS__, 'append_attribution' ), 20 );
	}

	/**
	 * Appends the data attribution (always, when the role carries one) and the
	 * optional plugin credit (only when the site owner switched it on).
	 *
	 * @param string $description Description HTML.
	 * @return string
	 */
	public static function append_attribution( $description ) {
		$post = get_post();
		if ( ! $post || 'job_listing' !== $post->post_type ) {
			return $description;
		}
		$joa_id = (string) get_post_meta( $post->ID, '_joawpjm_id', true );
		if ( '' === $joa_id ) {
			return $description;
		}

		$attribution = trim( (string) get_post_meta( $post->ID, '_joawpjm_attribution', true ) );
		if ( '' !== $attribution ) {
			$text = $attribution;
			if ( 0 === stripos( $attribution, 'via ' ) ) {
				/* translators: %s: attribution supplied with the job data, for example "via Erioun for Teams". */
				$text = sprintf( __( 'Listed %s', 'job-opportunities-api-for-wp-job-manager' ), $attribution );
			}
			$description .= '<p class="joawpjm-attribution"><small>' . esc_html( $text ) . '</small></p>';
		}

		if ( JOAWPJM_Settings::get( 'attribution' ) ) {
			$description .= '<p class="joawpjm-credit"><small><a href="https://jobopportunitiesapi.org">' . esc_html__( 'Jobs via Job Opportunities API', 'job-opportunities-api-for-wp-job-manager' ) . '</a></small></p>';
		}
		return $description;
	}
}
