<?php
/**
 * Turns what the AI chat proposes into real items of the plugin:
 *
 * - task      → an SEO roadmap item (any category, including off-site work such as reportage);
 * - change    → a roadmap item with an agent action that is prepared at once: it appears in the
 *               floating task popup as "Review & apply" and is applied only when the owner approves;
 * - follow_up → a follow-up that measures the pages / queries again after N days and reports;
 * - message   → a notification for the owner.
 *
 * Changes are only made for pages that matter (PageImportance): building blocks and unused
 * templates are skipped with the reason.
 *
 * @package SEOlity
 */

namespace SEOlity\Chat;

use SEOlity\Plugin;

defined( 'ABSPATH' ) || exit;

final class ChatActions {

	const IMPACT = array( 'critical' => 90, 'high' => 70, 'medium' => 50, 'low' => 30 );

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * @param array  $proposals From the chat task.
	 * @param string $conversation Conversation id (for links back).
	 * @return array[] One result per proposal: kind, ok, title, note, route, id.
	 */
	public function apply( array $proposals, $conversation = '', array $package = array() ) {
		$out  = array();
		$seen = array();
		foreach ( array_slice( $proposals, 0, 8 ) as $p ) {
			if ( ! is_array( $p ) || empty( $p['kind'] ) || empty( $p['title'] ) ) {
				continue;
			}
			$k = $p['kind'] . '|' . $p['title'];
			if ( isset( $seen[ $k ] ) ) {
				continue;
			}
			$seen[ $k ] = true;
			try {
				switch ( $p['kind'] ) {
					case 'task':
						$out[] = $this->task( $p );
						break;
					case 'change':
						$out[] = $this->change( $p );
						break;
					case 'follow_up':
						$out[] = $this->follow_up( $p, $conversation );
						break;
					case 'redirects':
						$out[] = $this->redirects( $p, $conversation );
						break;
					case 'rule':
						$out[] = $this->rule( $p );
						break;
					case 'review_404s':
						$ids = ! empty( $package['ids'] ) && '404' === $package['type'] ? (array) $package['ids'] : array();
						if ( ! $ids ) {
							$out[] = array( 'kind' => 'review_404s', 'ok' => false, 'title' => $p['title'], 'note' => __( 'Select the URLs on the 404 page and send them to the chat first.', 'seolity' ), 'route' => '#/404', 'id' => 0 );
							break;
						}
						$op    = $this->plugin->nf_triage->start( (string) ( ! empty( $p['instructions'] ) ? $p['instructions'] : $p['detail'] ), $ids );
						$out[] = array(
							'kind'  => 'review_404s',
							'ok'    => ! is_wp_error( $op ),
							'title' => $p['title'],
							'note'  => is_wp_error( $op ) ? $op->get_error_message() : __( 'Being prepared for exactly these URLs — it will appear in the floating popup for review. URLs without value are hidden from the 404 list; 301 / 410 change nothing until you approve.', 'seolity' ),
							'route' => is_wp_error( $op ) ? '#/404' : '#/404?op=' . (int) $op['id'],
							'id'    => is_wp_error( $op ) ? 0 : (int) $op['id'],
						);
						break;
					case 'message':
						$id    = $this->plugin->alerts->message( 'ai', (string) $p['title'], (string) $p['detail'], array( 'url' => $this->abs( isset( $p['url'] ) ? $p['url'] : '' ), 'links' => array( array( 'title' => __( 'Open the conversation', 'seolity' ), 'route' => '#/ai' ) ) ) );
						$out[] = array( 'kind' => 'message', 'ok' => (bool) $id, 'title' => $p['title'], 'note' => __( 'Added to notifications.', 'seolity' ), 'route' => '#/alerts', 'id' => (int) $id );
						break;
				}
			} catch ( \Throwable $e ) {
				$out[] = array( 'kind' => $p['kind'], 'ok' => false, 'title' => $p['title'], 'note' => $e->getMessage(), 'route' => '', 'id' => 0 );
			}
		}
		if ( $out ) {
			$this->plugin->audit_log->log( 'chat.actions', 'site', null, array( 'created' => count( array_filter( wp_list_pluck( $out, 'ok' ) ) ) ) );
		}
		return $out;
	}

