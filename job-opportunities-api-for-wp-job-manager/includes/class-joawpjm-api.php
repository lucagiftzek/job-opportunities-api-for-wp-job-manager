<?php
/**
 * Minimal HTTP client for the Job Opportunities API (JOA).
 *
 * This is the only place the plugin talks to the network. It only ever calls
 * the JOA API base URL, sends the key in the Authorization header and never
 * logs or returns the key.
 *
 * @package JOAWPJM
 */

defined( 'ABSPATH' ) || exit;

/**
 * JOA API client built on the WordPress HTTP API.
 */
class JOAWPJM_API {

	const DEFAULT_BASE = 'https://api.jobopportunitiesapi.org';

	/**
	 * API key.
	 *
	 * @var string
	 */
	private $key;

	/**
	 * Constructor.
	 *
	 * @param string|null $key API key; null reads the saved key.
	 */
	public function __construct( $key = null ) {
		$this->key = ( null === $key ) ? JOAWPJM_Settings::get_api_key() : (string) $key;
	}

	/**
	 * Base URL of the API. Overridable with the JOAWPJM_API_BASE constant (tests)
	 * and the joawpjm_api_base filter.
	 *
	 * @return string
	 */
	public static function base_url() {
		$base = ( defined( 'JOAWPJM_API_BASE' ) && JOAWPJM_API_BASE ) ? (string) JOAWPJM_API_BASE : self::DEFAULT_BASE;
		/**
		 * Filters the base URL of the Job Opportunities API.
		 *
		 * @param string $base Base URL without trailing slash.
		 */
		$base = (string) apply_filters( 'joawpjm_api_base', $base );
		return untrailingslashit( esc_url_raw( $base ) );
	}

	/**
	 * GET /v1/me: key status, plan and limits.
	 *
	 * @return array Result, see request().
	 */
	public function me() {
		return $this->request( '/v1/me' );
	}

	/**
	 * GET /v1/jobs.
	 *
	 * @param array $query Query parameters.
	 * @return array Result, see request().
	 */
	public function jobs( array $query ) {
		return $this->request( '/v1/jobs', $query );
	}

	/**
	 * GET /v1/jobs/{id}?include_closed=true.
	 *
	 * @param string $id JOA job id.
	 * @return array Result, see request().
	 */
	public function job( $id ) {
		return $this->request( '/v1/jobs/' . rawurlencode( (string) $id ), array( 'include_closed' => 'true' ) );
	}

	/**
	 * GET /v1/jobs/expired (Growth plan and above).
	 *
	 * @param string $since RFC 3339 time or a next_since cursor.
	 * @param int    $limit Page size.
	 * @return array Result, see request().
	 */
	public function expired( $since, $limit ) {
		return $this->request(
			'/v1/jobs/expired',
			array(
				'since' => (string) $since,
				'limit' => (int) $limit,
			)
		);
	}

