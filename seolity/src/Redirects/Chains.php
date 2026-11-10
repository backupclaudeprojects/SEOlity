<?php
/**
 * Redirect chains and loops, as a graph the owner can see and fix.
 *
 * A chain (A → B → C) costs every visitor and Googlebot an extra request per step and loses a
 * little signal on each hop; a loop (A → B → A) is a dead end. The fix points every step
 * straight to one final page (A → C, B → C). Which page is "final" is a real SEO decision: by
 * default the page the chain already ends on, or — when the AI reviews it — the live page that
 * best matches the old addresses and matters most for the site (e.g. the shop or a category
 * over a thin page), chosen only from checked candidates.
 *
 * Nothing changes until the owner approves; every new redirect is verified on the live site and
 * can be undone (rules that live in Rank Math are changed in Rank Math).
 *
 * @package SEOlity
 */

namespace SEOlity\Redirects;

use SEOlity\Activity\Activity;
use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class Chains {

	const MAX_CHAINS = 60;

	/** @var Plugin */
	private $plugin;

	/** @var array */
	private $node_cache = array();

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/** Chains with what each address is (no live requests: fast enough for the page). */
	public function view() {
		$chains = array_slice( $this->plugin->redirects->chains(), 0, self::MAX_CHAINS );
		$nodes  = array();
		$hops   = array();
		foreach ( $chains as $c ) {
			foreach ( $c['chain'] as $path ) {
				if ( ! isset( $nodes[ $path ] ) ) {
					$nodes[ $path ] = $this->node( $path );
				}
			}
			foreach ( $c['hops'] as $hop ) {
				$hops[ $hop['id'] ] = true;
			}
		}
		return array(
			'chains'   => $chains,
			'nodes'    => $nodes,
			'count'    => count( $chains ),
			'loops'    => count( array_filter( wp_list_pluck( $chains, 'loop' ) ) ),
			'hops'     => count( $hops ),
			'rankmath' => $this->plugin->rankmath->owns_redirects(),
		);
	}

	/** What one address is: a real page (title, importance, clicks) or only a redirect. */
	private function node( $path ) {
		if ( isset( $this->node_cache[ $path ] ) ) {
			return $this->node_cache[ $path ];
		}
		$url   = home_url( $path );
		$imp   = $this->plugin->importance->of( $url );
		$title = '';
		if ( $imp['object_id'] ) {
			if ( 'term' === $imp['object_type'] ) {
				$term  = get_term( (int) $imp['object_id'] );
				$title = $term && ! is_wp_error( $term ) ? $term->name : '';
			} else {
				$title = get_the_title( (int) $imp['object_id'] );
			}
		}
		$clicks = 0;
		if ( $this->plugin->gsc_oauth->is_connected() ) {
			$m      = $this->plugin->gsc_analytics->page_metrics( $url, 28 );
			$clicks = is_array( $m ) && isset( $m['clicks'] ) ? (int) $m['clicks'] : 0;
		}
		$is_page = $imp['object_id'] > 0 || '/' === $path;
		return $this->node_cache[ $path ] = array(
			'path'       => $path,
			'url'        => $url,
			'title'      => wp_strip_all_tags( (string) $title ),
			'is_page'    => $is_page,
			'importance' => $is_page ? $imp['level'] : '',
			'label'      => $is_page ? $imp['label'] : '',
			'score'      => $is_page ? (int) $imp['score'] : 0,
			'clicks_28d' => $clicks,
		);
	}

	/**
	 * Start a review that prepares the fix for approval.
	 *
	 * @param bool   $use_ai       Let the AI choose the best final page (else: where each chain ends).
	 * @param string $instructions The owner's rule (e.g. "products go to their category").
	 */
	public function start( $use_ai = false, $instructions = '' ) {
		if ( ! $this->plugin->redirects->chains() ) {
			return new \WP_Error( 'seolity_chains', __( 'There are no redirect chains or loops.', 'seolity' ), array( 'status' => 400 ) );
		}
		$use_ai = $use_ai && $this->plugin->ai->is_ready();
		$title  = $use_ai ? __( 'Fix redirect chains: AI chooses the best final page', 'seolity' ) : __( 'Fix redirect chains: point every step to the final page', 'seolity' );
		$id     = $this->plugin->operations->create( 'flatten_chains', array( 'title' => $title, 'instructions' => sanitize_textarea_field( (string) $instructions ), 'input' => array( 'ai' => $use_ai ) ) );
		$aid    = $this->plugin->activity->start( 'agent', __( 'Reviewing redirect chains', 'seolity' ), array( 'detail' => $title, 'dock' => true, 'status' => 'queued', 'step' => __( 'Queued — starting in a moment…', 'seolity' ), 'meta' => array( 'type' => 'operation', 'operation_id' => (int) $id ) ) );
		$this->plugin->operations->update( $id, array( 'activity_id' => $aid ) );
		$job = $this->plugin->queue->push( 'redirect_chains_prepare', array( 'id' => (int) $id, 'user_id' => get_current_user_id() ), array( 'unique' => 'rcp_' . (int) $id, 'priority' => 0, 'max_attempts' => 1, 'timeout' => 1200 ) );
		$this->plugin->activity->attach_job( $aid, $job );
		\SEOlity\Activity\AiJobs::run_after_response( $this->plugin );
		return $this->plugin->operations->get( $id );
	}

	/** Live pages a chain may end on: where it ends now, similar pages, and the shop. */
	private function candidates( array $c, array $live ) {
		$out   = array();
		$final = end( $c['chain'] );
		$add   = function ( $url, $why ) use ( &$out, $live ) {
			$path = Manager::normalize_source( $url );
			if ( isset( $out[ $path ] ) || ( isset( $live[ $path ] ) && ! $live[ $path ] ) ) {
				return;
			}
			$n            = $this->node( $path );
			$out[ $path ] = array(
				'url'        => home_url( $path ),
				'title'      => $n['title'],
				'why'        => $why,
				'importance' => $n['importance'],
				'score'      => $n['score'],
				'clicks_28d' => $n['clicks_28d'],
			);
		};
		if ( ! $c['loop'] ) {
			$add( home_url( $final ), 'where the chain ends today' );
		} else {
			foreach ( $c['chain'] as $p ) {
				if ( $this->node( $p )['is_page'] ) {
					$add( home_url( $p ), 'a real page inside the loop (its own redirect would be removed)' );
				}
			}
		}
		foreach ( array_slice( $c['chain'], 0, 2 ) as $p ) {
			foreach ( array_slice( $this->plugin->not_found->candidates( $p ), 0, 3 ) as $cand ) {
				if ( is_string( $cand['url'] ) && (int) $cand['score'] >= 35 ) {
					$add( $cand['url'], 'page similar to the old address (' . (int) $cand['score'] . '%)' );
				}
			}
		}
		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$shop = wc_get_page_permalink( 'shop' );
			if ( $shop ) {
				$add( $shop, 'the shop page' );
			}
		}
		return array_values( $out );
	}

	/** Queue handler: build the reviewed fix. */
	public function prepare( $op_id ) {
		$ops = $this->plugin->operations;
		$op  = $ops->get( $op_id );
		if ( ! $op || 'preparing' !== $op['status'] ) {
			return true;
		}
		$act    = $this->plugin->activity;
		$aid    = (int) $op['activity_id'];
		$prev   = Activity::set_current( $aid );
		$chains = array_slice( $this->plugin->redirects->chains(), 0, self::MAX_CHAINS );
		$act->step( $aid, __( 'Checking where each chain ends on the live site…', 'seolity' ), 10 );
		$live = array();
		foreach ( $chains as $c ) {
			$final = end( $c['chain'] );
			if ( ! $c['loop'] && ! isset( $live[ $final ] ) && count( $live ) < 80 ) {
				$ok             = $this->plugin->operations->verifier()->target_ok( home_url( $final ) );
				$live[ $final ] = ! empty( $ok['ok'] );
			}
		}
		$use_ai    = ! empty( $op['input']['ai'] ) && $this->plugin->ai->is_ready();
		$decisions = array();
		$cands     = array();
		foreach ( $chains as $i => $c ) {
			$cands[ $i ] = $this->candidates( $c, $live );
		}
		$summary = '';
		if ( $use_ai ) {
			$act->step( $aid, __( 'AI is choosing the best final page for each chain…', 'seolity' ), 40 );
			$input = array();
			foreach ( $chains as $i => $c ) {
				$input[] = array(
					'chain_id'   => $i,
					'addresses'  => array_map(
						function ( $p ) {
							$n = $this->node( $p );
							return array( 'path' => $p, 'title' => $n['title'], 'is_page' => $n['is_page'], 'clicks_28d' => $n['clicks_28d'] );
						},
						$c['chain']
					),
					'loop'       => $c['loop'],
					'candidates' => $cands[ $i ],
				);
			}
			$prev_i = \SEOlity\AI\Engine::with_instructions( (string) $op['instructions'] );
			$res    = $this->plugin->ai->run( 'redirect_chains', array( 'site' => home_url( '/' ), 'woocommerce' => class_exists( 'WooCommerce' ), 'chains' => $input ) );
			\SEOlity\AI\Engine::with_instructions( $prev_i );
			if ( is_wp_error( $res ) ) {
				$ops->update( $op_id, array( 'status' => 'failed', 'error' => $res->get_error_message() ) );
				$act->finish( $aid, 'failed', $res->get_error_message() );
				Activity::set_current( $prev );
				return true;
			}
			$summary = (string) $res['data']['summary'];
			foreach ( (array) $res['data']['decisions'] as $d ) {
				$decisions[ (int) $d['chain_id'] ] = $d;
			}
		}
		$act->step( $aid, __( 'Preparing the new redirects…', 'seolity' ), 75 );
		$items = array();
		$taken = array();
		$skip  = 0;
		foreach ( $chains as $i => $c ) {
			$allowed = wp_list_pluck( $cands[ $i ], 'url' );
			$target  = '';
			$reason  = '';
			$conf    = 80;
			if ( isset( $decisions[ $i ] ) && in_array( (string) $decisions[ $i ]['target_url'], $allowed, true ) ) {
				$target = (string) $decisions[ $i ]['target_url'];
				$reason = (string) $decisions[ $i ]['reason'];
				$conf   = max( 0, min( 100, (int) $decisions[ $i ]['confidence'] ) );
			} elseif ( ! $c['loop'] && ! empty( $live[ end( $c['chain'] ) ] ) ) {
				$target = home_url( end( $c['chain'] ) );
				$reason = __( 'Every step now goes straight to the page the chain already ends on.', 'seolity' );
			}
			if ( '' === $target ) {
				$skip++;
				continue;
			}
			$tpath = Manager::normalize_source( $target );
			foreach ( $c['hops'] as $hop ) {
				if ( isset( $taken[ $hop['id'] ] ) ) {
					continue;
				}
				$taken[ $hop['id'] ] = true;
				$base = array( 'key' => 'ch' . $hop['id'], 'reason' => $reason, 'confidence' => $conf, 'chain' => $c['chain'], 'loop' => $c['loop'] );
				if ( $hop['source'] === $tpath ) {
					// The chosen page itself redirects (a loop): its own rule goes, so it answers again.
					$items[] = $base + array( 'kind' => 'redirect_remove', 'replace_id' => (int) $hop['id'], 'source' => $hop['source'] );
				} elseif ( $hop['to'] !== $tpath ) {
					$items[] = $base + array( 'kind' => 'redirect', 'replace_id' => (int) $hop['id'], 'source' => $hop['source'], 'target' => $target, 'type' => in_array( (int) $hop['type'], array( 301, 302, 307, 308 ), true ) ? (int) $hop['type'] : 301, 'origin' => 'chain_fix', 'merge' => true );
				}
			}
		}
		$plan = array_values(
			array_filter(
				array(
					/* translators: 1 chains 2 redirects */
					sprintf( __( '%1$d chains/loops; %2$d redirects get a new target so that every old address reaches its final page in one step.', 'seolity' ), count( $chains ), count( $items ) ),
					$summary,
					/* translators: %d: chains */
					$skip ? sprintf( __( '%d chains were left as they are: they end on a page that does not answer, or no safe final page was found (fix them by hand).', 'seolity' ), $skip ) : '',
					$this->plugin->rankmath->owns_redirects() ? __( 'Rules that live in Rank Math are changed in Rank Math.', 'seolity' ) : '',
					__( 'Nothing changes until you approve. Every new redirect is checked on the live site and can be undone.', 'seolity' ),
				)
			)
		);
		$ops->staged( $op_id, $items, $plan );
		$act->finish( $aid, 'done', __( 'Ready for your review', 'seolity' ), array( 'summary' => __( 'Ready for your review', 'seolity' ), 'result' => array( 'operation_id' => (int) $op_id ) ) );
		Activity::set_current( $prev );
		return true;
	}
}