	private function abs( $u ) {
		$u = trim( (string) $u );
		if ( '' === $u ) {
			return '';
		}
		return esc_url_raw( preg_match( '#^https?://#', $u ) ? $u : home_url( '/' . ltrim( $u, '/' ) ) );
	}

	private function priority( array $p ) {
		return isset( self::IMPACT[ $p['priority'] ] ) ? $p['priority'] : 'medium';
	}

	private function task( array $p ) {
		$url  = $this->abs( isset( $p['url'] ) ? $p['url'] : '' );
		$imp  = $url ? $this->plugin->importance->of( $url ) : null;
		$prio = $this->priority( $p );
		$cat  = isset( $p['category'] ) ? (string) $p['category'] : 'other';
		$id   = $this->plugin->recommendations->add(
			array(
				'type'        => 'chat_task',
				'key'         => 'chat|' . md5( $p['title'] . '|' . $url ),
				'title'       => (string) $p['title'],
				'reason'      => (string) $p['detail'],
				'url'         => $url ? $url : null,
				'object_type' => $imp && $imp['object_id'] ? $imp['object_type'] : null,
				'object_id'   => $imp && $imp['object_id'] ? $imp['object_id'] : null,
				'priority'    => $prio,
				'category'    => $cat,
				'impact'      => self::IMPACT[ $prio ],
				'effort'      => 30,
				'confidence'  => 70,
				'evidence'    => array( 'source' => 'chat' ),
				'action'      => array( 'type' => 'route', 'route' => \SEOlity\Recommendations\Engine::category_route( $cat ) ),
				'force'       => ! $url, // Off-site work has no page to judge.
			)
		);
		if ( ! $id ) {
			return array( 'kind' => 'task', 'ok' => false, 'title' => $p['title'], 'note' => $imp ? implode( ' ', array_slice( $imp['reasons'], 0, 2 ) ) : __( 'Not added.', 'seolity' ), 'route' => '', 'id' => 0 );
		}
		return array( 'kind' => 'task', 'ok' => true, 'title' => $p['title'], 'note' => __( 'Added to the SEO roadmap.', 'seolity' ), 'route' => '#/actions?issue=' . (int) $id, 'id' => (int) $id );
	}

	/**
	 * A list of redirects from the chat → one reviewed operation (each pair checked: live target,
	 * no loop, old address not a live page). Existing rules for a source are corrected, not doubled.
	 */
	private function redirects( array $p, $conversation ) {
		$items = array();
		$db    = $this->plugin->db;
		foreach ( array_slice( isset( $p['redirects'] ) ? (array) $p['redirects'] : array(), 0, 60 ) as $i => $pair ) {
			$src = \SEOlity\Redirects\Manager::normalize_source( isset( $pair['source'] ) ? (string) $pair['source'] : '' );
			$tgt = $this->abs( isset( $pair['target'] ) ? (string) $pair['target'] : '' );
			if ( '/' === $src || '' === $tgt ) {
				continue;
			}
			$old = $db->row( "SELECT id FROM {redirects} WHERE project_id = %d AND source_hash = %s AND status IN ('active','external') AND is_regex = 0", array( $db->project_id(), \SEOlity\Core\Database::hash( $src ) ) );
			$nf  = (int) $db->value( 'SELECT id FROM {not_found} WHERE project_id = %d AND url_hash = %s', array( $db->project_id(), \SEOlity\Core\Database::hash( $src ) ) );
			$it  = array( 'kind' => 'redirect', 'key' => 'cr' . $i, 'source' => $src, 'target' => $tgt, 'type' => 301, 'origin' => 'chat', 'reason' => (string) $p['detail'] );
			if ( $old ) {
				$it['replace_id'] = (int) $old['id'];
			}
			if ( $nf ) {
				$it['not_found_id'] = $nf;
			}
			$items[] = $it;
		}
		if ( ! $items ) {
			return array( 'kind' => 'redirects', 'ok' => false, 'title' => $p['title'], 'note' => __( 'No usable redirect pairs (an old address and a live target page are needed).', 'seolity' ), 'route' => '', 'id' => 0 );
		}
		$id  = $this->plugin->operations->create( 'bulk_redirect', array( 'title' => (string) $p['title'], 'instructions' => (string) $p['detail'], 'input' => array( 'count' => count( $items ), 'items' => $items, 'conversation' => (string) $conversation ) ) );
		$aid = $this->plugin->activity->start( 'agent', __( 'Checking redirects from the chat', 'seolity' ), array( 'detail' => (string) $p['title'], 'dock' => true, 'status' => 'queued', 'step' => __( 'Queued — starting in a moment…', 'seolity' ), 'meta' => array( 'type' => 'operation', 'operation_id' => (int) $id ) ) );
		$this->plugin->operations->update( $id, array( 'activity_id' => $aid ) );
		$job = $this->plugin->queue->push( 'redirects_stage', array( 'id' => (int) $id ), array( 'unique' => 'rs_' . (int) $id, 'priority' => 0, 'max_attempts' => 1, 'timeout' => 900 ) );
		$this->plugin->activity->attach_job( $aid, $job );
		\SEOlity\Activity\AiJobs::run_after_response( $this->plugin );
		return array(
			'kind'  => 'redirects',
			'ok'    => true,
			'title' => $p['title'],
			/* translators: %d: redirects */
			'note'  => sprintf( __( '%d redirects are being checked; they wait in the 404 monitor under “Decisions waiting for your approval”. Nothing changes until you approve.', 'seolity' ), count( $items ) ),
			'route' => '#/404?op=' . (int) $id,
			'id'    => (int) $id,
		);
	}