	/**
	 * Performs a GET request.
	 *
	 * @param string $path  API path starting with a slash.
	 * @param array  $query Query parameters.
	 * @return array {
	 *     @type bool       $ok          True on a 2xx JSON response.
	 *     @type int        $code        HTTP status (0 when no response).
	 *     @type array|null $data        Decoded JSON body.
	 *     @type string     $error       Machine error code ('' when ok).
	 *     @type string     $message     Human-readable error message ('' when ok).
	 *     @type int        $retry_after Seconds to wait (429 only).
	 *     @type string     $feature     X-JOA-Required-Feature header (403 only).
	 * }
	 */
	public function request( $path, array $query = array() ) {
		$result = array(
			'ok'          => false,
			'code'        => 0,
			'data'        => null,
			'error'       => '',
			'message'     => '',
			'retry_after' => 0,
			'feature'     => '',
		);

		if ( '' === $this->key ) {
			$result['error']   = 'no_key';
			$result['message'] = __( 'No API key is saved.', 'job-opportunities-api-for-wp-job-manager' );
			return $result;
		}

		$url = self::base_url() . $path;
		if ( ! empty( $query ) ) {
			$url .= '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 20,
				'redirection' => 2,
				'user-agent'  => 'JOA-WPJM/' . JOAWPJM_VERSION,
				'headers'     => array(
					'Authorization' => 'Bearer ' . $this->key,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$result['error']   = 'http_error';
			$result['message'] = $response->get_error_message();
			return $result;
		}

		$code           = (int) wp_remote_retrieve_response_code( $response );
		$data           = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$result['code'] = $code;
		$result['data'] = is_array( $data ) ? $data : null;

		if ( $code >= 200 && $code < 300 ) {
			if ( is_array( $data ) ) {
				$result['ok'] = true;
				return $result;
			}
			$result['error']   = 'invalid_json';
			$result['message'] = __( 'The API returned a response that is not valid JSON.', 'job-opportunities-api-for-wp-job-manager' );
			return $result;
		}

		$result['error']   = ( is_array( $data ) && ! empty( $data['error'] ) && is_string( $data['error'] ) ) ? sanitize_key( $data['error'] ) : 'http_' . $code;
		$result['message'] = ( is_array( $data ) && ! empty( $data['message'] ) && is_string( $data['message'] ) ) ? self::shorten( $data['message'] ) : self::default_message( $code );

		if ( 429 === $code ) {
			$result['retry_after'] = self::parse_retry_after( wp_remote_retrieve_header( $response, 'retry-after' ) );
		}
		if ( 403 === $code ) {
			$result['feature'] = sanitize_key( (string) wp_remote_retrieve_header( $response, 'x-joa-required-feature' ) );
		}
		return $result;
	}

	/**
	 * Parses a Retry-After header (seconds or HTTP date). Defaults to 60 s.
	 *
	 * @param string|array $header Header value.
	 * @return int Seconds, between 1 and one day.
	 */
	public static function parse_retry_after( $header ) {
		if ( is_array( $header ) ) {
			$header = reset( $header );
		}
		$header = trim( (string) $header );
		if ( '' === $header ) {
			return 60;
		}
		if ( ctype_digit( $header ) ) {
			$seconds = (int) $header;
		} else {
			$time    = strtotime( $header );
			$seconds = $time ? $time - time() : 60;
		}
		return max( 1, min( DAY_IN_SECONDS, $seconds ) );
	}

	/**
	 * Fallback message for an HTTP status without a JSON error body.
	 *
	 * @param int $code HTTP status.
	 * @return string
	 */
	private static function default_message( $code ) {
		switch ( $code ) {
			case 401:
				return __( 'The API key is missing, unknown, revoked or expired.', 'job-opportunities-api-for-wp-job-manager' );
			case 402:
				return __( 'The monthly record allowance of this API key is used up.', 'job-opportunities-api-for-wp-job-manager' );
			case 403:
				return __( 'Your plan does not include this endpoint.', 'job-opportunities-api-for-wp-job-manager' );
			case 404:
				return __( 'Not found.', 'job-opportunities-api-for-wp-job-manager' );
			case 429:
				return __( 'Rate limit reached.', 'job-opportunities-api-for-wp-job-manager' );
		}
		if ( $code >= 500 ) {
			return __( 'The API is temporarily unavailable.', 'job-opportunities-api-for-wp-job-manager' );
		}
		/* translators: %d: HTTP status code. */
		return sprintf( __( 'Unexpected HTTP status %d.', 'job-opportunities-api-for-wp-job-manager' ), $code );
	}

	/**
	 * Trims an API message to a sensible length for display.
	 *
	 * @param string $message Message.
	 * @return string
	 */
	private static function shorten( $message ) {
		$message = sanitize_text_field( $message );
		return ( strlen( $message ) > 300 ) ? substr( $message, 0, 297 ) . '...' : $message;
	}
}
