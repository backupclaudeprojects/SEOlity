<?php
/**
 * Follow-ups = measured impact of every change ("look at this again and tell me what it did").
 *
 * Engineering of a measurement (2.0):
 *  - Every follow-up has a change date. Each checkpoint (by default day 7, 14, 28 and a long-term
 *    one) compares an equal-length window AFTER the change with the same length BEFORE it, so short
 *    checks never compare overlapping windows.
 *  - The site-wide trend is the control: if the whole site grew 30 %, a page that also grew 30 %
 *    did not improve. Expected = before × (site after / site before); lift = actual vs expected.
 *  - The pages are measured as ONE group (a merge or a slug change moves traffic between URLs) and
 *    the search queries those pages ranked for before the change are measured too, wherever they
 *    rank now.
 *  - Verdict with a confidence level from the data volume: positive / negative / neutral /
 *    insufficient data. Early checkpoints warn only on a clear result (so a harmful change can be
 *    undone early); the main checkpoint always reports; the long-term one reports when it differs.
 *  - At the main checkpoint the AI reviews the result (with the site's own record of what worked
 *    before) and writes the explanation and the next steps; the steps go to the SEO roadmap.
 *  - Every final result is added to the site's learnings by change type (what works on this site).
 *
 * Created from the AI chat, automatically after every applied change (operation), or by hand.
 *
 * @package SEOlity
 */

namespace SEOlity\Watch;

