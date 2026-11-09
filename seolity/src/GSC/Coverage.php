<?php
/**
 * Index coverage intelligence: Google Search Console "Page indexing" data, Search Console messages,
 * Google's own view of the sitemap URLs (URL Inspection) and redirect hygiene — in one place.
 *
 * Sources
 *  - The ZIP exports of Search Console → Pages (summary: Chart.csv + Critical / Non-critical issues;
 *    per reason "drilldown": Chart.csv + Table.csv with the URLs). The Search Console API does not
 *    offer this report, so the owner uploads the exports (one click each in Search Console).
 *  - Search Console messages (e-mails such as "New reasons prevent pages in a sitemap from being
 *    indexed"): Google has no API for them; the owner pastes or forwards the text, SEOlity reads it.
 *  - The daily index watch: the URL Inspection API for a rotating batch of the site's own URLs —
 *    when a NEW reason appears SEOlity notifies like Search Console's own e-mail, usually earlier.
 *  - SEOlity's data: redirects (also Rank Math's), published posts, crawl, changes and operations.
 *
 * Analysis (deterministic first, the AI explains and plans on top): intentional exclusions
 * (cart, feeds, add-to-cart, theme internals…) versus real problems; published posts whose own
 * address redirects (they stay in the sitemap and Search Console reports "Page with redirect" or
 * "Redirect error"); redirect chains, loops and rules pointing to the wrong article; 404s with a
 * likely destination; truncated links; noindex on pages that look like landing pages; real pages
 * in "Crawled – currently not indexed"; jumps in the timeline matched with change events.
 * Every fix goes through an operation (preview → approval → apply → verify → undo).
 *
 * @package SEOlity
 */

namespace SEOlity\GSC;

use SEOlity\Plugin;
use SEOlity\Redirects\Manager;

defined( 'ABSPATH' ) || exit;

final class Coverage {

	const OPTION   = 'seolity_coverage';
	const WATCH    = 'seolity_index_watch';
	const MAX_URLS = 1500;

	/** Intentional exclusions: URL pattern => label. */
	const INTENTIONAL = array(
		'feed'      => '#/feed/?$|/comments/feed|[?&]feed=#i',
		'cart'      => '#/(cart|checkout|my-account|wishlist|compare|login-register|login|register|lost-password|auth|orderreview|visitor-panel|buy-panel|agent-register|agent-dashboard)(/|$|\?)#i',
		'addtocart' => '#[?&](add-to-cart|add_to_wishlist|remove_item|removed_item)=#i',
		'tracking'  => '#[?&](utm_[a-z]+|fbclid|gclid|yclid|mc_cid|srsltid)=#i',
		'reply'     => '#[?&]replytocom=#i',
		'theme'     => '#[?&](cms_block|elementor_library)=|/(woodmart_slide|woodmart_layout|woodmart_sidebar|elementor_library|e-landing-page)/#i',
		'system'    => '#/(wp-admin|wp-json|wp-includes)(/|$)|/wp-content/(plugins|themes)/|[?&](rest_route|wc-ajax|p|page_id)=|\.php(\?|$)#i',
		'search'    => '#[?&]s=|/search/#i',
		'archive'   => '#/(author|tag)/[^/]+/?(page/\d+/?)?$|/page/\d+/?$#i',
		'wildcard'  => '#\*#',
	);

	/** Search Console reasons in English (as exported) => key. */
	const REASONS = array(
		'redirect error'                                     => 'redirect_error',
		'page with redirect'                                 => 'page_with_redirect',
		'not found (404)'                                    => 'not_found',
		'soft 404'                                           => 'soft_404',
		'excluded by ‘noindex’ tag'                          => 'noindex',
		"excluded by 'noindex' tag"                          => 'noindex',
		'blocked by robots.txt'                              => 'robots',
		'crawled - currently not indexed'                    => 'crawled_not_indexed',
		'discovered - currently not indexed'                 => 'discovered_not_indexed',
		'alternate page with proper canonical tag'           => 'alternate_canonical',
		'duplicate, google chose different canonical than user' => 'google_canonical',
		'duplicate without user-selected canonical'          => 'duplicate_no_canonical',
		'blocked due to other 4xx issue'                     => 'other_4xx',
		'blocked due to unauthorized request (401)'          => 'unauthorized',
		'blocked due to access forbidden (403)'              => 'forbidden',
		'server error (5xx)'                                 => 'server_error',
		'indexed, though blocked by robots.txt'              => 'indexed_blocked',
		'page indexed without content'                       => 'indexed_no_content',
	);

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function state() {
		$s = get_option( self::OPTION, array() );
		$s = is_array( $s ) ? $s : array();
		return array_merge( array( 'summary' => null, 'drilldowns' => array(), 'messages' => array(), 'ai' => null ), $s );
	}

	private function save( array $s ) {
		update_option( self::OPTION, $s, false );
	}

	public static function reason_key( $reason ) {
		$r = strtolower( trim( str_replace( array( '’', '‘' ), "'", (string) $reason ) ) );
		foreach ( self::REASONS as $name => $key ) {
			if ( strtolower( str_replace( array( '’', '‘' ), "'", $name ) ) === $r ) {
				return $key;
			}
		}
		return sanitize_key( $r );
	}

	/* ------------------------------------------------------------------ import */