	/** A lasting decision of the owner from the chat → a site rule (editable on the Site rules page). */
	private function rule( array $p ) {
		$r = $this->plugin->site_rules->save(
			array(
				'title'  => (string) $p['title'],
				'text'   => (string) $p['detail'],
				'query'  => isset( $p['rule_query'] ) ? (string) $p['rule_query'] : '',
				'url'    => $this->abs( isset( $p['url'] ) ? (string) $p['url'] : '' ),
				'avoid'  => array_map( array( $this, 'abs' ), isset( $p['rule_avoid'] ) ? (array) $p['rule_avoid'] : array() ),
				'source' => 'chat',
			)
		);
		return array( 'kind' => 'rule', 'ok' => ! is_wp_error( $r ), 'title' => $p['title'], 'note' => is_wp_error( $r ) ? $r->get_error_message() : __( 'Saved as a site rule — every AI part of SEOlity now follows it. Edit or remove it on the Site rules page.', 'seolity' ), 'route' => '#/site-rules', 'id' => is_wp_error( $r ) ? 0 : (int) $r['id'] );
	}

	private function change( array $p ) {
		$action = isset( $p['action'] ) ? (string) $p['action'] : '';
		$url    = $this->abs( isset( $p['url'] ) ? $p['url'] : '' );
		if ( '' === $url || '' === $action ) {
			return array( 'kind' => 'change', 'ok' => false, 'title' => $p['title'], 'note' => __( 'A change needs a page and an action.', 'seolity' ), 'route' => '', 'id' => 0 );
		}
		$imp = $this->plugin->importance->of( $url );
		if ( ! $imp['seo_target'] && 'create_redirect' !== $action ) {
			return array( 'kind' => 'change', 'ok' => false, 'title' => $p['title'], 'note' => $imp['label'] . ': ' . implode( ' ', array_slice( $imp['reasons'], 0, 2 ) ), 'route' => '', 'id' => 0 );
		}
		$otype = 'term' === $imp['object_type'] ? 'term' : 'post';
		$oid   = (int) $imp['object_id'];
		switch ( $action ) {
			case 'set_field':
				$field = isset( $p['field'] ) ? (string) $p['field'] : '';
				if ( ! $oid || ! \SEOlity\Content\Objects::supports( $otype, $field ) || null === $p['value'] ) {
					return array( 'kind' => 'change', 'ok' => false, 'title' => $p['title'], 'note' => __( 'This field cannot be changed on this page.', 'seolity' ), 'route' => '', 'id' => 0 );
				}
				$act = array( 'type' => 'set_field', 'object_type' => $otype, 'object_id' => $oid, 'field' => $field, 'value' => (string) $p['value'] );
				break;
			case 'create_redirect':
				$act = array( 'type' => 'create_redirect', 'source' => $url, 'target' => $this->abs( isset( $p['target'] ) ? $p['target'] : '' ) );
				break;
			default:
				if ( ! $oid ) {
					return array( 'kind' => 'change', 'ok' => false, 'title' => $p['title'], 'note' => __( 'This address is not a page or archive of the site.', 'seolity' ), 'route' => '', 'id' => 0 );
				}
				$act = array( 'type' => $action, 'object_type' => $otype, 'object_id' => $oid );
		}
		$prio = $this->priority( $p );
		$rid  = $this->plugin->recommendations->add(
			array(
				'type'        => 'chat_change',
				'key'         => 'chat|' . md5( $p['title'] . '|' . $url . '|' . $action ),
				'title'       => (string) $p['title'],
				'reason'      => (string) $p['detail'],
				'url'         => $url,
				'object_type' => $oid ? $otype : null,
				'object_id'   => $oid ? $oid : null,
				'priority'    => $prio,
				'category'    => isset( $p['category'] ) ? (string) $p['category'] : 'content',
				'impact'      => self::IMPACT[ $prio ],
				'effort'      => 20,
				'confidence'  => 75,
				'evidence'    => array( 'source' => 'chat' ),
				'action'      => $act,
				'force'       => 'create_redirect' === $action,
			)
		);
		if ( ! $rid ) {
			return array( 'kind' => 'change', 'ok' => false, 'title' => $p['title'], 'note' => __( 'Not added.', 'seolity' ), 'route' => '', 'id' => 0 );
		}
		// Prepare it now: the result waits in the floating popup for "Review & apply".
		$started = $this->plugin->agent->start( (int) $rid, isset( $p['instructions'] ) && $p['instructions'] ? (string) $p['instructions'] : (string) $p['detail'] );
		return array(
			'kind'  => 'change',
			'ok'    => ! is_wp_error( $started ),
			'title' => $p['title'],
			'note'  => is_wp_error( $started ) ? $started->get_error_message() : __( 'Being prepared — it will appear in the floating popup for “Review & apply”. Nothing changes until you approve.', 'seolity' ),
			'route' => '#/actions?issue=' . (int) $rid,
			'id'    => (int) $rid,
		);
	}