use SEOlity\Core\Database;
use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class FollowUps {

	const OPTION    = 'seolity_follow_ups';
	const LEARNINGS = 'seolity_impact_learnings';
	const MAX       = 300;
	const LAG       = 3; // Search Console data arrives about 3 days late.

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function all() {
		$list = get_option( self::OPTION, array() );
		return is_array( $list ) ? array_map( array( $this, 'upgrade' ), $list ) : array();
	}

	public function get( $id ) {
		foreach ( $this->all() as $f ) {
			if ( $f['id'] === $id ) {
				return $f;
			}
		}
		return null;
	}

	/** Items from 1.14 have no change date / checkpoints: give them the new shape. */
	private function upgrade( $f ) {
		if ( ! is_array( $f ) ) {
			return $f;
		}
		if ( empty( $f['change_date'] ) ) {
			$f['change_date'] = substr( (string) $f['created_at'], 0, 10 );
		}
		if ( ! isset( $f['checkpoints_due'] ) ) {
			$days                 = max( 3, (int) round( ( strtotime( $f['check_at'] ) - strtotime( $f['change_date'] ) ) / DAY_IN_SECONDS ) );
			$f['final_day']       = $days;
			$f['checkpoints_due'] = self::plan( $days );
			$f['checkpoints']     = isset( $f['checkpoints'] ) ? $f['checkpoints'] : array();
		}
		if ( ! isset( $f['kind'] ) ) {
			$f['kind'] = 'operation' === $f['source'] ? 'change' : $f['source'];
		}
		return $f;
	}

	/** Checkpoint days: 7, 14, the main day and a long-term confirmation (twice the main day, max 120). */
	private static function plan( $final ) {
		$final = max( 3, (int) $final );
		$days  = array_filter( array( 7, 14 ), static function ( $d ) use ( $final ) {
			return $d < $final;
		} );
		$days[] = $final;
		if ( $final >= 14 ) {
			$days[] = min( 120, $final * 2 );
		}
		$days = array_values( array_unique( array_map( 'intval', $days ) ) );
		sort( $days );
		return $days;
	}

	private function save( array $list ) {
		usort(
			$list,
			static function ( $a, $b ) {
				return strcmp( $b['created_at'], $a['created_at'] );
			}
		);
		$waiting = array_values( array_filter( $list, static function ( $f ) {
			return 'waiting' === $f['status'];
		} ) );
		$done    = array_values( array_filter( $list, static function ( $f ) {
			return 'waiting' !== $f['status'];
		} ) );
		update_option( self::OPTION, array_slice( array_merge( $waiting, $done ), 0, self::MAX ), false );
	}

	private function put( array $item ) {
		$list  = $this->all();
		$found = false;
		foreach ( $list as $i => $f ) {
			if ( $f['id'] === $item['id'] ) {
				$list[ $i ] = $item;
				$found      = true;
			}
		}
		if ( ! $found ) {
			$list[] = $item;
		}
		$this->save( $list );
	}

	/**
	 * @param array $f title, goal, urls[], queries[], days (main checkpoint), source, ref, kind, change_date.
	 */
	public function add( array $f ) {
		$urls    = array_values( array_unique( array_filter( array_map( array( $this, 'abs_url' ), isset( $f['urls'] ) ? (array) $f['urls'] : array() ) ) ) );
		$queries = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', isset( $f['queries'] ) ? (array) $f['queries'] : array() ) ) ) );
		$days    = max( 3, min( 180, isset( $f['days'] ) ? (int) $f['days'] : 28 ) );
		$change  = ! empty( $f['change_date'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $f['change_date'] ) ? $f['change_date'] : gmdate( 'Y-m-d' );
		$urls    = array_slice( $urls, 0, 10 );
		$item    = array(
			'id'              => substr( md5( wp_generate_password( 12, false, false ) . microtime() ), 0, 12 ),
			'title'           => mb_substr( wp_strip_all_tags( isset( $f['title'] ) ? (string) $f['title'] : __( 'Follow-up', 'seolity' ) ), 0, 200 ),
			'goal'            => mb_substr( wp_strip_all_tags( isset( $f['goal'] ) ? (string) $f['goal'] : '' ), 0, 500 ),
			'urls'            => $urls,
			'queries'         => array_slice( $queries, 0, 10 ),
			// Queries these pages ranked for before the change: measured wherever they rank later.
			'track_queries'   => $this->top_queries( $urls, $change, 15 ),
			'source'          => isset( $f['source'] ) ? sanitize_key( $f['source'] ) : 'manual',
			'kind'            => isset( $f['kind'] ) ? sanitize_key( $f['kind'] ) : ( isset( $f['source'] ) ? sanitize_key( $f['source'] ) : 'manual' ),
			'ref'             => isset( $f['ref'] ) ? (array) $f['ref'] : array(),
			'created_at'      => gmdate( 'Y-m-d H:i:s' ),
			'change_date'     => $change,
			'final_day'       => $days,
			'checkpoints_due' => self::plan( $days ),
			'checkpoints'     => array(),
			'check_at'        => gmdate( 'Y-m-d', strtotime( $change ) + ( min( 7, $days ) + self::LAG ) * DAY_IN_SECONDS ),
			'baseline'        => $this->window( $urls, $queries, gmdate( 'Y-m-d', strtotime( $change ) - 28 * DAY_IN_SECONDS ), gmdate( 'Y-m-d', strtotime( $change ) - DAY_IN_SECONDS ) ),
			'status'          => 'waiting',
			'result'          => null,
			'ai'              => null,
		);
		$this->put( $item );
		return $item;
	}

	public function remove( $id ) {
		$this->save( array_values( array_filter( $this->all(), static function ( $f ) use ( $id ) {
			return $f['id'] !== $id;
		} ) ) );
	}

	/**
	 * Run every checkpoint whose data has arrived. With $force_id that follow-up is measured now
	 * with whatever data exists (marked partial). Returns how many checkpoints were evaluated.
	 */
	public function check( $force_id = '' ) {
		$max = $this->data_until();
		$n   = 0;
		foreach ( $this->all() as $f ) {
			if ( 'waiting' !== $f['status'] ) {
				continue;
			}
			$forced = $f['id'] === $force_id;
			$done   = wp_list_pluck( $f['checkpoints'], 'day' );
			foreach ( $f['checkpoints_due'] as $day ) {
				if ( in_array( $day, $done, true ) ) {
					continue;
				}
				$end = gmdate( 'Y-m-d', strtotime( $f['change_date'] ) + $day * DAY_IN_SECONDS );
				$ok  = $max && $max >= $end;
				// Data stalled for over 10 days past the checkpoint: measure what exists.
				$stale = $max && time() > strtotime( $end ) + 10 * DAY_IN_SECONDS && $max > $f['change_date'];
				if ( ! $ok && ! $forced && ! $stale ) {
					break;
				}
				$cp = $this->evaluate( $f, $day, $ok ? $end : $max, ! $ok );
				if ( ! $cp ) {
					break;
				}
				$f['checkpoints'][] = $cp;
				$f                  = $this->after_checkpoint( $f, $cp );
				$n++;
				if ( $forced ) {
					break; // One checkpoint per manual check.
				}
			}
			$this->put( $f );
		}
		return $n;
	}

	/** Measure one checkpoint: equal windows before / after the change, site trend as control. */
	private function evaluate( array $f, $day, $post_end, $partial ) {
		$change = strtotime( $f['change_date'] );
		$len    = max( 3, min( 28, $day, (int) round( ( strtotime( $post_end ) - $change ) / DAY_IN_SECONDS ) ) );
		if ( strtotime( $post_end ) <= $change ) {
			return null;
		}
		$post_start = gmdate( 'Y-m-d', strtotime( $post_end ) - ( $len - 1 ) * DAY_IN_SECONDS );
		$pre_end    = gmdate( 'Y-m-d', $change - DAY_IN_SECONDS );
		$pre_start  = gmdate( 'Y-m-d', $change - $len * DAY_IN_SECONDS );
		$queries    = array_values( array_unique( array_merge( $f['queries'], isset( $f['track_queries'] ) ? $f['track_queries'] : array() ) ) );
		$pre        = $this->window( $f['urls'], $queries, $pre_start, $pre_end );
		$post       = $this->window( $f['urls'], $queries, $post_start, $post_end );
		$verdict    = self::verdict( $pre, $post );
		return array(
			'day'     => (int) $day,
			'at'      => gmdate( 'Y-m-d H:i:s' ),
			'partial' => (bool) $partial,
			'windows' => array( 'before' => array( $pre_start, $pre_end ), 'after' => array( $post_start, $post_end ), 'days' => $len ),
			'before'  => $pre,
			'after'   => $post,
			'verdict' => $verdict,
		);
	}

	/**
	 * Verdict of one checkpoint. Measured on the group (all pages together, or the queries when no
	 * page is watched), corrected by the site-wide trend.
	 */
	public static function verdict( array $pre, array $post ) {
		$use  = $pre['group']['impressions'] + $post['group']['impressions'] > 0 || ! $pre['qgroup']['impressions'] ? 'group' : 'qgroup';
		$b    = $pre[ $use ];
		$a    = $post[ $use ];
		$sc   = $pre['site']['clicks'] > 0 ? $post['site']['clicks'] / $pre['site']['clicks'] : 1;
		$si   = $pre['site']['impressions'] > 0 ? $post['site']['impressions'] / $pre['site']['impressions'] : 1;
		$exp_c = $b['clicks'] * $sc;
		$exp_i = $b['impressions'] * $si;
		$lift_c = ( $a['clicks'] - $exp_c ) / max( $exp_c, 1 );
		$lift_i = ( $a['impressions'] - $exp_i ) / max( $exp_i, 1 );
		$pos    = null !== $b['position'] && null !== $a['position'] ? round( $b['position'] - $a['position'], 1 ) : null;
		$volume = $b['impressions'] + $a['impressions'];
		$conf   = $volume >= 300 ? 'high' : ( $volume >= 80 ? 'medium' : ( $volume >= 20 ? 'low' : 'insufficient' ) );
		$score  = 0;
		if ( $lift_c >= 0.15 && $a['clicks'] - $exp_c >= 2 ) {
			$score += 2;
		} elseif ( $lift_c <= -0.15 && $exp_c - $a['clicks'] >= 2 ) {
			$score -= 2;
		}
		if ( $lift_i >= 0.2 && $a['impressions'] - $exp_i >= 10 ) {
			$score += 1;
		} elseif ( $lift_i <= -0.2 && $exp_i - $a['impressions'] >= 10 ) {
			$score -= 1;
		}
		if ( null !== $pos ) {
			$score += $pos >= 3 ? 2 : ( $pos >= 1 ? 1 : ( $pos <= -3 ? -2 : ( $pos <= -1 ? -1 : 0 ) ) );
		}
		$v = 'insufficient' === $conf ? 'insufficient' : ( $score >= 2 ? 'positive' : ( $score <= -2 ? 'negative' : 'neutral' ) );
		return array(
			'verdict'          => $v,
			'confidence'       => $conf,
			'score'            => $score,
			'measured_on'      => 'group' === $use ? 'pages' : 'queries',
			'clicks'           => array( 'before' => $b['clicks'], 'after' => $a['clicks'], 'expected' => round( $exp_c, 1 ), 'lift' => round( $lift_c * 100 ) ),
			'impressions'      => array( 'before' => $b['impressions'], 'after' => $a['impressions'], 'expected' => round( $exp_i ), 'lift' => round( $lift_i * 100 ) ),
			'position'         => array( 'before' => $b['position'], 'after' => $a['position'], 'gain' => $pos ),
			'ctr'              => array( 'before' => $b['impressions'] ? round( 100 * $b['clicks'] / $b['impressions'], 2 ) : null, 'after' => $a['impressions'] ? round( 100 * $a['clicks'] / $a['impressions'], 2 ) : null ),
			'site_trend'       => array( 'clicks' => round( ( $sc - 1 ) * 100 ), 'impressions' => round( ( $si - 1 ) * 100 ) ),
		);
	}

	/** Report the checkpoint (when it says something), finish at the last one, queue the AI review. */
	private function after_checkpoint( array $f, array $cp ) {
		$v       = $cp['verdict']['verdict'];
		$main    = (int) $cp['day'] === (int) $f['final_day'];
		$last    = (int) $cp['day'] === (int) end( $f['checkpoints_due'] );
		$prev    = count( $f['checkpoints'] ) > 1 ? $f['checkpoints'][ count( $f['checkpoints'] ) - 2 ]['verdict']['verdict'] : null;
		$early   = $cp['day'] < $f['final_day'];
		$clear   = in_array( $cp['verdict']['confidence'], array( 'high', 'medium' ), true ) && in_array( $v, array( 'positive', 'negative' ), true );
		$f['result'] = array(
			'verdict' => array( 'positive' => 'better', 'negative' => 'worse', 'neutral' => 'same', 'insufficient' => 'same' )[ $v ],
			'detail'  => $cp['verdict'],
			'day'     => $cp['day'],
			'lines'   => $this->lines( $cp ),
			'until'   => $cp['windows']['after'][1],
		);
		if ( ( $early && $clear ) || $main || ( $last && ! $main && $prev !== $v ) ) {
			$this->report( $f, $cp, $early );
		}
		if ( $main ) {
			$this->learn( $f, $cp );
			$this->plugin->queue->push( 'follow_up_review', array( 'id' => $f['id'] ), array( 'unique' => true, 'priority' => 12, 'max_attempts' => 3 ) );
		}
		$next = null;
		foreach ( $f['checkpoints_due'] as $d ) {
			if ( $d > $cp['day'] ) {
				$next = $d;
				break;
			}
		}
		if ( null === $next ) {
			$f['status']     = 'done';
			$f['checked_at'] = gmdate( 'Y-m-d H:i:s' );
		} else {
			$f['check_at'] = gmdate( 'Y-m-d', strtotime( $f['change_date'] ) + ( $next + self::LAG ) * DAY_IN_SECONDS );
		}
		return $f;
	}

	/** Group (and per-item) clicks / impressions / position for a date window, plus the site totals. */
	public function window( array $urls, array $queries, $from, $to ) {
		$db  = $this->plugin->db;
		$pid = $db->project_id();
		$sum = 'SUM(clicks) c, SUM(impressions) i, SUM(position * impressions) / NULLIF(SUM(impressions), 0) pos';
		$out = array( 'from' => $from, 'to' => $to, 'pages' => array(), 'queries' => array(), 'group' => self::zero(), 'qgroup' => self::zero(), 'site' => self::zero() );
		$hashes = array();
		foreach ( $urls as $u ) {
			$h        = Database::url_hash( $u );
			$hashes[] = $h;
			$r        = $db->row( "SELECT $sum FROM {gsc_page_query} WHERE project_id = %d AND page_hash = %s AND date BETWEEN %s AND %s", array( $pid, $h, $from, $to ) );
			$out['pages'][ $u ] = self::row( $r );
		}
		if ( $hashes ) {
			$r            = $db->row( "SELECT $sum FROM {gsc_page_query} WHERE project_id = %d AND page_hash IN (" . implode( ',', array_fill( 0, count( $hashes ), '%s' ) ) . ') AND date BETWEEN %s AND %s', array_merge( array( $pid ), $hashes, array( $from, $to ) ) );
			$out['group'] = self::row( $r );
		}
		$qh = array();
		foreach ( $queries as $q ) {
			$h    = Database::hash( $q );
			$qh[] = $h;
			$r    = $db->row( "SELECT $sum FROM {gsc_page_query} WHERE project_id = %d AND query_hash = %s AND date BETWEEN %s AND %s", array( $pid, $h, $from, $to ) );
			$out['queries'][ $q ] = self::row( $r );
		}
		if ( $qh ) {
			$r             = $db->row( "SELECT $sum FROM {gsc_page_query} WHERE project_id = %d AND query_hash IN (" . implode( ',', array_fill( 0, count( $qh ), '%s' ) ) . ') AND date BETWEEN %s AND %s', array_merge( array( $pid ), $qh, array( $from, $to ) ) );
			$out['qgroup'] = self::row( $r );
		}
		$site = $db->row( 'SELECT SUM(clicks) c, SUM(impressions) i, SUM(position * impressions) / NULLIF(SUM(impressions), 0) pos FROM {gsc_daily} WHERE project_id = %d AND date BETWEEN %s AND %s', array( $pid, $from, $to ) );
		if ( ! $site || ! (int) $site['i'] ) {
			$site = $db->row( "SELECT $sum FROM {gsc_page_query} WHERE project_id = %d AND date BETWEEN %s AND %s", array( $pid, $from, $to ) );
		}
		$out['site'] = self::row( $site );
		return $out;
	}

	private static function zero() {
		return array( 'clicks' => 0, 'impressions' => 0, 'position' => null );
	}

	private static function row( $r ) {
		return array(
			'clicks'      => $r ? (int) $r['c'] : 0,
			'impressions' => $r ? (int) $r['i'] : 0,
			'position'    => $r && null !== $r['pos'] ? round( (float) $r['pos'], 1 ) : null,
		);
	}

	/** Newest date of stored Search Console rows. */
	public function data_until() {
		return (string) $this->plugin->db->value( 'SELECT MAX(date) FROM {gsc_page_query} WHERE project_id = %d', array( $this->plugin->db->project_id() ) );
	}

	/** Top queries of the pages in the 28 days before the change (by impressions). */
	private function top_queries( array $urls, $change, $limit ) {
		if ( ! $urls ) {
			return array();
		}
		$db     = $this->plugin->db;
		$hashes = array_map( array( Database::class, 'url_hash' ), $urls );
		$rows   = $db->rows(
			'SELECT query, SUM(impressions) i FROM {gsc_page_query} WHERE project_id = %d AND page_hash IN (' . implode( ',', array_fill( 0, count( $hashes ), '%s' ) ) . ') AND date BETWEEN %s AND %s GROUP BY query ORDER BY i DESC LIMIT %d',
			array_merge( array( $db->project_id() ), $hashes, array( gmdate( 'Y-m-d', strtotime( $change ) - 28 * DAY_IN_SECONDS ), $change, (int) $limit ) )
		);
		return array_values( array_filter( wp_list_pluck( (array) $rows, 'query' ) ) );
	}

	/** Per page / query lines for the report and the UI. */
	private function lines( array $cp ) {
		$out = array();
		foreach ( array( 'pages', 'queries' ) as $kind ) {
			foreach ( $cp['after'][ $kind ] as $k => $a ) {
				$b = isset( $cp['before'][ $kind ][ $k ] ) ? $cp['before'][ $kind ][ $k ] : self::zero();
				if ( 'queries' === $kind && ! $a['impressions'] && ! $b['impressions'] ) {
					continue;
				}
				$dp    = null !== $a['position'] && null !== $b['position'] ? round( $b['position'] - $a['position'], 1 ) : null;
				$dc    = $a['clicks'] - $b['clicks'];
				$s     = ( $dc > 0 ? 1 : ( $dc < 0 ? -1 : 0 ) ) + ( null === $dp ? 0 : ( $dp >= 0.5 ? 1 : ( $dp <= -0.5 ? -1 : 0 ) ) );
				$out[] = array( 'kind' => $kind, 'key' => $k, 'before' => $b, 'after' => $a, 'clicks_delta' => $dc, 'position_gain' => $dp, 'trend' => $s > 0 ? 'up' : ( $s < 0 ? 'down' : 'flat' ) );
			}
		}
		return array_slice( $out, 0, 25 );
	}

	private function report( array $f, array $cp, $early ) {
		$d     = $cp['verdict'];
		$names = array(
			'positive'     => __( 'Positive: it worked.', 'seolity' ),
			'negative'     => __( 'Negative: it got worse — worth a look.', 'seolity' ),
			'neutral'      => __( 'No clear change yet.', 'seolity' ),
			'insufficient' => __( 'Not enough Search Console data to judge yet.', 'seolity' ),
		);
		$conf  = array( 'high' => __( 'high', 'seolity' ), 'medium' => __( 'medium', 'seolity' ), 'low' => __( 'low', 'seolity' ), 'insufficient' => __( 'too little data', 'seolity' ) );
		$lines = array( $names[ $d['verdict'] ] . ' ' . sprintf( /* translators: %s: confidence */ __( '(confidence: %s)', 'seolity' ), $conf[ $d['confidence'] ] ) );
		/* translators: 1 days 2 date 3 date */
		$lines[] = sprintf( __( 'Compared %1$d days after the change with the same %1$d days before it (%2$s → %3$s).', 'seolity' ), $cp['windows']['days'], $cp['windows']['before'][0], $cp['windows']['after'][1] ) . ( $cp['partial'] ? ' ' . __( 'Partial: Search Console data is not complete yet.', 'seolity' ) : '' );
		/* translators: 1 before 2 after 3 expected 4 lift % */
		$lines[] = sprintf( __( 'Clicks: %1$d → %2$d (expected from the site trend: %3$s, difference %4$s%%)', 'seolity' ), $d['clicks']['before'], $d['clicks']['after'], $d['clicks']['expected'], ( $d['clicks']['lift'] > 0 ? '+' : '' ) . $d['clicks']['lift'] );
		/* translators: 1 before 2 after 3 lift % */
		$lines[] = sprintf( __( 'Impressions: %1$d → %2$d (difference to the site trend %3$s%%)', 'seolity' ), $d['impressions']['before'], $d['impressions']['after'], ( $d['impressions']['lift'] > 0 ? '+' : '' ) . $d['impressions']['lift'] );
		if ( null !== $d['position']['gain'] ) {
			/* translators: 1 before 2 after */
			$lines[] = sprintf( __( 'Average position: %1$s → %2$s', 'seolity' ), $d['position']['before'], $d['position']['after'] );
		}
		/* translators: 1 clicks % 2 impressions % */
		$lines[] = sprintf( __( 'Whole site in the same time: clicks %1$s%%, impressions %2$s%%', 'seolity' ), ( $d['site_trend']['clicks'] > 0 ? '+' : '' ) . $d['site_trend']['clicks'], ( $d['site_trend']['impressions'] > 0 ? '+' : '' ) . $d['site_trend']['impressions'] );
		foreach ( array_slice( $this->lines( $cp ), 0, 6 ) as $l ) {
			$name = 'pages' === $l['kind'] ? urldecode( (string) wp_parse_url( $l['key'], PHP_URL_PATH ) ) : '«' . $l['key'] . '»';
			/* translators: 1 page or query 2 clicks before 3 after 4 position before 5 after */
			$lines[] = '• ' . sprintf( __( '%1$s: clicks %2$d → %3$d, position %4$s → %5$s', 'seolity' ), $name, $l['before']['clicks'], $l['after']['clicks'], null === $l['before']['position'] ? '—' : $l['before']['position'], null === $l['after']['position'] ? '—' : $l['after']['position'] );
		}
		if ( $early && 'negative' === $d['verdict'] ) {
			$lines[] = __( 'This is an early warning: if the drop continues at the next check, consider undoing the change (AI Changes → Undo).', 'seolity' );
		}
		$kind = 'positive' === $d['verdict'] ? 'success' : ( 'negative' === $d['verdict'] ? 'warning' : 'result' );
		/* translators: 1 day 2 title */
		$title = sprintf( __( 'Day %1$d result of “%2$s”', 'seolity' ), $cp['day'], $f['title'] );
		$ask   = rawurlencode( sprintf( 'Explain the result of the follow-up "%s" and what to do next.', $f['title'] ) );
		$links = array( array( 'title' => __( 'Follow-ups', 'seolity' ), 'route' => '#/alerts' ), array( 'title' => __( 'Ask the AI what to do next', 'seolity' ), 'route' => '#/ai?ask=' . $ask ) );
		if ( ! empty( $f['ref']['operation_id'] ) ) {
			$links[] = array( 'title' => __( 'The change (undo possible)', 'seolity' ), 'route' => '#/changes' );
		}
		$this->plugin->alerts->message(
			$kind,
			$title,
			( $f['goal'] ? __( 'Goal:', 'seolity' ) . ' ' . $f['goal'] . "\n\n" : '' ) . implode( "\n", $lines ),
			array( 'kind' => $kind, 'severity' => 'negative' === $d['verdict'] ? 'high' : 'medium', 'follow_up' => $f['id'], 'url' => $f['urls'] ? $f['urls'][0] : '', 'links' => $links ),
			'follow_up|' . $f['id'] . '|' . $cp['day']
		);
	}

	/* ------------------------------------------------------------------ learnings */

	/** Add a final result to the site's record of what works, by change type. */
	private function learn( array $f, array $cp ) {
		$d    = $cp['verdict'];
		$all  = get_option( self::LEARNINGS, array() );
		$all  = is_array( $all ) ? $all : array();
		$kind = $f['kind'] ? $f['kind'] : 'change';
		$k    = isset( $all[ $kind ] ) ? $all[ $kind ] : array( 'n' => 0, 'positive' => 0, 'negative' => 0, 'neutral' => 0, 'insufficient' => 0, 'lift_sum' => 0, 'pos_sum' => 0, 'pos_n' => 0, 'recent' => array() );
		$k['n']++;
		$k[ $d['verdict'] ]++;
		if ( 'insufficient' !== $d['verdict'] ) {
			$k['lift_sum'] += $d['clicks']['lift'];
		}
		if ( null !== $d['position']['gain'] ) {
			$k['pos_sum'] += $d['position']['gain'];
			$k['pos_n']++;
		}
		array_unshift( $k['recent'], array( 'title' => $f['title'], 'verdict' => $d['verdict'], 'lift' => $d['clicks']['lift'], 'gain' => $d['position']['gain'], 'date' => $f['change_date'] ) );
		$k['recent']   = array_slice( $k['recent'], 0, 12 );
		$all[ $kind ] = $k;
		update_option( self::LEARNINGS, $all, false );
	}

	/** What worked on this site so far (for the AI and the UI). */
	public function learnings() {
		$all = get_option( self::LEARNINGS, array() );
		$out = array();
		foreach ( is_array( $all ) ? $all : array() as $kind => $k ) {
			$judged = $k['n'] - $k['insufficient'];
			$out[]  = array(
				'kind'          => $kind,
				'measured'      => (int) $k['n'],
				'positive'      => (int) $k['positive'],
				'negative'      => (int) $k['negative'],
				'neutral'       => (int) $k['neutral'],
				'insufficient'  => (int) $k['insufficient'],
				'success_rate'  => $judged > 0 ? round( 100 * $k['positive'] / $judged ) : null,
				'avg_lift'      => $judged > 0 ? round( $k['lift_sum'] / $judged ) : null,
				'avg_pos_gain'  => $k['pos_n'] ? round( $k['pos_sum'] / $k['pos_n'], 1 ) : null,
				'recent'        => array_slice( $k['recent'], 0, 5 ),
			);
		}
		usort( $out, static function ( $a, $b ) {
			return $b['measured'] - $a['measured'];
		} );
		return $out;
	}

	/* ------------------------------------------------------------------ AI review */

	/**
	 * Queue job: the AI explains the result and plans the next steps (may throw Deferred while a
	 * subscription answer is on its way; the queue retries). Steps go to the SEO roadmap.
	 */
	public function ai_review( $id ) {
		$f = $this->get( $id );
		if ( ! $f || ! $f['checkpoints'] ) {
			return true;
		}
		$cp     = end( $f['checkpoints'] );
		$change = array();
		if ( ! empty( $f['ref']['operation_id'] ) ) {
			$op = $this->plugin->operations->get( (int) $f['ref']['operation_id'] );
			if ( $op ) {
				$change = array( 'type' => $op['type'], 'title' => $op['title'], 'items' => array_slice( array_map( array( $this->plugin->operations, 'describe' ), (array) $op['preview'] ), 0, 12 ), 'plan' => array_slice( (array) $op['plan'], 0, 8 ) );
			}
		}
		$input = array(
			'follow_up'   => array( 'title' => $f['title'], 'goal' => $f['goal'], 'kind' => $f['kind'], 'change_date' => $f['change_date'], 'urls' => array_map( 'urldecode', $f['urls'] ) ),
			'change'      => $change,
			'checkpoints' => array_map( static function ( $c ) {
				return array( 'day' => $c['day'], 'partial' => $c['partial'], 'windows' => $c['windows'], 'verdict' => $c['verdict'] );
			}, $f['checkpoints'] ),
			'lines'       => array_slice( $this->lines( $cp ), 0, 15 ),
			'learnings'   => array_slice( $this->learnings(), 0, 8 ),
		);
		$res = $this->plugin->ai->run( 'change_impact_review', $input );
		if ( is_wp_error( $res ) ) {
			return true; // The numbers were already reported; the AI text is a bonus.
		}
		$data = isset( $res['data'] ) ? $res['data'] : $res;
		$f    = $this->get( $id );
		if ( ! $f ) {
			return true;
		}
		$f['ai'] = array(
			'summary'      => isset( $data['summary'] ) ? (string) $data['summary'] : '',
			'causes'       => isset( $data['causes'] ) ? array_slice( (array) $data['causes'], 0, 6 ) : array(),
			'next_actions' => isset( $data['next_actions'] ) ? array_slice( (array) $data['next_actions'], 0, 6 ) : array(),
			'revert'       => ! empty( $data['revert'] ),
			'lesson'       => isset( $data['lesson'] ) ? (string) $data['lesson'] : '',
			'at'           => gmdate( 'Y-m-d H:i:s' ),
		);
		$this->put( $f );
		$added = 0;
		foreach ( $f['ai']['next_actions'] as $a ) {
			if ( empty( $a['title'] ) ) {
				continue;
			}
			$cat = isset( $a['category'] ) && in_array( $a['category'], array( 'indexability', 'technical', '404', 'duplicates', 'onpage', 'links', 'content', 'products', 'offpage', 'other' ), true ) ? $a['category'] : 'content';
			$rid = $this->plugin->recommendations->add(
				array(
					'type'       => 'impact_next_step',
					'key'        => $f['id'] . '|' . sanitize_title( $a['title'] ),
					'category'   => $cat,
					'title'      => (string) $a['title'],
					'reason'     => isset( $a['why'] ) ? (string) $a['why'] : '',
					'url'        => $f['urls'] ? $f['urls'][0] : null,
					'impact'     => isset( $a['impact'] ) ? (int) $a['impact'] : 50,
					'effort'     => isset( $a['effort'] ) ? (int) $a['effort'] : 40,
					'confidence' => 'high' === $cp['verdict']['confidence'] ? 80 : 60,
					'evidence'   => array( 'source' => 'follow_up', 'follow_up' => $f['id'], 'verdict' => $cp['verdict']['verdict'] ),
					'force'      => true,
				)
			);
			$added += $rid ? 1 : 0;
		}
		$text = $f['ai']['summary'];
		if ( $f['ai']['causes'] ) {
			$text .= "\n\n" . __( 'Why:', 'seolity' ) . "\n• " . implode( "\n• ", array_map( 'strval', $f['ai']['causes'] ) );
		}
		if ( $f['ai']['next_actions'] ) {
			$text .= "\n\n" . __( 'Next steps (added to the SEO roadmap):', 'seolity' ) . "\n• " . implode( "\n• ", wp_list_pluck( $f['ai']['next_actions'], 'title' ) );
		}
		if ( $f['ai']['revert'] ) {
			$text .= "\n\n" . __( 'The AI recommends undoing this change (AI Changes → Undo).', 'seolity' );
		}
		if ( $f['ai']['lesson'] ) {
			$text .= "\n\n" . __( 'Lesson for this site:', 'seolity' ) . ' ' . $f['ai']['lesson'];
		}
		/* translators: %s: title */
		$this->plugin->alerts->message( 'ai', sprintf( __( 'AI analysis of “%s”', 'seolity' ), $f['title'] ), $text, array( 'kind' => 'ai', 'follow_up' => $f['id'], 'links' => array( array( 'title' => __( 'SEO roadmap', 'seolity' ), 'route' => '#/actions' ), array( 'title' => __( 'Follow-ups', 'seolity' ), 'route' => '#/alerts' ) ) ), 'follow_up_ai|' . $f['id'] );
		return true;
	}

	/* ------------------------------------------------------------------ sources */

	/** After every applied change: measure what it did (7, 14, 28 days and long-term). */
	public function from_operation( $op ) {
		if ( ! is_array( $op ) || 'done' !== $op['status'] ) {
			return;
		}
		$urls = array();
		foreach ( (array) $op['preview'] as $it ) {
			if ( ! empty( $it['url'] ) ) {
				$urls[] = $it['url'];
			} elseif ( ! empty( $it['target'] ) && 'redirect' === ( isset( $it['kind'] ) ? $it['kind'] : '' ) ) {
				// A merge / redirect: measure the destination together with the old address.
				$urls[] = $it['target'];
				if ( ! empty( $it['source'] ) ) {
					$urls[] = $it['source'];
				}
			} elseif ( 'change' === ( isset( $it['kind'] ) ? $it['kind'] : '' ) && ! empty( $it['id'] ) ) {
				$c = $this->plugin->changes->get( (int) $it['id'] );
				if ( $c && 'post' === $c['object_type'] && 'publish' === get_post_status( (int) $c['object_id'] ) ) {
					$urls[] = (string) get_permalink( (int) $c['object_id'] );
				}
			}
		}
		$urls = array_values( array_unique( array_filter( $urls ) ) );
		if ( ! $urls ) {
			return;
		}
		$kind = ! empty( $op['type'] ) ? sanitize_key( $op['type'] ) : 'change';
		$this->add(
			array(
				'title'  => $op['title'] ? $op['title'] : __( 'Applied change', 'seolity' ),
				'goal'   => __( 'More clicks and a better position for the changed pages.', 'seolity' ),
				'urls'   => array_slice( $urls, 0, 8 ),
				'days'   => 28,
				'source' => 'operation',
				'kind'   => $kind,
				'ref'    => array( 'operation_id' => (int) $op['id'] ),
			)
		);
	}

	private function abs_url( $u ) {
		$u = trim( (string) $u );
		if ( '' === $u ) {
			return '';
		}
		return esc_url_raw( preg_match( '#^https?://#', $u ) ? $u : home_url( '/' . ltrim( $u, '/' ) ) );
	}
}