	/**
	 * Import Search Console exports. $files: list of array( 'name' => file name, 'path' => local file )
	 * (ZIP as downloaded, or a single CSV). Returns what was recognised.
	 */
	public function import( array $files ) {
		$s    = $this->state();
		$done = array();
		foreach ( $files as $file ) {
			$csv = $this->read_files( $file );
			if ( is_wp_error( $csv ) ) {
				$done[] = array( 'file' => $file['name'], 'error' => $csv->get_error_message() );
				continue;
			}
			$meta = isset( $csv['metadata'] ) ? self::kv( $csv['metadata'] ) : array();
			if ( ! empty( $meta['issue'] ) && isset( $csv['table'] ) ) {
				$key  = self::reason_key( $meta['issue'] );
				$urls = array();
				foreach ( self::rows( $csv['table'] ) as $r ) {
					$u = isset( $r['url'] ) ? $r['url'] : reset( $r );
					if ( $u ) {
						$urls[] = array( 'url' => esc_url_raw( $u ), 'raw' => (string) $u, 'last_crawled' => isset( $r['last crawled'] ) ? $r['last crawled'] : '' );
					}
				}
				$chart = array();
				foreach ( self::rows( isset( $csv['chart'] ) ? $csv['chart'] : '' ) as $r ) {
					$chart[] = array( 'date' => $r['date'], 'pages' => (int) ( isset( $r['affected pages'] ) ? $r['affected pages'] : 0 ) );
				}
				$s['drilldowns'][ $key ] = array( 'reason' => $meta['issue'], 'key' => $key, 'imported_at' => gmdate( 'Y-m-d H:i:s' ), 'sitemap' => isset( $meta['sitemap'] ) ? $meta['sitemap'] : '', 'chart' => $chart, 'urls' => array_slice( $urls, 0, self::MAX_URLS ), 'total' => count( $urls ) );
				$done[] = array( 'file' => $file['name'], 'type' => 'drilldown', 'reason' => $meta['issue'], 'urls' => count( $urls ) );
			} elseif ( isset( $csv['critical'] ) || isset( $csv['chart'] ) ) {
				$reasons = array();
				foreach ( array( 'critical' => true, 'noncritical' => false ) as $part => $critical ) {
					foreach ( self::rows( isset( $csv[ $part ] ) ? $csv[ $part ] : '' ) as $r ) {
						if ( empty( $r['reason'] ) ) {
							continue;
						}
						$reasons[] = array( 'reason' => $r['reason'], 'key' => self::reason_key( $r['reason'] ), 'source' => isset( $r['source'] ) ? $r['source'] : '', 'validation' => isset( $r['validation'] ) ? $r['validation'] : '', 'pages' => (int) ( isset( $r['pages'] ) ? $r['pages'] : 0 ), 'critical' => $critical );
					}
				}
				$chart = array();
				foreach ( self::rows( isset( $csv['chart'] ) ? $csv['chart'] : '' ) as $r ) {
					$chart[] = array( 'date' => $r['date'], 'not_indexed' => '' === $r['not indexed'] ? null : (int) $r['not indexed'], 'indexed' => '' === $r['indexed'] ? null : (int) $r['indexed'], 'impressions' => (int) ( isset( $r['impressions'] ) ? $r['impressions'] : 0 ) );
				}
				$s['summary'] = array( 'imported_at' => gmdate( 'Y-m-d H:i:s' ), 'sitemap' => isset( $meta['sitemap'] ) ? $meta['sitemap'] : '', 'reasons' => $reasons, 'chart' => $chart );
				$done[] = array( 'file' => $file['name'], 'type' => 'summary', 'reasons' => count( $reasons ) );
			} else {
				$done[] = array( 'file' => $file['name'], 'error' => __( 'Not a Search Console “Pages” export.', 'seolity' ) );
			}
		}
		$this->save( $s );
		$this->plugin->activity->event( 'gsc', __( 'Search Console indexing exports imported', 'seolity' ), sprintf( /* translators: %d: files */ __( '%d files', 'seolity' ), count( $files ) ) );
		return array( 'imported' => $done, 'findings' => count( $this->analyze() ) );
	}

