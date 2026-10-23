<?php
/**
 * Mock of the Job Opportunities API (JOA) for the end-to-end tests.
 *
 * Behaviour is driven by state/scenario.json, written by tests/run-e2e.sh.
 * Every request is appended to state/requests.log (one JSON object per line).
 *
 * Scenario keys:
 *   mode          normal | 401 | 429 | 500
 *   jobs          { "<q value or _default>": [ Job, ... ] }
 *   feed          true when the "plan" includes /v1/jobs/expired, else 403
 *   expired       [ { seq, id, closed_reason } ]  (cursor = "seq:<n>")
 *   closed        [ id, ... ]  -> /v1/jobs/{id} answers status=closed
 *   gone          [ id, ... ]  -> /v1/jobs/{id} answers 404
 *   withdrawn     [ id, ... ]  -> /v1/jobs/{id} answers 410
 *   records       "unlimited", { limit, remaining, reset } or absent (no headers).
 *                 When an object, the metered responses carry
 *                 X-RateLimit-Records-Limit/-Used/-Remaining/-Reset and
 *                 "remaining" decreases by every job row served (persisted here).
 */

const TEST_KEY = 'test-key-123';

$dir      = __DIR__ . '/state';
$scenario = json_decode( (string) @file_get_contents( $dir . '/scenario.json' ), true ) ?: array();
$path     = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$query    = $_GET;
$auth     = isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? $_SERVER['HTTP_AUTHORIZATION'] : '';

