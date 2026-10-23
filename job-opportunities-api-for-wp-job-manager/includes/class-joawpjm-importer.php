<?php
/**
 * Import engine: saved searches -> WP Job Manager listings, plus closure handling.
 *
 * @package JOAWPJM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Imports jobs from the Job Opportunities API (JOA) and expires closed roles.
 */
class JOAWPJM_Importer {

	/** Hard ceiling on the page size, whatever the plan allows. */
	const PAGE_CAP = 100;

	/** A run lock older than this is considered stale (seconds). */
	const LOCK_TTL = 900;

	/** Page size used for the closure feed. */
	const EXPIRED_PAGE = 1000;

	/** Maximum closure-feed pages per run. */
	const EXPIRED_MAX_PAGES = 10;

	/**
	 * API client.
	 *
	 * @var JOAWPJM_API
	 */
	private $api;

	/**
	 * Dry run: fetch and compare only.
	 *
	 * @var bool
	 */
	private $dry_run = false;

	/**
	 * What started the run (cron, manual, cli).
	 *
	 * @var string
	 */
	private $trigger = 'cron';

	/**
	 * Microtime after which no new request is started (0 = no limit).
	 *
	 * @var float
	 */
	private $deadline = 0.0;

	/**
	 * Records of the key's monthly allowance this run may still spend
	 * (shared by the search imports and the closure checks).
	 * null = no metered allowance known, only max_per_run applies.
	 *
	 * @var int|null
	 */
	private $record_budget = null;

	/**
	 * The run's original record budget, for the log note.
	 *
	 * @var int
	 */
	private $record_budget_total = 0;

	/**
	 * The latest X-RateLimit-Records-* values reported by the API in this run.
	 *
	 * @var array|null
	 */
	private $records = null;

	/**
	 * Whether a fatal API condition stopped the run.
	 *
	 * @var bool
	 */
	private $stopped = false;

	/**
	 * JOA id => post id for everything handled in this run.
	 *
	 * @var array
	 */
	private $seen_ids = array();

	/**
	 * Apply-URL hash => post id for everything handled in this run.
	 *
	 * @var array
	 */
	private $seen_hashes = array();

	/**
	 * Constructor.
	 *
	 * @param JOAWPJM_API|null $api Optional client (tests).
	 */
	public function __construct( $api = null ) {
		$this->api = ( $api instanceof JOAWPJM_API ) ? $api : new JOAWPJM_API();
	}