	private function follow_up( array $p, $conversation ) {
		$urls = isset( $p['urls'] ) ? (array) $p['urls'] : array();
		if ( ! $urls && ! empty( $p['url'] ) ) {
			$urls = array( $p['url'] );
		}
		foreach ( $this->plugin->followups->all() as $f ) {
			if ( 'waiting' === $f['status'] && $f['title'] === $p['title'] ) {
				return array( 'kind' => 'follow_up', 'ok' => true, 'title' => $p['title'], 'note' => sprintf( /* translators: %s: date */ __( 'Already scheduled for %s.', 'seolity' ), $f['check_at'] ), 'route' => '#/alerts?tab=follow', 'id' => 0 );
			}
		}
		$f = $this->plugin->followups->add(
			array(
				'title'   => (string) $p['title'],
				'goal'    => (string) $p['detail'],
				'urls'    => $urls,
				'queries' => isset( $p['queries'] ) ? (array) $p['queries'] : array(),
				'days'    => isset( $p['check_after_days'] ) ? (int) $p['check_after_days'] : 28,
				'source'  => 'chat',
				'ref'     => array( 'conversation' => (string) $conversation ),
			)
		);
		/* translators: %s: date */
		return array( 'kind' => 'follow_up', 'ok' => true, 'title' => $p['title'], 'note' => sprintf( __( 'SEOlity checks the result on %s and sends you a notification.', 'seolity' ), $f['check_at'] ), 'route' => '#/alerts?tab=follow', 'id' => 0 );
	}
}
