<?php
/**
 * Road to position 1, one search at a time.
 *
 * Every search the site appears for in Google (Search Console: real positions, an average over
 * the last 28 days, about 2–3 days behind) with an honest chance of reaching #1 computed from
 * the numbers — not from hope:
 *   won (≤1.5) · close (≤3.5) · realistic (≤10.5) · hard (≤20.5) · long shot (beyond, or almost
 *   no demand). The list is ordered by what #1 would really bring (estimated extra clicks at a
 *   typical #1 click rate, weighted by the chance and the buying intent of the search).
 *
 * The owner focuses on ONE search: the AI compares the page with the result that ranks first
 * (live Google results when a results source is set up, else the last imported ones), gives an
 * honest verdict, the few steps that move this search and what is not worth the time, and each
 * step can be prepared for review with one click. Progress and wins are only claimed from
 * Search Console data after the focus started.
 *
 * @package SEOlity
 */

namespace SEOlity\Rank;

use SEOlity\Activity\Activity;
use SEOlity\Core\Database;
use SEOlity\GSC\Analytics;
use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class RankOne {

	const OPTION   = 'seolity_rank1';
	const PLANS    = 'seolity_rank1_plans';
	const MIN_IMPR = 10;

	/** Chance weight per verdict (how likely the work pays off). */
	const CHANCE = array( 'won' => 0.1, 'close' => 1.0, 'realistic' => 0.75, 'hard' => 0.4, 'long_shot' => 0.12 );

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	private function pid() {
		return $this->plugin->db->project_id();
	}

	private function state() {
		$s = get_option( self::OPTION, array() );
		return is_array( $s ) ? array_merge( array( 'focus' => null, 'done' => array(), 'skipped' => array() ), $s ) : array( 'focus' => null, 'done' => array(), 'skipped' => array() );
	}

	private function save_state( array $s ) {
		update_option( self::OPTION, $s, false );
	}

	/** Honest chance of #1 from the real average position and the demand. */
	public static function chance( $position, $impressions ) {
		if ( $position <= 1.5 ) {
			return 'won';
		}
		if ( $impressions < 30 && $position > 3.5 ) {
			return $position <= 10.5 ? 'hard' : 'long_shot';
		}
		if ( $position <= 3.5 ) {
			return 'close';
		}
		if ( $position <= 10.5 ) {
			return 'realistic';
		}
		return $position <= 20.5 ? 'hard' : 'long_shot';
	}

	/** Search intent from the words people use (Persian and English). */
	public static function intent( $query ) {
		$q = mb_strtolower( (string) $query );
		if ( preg_match( '/(خرید|قیمت|فروش|سفارش|ارزان|تخفیف|فروشگاه|buy|price|shop|order|cheap|deal)/u', $q ) ) {
			return 'buy';
		}
		if ( preg_match( '/(بهترین|مقایسه|فرق|بررسی|نقد|best|vs|compare|review)/u', $q ) ) {
			return 'compare';
		}
		return 'learn';
	}

	/** All searches with real positions, chance and value, best opportunities first. */
	public function board() {
		$an = $this->plugin->gsc_analytics;
		if ( ! $this->plugin->gsc_oauth->is_connected() || ! $an->has_data() ) {
			return array( 'connected' => $this->plugin->gsc_oauth->is_connected(), 'items' => array(), 'stats' => null, 'focus' => null );
		}
		list( $cs, $ce, $ps, $pe ) = $an->periods( 28 );
		$db   = $this->plugin->db;
		$rows = $db->rows( 'SELECT query_hash h, MAX(query) q, MAX(page) page, SUM(clicks) c, SUM(impressions) i, SUM(position*impressions) pi FROM {gsc_page_query} WHERE project_id = %d AND date BETWEEN %s AND %s GROUP BY query_hash, page_hash', array( $this->pid(), $cs, $ce ) );
		$q    = array();
		foreach ( $rows as $r ) {
			$h = $r['h'];
			if ( ! isset( $q[ $h ] ) ) {
				$q[ $h ] = array( 'query' => $r['q'], 'clicks' => 0, 'impressions' => 0, 'pi' => 0.0, 'pages' => array() );
			}
			$q[ $h ]['clicks']      += (int) $r['c'];
			$q[ $h ]['impressions'] += (int) $r['i'];
			$q[ $h ]['pi']          += (float) $r['pi'];
			$q[ $h ]['pages'][]      = array( 'url' => $r['page'], 'impressions' => (int) $r['i'] );
		}
		$prev = array();
		foreach ( $db->rows( 'SELECT query_hash h, SUM(position*impressions)/NULLIF(SUM(impressions),0) p FROM {gsc_page_query} WHERE project_id = %d AND date BETWEEN %s AND %s GROUP BY query_hash', array( $this->pid(), $ps, $pe ) ) as $r ) {
			$prev[ $r['h'] ] = round( (float) $r['p'], 1 );
		}
		$st    = $this->state();
		$done  = array_column( (array) $st['done'], 'query' );
		$brand = array_filter( preg_split( '/[\s\-_.]+/u', mb_strtolower( get_bloginfo( 'name' ) . ' ' . wp_parse_url( home_url(), PHP_URL_HOST ) ) ), static function ( $w ) {
			return mb_strlen( $w ) >= 3 && ! in_array( $w, array( 'www', 'com', 'net', 'org' ), true );
		} );
		$items = array();
		$stats = array( 'searches' => 0, 'won' => 0, 'top3' => 0, 'climbed' => 0, 'best_climb' => null );
		foreach ( $q as $h => $x ) {
			if ( $x['impressions'] < self::MIN_IMPR ) {
				continue;
			}
			$pos = $x['impressions'] ? round( $x['pi'] / $x['impressions'], 1 ) : 0;
			usort(
				$x['pages'],
				static function ( $a, $b ) {
					return $b['impressions'] <=> $a['impressions'];
				}
			);
			$competing = count(
				array_filter(
					$x['pages'],
					static function ( $p ) use ( $x ) {
						return $p['impressions'] >= 0.2 * $x['impressions'];
					}
				)
			);
			$chance  = self::chance( $pos, $x['impressions'] );
			$intent  = self::intent( $x['query'] );
			$at_one  = (int) round( $x['impressions'] * Analytics::CTR_CURVE[1] );
			$gain    = max( 0, $at_one - $x['clicks'] );
			$is_brand = false;
			foreach ( $brand as $w ) {
				if ( false !== mb_strpos( mb_strtolower( $x['query'] ), $w ) ) {
					$is_brand = true;
				}
			}
			$priority = (int) round( $gain * self::CHANCE[ $chance ] * ( 'buy' === $intent ? 1.5 : ( 'compare' === $intent ? 1.2 : 1 ) ) * ( $is_brand ? 0.3 : 1 ) );
			$p0       = isset( $prev[ $h ] ) ? $prev[ $h ] : null;
			$rule     = $this->plugin->site_rules->owner_of( $x['query'] );
			$items[]  = array(
				'query'         => $x['query'],
				'page'          => $rule ? $rule['url'] : $x['pages'][0]['url'],
				'rule'          => $rule ? $rule['id'] : '',
				'competing'     => $competing,
				'clicks'        => $x['clicks'],
				'impressions'   => $x['impressions'],
				'position'      => $pos,
				'prev_position' => $p0,
				'chance'        => $chance,
				'intent'        => $intent,
				'brand'         => $is_brand,
				'gain'          => $gain,
				'priority'      => $priority,
				'done'          => in_array( $x['query'], $done, true ),
			);
			$stats['searches']++;
			$stats['won']  += 'won' === $chance ? 1 : 0;
			$stats['top3'] += $pos <= 3.5 ? 1 : 0;
			if ( null !== $p0 && $p0 - $pos >= 1 && $x['impressions'] >= 20 ) {
				$stats['climbed']++;
				if ( ! $stats['best_climb'] || $p0 - $pos > $stats['best_climb']['from'] - $stats['best_climb']['to'] ) {
					$stats['best_climb'] = array( 'query' => $x['query'], 'from' => $p0, 'to' => $pos );
				}
			}
		}
		usort(
			$items,
			static function ( $a, $b ) {
				return $b['priority'] <=> $a['priority'];
			}
		);
		return array(
			'connected' => true,
			'period'    => array( $cs, $ce ),
			'items'     => array_slice( $items, 0, 300 ),
			'stats'     => $stats,
			'focus'     => $this->focus_view(),
			'serp'      => array( 'automatic' => $this->plugin->serp->available(), 'source' => $this->plugin->serp->source() ),
			'market'    => $this->plugin->serp->locale( '' ),
		);
	}

	/** Weekly real position / clicks for one search (12 weeks). */
	private function series( $query ) {
		$an    = $this->plugin->gsc_analytics;
		$end   = $an->latest_date();
		$start = gmdate( 'Y-m-d', strtotime( "$end -83 days" ) );
		return $this->plugin->db->rows(
			'SELECT MIN(date) week, SUM(clicks) clicks, SUM(impressions) impressions, ROUND(SUM(position*impressions)/NULLIF(SUM(impressions),0),1) position FROM {gsc_page_query} WHERE project_id = %d AND query_hash = %s AND date BETWEEN %s AND %s GROUP BY FLOOR(DATEDIFF(date, %s) / 7) ORDER BY week',
			array( $this->pid(), Database::hash( $query ), $start, $end, $start )
		);
	}

	/** Real numbers of one search in a date window. */
	private function window( $query, $start, $end ) {
		$r = $this->plugin->db->row( 'SELECT COALESCE(SUM(clicks),0) clicks, COALESCE(SUM(impressions),0) impressions, SUM(position*impressions)/NULLIF(SUM(impressions),0) position, COUNT(DISTINCT date) days FROM {gsc_page_query} WHERE project_id = %d AND query_hash = %s AND date BETWEEN %s AND %s', array( $this->pid(), Database::hash( $query ), $start, $end ) );
		return array( 'clicks' => (int) $r['clicks'], 'impressions' => (int) $r['impressions'], 'position' => null !== $r['position'] ? round( (float) $r['position'], 1 ) : null, 'days' => (int) $r['days'] );
	}

	/** Start focusing on one search (the numbers at the start are kept to measure real progress). */
	public function focus( $query, $page = '' ) {
		$query = trim( sanitize_text_field( (string) $query ) );
		if ( '' === $query ) {
			return new \WP_Error( 'seolity_rank1', __( 'Choose a search first.', 'seolity' ), array( 'status' => 400 ) );
		}
		$an    = $this->plugin->gsc_analytics;
		$end   = $an->latest_date();
		$start = $this->window( $query, gmdate( 'Y-m-d', strtotime( "$end -27 days" ) ), $end );
		$rule = $this->plugin->site_rules->owner_of( $query );
		if ( $rule ) {
			$page = $rule['url']; // The owner decided which page ranks for this search.
		} elseif ( '' === $page ) {
			$pages = $an->query_pages( $query, 28 );
			$page  = $pages ? (string) $pages[0]['page'] : '';
		}
		$s          = $this->state();
		$s['focus'] = array(
			'query'      => $query,
			'page'       => esc_url_raw( $page ),
			'started'    => $end,
			'start'      => $start,
			'steps_done' => array(),
		);
		$this->save_state( $s );
		$this->plugin->site_profile->flush();
		$this->plugin->audit_log->log( 'rank1.focus', 'site', null, array( 'query' => $query ) );
		return $this->focus_view();
	}

	/** Leave the focus: done (#1 reached or finished) or skipped. */
	public function finish( $how ) {
		$s = $this->state();
		if ( ! $s['focus'] ) {
			return $this->focus_view();
		}
		$f   = $s['focus'];
		$now = $this->window( $f['query'], gmdate( 'Y-m-d', strtotime( $this->plugin->gsc_analytics->latest_date() . ' -6 days' ) ), $this->plugin->gsc_analytics->latest_date() );
		$rec = array( 'query' => $f['query'], 'from' => $f['start']['position'], 'to' => $now['position'], 'date' => gmdate( 'Y-m-d' ) );
		if ( 'done' === $how ) {
			$s['done'][] = $rec;
		} else {
			$s['skipped'][] = $rec;
		}
		$s['done']    = array_slice( $s['done'], -100 );
		$s['skipped'] = array_slice( $s['skipped'], -100 );
		$s['focus']   = null;
		$this->save_state( $s );
		return $this->focus_view();
	}

	/** The focused search: real progress since the start and the honest message about it. */
	public function focus_view() {
		$s = $this->state();
		if ( ! $s['focus'] ) {
			return null;
		}
		$f      = $s['focus'];
		$an     = $this->plugin->gsc_analytics;
		$latest = $an->latest_date();
		$since  = $this->window( $f['query'], gmdate( 'Y-m-d', strtotime( $f['started'] . ' +1 day' ) ), $latest );
		$last7  = $this->window( $f['query'], gmdate( 'Y-m-d', strtotime( "$latest -6 days" ) ), $latest );
		$from   = $f['start']['position'];
		$now    = $last7['position'];
		$plan   = $this->plan_for( $f['query'] );
		$news   = array( 'kind' => 'wait', 'text' => __( 'Waiting for data: Google Search Console reports with a delay of 2–3 days, and a change needs 1–3 weeks to show in rankings.', 'seolity' ) );
		if ( $since['days'] >= 7 && $last7['impressions'] >= 20 && null !== $from && null !== $now ) {
			if ( $now <= 1.5 ) {
				/* translators: %s: query */
				$news = array( 'kind' => 'won', 'text' => sprintf( __( 'Position 1 for “%s” — measured, not estimated: the average position of the last 7 days in Search Console is 1. Outstanding work! Keep the page fresh and pick the next search.', 'seolity' ), $f['query'] ) );
			} elseif ( $from - $now >= 1 ) {
				/* translators: 1 from 2 to 3 days */
				$news = array( 'kind' => 'up', 'text' => sprintf( __( 'Real progress: from %1$s to %2$s (average of the last 7 days, Search Console). The work is paying off — keep going with the next step.', 'seolity' ), $from, $now ) );
			} elseif ( $now - $from >= 1 ) {
				/* translators: 1 from 2 to */
				$news = array( 'kind' => 'down', 'text' => sprintf( __( 'Down from %1$s to %2$s for now. Rankings often wobble for a week or two after changes; if it stays down for 3 weeks, review the last change.', 'seolity' ), $from, $now ) );
			} else {
				/* translators: %s: position */
				$news = array( 'kind' => 'flat', 'text' => sprintf( __( 'Holding at %s. No real movement yet — the next step of the plan is what moves it.', 'seolity' ), $now ) );
			}
		}
		return array(
			'query'   => $f['query'],
			'page'    => $f['page'],
			'started' => $f['started'],
			'start'   => $f['start'],
			'since'   => $since,
			'last7'   => $last7,
			'series'  => $this->series( $f['query'] ),
			'news'    => $news,
			'plan'    => $plan,
			'steps'   => $this->steps_view( isset( $f['steps_done'] ) ? (array) $f['steps_done'] : array() ),
			'rule'    => $this->plugin->site_rules->owner_of( $f['query'] ),
			'serp'    => $this->serp_view( $f['query'] ),
			'google'  => $this->google_link( $f['query'] ),
		);
	}

	/** A Google results URL the owner can open in a private window (no personal results). */
	public function google_link( $query ) {
		list( $gl, $hl ) = $this->plugin->serp->locale( '' );
		return 'https://www.google.com/search?' . http_build_query( array( 'q' => $query, 'gl' => $gl, 'hl' => $hl, 'pws' => '0', 'num' => '20' ) );
	}

	/**
	 * The newest Google results for a search: the results API cache or the stored snapshots (where
	 * the "your browser" bookmark and the competitor tracking save them).
	 */
	private function latest_serp( $query ) {
		$cached = $this->plugin->serp->cached( $query );
		$db     = $this->plugin->db;
		$snap   = $db->row( "SELECT * FROM {serp_snapshots} WHERE project_id = %d AND query_hash = %s AND engine = 'google' ORDER BY checked_at DESC, id DESC LIMIT 1", array( $this->pid(), Database::hash( $query ) ) );
		if ( $snap ) {
			$at = strtotime( $snap['checked_at'] . ' UTC' );
			if ( ! $cached || empty( $cached['fetched'] ) || $at >= strtotime( (string) $cached['fetched'] ) ) {
				$rows = $db->rows( 'SELECT position, url, domain, title, snippet FROM {serp_results} WHERE snapshot_id = %d ORDER BY position LIMIT 100', array( (int) $snap['id'] ) );
				if ( $rows ) {
					return array(
						'results'   => array_map(
							static function ( $r ) {
								return array( 'position' => (int) $r['position'], 'url' => $r['url'], 'domain' => $r['domain'], 'title' => (string) $r['title'], 'snippet' => (string) $r['snippet'] );
							},
							$rows
						),
						'questions' => (array) json_decode( (string) $snap['questions'], true ),
						'fetched'   => gmdate( 'c', $at ),
						'source'    => (string) $snap['source'],
					);
				}
			}
		}
		return $cached;
	}

	/** Last known Google results for the search (live check or browser import): leader + our place. */
	private function serp_view( $query ) {
		$serp = $this->latest_serp( $query );
		if ( ! $serp || empty( $serp['results'] ) ) {
			return null;
		}
		$home = preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$ours = null;
		foreach ( $serp['results'] as $r ) {
			if ( preg_replace( '/^www\./', '', (string) $r['domain'] ) === $home ) {
				$ours = array( 'position' => (int) $r['position'], 'url' => $r['url'] );
				break;
			}
		}
		$lead = $serp['results'][0];
		return array(
			'fetched' => isset( $serp['fetched'] ) ? $serp['fetched'] : '',
			'source'  => isset( $serp['source'] ) ? $serp['source'] : '',
			'ours'    => $ours,
			'leader'  => array( 'title' => $lead['title'], 'url' => $lead['url'], 'domain' => $lead['domain'] ),
			'top'     => array_slice( $serp['results'], 0, 10 ),
			'count'   => count( $serp['results'] ),
		);
	}

	/** Live Google check through the configured results source (uses one search of its quota). */
	public function check_live( $query ) {
		if ( ! $this->plugin->serp->available() ) {
			return new \WP_Error( 'seolity_rank1_serp', __( 'No automatic Google results source is set up. Open the search in Google in a private window, or set up a free source (Settings → Crawler & audits; one needs no key at all).', 'seolity' ), array( 'status' => 400 ) );
		}
		$res = $this->plugin->serp->fetch( $query, '', 20, true );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$this->plugin->intel->snapshot( $query, $res, 'api' ); // Keep the history like the competitor tracking does.
		return $this->serp_view( $query );
	}

	private function plan_for( $query ) {
		$all = get_option( self::PLANS, array() );
		$h   = Database::hash( $query );
		return is_array( $all ) && isset( $all[ $h ] ) ? $all[ $h ] : null;
	}

	/** Ask the AI for the honest plan of the focused search (runs in the background). */
	public function start_plan( $instructions = '' ) {
		$s = $this->state();
		if ( ! $s['focus'] ) {
			return new \WP_Error( 'seolity_rank1', __( 'Choose a search to focus on first.', 'seolity' ), array( 'status' => 400 ) );
		}
		if ( ! $this->plugin->ai->is_ready() ) {
			return new \WP_Error( 'seolity_ai', __( 'Connect an AI engine first (Settings → AI).', 'seolity' ), array( 'status' => 400 ) );
		}
		$q   = $s['focus']['query'];
		/* translators: %s: query */
		$aid = $this->plugin->activity->start( 'agent', sprintf( __( 'Plan to rank #1 for “%s”', 'seolity' ), $q ), array( 'dock' => true, 'status' => 'queued', 'step' => __( 'Queued — starting in a moment…', 'seolity' ), 'meta' => array( 'type' => 'rank1', 'query' => $q ) ) );
		$job = $this->plugin->queue->push( 'rank1_plan', array( 'query' => $q, 'activity' => (int) $aid, 'instructions' => sanitize_textarea_field( (string) $instructions ), 'user_id' => get_current_user_id() ), array( 'unique' => 'rank1_' . md5( $q ), 'priority' => 0, 'max_attempts' => 2, 'timeout' => 900 ) );
		$this->plugin->activity->attach_job( $aid, $job );
		\SEOlity\Activity\AiJobs::run_after_response( $this->plugin );
		return array( 'started' => true, 'activity' => (int) $aid );
	}

	/** Queue handler. May throw Deferred (GitHub route): the queue retries. */
	public function make_plan( array $a ) {
		$q    = (string) $a['query'];
		$aid  = (int) $a['activity'];
		$act  = $this->plugin->activity;
		$prev = Activity::set_current( $aid );
		try {
			$act->step( $aid, __( 'Reading the page, its Search Console history and the Google results…', 'seolity' ), 15 );
			$s    = $this->state();
			$page = $s['focus'] && $s['focus']['query'] === $q ? (string) $s['focus']['page'] : '';
			$imp  = $page ? $this->plugin->importance->of( $page ) : null;
			$ctx  = $imp && $imp['object_id'] ? \SEOlity\Content\Objects::context( $this->plugin, 'term' === $imp['object_type'] ? 'term' : 'post', (int) $imp['object_id'] ) : null;
			$text = $ctx ? \SEOlity\Analysis\Text::strip( (string) $ctx['content'] ) : '';
			$an   = $this->plugin->gsc_analytics;
			$end  = $an->latest_date();
			$now  = $this->window( $q, gmdate( 'Y-m-d', strtotime( "$end -27 days" ) ), $end );
			$serp = $this->serp_view( $q );
			if ( ! $serp && $this->plugin->serp->available() ) {
				$fresh = $this->plugin->serp->fetch( $q, '', 10 );
				$serp  = is_wp_error( $fresh ) ? null : $this->serp_view( $q );
			}
			$full = $this->latest_serp( $q );
			$input = array(
				'search'          => $q,
				'market'          => $this->plugin->serp->locale( '' ),
				'real_numbers'    => array(
					'source'           => 'Google Search Console, last 28 days',
					'average_position' => $now['position'],
					'impressions'      => $now['impressions'],
					'clicks'           => $now['clicks'],
					'chance_by_rule'   => self::chance( (float) $now['position'], $now['impressions'] ),
					'clicks_at_1_estimate' => (int) round( $now['impressions'] * Analytics::CTR_CURVE[1] ),
					'weekly'           => $this->series( $q ),
					'our_pages_for_it' => array_slice( $an->query_pages( $q, 28 ), 0, 5 ),
				),
				'our_page'        => $ctx ? array(
					'url'              => $page,
					'importance'       => $imp['label'],
					'title'            => $ctx['title'],
					'seo_title'        => $ctx['meta_title'],
					'meta_description' => $ctx['meta_description'],
					'focus_keyword'    => $ctx['focus_keyword'],
					'words'            => \SEOlity\Analysis\Text::word_count( $text ),
					'text_start'       => mb_substr( $text, 0, 2500 ),
				) : array( 'url' => $page ),
				'google_results'  => $serp ? array(
					'checked'          => $serp['fetched'],
					'our_position'     => $serp['ours'] ? $serp['ours']['position'] : 'not in the results checked',
					'top'              => array_map(
						static function ( $r ) {
							return array( 'position' => $r['position'], 'domain' => $r['domain'], 'title' => $r['title'], 'snippet' => $r['snippet'] );
						},
						$serp['top']
					),
					'people_also_ask'  => $full && isset( $full['questions'] ) ? $full['questions'] : array(),
				) : 'not available — judge only from Search Console and the page',
				'owner_note'      => isset( $a['instructions'] ) ? (string) $a['instructions'] : '',
				'site_rules'      => array_values( array_filter( array_merge( $this->plugin->site_rules->owner_of( $q ) ? array( \SEOlity\Content\SiteRules::sentence( $this->plugin->site_rules->owner_of( $q ) ) ) : array(), $page ? $this->plugin->site_rules->for_url( $page ) : array() ) ) ),
			);
			$act->step( $aid, __( 'AI is comparing your page with the result that ranks first…', 'seolity' ), 45 );
			$res = $this->plugin->ai->run( 'rank1_plan', $input, array( 'knowledge_query' => $q ) );
			if ( is_wp_error( $res ) ) {
				$act->finish( $aid, 'failed', $res->get_error_message() );
				return true;
			}
			$all = get_option( self::PLANS, array() );
			$all = is_array( $all ) ? $all : array();
			$all[ Database::hash( $q ) ] = array( 'query' => $q, 'page' => $page, 'at' => gmdate( 'c' ), 'data' => $res['data'], 'meta' => $res['meta'], 'serp_used' => (bool) $serp );
			if ( count( $all ) > 40 ) {
				$all = array_slice( $all, -40, null, true );
			}
			update_option( self::PLANS, $all, false );
			$act->finish( $aid, 'done', __( 'Plan ready', 'seolity' ), array( 'summary' => (string) $res['data']['honest_assessment'] ), array( 'step' => __( 'Plan ready — open “Road to #1”', 'seolity' ) ) );
			return true;
		} finally {
			Activity::set_current( $prev );
		}
	}

	/** Prepare one step of the plan for review (roadmap task or a change for the page). */
	public function do_step( $index ) {
		$s = $this->state();
		if ( ! $s['focus'] ) {
			return new \WP_Error( 'seolity_rank1', __( 'Choose a search to focus on first.', 'seolity' ), array( 'status' => 400 ) );
		}
		$plan  = $this->plan_for( $s['focus']['query'] );
		$steps = $plan ? (array) $plan['data']['steps'] : array();
		if ( ! isset( $steps[ (int) $index ] ) ) {
			return new \WP_Error( 'seolity_rank1', __( 'Step not found.', 'seolity' ), array( 'status' => 404 ) );
		}
		$st   = $steps[ (int) $index ];
		$q    = $s['focus']['query'];
		$page = $s['focus']['page'];
		$prio = array( 'high' => 'high', 'medium' => 'medium', 'low' => 'low' );
		/* translators: %s: query */
		$goal = sprintf( __( 'Goal: position 1 on Google for “%s”.', 'seolity' ), $q );
		$p    = array(
			'kind'         => 'task' === $st['action'] ? 'task' : 'change',
			'title'        => (string) $st['title'],
			'detail'       => $goal . "\n\n" . (string) $st['detail'],
			'priority'     => isset( $prio[ $st['impact'] ] ) ? $prio[ $st['impact'] ] : 'medium',
			'category'     => (string) $st['category'],
			'url'          => $page,
			'action'       => 'task' === $st['action'] ? null : (string) $st['action'],
			'instructions' => $goal . ' ' . (string) $st['detail'],
		);
		$out = $this->plugin->chat_actions->apply( array( $p ) );
		$res = $out ? $out[0] : array( 'ok' => false, 'note' => __( 'Not added.', 'seolity' ) );
		if ( ! empty( $res['ok'] ) ) {
			$s['focus']['steps_done'][ (int) $index ] = array( 'at' => gmdate( 'c' ), 'route' => isset( $res['route'] ) ? $res['route'] : '', 'id' => isset( $res['id'] ) ? (int) $res['id'] : 0, 'kind' => isset( $res['kind'] ) ? $res['kind'] : '' );
			$this->save_state( $s );
		}
		return $res;
	}

	/** Prepared steps with the live state of what was prepared (task, change being prepared, waiting, applied). */
	private function steps_view( array $done ) {
		$out = array();
		foreach ( $done as $i => $d ) {
			$d   = (array) $d;
			$rid = isset( $d['id'] ) ? (int) $d['id'] : 0;
			if ( $rid ) {
				$rec            = $this->plugin->recommendations->get( $rid );
				$op             = $this->plugin->operations->for_issue( $rid );
				$d['issue']     = $rec ? (string) $rec['status'] : 'gone';
				$d['op_id']     = $op ? (int) $op['id'] : 0;
				$d['op_status'] = $op ? (string) $op['status'] : '';
			}
			$out[ $i ] = $d;
		}
		return (object) $out;
	}

	/** Reject what a step prepared and make the step available again. */
	public function reset_step( $index ) {
		$s = $this->state();
		if ( ! $s['focus'] || ! isset( $s['focus']['steps_done'][ (int) $index ] ) ) {
			return $this->focus_view();
		}
		$d   = (array) $s['focus']['steps_done'][ (int) $index ];
		$rid = isset( $d['id'] ) ? (int) $d['id'] : 0;
		if ( $rid ) {
			$op = $this->plugin->operations->for_issue( $rid );
			if ( $op && 'awaiting_approval' === $op['status'] ) {
				$this->plugin->operations->reject( (int) $op['id'] );
			}
			$this->plugin->recommendations->set_state( $rid, 'dismissed' );
		}
		unset( $s['focus']['steps_done'][ (int) $index ] );
		$this->save_state( $s );
		return $this->focus_view();
	}

	/** Prepare a step again with the owner's short instruction. */
	public function redo_step( $index, $instructions ) {
		$s = $this->state();
		if ( ! $s['focus'] || ! isset( $s['focus']['steps_done'][ (int) $index ] ) ) {
			return new \WP_Error( 'seolity_rank1', __( 'Step not found.', 'seolity' ), array( 'status' => 404 ) );
		}
		$d   = (array) $s['focus']['steps_done'][ (int) $index ];
		$rid = isset( $d['id'] ) ? (int) $d['id'] : 0;
		$txt = sanitize_textarea_field( (string) $instructions );
		if ( ! $rid ) {
			return new \WP_Error( 'seolity_rank1', __( 'Prepare this step first.', 'seolity' ), array( 'status' => 400 ) );
		}
		$op = $this->plugin->operations->for_issue( $rid );
		if ( $op && 'awaiting_approval' === $op['status'] ) {
			$this->plugin->operations->reject( (int) $op['id'] );
		}
		if ( 'task' === ( isset( $d['kind'] ) ? $d['kind'] : '' ) ) {
			$this->plugin->recommendations->set_state( $rid, 'open', array( 'instructions' => $txt ) );
			return $this->focus_view();
		}
		$res = $this->plugin->agent->start( $rid, $txt );
		return is_wp_error( $res ) ? $res : $this->focus_view();
	}
}
