<?php
/**
 * 404 monitor (section 26): logs 404s (URL, referrer, hits, first/last seen),
 * filters bots & scanners, and suggests the best redirect target using slug
 * similarity, WordPress search and (optionally) AI.
 *
 * @package SEOlity
 */

namespace SEOlity\Redirects;

use SEOlity\Analysis\Text;
use SEOlity\Core\Database;
use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class NotFoundMonitor {

	const BOT_RE     = '/bot|crawl|spider|slurp|bing|yandex|baidu|semrush|ahrefs|mj12|dotbot|petal|python|curl|wget|go-http|scrapy|headless/i';
	const SCANNER_RE = '#(wp-login|xmlrpc|\.env|\.git|phpmyadmin|wp-config|\.sql|\.bak|cgi-bin|/vendor/|eval-stdin|\.aspx?$|/admin\.php|/shell)#i';

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/** Called on template_redirect (late) for 404 responses. */
	public function maybe_log() {
		if ( ! is_404() || ! $this->plugin->settings->get( 'log_404' ) ) {
			return;
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( '' === $uri || preg_match( self::SCANNER_RE, $uri ) || mb_strlen( $uri ) > 1500 ) {
			return;
		}
		$ua     = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$is_bot = (bool) preg_match( self::BOT_RE, $ua );
		if ( $is_bot && ! $this->plugin->settings->get( 'log_404_bots' ) && false === stripos( $ua, 'googlebot' ) ) {
			return;
		}
		$ref  = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
		$path = Manager::normalize_source( $uri );
		$db   = $this->plugin->db;
		$now  = Database::now();
		$db->query(
			'INSERT INTO {not_found} (project_id,url,url_hash,referrer,user_agent,is_bot,hits,status,first_seen,last_seen) VALUES (%d,%s,%s,%s,%s,%d,1,%s,%s,%s)
			ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = VALUES(last_seen), referrer = IF(VALUES(referrer) <> \'\', VALUES(referrer), referrer), user_agent = VALUES(user_agent)',
			array( $db->project_id(), $path, Database::hash( $path ), substr( $ref, 0, 2000 ), substr( $ua, 0, 255 ), $is_bot ? 1 : 0, 'open', $now, $now )
		);
	}

	public function query( array $f ) {
		$db    = $this->plugin->db;
		// New entries are sorted by evidence in the background (probes without value are hidden).
		if ( empty( $f['no_classify'] ) && (int) $this->plugin->db->value( "SELECT COUNT(*) FROM {not_found} WHERE project_id = %d AND status = 'open' AND verdict IS NULL", array( $this->plugin->db->project_id() ) ) ) {
			$this->plugin->queue->push( 'not_found_classify', array(), array( 'unique' => true, 'priority' => 1 ) );
			\SEOlity\Queue\Scheduler::kick();
		}
		$where = array( 'project_id = %d', 'status = %s' );
		$args  = array( $db->project_id(), ! empty( $f['status'] ) ? $f['status'] : 'open' );
		if ( ! empty( $f['search'] ) ) {
			$where[] = 'url LIKE %s';
			$args[]  = '%' . $db->wpdb->esc_like( $f['search'] ) . '%';
		}
		$per  = isset( $f['per_page'] ) ? max( 1, min( 200, (int) $f['per_page'] ) ) : 50;
		$page = isset( $f['page'] ) ? max( 1, (int) $f['page'] ) : 1;
		return array(
			'items' => $db->rows( 'SELECT * FROM {not_found} WHERE ' . implode( ' AND ', $where ) . ' ORDER BY hits DESC, last_seen DESC LIMIT %d OFFSET %d', array_merge( $args, array( $per, ( $page - 1 ) * $per ) ) ),
			'total' => (int) $db->value( 'SELECT COUNT(*) FROM {not_found} WHERE ' . implode( ' AND ', $where ), $args ),
			'stats' => array(
				'open'       => (int) $db->value( "SELECT COUNT(*) FROM {not_found} WHERE project_id = %d AND status = 'open'", array( $db->project_id() ) ),
				'hits_7d'    => (int) $db->value( "SELECT COALESCE(SUM(hits),0) FROM {not_found} WHERE project_id = %d AND status = 'open' AND last_seen >= %s", array( $db->project_id(), gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ) ) ),
				'new_24h'    => (int) $db->value( 'SELECT COUNT(*) FROM {not_found} WHERE project_id = %d AND first_seen >= %s', array( $db->project_id(), gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) ),
				'with_referrer' => (int) $db->value( "SELECT COUNT(*) FROM {not_found} WHERE project_id = %d AND status = 'open' AND referrer <> ''", array( $db->project_id() ) ),
				'noise'      => (int) $db->value( "SELECT COUNT(*) FROM {not_found} WHERE project_id = %d AND status = 'noise'", array( $db->project_id() ) ),
				'important'  => (int) $db->value( "SELECT COUNT(*) FROM {not_found} WHERE project_id = %d AND status = 'open' AND verdict = 'important'", array( $db->project_id() ) ),
				'sorting'    => (int) $db->value( "SELECT COUNT(*) FROM {not_found} WHERE project_id = %d AND status = 'open' AND verdict IS NULL", array( $db->project_id() ) ),
				'no_target'  => (int) $db->value( "SELECT COUNT(*) FROM {not_found} WHERE project_id = %d AND status = 'open' AND suggested_target = ''", array( $db->project_id() ) ),
			),
		);
	}

	/** Redirect a logged 404 (to its suggestion or a given target). */
	public function redirect( $id, $target = '', $type = 301 ) {
		$row = $this->plugin->db->row( 'SELECT * FROM {not_found} WHERE id = %d', array( (int) $id ) );
		if ( ! $row ) {
			return new \WP_Error( 'seolity_404', __( 'Entry not found.', 'seolity' ), array( 'status' => 404 ) );
		}
		$target = $target ? $target : $row['suggested_target'];
		$res    = $this->plugin->redirects->create( $row['url'], $target, $type, false, '404_monitor' );
		if ( ! is_wp_error( $res ) ) {
			$this->plugin->db->update( 'not_found', array( 'status' => 'redirected' ), array( 'id' => (int) $id ) );
		}
		return $res;
	}

	public function set_status( $id, $status ) {
		$status = in_array( $status, array( 'open', 'ignored', 'noise' ), true ) ? $status : 'ignored';
		$data   = array( 'status' => $status );
		if ( 'open' === $status ) {
			// Brought back by the owner: never hidden again automatically.
			$data['verdict']        = 'owner_kept';
			$data['verdict_reason'] = __( 'You brought it back to the list.', 'seolity' );
		} elseif ( 'noise' === $status ) {
			$data['verdict']        = 'owner_hidden';
			$data['verdict_reason'] = __( 'You hid it as not important.', 'seolity' );
		}
		return (bool) $this->plugin->db->update( 'not_found', $data, array( 'id' => (int) $id ) );
	}

	/** Same status for many entries (hide / bring back / ignore a selection). */
	public function set_status_many( array $ids, $status ) {
		$n = 0;
		foreach ( array_slice( array_unique( array_map( 'intval', $ids ) ), 0, 500 ) as $id ) {
			$n += $this->set_status( $id, $status ) ? 1 : 0;
		}
		return $n;
	}

	/** Background: compute suggestions for unresolved 404s. */
	public function suggest_pending( $again = false ) {
		$cond = $again ? "(suggested_target IS NULL OR suggested_target = '' OR suggestion_score < 60)" : 'suggested_target IS NULL';
		$rows = $this->plugin->db->rows( "SELECT * FROM {not_found} WHERE project_id = %d AND status = 'open' AND $cond ORDER BY hits DESC LIMIT 60", array( $this->plugin->db->project_id() ) );
		$ai   = 0;
		foreach ( $rows as $r ) {
			$cands = $this->candidates( $r['url'] );
			$best  = $cands ? $cands[0] : null;
			if ( $cands && ( $best['score'] < 75 ) && $ai < 8 && $this->plugin->ai->is_ready() && (int) $r['hits'] >= 3 ) {
				$res = $this->plugin->ai->run( 'redirect_target', array( 'broken_url' => $r['url'], 'referrer' => $r['referrer'], 'candidates' => array_slice( $cands, 0, 8 ) ) );
				$ai++;
				if ( ! is_wp_error( $res ) ) {
					$best = '' !== $res['data']['target_url'] ? array( 'url' => $res['data']['target_url'], 'score' => (int) $res['data']['confidence'] ) : null;
				}
			}
			$this->plugin->db->update(
				'not_found',
				array(
					'suggested_target' => $best ? $best['url'] : '',
					'suggestion_score' => $best ? min( 100, (int) $best['score'] ) : 0,
				),
				array( 'id' => (int) $r['id'] )
			);
		}
		return true;
	}

	/**
	 * Comparable form of a slug or title: decoded, Persian/Arabic letters unified, zero-width
	 * non-joiners and punctuation removed — so "کره‌ای" = "کرهای", "؛" / "؟" / "%d" do not matter.
	 */
	public static function slug_key( $text ) {
		$t = Text::normalize( rawurldecode( (string) $text ) );
		$t = preg_replace( '/\.(html?|php|aspx?)$/u', '', $t );
		$t = preg_replace( '/%[0-9a-f]{0,2}/iu', ' ', $t );
		$t = str_replace( array( "\u{200C}", "\u{200D}", "\u{200F}", "\u{200E}" ), '', $t );
		$t = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $t );
		return trim( preg_replace( '/\s+/u', ' ', $t ) );
	}

	/** Every published page/post/product slug as a comparable key (cached for 30 minutes). */
	private function slug_index() {
		$idx = get_transient( 'seolity_slug_index' );
		if ( is_array( $idx ) ) {
			return $idx;
		}
		global $wpdb;
		$types = array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment', 'elementor_library' ) ) );
		$in    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$rows  = $types ? $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_name, post_title FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ($in) ORDER BY ID DESC LIMIT 5000", $types ), ARRAY_A ) : array(); // phpcs:ignore WordPress.DB
		$idx   = array();
		foreach ( (array) $rows as $r ) {
			$idx[] = array( (int) $r['ID'], self::slug_key( $r['post_name'] ), self::slug_key( $r['post_title'] ) );
		}
		set_transient( 'seolity_slug_index', $idx, 30 * MINUTE_IN_SECONDS );
		return $idx;
	}

	/** Similarity 0–100 of two keys: the better of character similarity and shared words. */
	private static function key_score( $a, $b ) {
		if ( '' === $a || '' === $b ) {
			return 0;
		}
		if ( $a === $b ) {
			return 100;
		}
		$ca = str_replace( ' ', '', $a );
		$cb = str_replace( ' ', '', $b );
		if ( $ca === $cb ) {
			return 99;
		}
		similar_text( $ca, $cb, $pct );
		$wa = array_unique( array_filter( explode( ' ', $a ), static function ( $w ) {
			return mb_strlen( $w ) >= 2;
		} ) );
		$wb = array_unique( array_filter( explode( ' ', $b ), static function ( $w ) {
			return mb_strlen( $w ) >= 2;
		} ) );
		$shared  = count( array_intersect( $wa, $wb ) );
		$jaccard = $wa && $wb ? $shared / count( array_unique( array_merge( $wa, $wb ) ) ) * 100 : 0;
		return (int) round( max( $pct, $jaccard ) );
	}

	/**
	 * Candidate targets ranked by how well they match the broken address: the same address
	 * without an old prefix (e.g. "/blog/"), the same slug written differently, similar slugs and
	 * titles, WordPress search, and the parent section.
	 */
	public function candidates( $broken ) {
		$path  = (string) wp_parse_url( $broken, PHP_URL_PATH );
		$segs  = array_values( array_filter( explode( '/', trim( $path, '/' ) ) ) );
		$slug  = $segs ? end( $segs ) : '';
		$key   = self::slug_key( $slug );
		$words = trim( preg_replace( '/[-_]+/', ' ', preg_replace( '/\.(html?|php|aspx?)$/', '', urldecode( $slug ) ) ) );
		if ( '' === $key ) {
			return array( array( 'url' => home_url( '/' ), 'title' => get_bloginfo( 'name' ), 'score' => 20 ) );
		}
		$out = array();
		$add = static function ( $id, $score ) use ( &$out ) {
			$id = (int) $id;
			if ( $id && ( ! isset( $out[ $id ] ) || $out[ $id ]['score'] < $score ) ) {
				$out[ $id ] = array( 'url' => get_permalink( $id ), 'title' => get_the_title( $id ), 'score' => (int) $score );
			}
		};
		// 1. The same address in the current structure (old "/blog/x" → "/x" and the other way round).
		if ( count( $segs ) > 1 && in_array( strtolower( rawurldecode( $segs[0] ) ), array( 'blog', 'mag', 'news', 'article', 'articles', 'post', 'posts', 'fa', 'en' ), true ) ) {
			$add( url_to_postid( home_url( '/' . implode( '/', array_slice( $segs, 1 ) ) . '/' ) ), 97 );
		}
		// 2. Slug and title keys of every published item (handles spelling, half-space and punctuation).
		foreach ( $this->slug_index() as $row ) {
			$score = max( self::key_score( $key, $row[1] ), (int) round( 0.95 * self::key_score( $key, $row[2] ) ) );
			if ( $score >= 55 ) {
				$add( $row[0], $score );
			}
		}
		// 3. WordPress search on the most telling words (all-words search fails on spelling variants).
		$long = array_slice( array_filter( explode( ' ', $key ), static function ( $w ) {
			return mb_strlen( $w ) >= 3;
		} ), 0, 3 );
		$types = array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment', 'elementor_library' ) ) );
		foreach ( array_unique( array( $words, implode( ' ', $long ) ) ) as $q ) {
			if ( '' === trim( $q ) ) {
				continue;
			}
			foreach ( get_posts( array( 'post_type' => $types, 'post_status' => 'publish', 's' => $q, 'posts_per_page' => 8, 'fields' => 'ids', 'suppress_filters' => true ) ) as $id ) {
				$add( $id, max( self::key_score( $key, self::slug_key( get_post_field( 'post_name', $id ) ) ), (int) round( Text::similarity( $words, get_the_title( $id ) ) * 100 ) ) );
			}
		}
		$out = array_values( $out );
		// 4. Parent path fallback (e.g. category of a deleted product).
		if ( count( $segs ) > 1 ) {
			$parent = '/' . implode( '/', array_slice( $segs, 0, -1 ) ) . '/';
			$pid    = url_to_postid( home_url( $parent ) );
			$term   = get_term_by( 'slug', sanitize_title( urldecode( $segs[ count( $segs ) - 2 ] ) ), 'product_cat' );
			if ( $pid ) {
				$out[] = array( 'url' => get_permalink( $pid ), 'title' => get_the_title( $pid ), 'score' => 40 );
			} elseif ( $term && ! is_wp_error( $term ) ) {
				$out[] = array( 'url' => get_term_link( $term ), 'title' => $term->name, 'score' => 45 );
			}
		}
		usort( $out, static function ( $a, $b ) {
			return $b['score'] <=> $a['score'];
		} );
		return array_slice( $out, 0, 10 );
	}
}
