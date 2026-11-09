<?php
/**
 * The site's SEO brain: everything SEOlity has learned about THIS site, visible to the owner.
 *
 *  1. observations() — measured facts from the site's own data, positive and negative: growth,
 *     the site's own CTR per position against typical CTR, which query types work (questions,
 *     buying, brand), what the best pages have in common (length, internal links), striking-distance
 *     queries on the way to position 1, devices, best weekday, pooled experiment results by change
 *     type and the measured impact of applied changes (follow-ups).
 *  2. think() — the AI turns the observations into beliefs ("on this site X works, because …"),
 *     wins, open questions, experiments worth running and a strategy towards position 1. Weekly
 *     and on demand; stored, shown on the Experiments page and used in every AI prompt.
 *
 * Learning no longer waits for single experiments to be significant: on a small site most single
 * page experiments are inconclusive, so effects are pooled by change type.
 *
 * @package SEOlity
 */

namespace SEOlity\Experiments;

use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class SiteBrain {

	const OPTION = 'seolity_site_brain';

	/** Typical organic CTR by position (rough industry curve, used only as a yardstick). */
	const CTR_CURVE = array( 1 => 28, 2 => 15, 3 => 11, 4 => 8, 5 => 7, 6 => 5, 7 => 4, 8 => 3, 9 => 2.5, 10 => 2 );

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function stored() {
		$s = get_option( self::OPTION, array() );
		return is_array( $s ) ? $s : array();
	}

	/** Everything for the UI. */
	public function view() {
		$s = $this->stored();
		return array(
			'observations' => $this->observations(),
			'ai'           => isset( $s['ai'] ) ? $s['ai'] : null,
			'thought_at'   => isset( $s['at'] ) ? $s['at'] : null,
			'thinking'     => (bool) $this->plugin->queue->find_unique( 'site_brain_think', true ),
		);
	}

	/**
	 * Measured observations. Each: area, kind (good | bad | info), text, value, confidence.
	 */
	public function observations() {
		$db  = $this->plugin->db;
		$pid = $db->project_id();
		$out = array();
		$max = (string) $db->value( 'SELECT MAX(date) FROM {gsc_daily} WHERE project_id = %d', array( $pid ) );
		if ( '' !== $max ) {
			$cur  = $db->row( 'SELECT SUM(clicks) c, SUM(impressions) i, SUM(position * impressions) / NULLIF(SUM(impressions),0) p FROM {gsc_daily} WHERE project_id = %d AND date > DATE_SUB(%s, INTERVAL 28 DAY)', array( $pid, $max ) );
			$prev = $db->row( 'SELECT SUM(clicks) c, SUM(impressions) i, SUM(position * impressions) / NULLIF(SUM(impressions),0) p FROM {gsc_daily} WHERE project_id = %d AND date <= DATE_SUB(%s, INTERVAL 28 DAY) AND date > DATE_SUB(%s, INTERVAL 56 DAY)', array( $pid, $max, $max ) );
			if ( $cur && (int) $prev['i'] > 0 ) {
				$dc = (int) $prev['c'] ? round( 100 * ( $cur['c'] - $prev['c'] ) / $prev['c'] ) : null;
				$di = round( 100 * ( $cur['i'] - $prev['i'] ) / $prev['i'] );
				/* translators: 1 clicks 2 change % 3 impressions 4 change % */
				$out[] = $this->obs( 'growth', ( null !== $dc && $dc >= 0 ) || $di >= 0 ? 'good' : 'bad', sprintf( __( 'Last 28 days: %1$d clicks (%2$s%%) and %3$d impressions (%4$s%%) compared with the 28 days before.', 'seolity' ), (int) $cur['c'], null === $dc ? '—' : ( $dc > 0 ? '+' : '' ) . $dc, (int) $cur['i'], ( $di > 0 ? '+' : '' ) . $di ), 'high' );
			}
			// Best weekday.
			$days = $db->rows( 'SELECT DAYOFWEEK(date) d, AVG(clicks) c FROM {gsc_daily} WHERE project_id = %d AND date > DATE_SUB(%s, INTERVAL 84 DAY) GROUP BY DAYOFWEEK(date) ORDER BY c DESC', array( $pid, $max ) );
			if ( count( (array) $days ) >= 7 && (float) $days[0]['c'] > 0 ) {
				$names = array( 1 => __( 'Sunday', 'seolity' ), 2 => __( 'Monday', 'seolity' ), 3 => __( 'Tuesday', 'seolity' ), 4 => __( 'Wednesday', 'seolity' ), 5 => __( 'Thursday', 'seolity' ), 6 => __( 'Friday', 'seolity' ), 7 => __( 'Saturday', 'seolity' ) );
				/* translators: 1 best day 2 worst day */
				$out[] = $this->obs( 'rhythm', 'info', sprintf( __( 'Most search clicks come on %1$s, fewest on %2$s — publish and post on social media a day before the busy day.', 'seolity' ), $names[ (int) $days[0]['d'] ], $names[ (int) end( $days )['d'] ] ), 'medium' );
			}
		}
		$pq = (string) $db->value( 'SELECT MAX(date) FROM {gsc_page_query} WHERE project_id = %d', array( $pid ) );
		if ( '' !== $pq ) {
			// The site's own CTR per position vs a typical curve.
			$rows = $db->rows( 'SELECT ROUND(position) pos, SUM(clicks) c, SUM(impressions) i FROM {gsc_page_query} WHERE project_id = %d AND date > DATE_SUB(%s, INTERVAL 90 DAY) AND position < 10.5 GROUP BY ROUND(position) HAVING i >= 30', array( $pid, $pq ) );
			$under = array();
			$over  = array();
			foreach ( (array) $rows as $r ) {
				$pos = max( 1, (int) $r['pos'] );
				$ctr = 100 * $r['c'] / max( 1, $r['i'] );
				if ( ! isset( self::CTR_CURVE[ $pos ] ) ) {
					continue;
				}
				if ( $ctr < self::CTR_CURVE[ $pos ] * 0.6 ) {
					$under[] = sprintf( '%d: %s%%', $pos, round( $ctr, 1 ) );
				} elseif ( $ctr > self::CTR_CURVE[ $pos ] * 1.2 ) {
					$over[] = sprintf( '%d: %s%%', $pos, round( $ctr, 1 ) );
				}
			}
			if ( $over ) {
				/* translators: %s: positions */
				$out[] = $this->obs( 'ctr', 'good', sprintf( __( 'Titles and snippets attract more clicks than usual at positions %s — what you write in titles works.', 'seolity' ), implode( ', ', $over ) ), 'medium' );
			}
			if ( $under ) {
				/* translators: %s: positions */
				$out[] = $this->obs( 'ctr', 'bad', sprintf( __( 'Fewer clicks than usual at positions %s — rewrite titles and descriptions of those pages (CTR).', 'seolity' ), implode( ', ', $under ) ), 'medium' );
			}
			// Query types.
			$brand = $this->brand_terms();
			$types = array(
				'question' => '(چیست|چیه|چگونه|چطور|چجوری|چرا|آیا|کدام|بهترین|طرز|خواص|مضرات|فرق|تفاوت|how|what|why|best)',
				'buy'      => '(خرید|قیمت|فروش|سفارش|ارزان|تخفیف|buy|price)',
			);
			$res = array();
			foreach ( $types as $k => $re ) {
				$res[ $k ] = $db->row( 'SELECT SUM(clicks) c, SUM(impressions) i, SUM(position*impressions)/NULLIF(SUM(impressions),0) p FROM {gsc_page_query} WHERE project_id = %d AND date > DATE_SUB(%s, INTERVAL 90 DAY) AND query REGEXP %s', array( $pid, $pq, $re ) );
			}
			if ( $brand ) {
				$res['brand'] = $db->row( 'SELECT SUM(clicks) c, SUM(impressions) i, SUM(position*impressions)/NULLIF(SUM(impressions),0) p FROM {gsc_page_query} WHERE project_id = %d AND date > DATE_SUB(%s, INTERVAL 90 DAY) AND query REGEXP %s', array( $pid, $pq, '(' . implode( '|', array_map( 'preg_quote', $brand ) ) . ')' ) );
			}
			$all   = $db->row( 'SELECT SUM(clicks) c, SUM(impressions) i FROM {gsc_page_query} WHERE project_id = %d AND date > DATE_SUB(%s, INTERVAL 90 DAY)', array( $pid, $pq ) );
			$label = array( 'question' => __( 'Question searches (what is, how to, benefits …)', 'seolity' ), 'buy' => __( 'Buying searches (buy, price …)', 'seolity' ), 'brand' => __( 'Brand searches', 'seolity' ) );
			foreach ( $res as $k => $r ) {
				if ( ! $r || (int) $r['i'] < 20 || ! (int) $all['i'] ) {
					continue;
				}
				$share = round( 100 * $r['i'] / $all['i'] );
				$ctr   = round( 100 * $r['c'] / $r['i'], 1 );
				/* translators: 1 type 2 share 3 clicks 4 ctr 5 position */
				$out[] = $this->obs( 'queries', 'buy' === $k && $r['p'] > 10 ? 'bad' : 'info', sprintf( __( '%1$s: %2$d%% of impressions, %3$d clicks, CTR %4$s%%, average position %5$s.', 'seolity' ), $label[ $k ], $share, (int) $r['c'], $ctr, round( (float) $r['p'], 1 ) ), (int) $r['i'] > 300 ? 'high' : 'medium' );
			}
			// Position 1 / top 3 and striking distance.
			$q = $db->rows( 'SELECT query, SUM(impressions) i, SUM(clicks) c, SUM(position*impressions)/NULLIF(SUM(impressions),0) p FROM {gsc_page_query} WHERE project_id = %d AND date > DATE_SUB(%s, INTERVAL 28 DAY) GROUP BY query HAVING i >= 10', array( $pid, $pq ) );
			$top1 = array();
			$top3 = 0;
			$near = array();
			foreach ( (array) $q as $r ) {
				if ( $r['p'] <= 1.5 ) {
					$top1[] = $r['query'];
				}
				if ( $r['p'] <= 3.5 ) {
					$top3++;
				} elseif ( $r['p'] <= 15 ) {
					$near[] = $r;
				}
			}
			if ( $top1 || $top3 ) {
				/* translators: 1 count position 1 2 examples 3 top 3 */
				$out[] = $this->obs( 'rank1', 'good', sprintf( __( 'Already position 1 for %1$d searches (%2$s) and top 3 for %3$d.', 'seolity' ), count( $top1 ), implode( '، ', array_slice( $top1, 0, 5 ) ), $top3 ), 'high' );
			}
			if ( $near ) {
				usort( $near, static function ( $a, $b ) {
					return $b['i'] - $a['i'];
				} );
				/* translators: 1 count 2 examples */
				$out[] = $this->obs( 'rank1', 'info', sprintf( __( '%1$d searches are on positions 4–15 with real demand — the shortest road to position 1: %2$s.', 'seolity' ), count( $near ), implode( '، ', array_map( static function ( $r ) {
					return $r['query'] . ' (' . round( (float) $r['p'], 1 ) . ')';
				}, array_slice( $near, 0, 6 ) ) ) ), 'high' );
			}
			// What the best pages have in common.
			$top = $db->rows( 'SELECT page_hash, SUM(clicks) c FROM {gsc_page_query} WHERE project_id = %d AND date > DATE_SUB(%s, INTERVAL 90 DAY) GROUP BY page_hash ORDER BY c DESC LIMIT 10', array( $pid, $pq ) );
			if ( count( (array) $top ) >= 5 ) {
				$hashes = wp_list_pluck( $top, 'page_hash' );
				$in     = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );
				$a      = $db->row( "SELECT AVG(word_count) w, AVG(inlinks) l FROM {urls} WHERE project_id = %d AND url_hash IN ($in) AND status_code = 200", array_merge( array( $pid ), $hashes ) );
				$b      = $db->row( "SELECT AVG(word_count) w, AVG(inlinks) l FROM {urls} WHERE project_id = %d AND url_hash NOT IN ($in) AND status_code = 200 AND indexable = 1 AND word_count > 150", array_merge( array( $pid ), $hashes ) );
				if ( $a && $b && (float) $a['w'] > 0 && (float) $b['w'] > 0 ) {
					/* translators: 1 words top 2 words others 3 links top 4 links others */
					$out[] = $this->obs( 'content', 'info', sprintf( __( 'The 10 pages with the most clicks have about %1$d words and %3$d internal links each; the other pages about %2$d words and %4$d links.', 'seolity' ), (int) $a['w'], (int) $b['w'], (int) $a['l'], (int) $b['l'] ), 'medium' );
				}
			}
		}
		// Devices.
		$dev = $db->rows( "SELECT dim_value d, SUM(clicks) c, SUM(impressions) i, SUM(position*impressions)/NULLIF(SUM(impressions),0) p FROM {gsc_breakdown} WHERE project_id = %d AND dimension = 'device' AND date > DATE_SUB(CURDATE(), INTERVAL 90 DAY) GROUP BY dim_value ORDER BY i DESC", array( $pid ) );
		if ( count( (array) $dev ) >= 2 ) {
			$tot = array_sum( wp_list_pluck( $dev, 'i' ) );
			$out[] = $this->obs( 'devices', 'info', implode( ' · ', array_map( static function ( $r ) use ( $tot ) {
				/* translators: 1 device 2 share 3 ctr 4 position */
				return sprintf( __( '%1$s %2$d%% (CTR %3$s%%, position %4$s)', 'seolity' ), strtolower( $r['d'] ), round( 100 * $r['i'] / max( 1, $tot ) ), round( 100 * $r['c'] / max( 1, $r['i'] ), 1 ), round( (float) $r['p'], 1 ) );
			}, $dev ) ), 'high' );
		}
		// Pooled experiments and measured impact of changes.
		foreach ( array_slice( $this->plugin->learning->insights(), 0, 8 ) as $i ) {
			$out[] = $this->obs( 'experiments', $i['avg_delta'] > 0 ? 'good' : 'bad', $i['text'], $i['samples'] >= 10 ? 'high' : ( $i['samples'] >= 5 ? 'medium' : 'low' ) );
		}
		foreach ( array_slice( $this->plugin->followups->learnings(), 0, 6 ) as $l ) {
			if ( null === $l['success_rate'] ) {
				continue;
			}
			/* translators: 1 type 2 measured 3 rate 4 lift */
			$out[] = $this->obs( 'impact', $l['success_rate'] >= 50 ? 'good' : 'bad', sprintf( __( 'Applied changes of type “%1$s”: %2$d measured, %3$d%% positive, clicks %4$s%% vs the site trend.', 'seolity' ), $l['kind'], $l['measured'], $l['success_rate'], null === $l['avg_lift'] ? '—' : ( $l['avg_lift'] > 0 ? '+' : '' ) . $l['avg_lift'] ), $l['measured'] >= 5 ? 'high' : 'low' );
		}
		return $out;
	}

	private function obs( $area, $kind, $text, $confidence ) {
		return array( 'area' => $area, 'kind' => $kind, 'text' => $text, 'confidence' => $confidence );
	}

	/** Words of the site name / domain that identify brand searches. */
	private function brand_terms() {
		$host  = preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$terms = array( strtok( $host, '.' ) );
		foreach ( preg_split( '/[\s|\-–—:]+/u', (string) get_bloginfo( 'name' ) ) as $w ) {
			if ( mb_strlen( $w ) >= 3 ) {
				$terms[] = $w;
			}
		}
		return array_values( array_unique( array_filter( $terms ) ) );
	}

	/** Queue job: the AI thinks about the observations (may throw Deferred; the queue waits). */
	public function think() {
		$obs = $this->observations();
		if ( ! $obs ) {
			return true;
		}
		$res = $this->plugin->ai->run( 'site_brain', array( 'observations' => $obs, 'previous_beliefs' => isset( $this->stored()['ai']['beliefs'] ) ? $this->stored()['ai']['beliefs'] : array() ), array( 'knowledge_query' => 'measure seo experiments learnings ctr position competitors rank 1' ) );
		if ( is_wp_error( $res ) ) {
			return true;
		}
		update_option( self::OPTION, array( 'ai' => $res['data'], 'at' => gmdate( 'Y-m-d H:i:s' ) ), false );
		$d = $res['data'];
		$this->plugin->alerts->message( 'ai', __( 'What SEOlity has learned about your site', 'seolity' ), (string) $d['summary'] . ( ! empty( $d['wins'] ) ? "\n\n" . __( 'Going well:', 'seolity' ) . "\n• " . implode( "\n• ", array_slice( (array) $d['wins'], 0, 5 ) ) : '' ), array( 'kind' => 'ai', 'links' => array( array( 'title' => __( 'The site’s SEO brain', 'seolity' ), 'route' => '#/experiments' ) ) ), 'site_brain|' . gmdate( 'Y-W' ) );
		return true;
	}

	/** Short lines for the AI system prompt. */
	public function prompt_lines() {
		$s = $this->stored();
		$out = array();
		foreach ( array_slice( isset( $s['ai']['beliefs'] ) ? (array) $s['ai']['beliefs'] : array(), 0, 6 ) as $b ) {
			if ( ! empty( $b['belief'] ) ) {
				$out[] = $b['belief'] . ( ! empty( $b['confidence'] ) ? ' (' . $b['confidence'] . ')' : '' );
			}
		}
		return $out;
	}
}
