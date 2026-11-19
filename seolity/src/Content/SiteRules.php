<?php
/**
 * Site rules: the owner's lasting decisions, binding for every part of SEOlity.
 *
 * Two kinds, stored together:
 * - Query ownership: "the search «خرید کیمچی» belongs to /product-category/kimchi/ — other
 *   pages must not target it" (optionally naming the pages that must stop competing). Enforced in
 *   code (no other page can get that focus keyword; Road to #1 works on the owner page) and in
 *   every AI prompt (proposals consolidate signals to the owner page instead of competing).
 * - General rules: any sentence the owner wants respected ("never redirect products to the home
 *   page", "prices are in Toman", …) — injected into every AI prompt.
 *
 * Rules come from the owner (Site rules page) or from the chat (kind "rule"); both can be edited,
 * paused or removed at any time.
 *
 * @package SEOlity
 */

namespace SEOlity\Content;

use SEOlity\Analysis\Text;
use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class SiteRules {

	const OPTION = 'seolity_site_rules';
	const MAX    = 100;

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/** @return array[] Newest first. */
	public function all() {
		$r = get_option( self::OPTION, array() );
		return is_array( $r ) ? array_values( $r ) : array();
	}

	public function active() {
		return array_values(
			array_filter(
				$this->all(),
				static function ( $r ) {
					return ! empty( $r['active'] );
				}
			)
		);
	}

	public function get( $id ) {
		foreach ( $this->all() as $r ) {
			if ( $r['id'] === $id ) {
				return $r;
			}
		}
		return null;
	}

	private function store( array $rules ) {
		update_option( self::OPTION, array_slice( array_values( $rules ), 0, self::MAX ), false );
	}

	/** Comparable form of a search query. */
	public static function qkey( $q ) {
		$q = Text::normalize( (string) $q );
		$q = str_replace( array( "\u{200C}", "\u{200D}" ), ' ', $q );
		return trim( preg_replace( '/\s+/u', ' ', $q ) );
	}

	/** Comparable form of a page address (path, no trailing slash, decoded). */
	public static function pkey( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$path = rawurldecode( '' === $path ? '/' : $path );
		return '/' === $path ? '/' : untrailingslashit( $path );
	}

	/**
	 * Create or update a rule. One ownership rule per query: saving another owner for the same
	 * query replaces it.
	 *
	 * @return array|\WP_Error The saved rule.
	 */
	public function save( array $in ) {
		$text  = trim( sanitize_textarea_field( isset( $in['text'] ) ? (string) $in['text'] : '' ) );
		$query = trim( sanitize_text_field( isset( $in['query'] ) ? (string) $in['query'] : '' ) );
		$url   = isset( $in['url'] ) ? esc_url_raw( trim( (string) $in['url'] ) ) : '';
		$avoid = array_values( array_filter( array_unique( array_map( 'esc_url_raw', array_map( 'trim', isset( $in['avoid'] ) ? (array) $in['avoid'] : array() ) ) ) ) );
		if ( '' !== $query && '' === $url ) {
			return new \WP_Error( 'seolity_rule', __( 'A search can only be given to a page: choose the page that should rank for it.', 'seolity' ), array( 'status' => 400 ) );
		}
		if ( '' === $text && '' === $query ) {
			return new \WP_Error( 'seolity_rule', __( 'Write the rule, or choose a search and the page it belongs to.', 'seolity' ), array( 'status' => 400 ) );
		}
		$avoid = array_values(
			array_filter(
				$avoid,
				static function ( $a ) use ( $url ) {
					return self::pkey( $a ) !== self::pkey( $url );
				}
			)
		);
		$rules = $this->all();
		$id    = isset( $in['id'] ) ? sanitize_key( (string) $in['id'] ) : '';
		if ( '' === $id && '' !== $query ) {
			foreach ( $rules as $r ) {
				if ( '' !== $r['query'] && self::qkey( $r['query'] ) === self::qkey( $query ) ) {
					$id = $r['id'];
				}
			}
		}
		$now  = gmdate( 'c' );
		$rule = array(
			'id'      => '' !== $id ? $id : substr( md5( wp_generate_password( 12, false ) . microtime() ), 0, 12 ),
			'title'   => mb_substr( sanitize_text_field( isset( $in['title'] ) ? (string) $in['title'] : '' ), 0, 160 ),
			'text'    => mb_substr( $text, 0, 1200 ),
			'query'   => mb_substr( $query, 0, 200 ),
			'url'     => $url,
			'avoid'   => array_slice( $avoid, 0, 20 ),
			'source'  => isset( $in['source'] ) && 'chat' === $in['source'] ? 'chat' : 'owner',
			'active'  => ! isset( $in['active'] ) || (bool) $in['active'],
			'created' => $now,
			'updated' => $now,
		);
		if ( '' === $rule['title'] ) {
			/* translators: %s: query */
			$rule['title'] = '' !== $query ? sprintf( __( '“%s” belongs to one page', 'seolity' ), $query ) : mb_substr( $text, 0, 80 );
		}
		$found = false;
		foreach ( $rules as $i => $r ) {
			if ( $r['id'] === $rule['id'] ) {
				$rule['created'] = $r['created'];
				$rules[ $i ]     = $rule;
				$found           = true;
			}
		}
		if ( ! $found ) {
			array_unshift( $rules, $rule );
		}
		$this->store( $rules );
		$this->plugin->site_profile->flush();
		$this->plugin->audit_log->log( 'site_rule.saved', 'site', null, array( 'id' => $rule['id'], 'source' => $rule['source'] ) );
		return $rule;
	}

	public function delete( $id ) {
		$this->store(
			array_filter(
				$this->all(),
				static function ( $r ) use ( $id ) {
					return $r['id'] !== $id;
				}
			)
		);
		$this->plugin->audit_log->log( 'site_rule.deleted', 'site', null, array( 'id' => $id ) );
		return true;
	}

	public function set_active( $id, $on ) {
		$rules = $this->all();
		foreach ( $rules as $i => $r ) {
			if ( $r['id'] === $id ) {
				$rules[ $i ]['active']  = (bool) $on;
				$rules[ $i ]['updated'] = gmdate( 'c' );
			}
		}
		$this->store( $rules );
		return true;
	}

	/** The active ownership rule of a search, or null. */
	public function owner_of( $query ) {
		$k = self::qkey( $query );
		foreach ( $this->active() as $r ) {
			if ( '' !== $r['query'] && self::qkey( $r['query'] ) === $k ) {
				return $r;
			}
		}
		return null;
	}

	/**
	 * Rules a page would break by targeting these keywords (it is not the owner of the search).
	 *
	 * @param string   $url      Page address.
	 * @param string[] $keywords Keywords the page would target.
	 * @return array[]
	 */
	public function conflicts( $url, array $keywords ) {
		$page = self::pkey( $url );
		$out  = array();
		foreach ( $this->active() as $r ) {
			if ( '' === $r['query'] || '' === $page || self::pkey( $r['url'] ) === $page ) {
				continue;
			}
			$rq = self::qkey( $r['query'] );
			foreach ( $keywords as $kw ) {
				if ( '' !== trim( (string) $kw ) && self::qkey( $kw ) === $rq ) {
					$out[ $r['id'] ] = $r;
				}
			}
		}
		return array_values( $out );
	}

	/** One sentence per rule, for prompts and the editor. */
	public static function sentence( array $r ) {
		if ( '' !== $r['query'] ) {
			$s = sprintf( 'The search «%s» belongs ONLY to %s. No other page may target it (focus keyword, SEO title, H1, main topic); other pages that touch the topic should link to that page instead of competing.', $r['query'], self::pkey( $r['url'] ) );
			if ( $r['avoid'] ) {
				$s .= ' Pages that must stop competing for it: ' . implode( ', ', array_map( array( __CLASS__, 'pkey' ), $r['avoid'] ) ) . '.';
			}
			return $s . ( '' !== $r['text'] ? ' ' . $r['text'] : '' );
		}
		return $r['text'];
	}

	/** Lines for the system prompt of every AI call. */
	public function prompt_lines() {
		$out = array();
		$len = 0;
		foreach ( $this->active() as $r ) {
			$line = self::sentence( $r );
			$len += mb_strlen( $line );
			if ( $len > 4000 ) {
				break;
			}
			$out[] = $line;
		}
		return $out;
	}

	/** Rules about one page (it owns a search, or must stop competing for one). */
	public function for_url( $url ) {
		$page = self::pkey( $url );
		$out  = array();
		foreach ( $this->active() as $r ) {
			if ( '' === $r['query'] ) {
				continue;
			}
			if ( self::pkey( $r['url'] ) === $page ) {
				/* translators: %s: query */
				$out[] = sprintf( 'This page OWNS the search «%s»: target it fully.', $r['query'] );
			} elseif ( in_array( $page, array_map( array( __CLASS__, 'pkey' ), $r['avoid'] ), true ) ) {
				$out[] = sprintf( 'This page must NOT target «%s» (it belongs to %s): do not use it as focus keyword, title or H1; link to %s for it.', $r['query'], self::pkey( $r['url'] ), self::pkey( $r['url'] ) );
			}
		}
		return $out;
	}

	/**
	 * Searches where two or more of the site's pages compete (each ≥ 20% of the impressions,
	 * last 28 days) and no rule decides yet — candidates for an ownership rule.
	 */
	public function suggestions( $limit = 12 ) {
		if ( ! $this->plugin->gsc_oauth->is_connected() || ! $this->plugin->gsc_analytics->has_data() ) {
			return array();
		}
		list( $cs, $ce ) = $this->plugin->gsc_analytics->periods( 28 );
		$rows            = $this->plugin->db->rows( 'SELECT query_hash h, MAX(query) q, MAX(page) page, SUM(clicks) c, SUM(impressions) i, SUM(position*impressions)/NULLIF(SUM(impressions),0) p FROM {gsc_page_query} WHERE project_id = %d AND date BETWEEN %s AND %s GROUP BY query_hash, page_hash HAVING i >= 10', array( $this->plugin->db->project_id(), $cs, $ce ) );
		$by              = array();
		foreach ( $rows as $r ) {
			$by[ $r['h'] ]['query']   = $r['q'];
			$by[ $r['h'] ]['pages'][] = array( 'url' => $r['page'], 'clicks' => (int) $r['c'], 'impressions' => (int) $r['i'], 'position' => round( (float) $r['p'], 1 ) );
		}
		$out = array();
		foreach ( $by as $x ) {
			$total = array_sum( wp_list_pluck( $x['pages'], 'impressions' ) );
			$comp  = array_values(
				array_filter(
					$x['pages'],
					static function ( $p ) use ( $total ) {
						return $p['impressions'] >= 0.2 * $total;
					}
				)
			);
			if ( count( $comp ) < 2 || $total < 30 || $this->owner_of( $x['query'] ) ) {
				continue;
			}
			usort(
				$comp,
				static function ( $a, $b ) {
					return $b['impressions'] <=> $a['impressions'];
				}
			);
			$out[] = array( 'query' => $x['query'], 'impressions' => $total, 'pages' => array_slice( $comp, 0, 5 ) );
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return $b['impressions'] <=> $a['impressions'];
			}
		);
		return array_slice( $out, 0, max( 1, (int) $limit ) );
	}
}