@file_put_contents(
	$dir . '/requests.log',
	json_encode(
		array(
			'path'  => $path,
			'query' => $query,
			'auth'  => ( 'Bearer ' . TEST_KEY === $auth ) ? 'ok' : 'bad',
			'ua'    => isset( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : '',
		),
		JSON_UNESCAPED_SLASHES
	) . "\n",
	FILE_APPEND
);

function out( $code, $body, $headers = array() ) {
	http_response_code( $code );
	header( 'Content-Type: application/json' );
	foreach ( $headers as $k => $v ) {
		header( $k . ': ' . $v );
	}
	echo json_encode( $body );
	exit;
}

/**
 * The X-RateLimit-Records-* headers for a metered response, following the
 * "records" scenario key. $spent rows decrease "remaining" (persisted to
 * scenario.json so the next request reflects this one).
 */
function record_headers( $scenario, $spent = 0 ) {
	if ( ! isset( $scenario['records'] ) ) {
		return array();
	}
	if ( 'unlimited' === $scenario['records'] ) {
		return array( 'X-RateLimit-Records-Limit' => 'unlimited' );
	}
	if ( ! is_array( $scenario['records'] ) || ! isset( $scenario['records']['limit'] ) ) {
		return array();
	}
	if ( $spent > 0 && isset( $scenario['records']['remaining'] ) && is_numeric( $scenario['records']['remaining'] ) ) {
		$scenario['records']['remaining'] = max( 0, (int) $scenario['records']['remaining'] - (int) $spent );
		@file_put_contents( __DIR__ . '/state/scenario.json', json_encode( $scenario, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}
	$headers = array( 'X-RateLimit-Records-Limit' => (string) (int) $scenario['records']['limit'] );
	if ( isset( $scenario['records']['remaining'] ) && is_numeric( $scenario['records']['remaining'] ) ) {
		$headers['X-RateLimit-Records-Used']      = (string) max( 0, (int) $scenario['records']['limit'] - (int) $scenario['records']['remaining'] );
		$headers['X-RateLimit-Records-Remaining'] = (string) (int) $scenario['records']['remaining'];
	}
	if ( isset( $scenario['records']['reset'] ) && is_numeric( $scenario['records']['reset'] ) ) {
		$headers['X-RateLimit-Records-Reset'] = (string) (int) $scenario['records']['reset'];
	}
	return $headers;
}

function all_jobs( $scenario ) {
	$all = array();
	foreach ( (array) ( isset( $scenario['jobs'] ) ? $scenario['jobs'] : array() ) as $list ) {
		foreach ( $list as $job ) {
			$all[ $job['id'] ] = $job;
		}
	}
	return $all;
}

$mode = isset( $scenario['mode'] ) ? $scenario['mode'] : 'normal';

if ( 'Bearer ' . TEST_KEY !== $auth || '401' === $mode ) {
	out( 401, array( 'error' => 'unauthorized', 'message' => 'Missing, unknown, revoked or expired key.' ) );
}
if ( '429' === $mode && '/v1/me' !== $path ) {
	out( 429, array( 'error' => 'rate_limited', 'message' => 'Too many requests.' ), array( 'Retry-After' => '120' ) );
}
if ( '500' === $mode && '/v1/me' !== $path ) {
	out( 503, array( 'error' => 'unavailable', 'message' => 'Try again later.' ) );
}

if ( '/v1/me' === $path ) {
	out(
		200,
		array(
			'plan'              => ! empty( $scenario['feed'] ) ? 'growth' : 'explore',
			'status'            => 'active',
			'limits'            => array(
				'max_page_size' => 3,
				'per_day'       => 1000,
				'per_minute'    => 60,
			),
			'usage_today'       => 7,
			'created'           => '2026-01-01',
			'renews_or_expires' => null,
		),
		record_headers( $scenario )
	);
}

if ( '/v1/jobs' === $path ) {
	$key   = ( isset( $query['q'] ) && isset( $scenario['jobs'][ $query['q'] ] ) ) ? $query['q'] : '_default';
	$list  = isset( $scenario['jobs'][ $key ] ) ? array_values( $scenario['jobs'][ $key ] ) : array();
	$limit = isset( $query['limit'] ) ? max( 1, min( 200, (int) $query['limit'] ) ) : 25;
	$from  = isset( $query['cursor'] ) ? (int) $query['cursor'] : 0;
	$page  = array_slice( $list, $from, $limit );
	$next  = $from + count( $page );
	if ( empty( $query['include_description'] ) || 'true' !== $query['include_description'] ) {
		foreach ( $page as &$job ) {
			unset( $job['description'] );
		}
		unset( $job );
	}
	out(
		200,
		array(
			'data'        => $page,
			'has_more'    => $next < count( $list ),
			'next_cursor' => $next < count( $list ) ? (string) $next : null,
		),
		record_headers( $scenario, count( $page ) )
	);
}

if ( '/v1/jobs/expired' === $path ) {
	if ( empty( $scenario['feed'] ) ) {
		out( 403, array( 'error' => 'plan_upgrade_required', 'message' => 'This endpoint is not included in your plan.' ), array( 'X-JOA-Required-Feature' => 'delta_feed' ) );
	}
	$since = isset( $query['since'] ) ? (string) $query['since'] : '';
	if ( '' === $since ) {
		out( 400, array( 'error' => 'bad_request', 'message' => 'since is required' ) );
	}
	$after = ( 0 === strpos( $since, 'seq:' ) ) ? (int) substr( $since, 4 ) : 0;
	$rows  = array();
	$max   = $after;
	foreach ( (array) ( isset( $scenario['expired'] ) ? $scenario['expired'] : array() ) as $row ) {
		if ( $row['seq'] > $after ) {
			$rows[] = array(
				'id'            => $row['id'],
				'closed_at'     => '2026-10-05T10:00:00Z',
				'closed_reason' => $row['closed_reason'],
			);
			$max    = max( $max, $row['seq'] );
		}
	}
	out(
		200,
		array(
			'data'       => $rows,
			'next_since' => $rows ? 'seq:' . $max : $since,
			'count'      => count( $rows ),
		),
		record_headers( $scenario, count( $rows ) )
	);
}

if ( preg_match( '#^/v1/jobs/([^/]+)$#', $path, $m ) ) {
	$id = urldecode( $m[1] );
	if ( in_array( $id, (array) ( isset( $scenario['gone'] ) ? $scenario['gone'] : array() ), true ) ) {
		out( 404, array( 'error' => 'not_found', 'message' => 'No such row.' ), record_headers( $scenario ) );
	}
	if ( in_array( $id, (array) ( isset( $scenario['withdrawn'] ) ? $scenario['withdrawn'] : array() ), true ) ) {
		out( 410, array( 'error' => 'gone', 'message' => 'The employer withdrew this listing.' ), record_headers( $scenario ) );
	}
	$jobs = all_jobs( $scenario );
	$job  = isset( $jobs[ $id ] ) ? $jobs[ $id ] : array(
		'id'     => $id,
		'title'  => 'Unknown',
		'status' => 'live',
	);
	if ( in_array( $id, (array) ( isset( $scenario['closed'] ) ? $scenario['closed'] : array() ), true ) ) {
		$job['status']        = 'closed';
		$job['closed_reason'] = 'employer_closed';
	} else {
		$job['status'] = 'live';
	}
	$desc = isset( $job['description'] ) ? $job['description'] : null;
	unset( $job['description'] );
	out(
		200,
		array(
			'data'        => $job,
			'description' => $desc,
		),
		record_headers( $scenario, 1 )
	);
}

out( 404, array( 'error' => 'not_found', 'message' => 'Unknown path ' . $path ) );