	/**
	 * Runs an import.
	 *
	 * @param array $args {
	 *     Optional. Run arguments.
	 *
	 *     @type string $search  Only this saved search id ('' = every enabled search, plus closures).
	 *     @type bool   $dry_run Fetch and compare only; write nothing.
	 *     @type string $trigger cron|manual|cli.
	 *     @type int    $budget  Time budget in seconds (0 = none).
	 * }
	 * @return array {ok: bool, message: string, entries: array[]}
	 */
	public function run( array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'search'  => '',
				'dry_run' => false,
				'trigger' => 'cron',
				'budget'  => 0,
			)
		);

		$this->dry_run             = (bool) $args['dry_run'];
		$this->trigger             = sanitize_key( $args['trigger'] );
		$this->deadline            = ( (int) $args['budget'] > 0 ) ? microtime( true ) + (int) $args['budget'] : 0.0;
		$this->stopped             = false;
		$this->record_budget       = null;
		$this->record_budget_total = 0;
		$this->records             = null;

		$summary = array(
			'ok'      => true,
			'message' => '',
			'entries' => array(),
		);

		$problem = '';
		if ( ! JOAWPJM_Plugin::wpjm_active() ) {
			$problem = __( 'WP Job Manager is not active.', 'job-opportunities-api-for-wp-job-manager' );
		} elseif ( '' === JOAWPJM_Settings::get_api_key() ) {
			$problem = __( 'No API key is saved.', 'job-opportunities-api-for-wp-job-manager' );
		} elseif ( get_option( JOAWPJM_Settings::KEY_INVALID_OPTION ) ) {
			$problem = __( 'The saved API key was rejected. Save a valid key to resume imports.', 'job-opportunities-api-for-wp-job-manager' );
		} else {
			$retry = (int) get_transient( JOAWPJM_Settings::RETRY_TRANSIENT );
			if ( $retry > time() ) {
				/* translators: %d: number of seconds. */
				$problem = sprintf( __( 'The API asked to slow down. Imports resume in %d seconds.', 'job-opportunities-api-for-wp-job-manager' ), $retry - time() );
			}
		}

		$searches = $this->searches_to_run( (string) $args['search'] );
		if ( '' === $problem && '' !== $args['search'] && empty( $searches ) ) {
			$problem = __( 'No saved search has that id.', 'job-opportunities-api-for-wp-job-manager' );
		}

		if ( '' !== $problem ) {
			$summary['ok']      = false;
			$summary['message'] = $problem;
			return $summary;
		}

		if ( ! $this->dry_run && ! self::acquire_lock() ) {
			$summary['ok']      = false;
			$summary['message'] = __( 'Another import is already running. Try again in a few minutes.', 'job-opportunities-api-for-wp-job-manager' );
			return $summary;
		}

		// The per-run share of the key's monthly record allowance (null = no metered allowance known).
		$this->record_budget       = $this->run_budget( $this->trigger );
		$this->record_budget_total = (int) $this->record_budget;

		try {
			$page_size = $this->page_size( $summary );
			if ( $page_size > 0 ) {
				foreach ( $searches as $search ) {
					if ( $this->stopped ) {
						break;
					}
					$summary['entries'][] = $this->import_search( $search, $page_size );
				}
				if ( ! $this->stopped && ! $this->dry_run && '' === $args['search'] ) {
					$summary['entries'][] = $this->process_closures();
				}
			}
		} finally {
			if ( ! $this->dry_run ) {
				$this->save_records_state();
				self::release_lock();
			}
		}

		foreach ( $summary['entries'] as $entry ) {
			if ( ! empty( $entry['errors'] ) ) {
				$summary['ok'] = false;
			}
			if ( ! $this->dry_run ) {
				JOAWPJM_Settings::add_log_entry( $entry );
			}
		}

		/**
		 * Fires after an import run.
		 *
		 * @param array $summary Run summary.
		 * @param array $args    Run arguments.
		 */
		do_action( 'joawpjm_import_finished', $summary, $args );

		return $summary;
	}

	/**
	 * Searches for this run.
	 *
	 * @param string $only Search id, or '' for every enabled search.
	 * @return array
	 */
	private function searches_to_run( $only ) {
		$searches = JOAWPJM_Settings::get_searches();
		if ( '' !== $only ) {
			return isset( $searches[ $only ] ) ? array( $searches[ $only ] ) : array();
		}
		return array_values(
			array_filter(
				$searches,
				function ( $search ) {
					return ! empty( $search['enabled'] );
				}
			)
		);
	}

	/**
	 * Page size for /v1/jobs: min(plan max_page_size, PAGE_CAP). The /v1/me
	 * answer is cached for 12 hours.
	 *
	 * @param array $summary Run summary (an error entry is added on failure).
	 * @return int 0 when the run must stop.
	 */
	private function page_size( array &$summary ) {
		$me = get_transient( JOAWPJM_Settings::ME_TRANSIENT );
		if ( ! is_array( $me ) ) {
			$result = $this->api->me();
			$this->remember_records( $result );
			if ( ! $result['ok'] ) {
				$entry                = self::new_entry( __( 'Connection check', 'job-opportunities-api-for-wp-job-manager' ), '', $this->trigger );
				$entry['errors'][]    = $this->handle_error( $result, __( 'Key check', 'job-opportunities-api-for-wp-job-manager' ) );
				$summary['entries'][] = $entry;
				$this->stopped        = true;
				return 0;
			}
			$me = $result['data'];
			set_transient( JOAWPJM_Settings::ME_TRANSIENT, $me, 12 * HOUR_IN_SECONDS );
		}
		$max = isset( $me['limits']['max_page_size'] ) ? (int) $me['limits']['max_page_size'] : self::PAGE_CAP;
		return max( 1, min( $max, self::PAGE_CAP ) );
	}

	/**
	 * Maps a saved search to /v1/jobs query parameters.
	 *
	 * @param array $search Saved search.
	 * @param array $state  Run state of the search.
	 * @return array
	 */
	public static function build_query( array $search, array $state = array() ) {
		$query = array();
		$map   = array(
			'q'                    => 'q',
			'title'                => 'title',
			'description_contains' => 'description_contains',
			'country'              => 'country',
			'city'                 => 'city',
			'employment_type'      => 'employment_type',
			'seniority'            => 'seniority',
			'category'             => 'category',
			'company'              => 'company',
			'company_domain'       => 'company_domain',
		);
		foreach ( $map as $field => $param ) {
			if ( isset( $search[ $field ] ) && '' !== trim( (string) $search[ $field ] ) ) {
				$query[ $param ] = trim( (string) $search[ $field ] );
			}
		}
		if ( ! empty( $search['remote'] ) && 'any' !== $search['remote'] ) {
			$query['remote'] = $search['remote'];
		}
		if ( ! empty( $search['has_salary'] ) ) {
			$query['has_salary'] = 'true';
		}
		$query['include_description'] = JOAWPJM_Settings::get( 'keep_descriptions' ) ? 'true' : 'false';
		if ( ! empty( $state['last_success'] ) ) {
			$query['posted_after'] = gmdate( 'Y-m-d\TH:i:s\Z', (int) $state['last_success'] - DAY_IN_SECONDS );
		}
		return $query;
	}

	/**
	 * Imports one saved search.
	 *
	 * @param array $search    Saved search.
	 * @param int   $page_size Page size.
	 * @return array Log entry.
	 */
	private function import_search( array $search, $page_size ) {
		$entry     = self::new_entry( $search['label'], $search['id'], $this->trigger );
		$state     = JOAWPJM_Settings::get_state();
		$sstate    = isset( $state['searches'][ $search['id'] ] ) ? (array) $state['searches'][ $search['id'] ] : array();
		$query     = self::build_query( $search, $sstate );
		$remaining = max( 1, min( 500, (int) $search['max_per_run'] ) );
		$cursor    = '';
		$started   = time();
		$ok        = true;
		// True when the run's record budget stopped the search before any request:
		// the search did not actually run, so its incremental window must not move.
		$budget_stopped = false;

		while ( $remaining > 0 ) {
			if ( $this->out_of_time() ) {
				$entry['notes'][] = __( 'Time budget reached; the next run continues.', 'job-opportunities-api-for-wp-job-manager' );
				break;
			}
			if ( null !== $this->record_budget && $this->record_budget <= 0 ) {
				$budget_stopped = true;
				$this->pacing_note( $entry );
				break;
			}
			$params = $query;
			$limit  = min( $remaining, $page_size );
			if ( null !== $this->record_budget ) {
				$limit = min( $limit, $this->record_budget );
			}
			$params['limit'] = $limit;
			if ( '' !== $cursor ) {
				$params['cursor'] = $cursor;
			}
			$result = $this->api->jobs( $params );
			if ( ! $result['ok'] ) {
				$entry['errors'][] = $this->handle_error( $result, __( 'Job search', 'job-opportunities-api-for-wp-job-manager' ) );
				$ok                = false;
				$this->spend_records( $result, 0 );
				break;
			}
			$rows              = ( isset( $result['data']['data'] ) && is_array( $result['data']['data'] ) ) ? $result['data']['data'] : array();
			$entry['fetched'] += count( $rows );
			$this->process_rows( $rows, $search, $entry );
			$remaining -= count( $rows );
			$this->spend_records( $result, count( $rows ) );

			$cursor = isset( $result['data']['next_cursor'] ) ? (string) $result['data']['next_cursor'] : '';
			if ( empty( $rows ) || empty( $result['data']['has_more'] ) || '' === $cursor ) {
				break;
			}
		}

		if ( $ok && ! $budget_stopped && ! $this->dry_run ) {
			$state = JOAWPJM_Settings::get_state();
			$state['searches'][ $search['id'] ]['last_success'] = $started;
			if ( empty( $state['expired_since'] ) ) {
				$state['expired_since'] = gmdate( 'Y-m-d\TH:i:s\Z', $started );
			}
			JOAWPJM_Settings::update_state( $state );
		}
		return $entry;
	}

	/**
	 * Creates or updates listings for one page of API rows.
	 *
	 * @param array $rows   API job rows.
	 * @param array $search Saved search.
	 * @param array $entry  Log entry (counters updated in place).
	 * @return void
	 */
	private function process_rows( array $rows, array $search, array &$entry ) {
		$ids    = array();
		$hashes = array();
		foreach ( $rows as $row ) {
			if ( is_array( $row ) && ! empty( $row['id'] ) ) {
				$ids[] = (string) $row['id'];
				$hash  = self::apply_hash( isset( $row['apply_url'] ) ? $row['apply_url'] : '' );
				if ( '' !== $hash ) {
					$hashes[] = $hash;
				}
			}
		}
		$by_id   = self::find_posts_by_meta( '_joawpjm_id', $ids );
		$by_hash = self::find_posts_by_meta( '_joawpjm_apply_hash', $hashes );

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || empty( $row['id'] ) || empty( $row['title'] ) ) {
				++$entry['skipped'];
				continue;
			}
			$jid  = (string) $row['id'];
			$hash = self::apply_hash( isset( $row['apply_url'] ) ? $row['apply_url'] : '' );

			$post_id = 0;
			if ( isset( $this->seen_ids[ $jid ] ) ) {
				$post_id = $this->seen_ids[ $jid ];
			} elseif ( isset( $by_id[ $jid ] ) ) {
				$post_id = $by_id[ $jid ];
			}

			if ( ! $post_id && '' !== $hash && ( isset( $this->seen_hashes[ $hash ] ) || isset( $by_hash[ $hash ] ) ) ) {
				// Same role (same employer apply link) already imported under another id.
				++$entry['skipped'];
				continue;
			}

			if ( $post_id ) {
				$outcome = $this->update_listing( $post_id, $row, $search );
			} else {
				$post_id = $this->create_listing( $row, $search );
				$outcome = $post_id ? 'created' : 'failed';
			}

			if ( 'failed' === $outcome ) {
				/* translators: %s: JOA job id. */
				$entry['errors'][] = sprintf( __( 'Could not save job %s.', 'job-opportunities-api-for-wp-job-manager' ), $jid );
				continue;
			}
			++$entry[ $outcome ];
			if ( $post_id ) {
				$this->seen_ids[ $jid ] = $post_id;
				if ( '' !== $hash ) {
					$this->seen_hashes[ $hash ] = $post_id;
				}
			}
		}
	}

	/**
	 * Creates a listing.
	 *
	 * @param array $row    API row.
	 * @param array $search Saved search.
	 * @return int Post id (a fake positive id in dry-run mode), 0 on failure.
	 */
	private function create_listing( array $row, array $search ) {
		if ( $this->dry_run ) {
			return -1;
		}
		$mapped = $this->map_row( $row, $search, true );

		$postarr                                        = $mapped['post'];
		$postarr['post_type']                           = 'job_listing';
		$postarr['meta_input']                          = $mapped['meta'];
		$postarr['meta_input']['_joawpjm_hash']         = $mapped['hash'];
		$postarr['meta_input']['_joawpjm_last_checked'] = time();
		$postarr['meta_input']['_joawpjm_imported_at']  = time();

		$post_id = wp_insert_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return 0;
		}
		$this->set_terms( $post_id, $mapped );

		/**
		 * Fires after a listing was created from a JOA row.
		 *
		 * @param int   $post_id Listing id.
		 * @param array $row     API row.
		 * @param array $search  Saved search.
		 */
		do_action( 'joawpjm_listing_created', $post_id, $row, $search );
		return (int) $post_id;
	}

	/**
	 * Updates an existing listing when the JOA row changed.
	 *
	 * @param int   $post_id Listing id.
	 * @param array $row     API row.
	 * @param array $search  Saved search.
	 * @return string updated|unchanged|skipped
	 */
	private function update_listing( $post_id, array $row, array $search ) {
		if ( $post_id < 0 ) {
			return 'skipped'; // Created earlier in this dry run.
		}
		$post = get_post( $post_id );
		if ( ! $post || 'trash' === $post->post_status ) {
			return 'skipped'; // Respect an admin who trashed the listing.
		}

		$mapped     = $this->map_row( $row, $search, false );
		$was_closed = ( 'closed' === get_post_meta( $post_id, '_joawpjm_status', true ) && 'expired' === $post->post_status );
		$locked     = (bool) get_post_meta( $post_id, '_joawpjm_locked', true );
		$changed    = ( get_post_meta( $post_id, '_joawpjm_hash', true ) !== $mapped['hash'] );

		if ( $this->dry_run ) {
			return ( ! $locked && ( $changed || $was_closed ) ) ? 'updated' : ( $locked ? 'skipped' : 'unchanged' );
		}

		update_post_meta( $post_id, '_joawpjm_last_seen', time() );
		update_post_meta( $post_id, '_joawpjm_last_checked', time() );

		if ( $locked ) {
			return 'skipped';
		}
		if ( ! $changed && ! $was_closed ) {
			return 'unchanged';
		}

		$postarr                                = $mapped['post'];
		$postarr['ID']                          = $post_id;
		$postarr['meta_input']                  = $mapped['meta'];
		$postarr['meta_input']['_joawpjm_hash'] = $mapped['hash'];
		unset( $postarr['post_date'], $postarr['post_date_gmt'], $postarr['post_status'], $postarr['post_author'] );

		if ( $was_closed ) {
			// The role is live again (a withdrawn row can come back): re-list it.
			$postarr['post_status'] = self::default_status();
			if ( empty( $row['expires_at'] ) ) {
				$postarr['meta_input']['_job_expires'] = gmdate( 'Y-m-d', time() + self::duration_days() * DAY_IN_SECONDS );
			}
		}

		$result = wp_update_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $result ) || ! $result ) {
			return 'failed';
		}
		$this->set_terms( $post_id, $mapped );

		/**
		 * Fires after a listing was updated from a JOA row.
		 *
		 * @param int   $post_id Listing id.
		 * @param array $row     API row.
		 * @param array $search  Saved search.
		 */
		do_action( 'joawpjm_listing_updated', $post_id, $row, $search );
		return 'updated';
	}

	/**
	 * Maps an API row to post fields, meta and terms.
	 *
	 * @param array $row    API row.
	 * @param array $search Saved search.
	 * @param bool  $is_new Whether the listing is being created.
	 * @return array {post: array, meta: array, job_types: int[], categories: int[], hash: string}
	 */
	public function map_row( array $row, array $search, $is_new ) {
		$str = function ( $key ) use ( $row ) {
			return ( isset( $row[ $key ] ) && is_scalar( $row[ $key ] ) ) ? trim( (string) $row[ $key ] ) : '';
		};

		$apply_url     = esc_url_raw( $str( 'apply_url' ) );
		$canonical_url = esc_url_raw( $str( 'canonical_url' ) );
		$application   = ( '' !== $canonical_url ) ? $canonical_url : $apply_url;

		// Dates: posted_at, else first_seen_at, never in the future.
		$posted = strtotime( $str( 'posted_at' ) );
		if ( ! $posted ) {
			$posted = strtotime( $str( 'first_seen_at' ) );
		}
		if ( ! $posted || $posted > time() ) {
			$posted = time();
		}

		$location = $str( 'location' );
		if ( '' === $location ) {
			$location = implode( ', ', array_filter( array( $str( 'city' ), $str( 'region' ), $str( 'country' ) ) ) );
		}

		$post = array(
			'post_title'    => sanitize_text_field( $str( 'title' ) ),
			'post_content'  => self::build_content( $row, $application ),
			'post_status'   => self::default_status(),
			'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $posted ),
			'post_date'     => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $posted ) ),
			'post_author'   => (int) $search['author'],
		);

		$meta = array(
			'_job_location'          => sanitize_text_field( $location ),
			'_application'           => $application,
			'_company_name'          => sanitize_text_field( $str( 'company' ) ),
			'_company_website'       => esc_url_raw( $str( 'employer_website' ) ),
			'_remote_position'       => ( 'remote' === $str( 'remote' ) ) ? 1 : 0,
			'_joawpjm_id'            => sanitize_text_field( $str( 'id' ) ),
			'_joawpjm_slug'          => sanitize_text_field( $str( 'slug' ) ),
			'_joawpjm_source'        => sanitize_text_field( $str( 'source' ) ),
			'_joawpjm_source_type'   => sanitize_text_field( $str( 'source_type' ) ),
			'_joawpjm_remote'        => sanitize_key( $str( 'remote' ) ),
			'_joawpjm_status'        => 'live',
			'_joawpjm_canonical_url' => $canonical_url,
			'_joawpjm_attribution'   => sanitize_text_field( $str( 'attribution' ) ),
			'_joawpjm_company_logo'  => esc_url_raw( $str( 'company_logo' ) ),
			'_joawpjm_apply_hash'    => self::apply_hash( $str( 'apply_url' ) ),
		);

		$expires = strtotime( $str( 'expires_at' ) );
		if ( $expires ) {
			$meta['_job_expires'] = gmdate( 'Y-m-d', $expires );
		}

		$meta = array_merge( $meta, self::salary_meta( $row ) );

		// Hash only what comes from the API so unchanged rows are not rewritten.
		$hash = md5( (string) wp_json_encode( array( $post['post_title'], $post['post_content'], $meta ) ) );

		$meta['_joawpjm_last_seen'] = time();
		if ( $is_new ) {
			if ( ! $expires ) {
				// Post date + default duration, but never already in the past for a role that is live today.
				$meta['_job_expires'] = gmdate( 'Y-m-d', max( $posted + self::duration_days() * DAY_IN_SECONDS, time() + DAY_IN_SECONDS ) );
			}
			$meta['_filled']         = 0;
			$meta['_featured']       = 0;
			$meta['_joawpjm_search'] = sanitize_text_field( $search['id'] );
		}

		return array(
			'post'       => $post,
			'meta'       => $meta,
			'job_types'  => self::job_type_terms( $search, $str( 'employment_type' ) ),
			'categories' => self::category_terms( $search ),
			'hash'       => $hash,
		);
	}

	/**
	 * Converts the plain-text advert to safe HTML.
	 *
	 * @param array  $row         API row.
	 * @param string $application Application URL.
	 * @return string
	 */
	public static function build_content( array $row, $application ) {
		$text = ( JOAWPJM_Settings::get( 'keep_descriptions' ) && isset( $row['description'] ) && is_string( $row['description'] ) ) ? $row['description'] : '';
		// The text is plain, but some sources leave HTML entities such as &nbsp; in it.
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( "\xC2\xA0", ' ', $text );
		$text = trim( preg_replace( "/[ \t]+/", ' ', $text ) );

		if ( '' !== $text ) {
			return wp_kses_post( wpautop( esc_html( $text ) ) );
		}

		$line = esc_html__( 'The full advert for this role is on the employer’s own website.', 'job-opportunities-api-for-wp-job-manager' );
		if ( '' !== $application ) {
			$line .= ' <a href="' . esc_url( $application ) . '">' . esc_html__( 'Read the advert and apply', 'job-opportunities-api-for-wp-job-manager' ) . '</a>';
		}
		return '<p>' . $line . '</p>';
	}

	/**
	 * Salary meta when the salary is published by the employer.
	 *
	 * @param array $row API row.
	 * @return array
	 */
	public static function salary_meta( array $row ) {
		$fields = ( isset( $row['field_sources'] ) && is_array( $row['field_sources'] ) ) ? $row['field_sources'] : array();
		if ( isset( $fields['salary'] ) && 'published' !== $fields['salary'] ) {
			return array();
		}
		$min = ( isset( $row['salary_min'] ) && is_numeric( $row['salary_min'] ) ) ? (float) $row['salary_min'] : 0;
		$max = ( isset( $row['salary_max'] ) && is_numeric( $row['salary_max'] ) ) ? (float) $row['salary_max'] : 0;
		if ( $min <= 0 && $max <= 0 ) {
			return array();
		}
		$format = function ( $n ) {
			return ( floor( $n ) === $n ) ? (string) (int) $n : (string) round( $n, 2 );
		};
		if ( $min > 0 && $max > $min ) {
			$salary = $format( $min ) . '–' . $format( $max );
		} else {
			$salary = $format( $min > 0 ? $min : $max );
		}
		$units = array(
			'hour'  => 'HOUR',
			'day'   => 'DAY',
			'week'  => 'WEEK',
			'month' => 'MONTH',
			'year'  => 'YEAR',
		);
		$meta  = array( '_job_salary' => $salary );
		if ( ! empty( $row['salary_currency'] ) && is_string( $row['salary_currency'] ) ) {
			$meta['_job_salary_currency'] = strtoupper( sanitize_text_field( $row['salary_currency'] ) );
		}
		if ( ! empty( $row['salary_period'] ) && isset( $units[ $row['salary_period'] ] ) ) {
			$meta['_job_salary_unit'] = $units[ $row['salary_period'] ];
		}
		return $meta;
	}

	/**
	 * Job type terms for a row.
	 *
	 * @param array  $search          Saved search.
	 * @param string $employment_type JOA employment type.
	 * @return int[]
	 */
	public static function job_type_terms( array $search, $employment_type ) {
		if ( ! taxonomy_exists( 'job_listing_type' ) || empty( $search['job_type'] ) ) {
			return array();
		}
		if ( 'auto' !== $search['job_type'] ) {
			$term = get_term( (int) $search['job_type'], 'job_listing_type' );
			return ( $term && ! is_wp_error( $term ) ) ? array( (int) $term->term_id ) : array();
		}
		$wanted = self::normalize_name( $employment_type );
		if ( '' === $wanted ) {
			return array();
		}
		$aliases    = array(
			'fulltime'   => array( 'fulltime' ),
			'parttime'   => array( 'parttime' ),
			'contract'   => array( 'contract', 'contractor' ),
			'temporary'  => array( 'temporary', 'temp' ),
			'internship' => array( 'internship', 'intern' ),
			'freelance'  => array( 'freelance', 'freelancer' ),
		);
		$candidates = isset( $aliases[ $wanted ] ) ? $aliases[ $wanted ] : array( $wanted );
		$terms      = get_terms(
			array(
				'taxonomy'   => 'job_listing_type',
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $terms ) ) {
			return array();
		}
		foreach ( $terms as $term ) {
			if ( in_array( self::normalize_name( $term->name ), $candidates, true ) || in_array( self::normalize_name( $term->slug ), $candidates, true ) ) {
				return array( (int) $term->term_id );
			}
		}
		return array();
	}

	/**
	 * Category terms for a search (only when WPJM categories are enabled).
	 *
	 * @param array $search Saved search.
	 * @return int[]
	 */
	public static function category_terms( array $search ) {
		if ( empty( $search['job_category'] ) || ! taxonomy_exists( 'job_listing_category' ) || ! get_option( 'job_manager_enable_categories' ) ) {
			return array();
		}
		$term = get_term( (int) $search['job_category'], 'job_listing_category' );
		return ( $term && ! is_wp_error( $term ) ) ? array( (int) $term->term_id ) : array();
	}

	/**
	 * Lowercase letters only ("Full-time" and "Full Time" -> "fulltime").
	 *
	 * @param string $name Name.
	 * @return string
	 */
	private static function normalize_name( $name ) {
		return preg_replace( '/[^a-z]/', '', strtolower( (string) $name ) );
	}

	/**
	 * Assigns mapped terms.
	 *
	 * @param int   $post_id Listing id.
	 * @param array $mapped  Result of map_row().
	 * @return void
	 */
	private function set_terms( $post_id, array $mapped ) {
		if ( ! empty( $mapped['job_types'] ) ) {
			wp_set_object_terms( $post_id, $mapped['job_types'], 'job_listing_type' );
		}
		if ( ! empty( $mapped['categories'] ) ) {
			wp_set_object_terms( $post_id, $mapped['categories'], 'job_listing_category' );
		}
	}

	/**
	 * Expires imported listings of roles JOA reports as closed.
	 *
	 * Growth plans and above: the cheap /v1/jobs/expired feed. Other plans
	 * (403 on the feed, remembered for 24 hours): a few per-listing checks per run.
	 *
	 * @return array Log entry.
	 */
	private function process_closures() {
		$entry = self::new_entry( __( 'Closure checks', 'job-opportunities-api-for-wp-job-manager' ), '', $this->trigger );

		if ( ! get_transient( JOAWPJM_Settings::NO_FEED_TRANSIENT ) ) {
			$outcome = $this->poll_expired_feed( $entry );
			if ( 'unavailable' !== $outcome ) {
				return $entry;
			}
			set_transient( JOAWPJM_Settings::NO_FEED_TRANSIENT, 1, DAY_IN_SECONDS );
		}

		$this->check_listings( $entry );
		return $entry;
	}

	/**
	 * Polls /v1/jobs/expired from the stored cursor.
	 *
	 * @param array $entry Log entry.
	 * @return string ok|error|unavailable
	 */
	private function poll_expired_feed( array &$entry ) {
		$state = JOAWPJM_Settings::get_state();
		$since = (string) $state['expired_since'];
		if ( '' === $since ) {
			return 'ok'; // Nothing imported yet.
		}

		for ( $page = 0; $page < self::EXPIRED_MAX_PAGES; $page++ ) {
			if ( $this->out_of_time() ) {
				$entry['notes'][] = __( 'Time budget reached; the next run continues.', 'job-opportunities-api-for-wp-job-manager' );
				break;
			}
			$result = $this->api->expired( $since, self::EXPIRED_PAGE );
			// The closure feed is not part of the record budget; just remember its allowance headers.
			$this->remember_records( $result );
			if ( ! $result['ok'] ) {
				if ( 403 === $result['code'] ) {
					return 'unavailable';
				}
				$entry['errors'][] = $this->handle_error( $result, __( 'Closure feed', 'job-opportunities-api-for-wp-job-manager' ) );
				return 'error';
			}
			$rows = ( isset( $result['data']['data'] ) && is_array( $result['data']['data'] ) ) ? $result['data']['data'] : array();
			$ids  = array();
			foreach ( $rows as $row ) {
				if ( is_array( $row ) && ! empty( $row['id'] ) ) {
					$ids[ (string) $row['id'] ] = isset( $row['closed_reason'] ) ? (string) $row['closed_reason'] : '';
				}
			}
			$entry['fetched'] += count( $ids );
			foreach ( array_chunk( array_keys( $ids ), 200 ) as $chunk ) {
				foreach ( self::find_posts_by_meta( '_joawpjm_id', $chunk ) as $jid => $post_id ) {
					if ( $this->expire_listing( $post_id, $ids[ $jid ] ) ) {
						++$entry['expired'];
					}
				}
			}

			// An empty page echoes the cursor back: we are current.
			$next     = isset( $result['data']['next_since'] ) ? (string) $result['data']['next_since'] : '';
			$advanced = ( '' !== $next && $next !== $since );
			if ( $advanced ) {
				$since                  = $next;
				$state                  = JOAWPJM_Settings::get_state();
				$state['expired_since'] = $since;
				JOAWPJM_Settings::update_state( $state );
			}
			if ( ! $advanced || count( $rows ) < self::EXPIRED_PAGE ) {
				break;
			}
		}
		return 'ok';
	}

	/**
	 * Per-listing closure checks for plans without the closure feed: the N
	 * listings checked longest ago, via GET /v1/jobs/{id}?include_closed=true.
	 *
	 * @param array $entry Log entry.
	 * @return void
	 */
	private function check_listings( array &$entry ) {
		$limit = max( 0, min( 500, (int) JOAWPJM_Settings::get( 'closure_checks' ) ) );
		if ( 0 === $limit ) {
			return;
		}
		$post_ids = get_posts(
			array(
				'post_type'      => 'job_listing',
				'post_status'    => array( 'publish', 'pending' ),
				'fields'         => 'ids',
				'posts_per_page' => $limit,
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded by $limit; only imported listings carry this key.
				'meta_query'     => array(
					'checked' => array(
						'key'     => '_joawpjm_last_checked',
						'compare' => 'EXISTS',
						'type'    => 'NUMERIC',
					),
				),
				'orderby'        => array( 'checked' => 'ASC' ),
			)
		);

		foreach ( $post_ids as $post_id ) {
			if ( $this->stopped || $this->out_of_time() ) {
				break;
			}
			if ( null !== $this->record_budget && $this->record_budget <= 0 ) {
				$this->pacing_note( $entry );
				break;
			}
			$jid = (string) get_post_meta( $post_id, '_joawpjm_id', true );
			if ( '' === $jid ) {
				continue;
			}
			$result = $this->api->job( $jid );
			$this->spend_records( $result, $result['ok'] ? 1 : 0 );
			++$entry['fetched'];
			if ( $result['ok'] ) {
				$job = ( isset( $result['data']['data'] ) && is_array( $result['data']['data'] ) ) ? $result['data']['data'] : array();
				if ( isset( $job['status'] ) && 'closed' === $job['status'] ) {
					if ( $this->expire_listing( $post_id, isset( $job['closed_reason'] ) ? (string) $job['closed_reason'] : 'closed' ) ) {
						++$entry['expired'];
					}
				}
			} elseif ( 404 === $result['code'] || 410 === $result['code'] ) {
				if ( $this->expire_listing( $post_id, ( 410 === $result['code'] ) ? 'withdrawn' : 'not_found' ) ) {
					++$entry['expired'];
				}
			} else {
				$entry['errors'][] = $this->handle_error( $result, __( 'Closure check', 'job-opportunities-api-for-wp-job-manager' ) );
				if ( $this->stopped ) {
					break;
				}
				continue;
			}
			update_post_meta( $post_id, '_joawpjm_last_checked', time() );
		}
	}

	/**
	 * Marks a listing expired because JOA reports the role closed. Applies to
	 * admin-locked listings too: a closed role must not stay open.
	 *
	 * @param int    $post_id Listing id.
	 * @param string $reason  Closure reason.
	 * @return bool True when the listing was expired now.
	 */
	public function expire_listing( $post_id, $reason ) {
		if ( $this->dry_run ) {
			return false;
		}
		$post = get_post( $post_id );
		if ( ! $post || 'job_listing' !== $post->post_type || 'trash' === $post->post_status ) {
			return false;
		}
		update_post_meta( $post_id, '_joawpjm_status', 'closed' );
		update_post_meta( $post_id, '_joawpjm_closed_reason', sanitize_key( $reason ) );
		update_post_meta( $post_id, '_joawpjm_last_checked', time() );
		if ( 'expired' === $post->post_status ) {
			return false;
		}
		$result = wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'expired',
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			return false;
		}
		/**
		 * Fires after a listing was expired because JOA reports the role closed.
		 *
		 * @param int    $post_id Listing id.
		 * @param string $reason  Closure reason.
		 */
		do_action( 'joawpjm_listing_expired', $post_id, $reason );
		return true;
	}

	/**
	 * Turns an API error into a log message and decides whether the run stops.
	 *
	 * @param array  $result  Result of JOAWPJM_API::request().
	 * @param string $context Short label of the failing call.
	 * @return string Message for the log (never contains the key).
	 */
	private function handle_error( array $result, $context ) {
		$code = (int) $result['code'];
		switch ( true ) {
			case 401 === $code:
				update_option( JOAWPJM_Settings::KEY_INVALID_OPTION, 1, false );
				delete_transient( JOAWPJM_Settings::ME_TRANSIENT );
				$this->stopped = true;
				break;
			case 429 === $code:
				$wait = max( 1, (int) $result['retry_after'] );
				set_transient( JOAWPJM_Settings::RETRY_TRANSIENT, time() + $wait, $wait );
				$this->stopped = true;
				break;
			case 402 === $code:
			case 403 === $code:
			case $code >= 500:
			case 0 === $code:
			case 'invalid_json' === $result['error']:
				$this->stopped = true;
				break;
		}
		$message = sprintf(
			/* translators: 1: what was being done, 2: HTTP status code, 3: error message. */
			__( '%1$s failed (HTTP %2$d): %3$s', 'job-opportunities-api-for-wp-job-manager' ),
			$context,
			$code,
			$result['message']
		);
		if ( 429 === $code ) {
			/* translators: %d: number of seconds. */
			$message .= ' ' . sprintf( __( 'Paused for %d seconds.', 'job-opportunities-api-for-wp-job-manager' ), (int) $result['retry_after'] );
		}
		if ( $this->stopped ) {
			$message .= ' ' . __( 'Run stopped.', 'job-opportunities-api-for-wp-job-manager' );
		}
		return $message;
	}

	/**
	 * Whether the time budget is used up.
	 *
	 * @return bool
	 */
	private function out_of_time() {
		return $this->deadline > 0 && microtime( true ) >= $this->deadline;
	}

	/**
	 * This run's share of the key's monthly record allowance, from the last
	 * allowance the API reported (run state "records").
	 *
	 * No budget (null) when the allowance is unknown, the key is unmetered,
	 * or the monthly period already reset. A run started by hand (Run import
	 * now, WP-CLI) may use everything that is left; a scheduled run gets an
	 * equal share of what is left across the remaining runs of the period.
	 *
	 * @param string $trigger Run trigger (cron|manual|cli).
	 * @return int|null
	 */
	private function run_budget( $trigger ) {
		$state   = JOAWPJM_Settings::get_state();
		$records = ( isset( $state['records'] ) && is_array( $state['records'] ) ) ? $state['records'] : null;
		if ( ! is_array( $records ) || ! isset( $records['limit'] ) || -1 === (int) $records['limit'] ) {
			return null; // No allowance known yet, or an unmetered key.
		}
		$remaining = ( isset( $records['remaining'] ) && is_numeric( $records['remaining'] ) ) ? (int) $records['remaining'] : null;
		if ( null === $remaining ) {
			return null;
		}
		$reset = ( isset( $records['reset'] ) && is_numeric( $records['reset'] ) ) ? (int) $records['reset'] : 0;
		if ( $reset <= time() ) {
			return null; // A new monthly period starts: pace from the fresh numbers.
		}
		if ( 'cron' !== $trigger ) {
			return max( 0, $remaining );
		}
		$intervals = array(
			'hourly'     => HOUR_IN_SECONDS,
			'twicedaily' => 12 * HOUR_IN_SECONDS,
			'daily'      => DAY_IN_SECONDS,
		);
		$schedule  = (string) JOAWPJM_Settings::get( 'schedule' );
		$interval  = isset( $intervals[ $schedule ] ) ? $intervals[ $schedule ] : 12 * HOUR_IN_SECONDS;
		$runs_left = max( 1, (int) ceil( ( $reset - time() ) / $interval ) );
		return (int) floor( $remaining / $runs_left );
	}

	/**
	 * Adds the one log note for a loop that reached the run's record budget.
	 *
	 * @param array $entry Log entry.
	 * @return void
	 */
	private function pacing_note( array &$entry ) {
		$reset = ( is_array( $this->records ) && isset( $this->records['reset'] ) && (int) $this->records['reset'] > 0 ) ? (int) $this->records['reset'] : 0;
		if ( $reset > 0 ) {
			$entry['notes'][] = sprintf(
				/* translators: 1: records available to this run, 2: date and time the monthly record allowance resets. */
				__( 'Pacing this key’s monthly record allowance: %1$d records were available to this run and they are used. The allowance resets on %2$s.', 'job-opportunities-api-for-wp-job-manager' ),
				$this->record_budget_total,
				date_i18n( 'Y-m-d H:i', $reset )
			);
		} else {
			$entry['notes'][] = sprintf(
				/* translators: %d: records available to this run. */
				__( 'Pacing this key’s monthly record allowance: %d records were available to this run and they are used.', 'job-opportunities-api-for-wp-job-manager' ),
				$this->record_budget_total
			);
		}
	}

	/**
	 * Remembers the latest allowance reported by an API response and, when a
	 * record budget applies, spends the rows the response actually returned.
	 *
	 * @param array $result Result of JOAWPJM_API::request().
	 * @param int   $rows   Rows the response returned.
	 * @return void
	 */
	private function spend_records( array $result, $rows ) {
		$this->remember_records( $result );
		if ( null !== $this->record_budget ) {
			$this->record_budget = max( 0, $this->record_budget - max( 0, (int) $rows ) );
		}
	}

	/**
	 * Remembers the latest allowance reported by an API response.
	 *
	 * @param array $result Result of JOAWPJM_API::request().
	 * @return void
	 */
	private function remember_records( array $result ) {
		if ( isset( $result['records'] ) && is_array( $result['records'] ) ) {
			$this->records = $result['records'];
		}
	}

	/**
	 * Saves the latest known record allowance into the run state, for the
	 * next run's budget and the settings page.
	 *
	 * @return void
	 */
	private function save_records_state() {
		if ( ! is_array( $this->records ) ) {
			return;
		}
		// Re-read the state: the searches saved their last success during the run.
		$state            = JOAWPJM_Settings::get_state();
		$state['records'] = $this->records;
		JOAWPJM_Settings::update_state( $state );
	}

	/**
	 * A fresh log entry.
	 *
	 * @param string $label   Search label.
	 * @param string $id      Search id.
	 * @param string $trigger Trigger.
	 * @return array
	 */
	private static function new_entry( $label, $id, $trigger ) {
		return array(
			'time'      => time(),
			'trigger'   => $trigger,
			'search'    => (string) $label,
			'search_id' => (string) $id,
			'fetched'   => 0,
			'created'   => 0,
			'updated'   => 0,
			'unchanged' => 0,
			'skipped'   => 0,
			'expired'   => 0,
			'errors'    => array(),
			'notes'     => array(),
		);
	}

	/**
	 * Post status for new listings.
	 *
	 * @return string
	 */
	private static function default_status() {
		return ( 'pending' === JOAWPJM_Settings::get( 'post_status' ) ) ? 'pending' : 'publish';
	}

	/**
	 * Default listing duration in days.
	 *
	 * @return int
	 */
	private static function duration_days() {
		return max( 1, (int) JOAWPJM_Settings::get( 'duration' ) );
	}

	/**
	 * Hash of a normalised apply URL (secondary de-duplication key).
	 *
	 * @param string $url Apply URL.
	 * @return string '' when there is no URL.
	 */
	public static function apply_hash( $url ) {
		$url = strtolower( trim( (string) $url ) );
		if ( '' === $url ) {
			return '';
		}
		$url = preg_replace( '#^https?://(www\.)?#', '', $url );
		return md5( untrailingslashit( $url ) );
	}

	/**
	 * Finds job listings by a meta value, any status (trash included, so a
	 * listing an admin trashed is not imported again).
	 *
	 * @param string $key    Meta key.
	 * @param array  $values Meta values.
	 * @return array value => post id
	 */
	public static function find_posts_by_meta( $key, array $values ) {
		$values = array_values( array_unique( array_filter( array_map( 'strval', $values ) ) ) );
		if ( empty( $values ) ) {
			return array();
		}
		$post_ids = get_posts(
			array(
				'post_type'      => 'job_listing',
				'post_status'    => array_values( array_diff( get_post_stati(), array( 'auto-draft', 'inherit' ) ) ),
				'fields'         => 'ids',
				'posts_per_page' => count( $values ) * 3,
				'no_found_rows'  => true,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one batched lookup per page of results.
				'meta_query'     => array(
					array(
						'key'     => $key,
						'value'   => $values,
						'compare' => 'IN',
					),
				),
			)
		);
		if ( empty( $post_ids ) ) {
			return array();
		}
		update_meta_cache( 'post', $post_ids );
		$found = array();
		foreach ( $post_ids as $post_id ) {
			$value = (string) get_post_meta( $post_id, $key, true );
			if ( '' !== $value && ! isset( $found[ $value ] ) ) {
				$found[ $value ] = (int) $post_id;
			}
		}
		return $found;
	}

	/**
	 * Takes the run lock. Uses a single INSERT IGNORE on the options table so
	 * two overlapping runs (cron + manual, or two cron spawns) cannot both win.
	 *
	 * @return bool
	 */
	public static function acquire_lock() {
		global $wpdb;
		$now = time();
		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic lock; must bypass the object cache.
			$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", JOAWPJM_Settings::LOCK_OPTION, (string) $now ) );
			if ( 1 === (int) $inserted ) {
				wp_cache_delete( JOAWPJM_Settings::LOCK_OPTION, 'options' );
				wp_cache_delete( 'notoptions', 'options' );
				return true;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic lock; must bypass the object cache.
			$taken = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", JOAWPJM_Settings::LOCK_OPTION ) );
			if ( $taken && $taken > $now - self::LOCK_TTL ) {
				return false;
			}
			// Stale lock (a run that died): remove exactly that one and retry.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic lock; must bypass the object cache.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", JOAWPJM_Settings::LOCK_OPTION, (string) $taken ) );
		}
		return false;
	}

	/**
	 * Releases the run lock.
	 *
	 * @return void
	 */
	public static function release_lock() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic lock; must bypass the object cache.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", JOAWPJM_Settings::LOCK_OPTION ) );
		wp_cache_delete( JOAWPJM_Settings::LOCK_OPTION, 'options' );
	}

	/**
	 * Whether a run currently holds the lock.
	 *
	 * @return bool
	 */
	public static function is_locked() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- lock state must not come from a cache.
		$taken = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", JOAWPJM_Settings::LOCK_OPTION ) );
		return $taken > time() - self::LOCK_TTL;
	}
}