	/** CSV texts of one upload, keyed chart / critical / noncritical / metadata / table. */
	private function read_files( array $file ) {
		$out  = array();
		$name = strtolower( (string) $file['name'] );
		if ( '.zip' === substr( $name, -4 ) ) {
			if ( ! class_exists( '\\ZipArchive' ) ) {
				return new \WP_Error( 'seolity_zip', __( 'This server cannot open ZIP files (PHP zip extension). Unzip and upload the CSV files instead.', 'seolity' ) );
			}
			$zip = new \ZipArchive();
			if ( true !== $zip->open( $file['path'] ) ) {
				return new \WP_Error( 'seolity_zip', __( 'The ZIP file could not be opened.', 'seolity' ) );
			}
			for ( $i = 0; $i < $zip->numFiles && $i < 20; $i++ ) {
				$entry = (string) $zip->getNameIndex( $i );
				$key   = self::csv_key( $entry );
				if ( $key && $zip->statIndex( $i )['size'] < 5 * MB_IN_BYTES ) {
					$out[ $key ] = (string) $zip->getFromIndex( $i );
				}
			}
			$zip->close();
		} else {
			$key = self::csv_key( $name );
			if ( $key ) {
				$out[ $key ] = (string) file_get_contents( $file['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
		return $out ? $out : new \WP_Error( 'seolity_zip', __( 'No Search Console CSV found in this file.', 'seolity' ) );
	}

	private static function csv_key( $entry ) {
		$b = strtolower( basename( $entry ) );
		if ( false !== strpos( $b, 'non-critical' ) ) {
			return 'noncritical';
		}
		foreach ( array( 'critical' => 'critical', 'chart' => 'chart', 'metadata' => 'metadata', 'table' => 'table' ) as $needle => $key ) {
			if ( false !== strpos( $b, $needle ) ) {
				return $key;
			}
		}
		return '';
	}

	/** CSV text → list of rows keyed by lower-case header. */
	private static function rows( $text ) {
		$text  = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $text );
		$lines = preg_split( '/\r\n|\n|\r/', trim( $text ) );
		if ( ! $lines || '' === $lines[0] ) {
			return array();
		}
		$head = array_map( static function ( $h ) {
			return strtolower( trim( $h ) );
		}, str_getcsv( array_shift( $lines ) ) );
		$out = array();
		foreach ( $lines as $l ) {
			if ( '' === trim( $l ) ) {
				continue;
			}
			$cells = str_getcsv( $l );
			$row   = array();
			foreach ( $head as $i => $h ) {
				$row[ $h ] = isset( $cells[ $i ] ) ? trim( $cells[ $i ] ) : '';
			}
			$out[] = $row;
		}
		return $out;
	}

	/** Two-column "Property,Value" CSV → array. */
	private static function kv( $text ) {
		$out = array();
		foreach ( self::rows( $text ) as $r ) {
			$v                            = array_values( $r );
			$out[ strtolower( $v[0] ) ] = isset( $v[1] ) ? $v[1] : '';
		}
		return $out;
	}

	/* ------------------------------------------------------------------ analysis */

	/** Which intentional pattern a URL matches ('' = none). */
	public static function intentional( $url ) {
		foreach ( self::INTENTIONAL as $key => $re ) {
			if ( preg_match( $re, rawurldecode( (string) $url ) ) ) {
				return $key;
			}
		}
		if ( preg_match( '#/category/[^/]*(uncategorized|%d8%af%d8%b3%d8%aa%d9%87%d8%a8%d9%86%d8%af%db%8c|دسته‌بندی-نشده|دستهبندی-نشده)#iu', (string) $url ) ) {
			return 'archive';
		}
		return '';
	}

	/** Every finding from the imported data plus SEOlity's own redirect audit. */
	public function analyze() {
		$s        = $this->state();
		$findings = array();
		foreach ( $s['drilldowns'] as $key => $d ) {
			$real   = array();
			$intent = array();
			foreach ( $d['urls'] as $u ) {
				$p = self::intentional( $u['url'] );
				if ( $p ) {
					$intent[ $p ][] = $u['url'];
				} else {
					$real[] = $u;
				}
			}
			if ( $intent && ! in_array( $key, array( 'page_with_redirect' ), true ) ) {
				$n = array_sum( array_map( 'count', $intent ) );
				/* translators: 1 count 2 reason */
				$findings[] = $this->finding( 'intentional_' . $key, 'info', $d['reason'], sprintf( __( '%1$d of these URLs are excluded on purpose (%2$s) — this is correct, nothing to fix.', 'seolity' ), $n, implode( ', ', array_map( array( $this, 'pattern_label' ), array_keys( $intent ) ) ) ), __( 'Feeds, cart / checkout / account, add-to-cart links, tracking parameters, theme internals and internal search should stay out of Google. Do not press “Validate fix” for them.', 'seolity' ), array_slice( array_merge( ...array_values( $intent ) ), 0, 30 ), array(), true );
			}
			switch ( $key ) {
				case 'page_with_redirect':
				case 'redirect_error':
					foreach ( $this->redirect_findings( $real, $key, $d['reason'] ) as $f ) {
						$findings[] = $f;
					}
					break;
				case 'not_found':
				case 'soft_404':
					foreach ( $this->not_found_findings( $real, $d['reason'] ) as $f ) {
						$findings[] = $f;
					}
					break;
				case 'noindex':
					if ( $real ) {
						/* translators: %d: count */
						$findings[] = $this->finding( 'noindex_check', 'medium', $d['reason'], sprintf( __( '%d pages have noindex but do not look like system pages — check whether they should be in Google.', 'seolity' ), count( $real ) ), __( 'Landing pages or category pages with noindex cannot rank. If a page redirects anyway, take it out of the menus and links instead.', 'seolity' ), wp_list_pluck( $real, 'url' ) );
					}
					break;
				case 'crawled_not_indexed':
				case 'discovered_not_indexed':
					if ( $real ) {
						/* translators: %d: count */
						$findings[] = $this->finding( $key . '_pages', 'crawled_not_indexed' === $key ? 'medium' : 'low', $d['reason'], sprintf( __( '%d real pages are not indexed yet — Google did not find them valuable or important enough.', 'seolity' ), count( $real ) ), __( 'Improve them (unique depth, answer the query better, E-E-A-T), link to them from strong pages and the menu, check they are in the sitemap, then request indexing in URL Inspection.', 'seolity' ), wp_list_pluck( $real, 'url' ) );
					}
					break;
				case 'robots':
					$blocked = array_values( array_filter( $real, function ( $u ) {
						return $this->post_id_for( $u['url'] ) > 0;
					} ) );
					if ( $blocked ) {
						/* translators: %d: count */
						$findings[] = $this->finding( 'robots_real', 'high', $d['reason'], sprintf( __( '%d published pages are blocked by robots.txt.', 'seolity' ), count( $blocked ) ), __( 'Google cannot crawl them. Remove the matching Disallow rule (Robots.txt page) unless they are meant to be private.', 'seolity' ), wp_list_pluck( $blocked, 'url' ) );
					}
					break;
				case 'google_canonical':
				case 'duplicate_no_canonical':
					if ( $real ) {
						/* translators: %d: count */
						$findings[] = $this->finding( $key, 'medium', $d['reason'], sprintf( __( '%d pages are seen by Google as duplicates of another page.', 'seolity' ), count( $real ) ), __( 'Usually two articles on the same topic (cannibalization) or URL variants. See “Duplicate & similar articles”: merge, differentiate or set a canonical.', 'seolity' ), wp_list_pluck( $real, 'url' ) );
					}
					break;
			}
		}
		foreach ( $this->redirect_audit() as $f ) {
			$findings[] = $f;
		}
		foreach ( $this->timeline_events() as $f ) {
			$findings[] = $f;
		}
		if ( $s['summary'] ) {
			foreach ( $s['summary']['reasons'] as $r ) {
				if ( 'Failed' === $r['validation'] && in_array( $r['key'], array( 'noindex', 'not_found', 'robots' ), true ) ) {
					/* translators: %s: reason */
					$findings[] = $this->finding( 'validation_' . $r['key'], 'low', $r['reason'], sprintf( __( 'Validation of “%s” failed.', 'seolity' ), $r['reason'] ), __( 'Validation fails when some URLs still have the reason — normal for intentional exclusions (noindex system pages, junk 404s). Only validate after fixing the real pages listed here.', 'seolity' ), array() );
				}
			}
		}
		// Deduplicate by id (the redirect audit can repeat a drilldown finding).
		$seen = array();
		$out  = array();
		foreach ( $findings as $f ) {
			if ( ! isset( $seen[ $f['id'] ] ) ) {
				$seen[ $f['id'] ] = 1;
				$out[]            = $f;
			}
		}
		$order = array( 'critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3, 'info' => 4 );
		usort( $out, static function ( $a, $b ) use ( $order ) {
			return $order[ $a['severity'] ] - $order[ $b['severity'] ];
		} );
		return $out;
	}

	private function finding( $id, $severity, $reason, $title, $detail, array $urls, array $fix = array(), $intentional = false ) {
		return array(
			'id'          => substr( sanitize_key( $id ), 0, 80 ),
			'severity'    => $severity,
			'reason'      => (string) $reason,
			'title'       => $title,
			'detail'      => $detail,
			'urls'        => array_values( array_slice( $urls, 0, 50 ) ),
			'count'       => count( $urls ),
			'fix'         => $fix,
			'intentional' => (bool) $intentional,
		);
	}

	private function pattern_label( $k ) {
		$labels = array( 'feed' => __( 'feeds', 'seolity' ), 'cart' => __( 'cart, checkout, account pages', 'seolity' ), 'addtocart' => __( 'add-to-cart links', 'seolity' ), 'tracking' => __( 'tracking parameters', 'seolity' ), 'reply' => __( 'comment reply links', 'seolity' ), 'theme' => __( 'theme / builder internals', 'seolity' ), 'system' => __( 'WordPress system URLs', 'seolity' ), 'search' => __( 'internal search', 'seolity' ), 'archive' => __( 'author / tag / paged archives', 'seolity' ), 'wildcard' => __( 'robots.txt patterns read as URLs', 'seolity' ) );
		return isset( $labels[ $k ] ) ? $labels[ $k ] : $k;
	}

	/** Findings for URLs Google saw redirecting. */
	private function redirect_findings( array $urls, $key, $reason ) {
		$out = array();
		foreach ( $urls as $u ) {
			$path = Manager::normalize_source( $u['url'] );
			$pid  = $this->post_id_for( $u['url'] );
			$rule = $pid ? $this->rule_for( $path ) : null;
			if ( $pid && ! $rule ) {
				// No rule in SEOlity / Rank Math: does the live URL really still redirect? (Search Console may be old.)
				$live = $this->plugin->operations->verifier()->fetch( $u['url'], false );
				$rule = ! is_wp_error( $live ) && $live['status'] >= 300 && $live['status'] < 400 ? array( 'target' => (string) $live['location'] ) : null;
			}
			if ( $pid && $rule && 'publish' === get_post_status( $pid ) && Manager::normalize_source( (string) get_permalink( $pid ) ) === $path ) {
				/* translators: %s: title */
				$out[] = $this->finding( 'hidden_' . $pid, 'high', $reason, sprintf( __( 'Published article “%s” redirects to another page', 'seolity' ), get_the_title( $pid ) ), __( 'Its own address redirects (after a merge), but it is still published, so it stays in the sitemap and Search Console reports it. Make it a draft: visitors keep being redirected, it leaves the sitemap, nothing is deleted.', 'seolity' ) . ( $rule ? ' → ' . urldecode( $rule['target'] ) : '' ), array( $u['url'] ), array( 'type' => 'draft', 'post_id' => $pid, 'label' => __( 'Unpublish (keep the redirect)', 'seolity' ) ) );
			}
			if ( 'redirect_error' === $key ) {
				$trace = $this->trace( $u['url'] );
				$out[] = $this->finding( 'trace_' . md5( $u['url'] ), 'ok' === $trace['result'] ? 'low' : 'high', $reason, 'ok' === $trace['result'] ? __( 'The redirect error is gone now — press “Validate fix” in Search Console.', 'seolity' ) : $trace['message'], implode( ' → ', array_map( 'urldecode', wp_list_pluck( $trace['hops'], 'url' ) ) ), array( $u['url'] ) );
			}
		}
		return $out;
	}

	/** Follow a URL's redirects live (max 8 hops): loop, long chain, broken end. */
	public function trace( $url ) {
		$hops = array();
		$seen = array();
		$cur  = $url;
		for ( $i = 0; $i < 8; $i++ ) {
			$res = $this->plugin->operations->verifier()->fetch( $cur, false );
			if ( is_wp_error( $res ) ) {
				$hops[] = array( 'url' => $cur, 'status' => 0 );
				return array( 'result' => 'error', 'hops' => $hops, 'message' => $res->get_error_message() );
			}
			$hops[] = array( 'url' => $cur, 'status' => (int) $res['status'] );
			$seen[] = $cur;
			if ( $res['status'] < 300 || $res['status'] >= 400 ) {
				$ok = 200 === (int) $res['status'];
				/* translators: 1 hops 2 status */
				return array( 'result' => $ok ? ( count( $hops ) > 2 ? 'chain' : 'ok' ) : 'broken', 'hops' => $hops, 'message' => $ok ? sprintf( __( 'Redirect chain of %d steps — point the first URL straight at the last one.', 'seolity' ), count( $hops ) - 1 ) : sprintf( __( 'The redirect ends on an error page (HTTP %d).', 'seolity' ), (int) $res['status'] ) );
			}
			$next = (string) $res['location'];
			if ( '' === $next ) {
				return array( 'result' => 'broken', 'hops' => $hops, 'message' => __( 'The server answers with a redirect without a destination.', 'seolity' ) );
			}
			$next = '/' === $next[0] ? home_url( $next ) : $next;
			if ( in_array( $next, $seen, true ) ) {
				$hops[] = array( 'url' => $next, 'status' => 0 );
				return array( 'result' => 'loop', 'hops' => $hops, 'message' => __( 'Redirect loop: the URLs send visitors in a circle. Fix the rules so the old URL points at the live article.', 'seolity' ) );
			}
			$cur = $next;
		}
		return array( 'result' => 'loop', 'hops' => $hops, 'message' => __( 'More than 8 redirects — Google stops following.', 'seolity' ) );
	}

	/** 404 URLs: truncated links, junk, likely destinations. */
	private function not_found_findings( array $urls, $reason ) {
		$out       = array();
		$truncated = array();
		$files     = array();
		foreach ( $urls as $u ) {
			if ( false !== strpos( (string) $u['raw'], "\xEF\xBF\xBD" ) || ! mb_check_encoding( rawurldecode( (string) $u['url'] ), 'UTF-8' ) ) {
				$truncated[] = $u['url'];
				continue;
			}
			if ( $this->rule_for( Manager::normalize_source( $u['url'] ) ) ) {
				continue; // Already redirected since Google looked.
			}
			if ( false !== strpos( $u['url'], '/wp-content/uploads/' ) ) {
				$files[] = $u['url'];
				continue;
			}
			$best = $this->best_match( $u['url'] );
			if ( $best && $best['score'] >= 0.5 ) {
				/* translators: %s: title */
				$out[] = $this->finding( 'nf_' . md5( $u['url'] ), 'medium', $reason, sprintf( __( '404 that probably belongs to “%s”', 'seolity' ), $best['title'] ), __( 'A 301 to the closest live page keeps its links and visitors. Check the suggestion before approving.', 'seolity' ), array( $u['url'] ), array( 'type' => 'redirect', 'source' => $u['url'], 'target' => $best['url'], 'label' => __( 'Create 301 redirect', 'seolity' ) ) );
			}
		}
		if ( $files ) {
			/* translators: %d: count */
			$out[] = $this->finding( 'nf_files', 'medium', $reason, sprintf( __( '%d deleted files are still linked', 'seolity' ), count( $files ) ), __( 'A PDF or image was removed from the media library but a page or menu still links to it. Upload it again or remove the link.', 'seolity' ), $files );
		}
		if ( $truncated ) {
			/* translators: %d: count */
			$out[] = $this->finding( 'nf_truncated', 'medium', $reason, sprintf( __( '%d broken links contain a cut-off character (�)', 'seolity' ), count( $truncated ) ), __( 'Somewhere a link to an article was cut in the middle of a Persian letter. Find the link (Internal Links) and fix it, or redirect the broken address to the article.', 'seolity' ), $truncated );
		}
		return $out;
	}

	/**
	 * Redirect hygiene from SEOlity's rules (Rank Math's included), without any export:
	 * published posts behind their own redirect, chains, rules that probably point at the wrong article.
	 */
	public function redirect_audit() {
		$db    = $this->plugin->db;
		$rules = $db->rows( "SELECT id, source, target, origin FROM {redirects} WHERE project_id = %d AND is_regex = 0 AND status IN ('active','external') AND type IN (301,302,307,308) LIMIT 3000", array( $db->project_id() ) );
		$by    = array();
		foreach ( (array) $rules as $r ) {
			$by[ $r['source'] ] = $r;
		}
		$out = array();
		foreach ( (array) $rules as $r ) {
			$local = $this->is_local( $r['target'] );
			$tpath = $local ? Manager::normalize_source( $r['target'] ) : '';
			// 1. A published post whose own address is the source.
			$pid = $this->post_id_for( home_url( $r['source'] ) );
			if ( $pid && 'publish' === get_post_status( $pid ) && Manager::normalize_source( (string) get_permalink( $pid ) ) === $r['source'] ) {
				/* translators: %s: title */
				$out[] = $this->finding( 'hidden_' . $pid, 'high', __( 'Redirects', 'seolity' ), sprintf( __( 'Published article “%s” redirects to another page', 'seolity' ), get_the_title( $pid ) ), __( 'Its own address redirects, but it is still published: it stays in the sitemap and Search Console reports “Page with redirect” or “Redirect error”. Make it a draft (the redirect keeps working, nothing is deleted) — or delete the rule if the article should be live.', 'seolity' ) . ' → ' . urldecode( $r['target'] ), array( home_url( $r['source'] ) ), array( 'type' => 'draft', 'post_id' => $pid, 'label' => __( 'Unpublish (keep the redirect)', 'seolity' ) ) );
			}
			// 2. Chain: the target is itself redirected.
			if ( $local && isset( $by[ $tpath ] ) && $tpath !== $r['source'] ) {
				$final = $by[ $tpath ]['target'];
				for ( $i = 0; $i < 6 && $this->is_local( $final ) && isset( $by[ Manager::normalize_source( $final ) ] ); $i++ ) {
					$final = $by[ Manager::normalize_source( $final ) ]['target'];
				}
				$out[] = $this->finding( 'chain_' . $r['id'], 'medium', __( 'Redirects', 'seolity' ), __( 'Redirect chain', 'seolity' ), urldecode( $r['source'] ) . ' → ' . urldecode( $tpath ) . ' → ' . urldecode( $final ), array( home_url( $r['source'] ) ), array( 'type' => 'retarget', 'rule_id' => (int) $r['id'], 'source' => $r['source'], 'target' => $final, 'label' => __( 'Point straight at the final page', 'seolity' ) ) );
				continue;
			}
			// 3. Probably the wrong article: another published post matches the old slug much better.
			if ( $local && in_array( $r['origin'], array( 'import', 'manual', 'ai' ), true ) ) {
				$best = $this->best_match( home_url( $r['source'] ) );
				$tid  = $this->post_id_for( $r['target'] );
				if ( $best && $best['id'] !== $tid && $best['score'] >= 0.7 && $best['score'] - $this->slug_similarity( $r['source'], $tpath ) >= 0.35 ) {
					/* translators: 1 old address 2 suggested title */
					$out[] = $this->finding( 'wrong_' . $r['id'], 'medium', __( 'Redirects', 'seolity' ), __( 'Redirect probably points to the wrong article', 'seolity' ), sprintf( __( '%1$s matches “%2$s” much better than the current destination.', 'seolity' ), urldecode( $r['source'] ), $best['title'] ) . ' (' . urldecode( $tpath ) . ')', array( home_url( $r['source'] ) ), array( 'type' => 'retarget', 'rule_id' => (int) $r['id'], 'source' => $r['source'], 'target' => $best['url'], 'label' => __( 'Point to the matching article', 'seolity' ) ) );
				}
			}
		}
		return $out;
	}

	/** Big jumps of indexed / not-indexed pages matched with what changed on the site those days. */
	private function timeline_events() {
		$s = $this->state();
		if ( empty( $s['summary']['chart'] ) ) {
			return array();
		}
		$out  = array();
		$prev = null;
		foreach ( $s['summary']['chart'] as $p ) {
			if ( null === $p['indexed'] ) {
				continue;
			}
			if ( $prev ) {
				$di = $p['indexed'] - $prev['indexed'];
				$dn = (int) $p['not_indexed'] - (int) $prev['not_indexed'];
				if ( abs( $di ) >= max( 8, 0.05 * $prev['indexed'] ) || abs( $dn ) >= max( 15, 0.08 * max( 1, $prev['not_indexed'] ) ) ) {
					$ev = $this->events_near( $p['date'] );
					/* translators: 1 date 2 indexed before 3 after 4 not indexed before 5 after */
					$title = sprintf( __( '%1$s: indexed %2$d → %3$d, not indexed %4$d → %5$d', 'seolity' ), $p['date'], $prev['indexed'], $p['indexed'], (int) $prev['not_indexed'], (int) $p['not_indexed'] );
					$out[] = $this->finding( 'jump_' . $p['date'], $di < 0 ? 'medium' : 'info', __( 'Timeline', 'seolity' ), $title, $ev ? __( 'Changes on the site in the days before:', 'seolity' ) . ' ' . implode( '; ', $ev ) : __( 'No SEOlity-recorded change in the days before (a theme, plugin or permalink change outside SEOlity, or Google re-evaluating).', 'seolity' ), array() );
				}
			}
			$prev = $p;
		}
		return $out;
	}

	private function events_near( $date ) {
		$db   = $this->plugin->db;
		$from = gmdate( 'Y-m-d', strtotime( $date ) - 7 * DAY_IN_SECONDS );
		$to   = $date . ' 23:59:59';
		$out  = array();
		$red  = (int) $db->value( 'SELECT COUNT(*) FROM {redirects} WHERE project_id = %d AND created_at BETWEEN %s AND %s', array( $db->project_id(), $from, $to ) );
		if ( $red ) {
			/* translators: %d: count */
			$out[] = sprintf( __( '%d redirects created', 'seolity' ), $red );
		}
		$ops = $db->rows( "SELECT title FROM {operations} WHERE project_id = %d AND status IN ('done','partial') AND updated_at BETWEEN %s AND %s LIMIT 5", array( $db->project_id(), $from, $to ) );
		foreach ( (array) $ops as $o ) {
			$out[] = $o['title'];
		}
		$slugs = (int) $db->value( "SELECT COUNT(*) FROM {redirects} WHERE project_id = %d AND origin = 'slug_change' AND created_at BETWEEN %s AND %s", array( $db->project_id(), $from, $to ) );
		if ( $slugs ) {
			/* translators: %d: count */
			$out[] = sprintf( __( '%d slugs changed', 'seolity' ), $slugs );
		}
		return $out;
	}

	/* ------------------------------------------------------------------ helpers */

	private function is_local( $url ) {
		$host = wp_parse_url( (string) $url, PHP_URL_HOST );
		return ! $host || $host === wp_parse_url( home_url(), PHP_URL_HOST );
	}

	private function rule_for( $path ) {
		return $this->plugin->db->row( "SELECT id, source, target FROM {redirects} WHERE project_id = %d AND source_hash = %s AND status IN ('active','external')", array( $this->plugin->db->project_id(), \SEOlity\Core\Database::hash( $path ) ) );
	}

	private function post_id_for( $url ) {
		$path = Manager::normalize_source( $url );
		if ( '/' === $path || false !== strpos( $path, '?' ) ) {
			return 0;
		}
		return (int) url_to_postid( home_url( $path . '/' ) );
	}

	/** Slug tokens: punctuation and Arabic letter variants normalized. */
	private static function tokens( $path ) {
		$slug = rawurldecode( (string) $path );
		$slug = trim( (string) preg_replace( '#^/(blog|product|category)/#u', '/', $slug ), '/' );
		$slug = basename( $slug );
		$slug = str_replace( array( 'ي', 'ك', 'ِ', 'ُ', 'َ', 'ّ', '‌' ), array( 'ی', 'ک', '', '', '', '', '' ), $slug );
		$slug = preg_replace( '/[؛،؟?!:«»".,()\[\]]+/u', '', $slug );
		$t    = array_filter( explode( '-', mb_strtolower( $slug ) ), static function ( $w ) {
			return mb_strlen( $w ) > 1 && ! preg_match( '/^\d+$/', $w );
		} );
		return array_values( array_unique( $t ) );
	}

	private function slug_similarity( $a, $b ) {
		$x = self::tokens( $a );
		$y = self::tokens( $b );
		if ( ! $x || ! $y ) {
			return 0;
		}
		$i = count( array_intersect( $x, $y ) );
		return $i / max( 1, min( count( $x ), count( $y ) ) * 0.5 + max( count( $x ), count( $y ) ) * 0.5 );
	}

	/** Closest published post / page / product by slug. */
	private function best_match( $url ) {
		static $pool = null;
		if ( null === $pool ) {
			global $wpdb;
			$pool = (array) $wpdb->get_results( "SELECT ID, post_title, post_name FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('post','page','product') LIMIT 3000", ARRAY_A ); // phpcs:ignore WordPress.DB
		}
		$best = null;
		foreach ( $pool as $p ) {
			$score = $this->slug_similarity( Manager::normalize_source( $url ), '/' . rawurldecode( $p['post_name'] ) );
			if ( ! $best || $score > $best['score'] ) {
				$best = array( 'id' => (int) $p['ID'], 'title' => $p['post_title'], 'score' => $score );
			}
		}
		if ( $best ) {
			$best['url'] = (string) get_permalink( $best['id'] );
		}
		return $best;
	}

	/* ------------------------------------------------------------------ fixes */

	/** Turn a finding into an operation that waits for the owner's approval. */
	public function fix( $finding_id ) {
		$f = null;
		foreach ( $this->analyze() as $x ) {
			if ( $x['id'] === $finding_id ) {
				$f = $x;
			}
		}
		if ( ! $f || empty( $f['fix']['type'] ) ) {
			return new \WP_Error( 'seolity_coverage', __( 'This finding has no automatic fix.', 'seolity' ), array( 'status' => 404 ) );
		}
		$ops   = $this->plugin->operations;
		$fix   = $f['fix'];
		$items = array();
		if ( 'draft' === $fix['type'] ) {
			$cid = $this->plugin->changes->propose( array( 'object_type' => 'post', 'object_id' => (int) $fix['post_id'], 'field' => 'post_status', 'after' => 'draft', 'reason' => __( 'Its address redirects to another page: unpublished so it leaves the sitemap (kept as a draft).', 'seolity' ), 'source' => 'coverage' ) );
			if ( is_wp_error( $cid ) ) {
				return $cid;
			}
			$items[] = array( 'kind' => 'change', 'id' => (int) $cid, 'key' => 'draft' );
		} elseif ( 'redirect' === $fix['type'] ) {
			$items[] = array( 'kind' => 'redirect', 'source' => $fix['source'], 'target' => $fix['target'], 'type' => 301, 'origin' => 'coverage', 'key' => 'r' );
		} elseif ( 'retarget' === $fix['type'] ) {
			$items[] = array( 'kind' => 'redirect', 'source' => $fix['source'], 'target' => $fix['target'], 'type' => 301, 'origin' => 'coverage', 'replace_id' => (int) $fix['rule_id'], 'key' => 'r' );
		}
		$id = $ops->create( 'coverage_fix', array( 'title' => $f['title'], 'status' => 'preparing' ) );
		return $ops->staged( $id, $items, array( $f['detail'] ) );
	}

	/* ------------------------------------------------------------------ messages */

	/** Known Search Console message types: regex => [type, severity]. */
	const MESSAGE_TYPES = array(
		'/new reasons? prevents? pages?.*from being indexed|دلایل جدیدی.*ایندکس/iu'          => array( 'indexing_new_reason', 'high' ),
		'/page indexing issues? detected|مشکلات ایندکس/iu'                                 => array( 'indexing_issues', 'high' ),
		'/core web vitals|معیارهای حیاتی/iu'                                                 => array( 'core_web_vitals', 'medium' ),
		'/manual action|اقدام دستی/iu'                                                        => array( 'manual_action', 'critical' ),
		'/security issue|hacked|malware|مشکل امنیتی/iu'                                       => array( 'security', 'critical' ),
		'/mobile usability|سهولت استفاده.*موبایل/iu'                                          => array( 'mobile', 'medium' ),
		'/(breadcrumbs?|structured data|product snippets?|merchant listings?|faq|review snippets?).*(issues?|errors?)|داده.*ساختاریافته/iu' => array( 'structured_data', 'medium' ),
		'/new owner|مالک جدید/iu'                                                              => array( 'new_owner', 'medium' ),
		'/sitemap.*(could not|couldn.t|error)|نقشه سایت.*خطا/iu'                             => array( 'sitemap', 'high' ),
		'/increase in.*(404|not found|server error|5xx)|افزایش.*(404|خطا)/iu'                 => array( 'crawl_errors', 'high' ),
		'/clicks.*(increase|milestone)|رکورد|milestone/iu'                                    => array( 'performance', 'info' ),
	);

	/** Read a pasted Search Console message (e-mail text), store it and notify with an explanation. */
	public function add_message( $text ) {
		$text = trim( wp_strip_all_tags( (string) $text ) );
		if ( mb_strlen( $text ) < 20 ) {
			return new \WP_Error( 'seolity_coverage', __( 'Paste the whole message text.', 'seolity' ), array( 'status' => 400 ) );
		}
		$type = array( 'other', 'medium' );
		foreach ( self::MESSAGE_TYPES as $re => $t ) {
			if ( preg_match( $re, $text ) ) {
				$type = $t;
				break;
			}
		}
		$reasons = array();
		foreach ( array_keys( self::REASONS ) as $name ) {
			if ( false !== stripos( str_replace( array( '’', '‘' ), "'", $text ), str_replace( array( '’', '‘' ), "'", $name ) ) ) {
				$reasons[] = self::reason_key( $name );
			}
		}
		$reasons = array_values( array_unique( $reasons ) );
		$msg     = array(
			'id'       => substr( md5( $text ), 0, 12 ),
			'type'     => $type[0],
			'severity' => $type[1],
			'reasons'  => $reasons,
			'text'     => mb_substr( $text, 0, 4000 ),
			'at'       => gmdate( 'Y-m-d H:i:s' ),
			'ai'       => null,
		);
		$s = $this->state();
		$s['messages'] = array_slice( array_merge( array( $msg ), array_filter( $s['messages'], static function ( $m ) use ( $msg ) {
			return $m['id'] !== $msg['id'];
		} ) ), 0, 50 );
		$this->save( $s );
		$help = $this->message_help( $msg );
		$this->plugin->alerts->message( 'warning', __( 'Search Console message', 'seolity' ) . ': ' . mb_substr( strtok( $text, "\n" ), 0, 140 ), $help, array( 'kind' => 'warning', 'severity' => $type[1], 'source' => 'gsc_message', 'links' => array( array( 'title' => __( 'Index coverage', 'seolity' ), 'route' => '#/coverage' ) ) ), 'gsc_message|' . $msg['id'] );
		$this->plugin->queue->push( 'coverage_review', array( 'message_id' => $msg['id'] ), array( 'unique' => true, 'priority' => 8, 'max_attempts' => 3 ) );
		return $msg;
	}

	/** Plain explanation + first steps for a message (works without AI). */
	private function message_help( array $m ) {
		$lines = array();
		$known = array(
			'redirect_error'         => __( 'Redirect error: Google followed a redirect that ended in a loop, a too-long chain, an empty or broken destination. Usually after changing article addresses twice or merging. Open Index coverage → the URL is traced live and the rule is fixed with your approval; then “Validate fix”.', 'seolity' ),
			'page_with_redirect'     => __( 'Page with redirect: the URL redirects — fine for old addresses, a problem only when it is a published page or still in the sitemap.', 'seolity' ),
			'not_found'              => __( 'Not found (404): deleted or mistyped URLs. Redirect the valuable ones (links, traffic) to the closest page; ignore junk.', 'seolity' ),
			'soft_404'               => __( 'Soft 404: the page answers 200 but looks empty / not found to Google (empty category, out-of-stock template). Add content or return a real 404/410.', 'seolity' ),
			'noindex'                => __( 'Excluded by noindex: correct for cart, account and archives; check landing and category pages.', 'seolity' ),
			'robots'                 => __( 'Blocked by robots.txt: correct for add-to-cart, feeds and system URLs; a problem only for real pages.', 'seolity' ),
			'crawled_not_indexed'    => __( 'Crawled – currently not indexed: Google saw the page and decided not to index it yet (quality, duplication, weak internal links). Feeds in this list are normal.', 'seolity' ),
			'discovered_not_indexed' => __( 'Discovered – currently not indexed: Google knows the URL but has not crawled it (crawl budget, weak links, slow server).', 'seolity' ),
			'server_error'           => __( 'Server error (5xx): the server failed while Google crawled — check hosting limits, firewall and uptime first.', 'seolity' ),
			'google_canonical'       => __( 'Google chose a different canonical: two pages look the same to Google — merge or differentiate them.', 'seolity' ),
		);
		foreach ( $m['reasons'] as $r ) {
			if ( isset( $known[ $r ] ) ) {
				$lines[] = '• ' . $known[ $r ];
			}
			$d = $this->state()['drilldowns'];
			if ( empty( $d[ $r ] ) ) {
				$lines[] = '  ' . __( 'Upload the export of this reason (Search Console → Pages → the reason → Export → Download ZIP) on the Index coverage page so SEOlity can see the URLs.', 'seolity' );
			}
		}
		if ( ! $lines ) {
			$types = array(
				'manual_action'   => __( 'A manual action is a penalty by a Google reviewer — the most serious message. Open Search Console → Security & Manual actions, fix every listed issue, then request a review.', 'seolity' ),
				'security'        => __( 'Security issue: the site may be hacked. Scan files and users, update everything, then request a review in Search Console.', 'seolity' ),
				'core_web_vitals' => __( 'Core Web Vitals: real visitors find some pages slow or jumpy. See Site Audit → Core Web Vitals and Server & speed.', 'seolity' ),
				'structured_data' => __( 'Structured data issue: some rich-result markup is invalid or incomplete. See Schema.', 'seolity' ),
				'sitemap'         => __( 'Sitemap problem: Google could not read a sitemap. Check Sitemap (address, errors, size).', 'seolity' ),
			);
			$lines[] = isset( $types[ $m['type'] ] ) ? $types[ $m['type'] ] : __( 'SEOlity stored the message; the AI explains it in a moment.', 'seolity' );
		}
		return implode( "\n", $lines );
	}

	/* ------------------------------------------------------------------ AI */

	/** Queue job: the AI explains the whole indexing picture (and a message, if given) and plans. */
	public function ai_review( $message_id = '' ) {
		$s     = $this->state();
		$msg   = null;
		foreach ( $s['messages'] as $m ) {
			if ( $m['id'] === $message_id ) {
				$msg = $m;
			}
		}
		$drill = array();
		foreach ( $s['drilldowns'] as $k => $d ) {
			$drill[] = array( 'reason' => $d['reason'], 'total' => $d['total'], 'sample' => array_slice( array_map( static function ( $u ) {
				return rawurldecode( $u['url'] );
			}, $d['urls'] ), 0, 25 ), 'trend' => array_slice( $d['chart'], -10 ) );
		}
		$input = array(
			'summary'    => $s['summary'] ? array( 'reasons' => $s['summary']['reasons'], 'timeline' => array_slice( $s['summary']['chart'], -45 ) ) : null,
			'drilldowns' => $drill,
			'findings'   => array_map( static function ( $f ) {
				return array( 'severity' => $f['severity'], 'reason' => $f['reason'], 'title' => $f['title'], 'detail' => $f['detail'], 'count' => $f['count'], 'intentional' => $f['intentional'] );
			}, array_slice( $this->analyze(), 0, 40 ) ),
			'message'    => $msg ? $msg['text'] : null,
		);
		if ( ! $input['summary'] && ! $drill && ! $msg ) {
			return true;
		}
		$res = $this->plugin->ai->run( 'coverage_review', $input, array( 'knowledge_query' => 'google search console page indexing redirect error coverage' ) );
		if ( is_wp_error( $res ) ) {
			return true;
		}
		$data = $res['data'];
		$s    = $this->state();
		$ai   = array( 'summary' => (string) $data['summary'], 'reasons' => (array) $data['reasons'], 'timeline' => (string) $data['timeline'], 'plan' => (array) $data['plan'], 'at' => gmdate( 'Y-m-d H:i:s' ), 'message_id' => $message_id );
		if ( $msg ) {
			foreach ( $s['messages'] as &$m ) {
				if ( $m['id'] === $message_id ) {
					$m['ai'] = $ai;
				}
			}
			unset( $m );
		}
		$s['ai'] = $ai;
		$this->save( $s );
		foreach ( $ai['plan'] as $p ) {
			if ( empty( $p['title'] ) ) {
				continue;
			}
			$cat = isset( $p['category'] ) && in_array( $p['category'], array( 'indexability', 'technical', '404', 'duplicates', 'onpage', 'links', 'content', 'products', 'offpage', 'other' ), true ) ? $p['category'] : 'indexability';
			$pri = isset( $p['priority'] ) && in_array( $p['priority'], array( 'critical', 'high', 'medium', 'low' ), true ) ? $p['priority'] : 'medium';
			$this->plugin->recommendations->add( array( 'type' => 'coverage_fix', 'key' => sanitize_title( $p['title'] ), 'category' => $cat, 'title' => (string) $p['title'], 'reason' => isset( $p['why'] ) ? (string) $p['why'] : '', 'priority' => $pri, 'impact' => 60, 'effort' => 30, 'confidence' => 70, 'evidence' => array( 'source' => 'coverage' ), 'force' => true ) );
		}
		$this->plugin->alerts->message( 'ai', $msg ? __( 'AI explanation of the Search Console message', 'seolity' ) : __( 'AI analysis of index coverage', 'seolity' ), $ai['summary'] . ( $ai['plan'] ? "\n\n" . __( 'Plan (added to the SEO roadmap):', 'seolity' ) . "\n• " . implode( "\n• ", wp_list_pluck( $ai['plan'], 'title' ) ) : '' ), array( 'kind' => 'ai', 'links' => array( array( 'title' => __( 'Index coverage', 'seolity' ), 'route' => '#/coverage' ) ) ), 'coverage_ai|' . md5( $ai['summary'] ) );
		return true;
	}

	/* ------------------------------------------------------------------ daily watch */

	/**
	 * Daily: URL Inspection for a rotating batch of the site's URLs (Google's real index state),
	 * a notification when a NEW reason appears (like Search Console's e-mail), sitemap errors, and
	 * new redirect problems from the audit.
	 */
	public function watch() {
		$w = get_option( self::WATCH, array() );
		$w = is_array( $w ) ? $w : array();
		$w = array_merge( array( 'urls' => array(), 'pointer' => 0, 'reasons' => array(), 'audit' => array(), 'sitemaps' => array() ), $w );
		$n = (int) $this->plugin->settings->get( 'index_watch_daily' );
		if ( $n > 0 && $this->plugin->gsc_oauth->is_connected() ) {
			global $wpdb;
			$ids   = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('post','page','product') ORDER BY ID LIMIT 5000" ); // phpcs:ignore WordPress.DB
			$total = count( $ids );
			$new   = array();
			for ( $i = 0; $i < min( $n, $total ); $i++ ) {
				$id  = (int) $ids[ ( $w['pointer'] + $i ) % $total ];
				$url = (string) get_permalink( $id );
				$res = $this->plugin->gsc_client->inspect( $url );
				if ( is_wp_error( $res ) ) {
					break; // Quota or auth: try again tomorrow.
				}
				$st = isset( $res['inspectionResult']['indexStatusResult'] ) ? $res['inspectionResult']['indexStatusResult'] : array();
				$cs = isset( $st['coverageState'] ) ? (string) $st['coverageState'] : '';
				$w['urls'][ $url ] = array( 'state' => $cs, 'verdict' => isset( $st['verdict'] ) ? $st['verdict'] : '', 'fetch' => isset( $st['pageFetchState'] ) ? $st['pageFetchState'] : '', 'canonical' => isset( $st['googleCanonical'] ) ? $st['googleCanonical'] : '', 'crawled' => isset( $st['lastCrawlTime'] ) ? $st['lastCrawlTime'] : '', 'at' => gmdate( 'Y-m-d' ) );
				if ( $cs && 'PASS' !== ( isset( $st['verdict'] ) ? $st['verdict'] : '' ) && ! in_array( $cs, $w['reasons'], true ) ) {
					$new[ $cs ][] = $url;
				}
			}
			$w['pointer'] = $total ? ( $w['pointer'] + $n ) % $total : 0;
			foreach ( $new as $reason => $urls ) {
				$w['reasons'][] = $reason;
				/* translators: %s: reason */
				$this->plugin->alerts->message( 'warning', sprintf( __( 'New reason prevents pages from being indexed: %s', 'seolity' ), $reason ), __( 'Found by SEOlity’s daily Google URL Inspection of your own pages (before Search Console e-mails about it):', 'seolity' ) . "\n• " . implode( "\n• ", array_map( 'urldecode', array_slice( $urls, 0, 10 ) ) ), array( 'kind' => 'warning', 'severity' => 'high', 'url' => $urls[0], 'links' => array( array( 'title' => __( 'Index coverage', 'seolity' ), 'route' => '#/coverage' ) ) ), 'index_watch|' . $reason );
			}
			if ( count( $w['urls'] ) > 3000 ) {
				$w['urls'] = array_slice( $w['urls'], -3000, null, true );
			}
			// Sitemaps known to Google: errors / warnings.
			$sm = $this->plugin->gsc_client->sitemaps();
			if ( ! is_wp_error( $sm ) && ! empty( $sm['sitemap'] ) ) {
				foreach ( $sm['sitemap'] as $x ) {
					$err = (int) ( isset( $x['errors'] ) ? $x['errors'] : 0 );
					$key = isset( $x['path'] ) ? $x['path'] : '';
					if ( $err > 0 && ( ! isset( $w['sitemaps'][ $key ] ) || $w['sitemaps'][ $key ] < $err ) ) {
						/* translators: 1 sitemap 2 errors */
						$this->plugin->alerts->message( 'warning', sprintf( __( 'Google reports %2$d errors in sitemap %1$s', 'seolity' ), urldecode( $key ), $err ), __( 'Open Sitemap and Search Console → Sitemaps to see which entries Google could not use.', 'seolity' ), array( 'kind' => 'warning', 'severity' => 'high', 'links' => array( array( 'title' => __( 'Sitemap', 'seolity' ), 'route' => '#/sitemap' ) ) ), 'sitemap_err|' . $key . '|' . $err );
					}
					$w['sitemaps'][ $key ] = $err;
				}
			}
		}
		// Redirect hygiene: notify about NEW problems only.
		$ids = array();
		foreach ( $this->redirect_audit() as $f ) {
			$ids[ $f['id'] ] = $f;
		}
		$fresh = array_diff_key( $ids, array_flip( $w['audit'] ) );
		$hidden = array_filter( $fresh, static function ( $f ) {
			return 0 === strpos( $f['id'], 'hidden_' ) || 0 === strpos( $f['id'], 'chain_' );
		} );
		if ( $hidden ) {
			/* translators: %d: count */
			$this->plugin->alerts->message( 'warning', sprintf( __( '%d new redirect problems', 'seolity' ), count( $hidden ) ), "• " . implode( "\n• ", wp_list_pluck( $hidden, 'title' ) ), array( 'kind' => 'warning', 'severity' => 'medium', 'links' => array( array( 'title' => __( 'Index coverage', 'seolity' ), 'route' => '#/coverage' ) ) ), 'redirect_audit|' . md5( implode( ',', array_keys( $hidden ) ) ) );
		}
		$w['audit'] = array_keys( $ids );
		update_option( self::WATCH, $w, false );
		return true;
	}

	/** Index watch overview for the UI. */
	public function watch_view() {
		$w      = get_option( self::WATCH, array() );
		$urls   = is_array( $w ) && ! empty( $w['urls'] ) ? $w['urls'] : array();
		$counts = array();
		foreach ( $urls as $u ) {
			$k            = $u['state'] ? $u['state'] : '—';
			$counts[ $k ] = isset( $counts[ $k ] ) ? $counts[ $k ] + 1 : 1;
		}
		arsort( $counts );
		$problems = array();
		foreach ( $urls as $url => $u ) {
			if ( 'PASS' !== $u['verdict'] && $u['state'] ) {
				$problems[] = array( 'url' => $url ) + $u;
			}
		}
		return array( 'checked' => count( $urls ), 'states' => $counts, 'problems' => array_slice( $problems, 0, 100 ), 'daily' => (int) $this->plugin->settings->get( 'index_watch_daily' ) );
	}

	public function reset() {
		delete_option( self::OPTION );
		return true;
	}
}
