<?php
/**
 * What SEOlity knows about THIS site — given to the AI in every prompt, so it reasons like the
 * site's own designer instead of a stranger: the structure (main sections, categories, shop, blog),
 * the pages that carry the site, the searches it lives on and which page ranks for each, and the
 * work and decisions of the last weeks (so it never re-proposes what was just done or undone).
 *
 * Compact on purpose (a few hundred words) and cached for six hours.
 *
 * @package SEOlity
 */

namespace SEOlity\Content;

use SEOlity\Activity\Activity;
use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class SiteProfile {

	const CACHE = 'seolity_site_profile';

	/** Tasks that do not need the profile (short, per-item jobs). */
	const SKIP = array( 'image_alt', 'media_seo', 'link_sentence', 'redirect_target' );

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	private static function path( $url ) {
		$p = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
		return rawurldecode( '' === $p ? '/' : $p );
	}

	public function flush() {
		delete_transient( self::CACHE );
	}

	/** @return string[] Lines for the system prompt. */
	public function prompt_lines() {
		$cached = get_transient( self::CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$lines = array();
		$woo   = class_exists( 'WooCommerce' );
		$count = static function ( $type ) {
			$c = wp_count_posts( $type );
			return $c && isset( $c->publish ) ? (int) $c->publish : 0;
		};
		$lines[] = sprintf(
			'Site: %s (%s) — language %s, %s. Published: %d posts, %d pages%s.',
			get_bloginfo( 'name' ),
			home_url( '/' ),
			get_locale(),
			$woo ? 'online shop (WooCommerce)' : 'content site',
			$count( 'post' ),
			$count( 'page' ),
			$woo ? ', ' . $count( 'product' ) . ' products' : ''
		);
		$desc = trim( (string) get_bloginfo( 'description' ) );
		if ( '' !== $desc ) {
			$lines[] = 'Tagline: ' . $desc;
		}
		$keys  = array();
		$front = (int) get_option( 'page_on_front' );
		$blog  = (int) get_option( 'page_for_posts' );
		if ( $front ) {
			$keys[] = 'home = "' . get_the_title( $front ) . '"';
		}
		if ( $woo && function_exists( 'wc_get_page_permalink' ) ) {
			$keys[] = 'shop = ' . self::path( wc_get_page_permalink( 'shop' ) );
		}
		if ( $blog ) {
			$keys[] = 'blog = ' . self::path( get_permalink( $blog ) );
		}
		if ( $keys ) {
			$lines[] = 'Key pages: ' . implode( '; ', $keys ) . '.';
		}
		// Main sections: product categories (or post categories) by size.
		$tax   = $woo && taxonomy_exists( 'product_cat' ) ? 'product_cat' : 'category';
		$terms = get_terms( array( 'taxonomy' => $tax, 'orderby' => 'count', 'order' => 'DESC', 'number' => 10, 'hide_empty' => true ) );
		if ( is_array( $terms ) && $terms ) {
			$lines[] = ( 'product_cat' === $tax ? 'Product categories' : 'Categories' ) . ': ' . implode(
				'; ',
				array_map(
					static function ( $t ) {
						$link = get_term_link( $t );
						return $t->name . ' (' . (int) $t->count . ') ' . ( is_wp_error( $link ) ? '' : self::path( $link ) );
					},
					$terms
				)
			) . '.';
		}
		$an = $this->plugin->gsc_analytics;
		if ( $this->plugin->gsc_oauth->is_connected() && $an->has_data() ) {
			$pages = $an->top_pages( 28, 8 );
			if ( $pages ) {
				$lines[] = 'Pages that carry the site (Search Console, 28 days): ' . implode(
					'; ',
					array_map(
						static function ( $p ) {
							return self::path( $p['page'] ) . ' — ' . $p['clicks'] . ' clicks, position ' . $p['position'];
						},
						$pages
					)
				) . '.';
			}
			$queries = $an->top_queries( 28, 12 );
			if ( $queries ) {
				$q = array();
				foreach ( $queries as $row ) {
					$owner = $this->plugin->site_rules->owner_of( $row['query'] );
					$top   = $owner ? array( array( 'page' => $owner['url'] ) ) : $an->query_pages( $row['query'], 28 );
					$q[]   = '«' . $row['query'] . '» ' . $row['clicks'] . ' clicks, pos ' . $row['position'] . ( $top ? ' → ' . self::path( $top[0]['page'] ) . ( $owner ? ' (rule)' : '' ) : '' );
				}
				$lines[] = 'Searches the site lives on (and the page that ranks): ' . implode( '; ', $q ) . '.';
			}
		}
		// Recent work, so nothing is re-proposed blindly.
		$done = $this->plugin->db->rows( "SELECT field, url, status, applied_at, reverted_at FROM {changes} WHERE project_id = %d AND status IN ('applied','reverted','applied_editor') AND applied_at IS NOT NULL ORDER BY applied_at DESC LIMIT 10", array( $this->plugin->db->project_id() ) );
		if ( $done ) {
			$lines[] = 'Recent changes (newest first): ' . implode(
				'; ',
				array_map(
					static function ( $c ) {
						return substr( (string) $c['applied_at'], 0, 10 ) . ' ' . Activity::field_label( $c['field'] ) . ' of ' . self::path( $c['url'] ) . ( 'reverted' === $c['status'] ? ' (undone by the owner)' : '' );
					},
					$done
				)
			) . '.';
		}
		$ops = $this->plugin->db->rows( "SELECT title, status, finished_at FROM {operations} WHERE project_id = %d AND status IN ('done','partial','rolled_back') ORDER BY id DESC LIMIT 6", array( $this->plugin->db->project_id() ) );
		if ( $ops ) {
			$lines[] = 'Recent approved work: ' . implode(
				'; ',
				array_map(
					static function ( $o ) {
						return substr( (string) $o['finished_at'], 0, 10 ) . ' ' . $o['title'] . ( 'rolled_back' === $o['status'] ? ' (undone)' : '' );
					},
					$ops
				)
			) . '.';
		}
		$r1 = get_option( 'seolity_rank1', array() );
		if ( is_array( $r1 ) && ! empty( $r1['focus']['query'] ) ) {
			$lines[] = 'The owner is currently focused on ranking #1 for «' . $r1['focus']['query'] . '» with ' . self::path( $r1['focus']['page'] ) . '.';
		}
		set_transient( self::CACHE, $lines, 6 * HOUR_IN_SECONDS );
		return $lines;
	}
}
