/**
 * SEO roadmap — the "What should I do today?" page.
 *
 * Nothing is analyzed until the owner presses "Analyze". Then SEOlity reads Search
 * Console, the audit, content and competitors, studies the SEO knowledge base and
 * writes a stable, versioned roadmap. Every task opens a guide with the affected pages,
 * one-click AI actions and exact dashboard steps.
 */
( function ( w, d ) {
	'use strict';
	var SL = w.SL;
	var App = SL.app;
	var t = SL.t;
	var h = SL.h;

	var PHASES = [
		[ 'now', function () {
			return t( 'This week' );
		}, 'zap' ],
		[ 'week', function () {
			return t( 'Next 2 weeks' );
		}, 'calendar' ],
		[ 'month', function () {
			return t( 'This month' );
		}, 'layers' ],
		[ 'later', function () {
			return t( 'Later' );
		}, 'clock' ],
	];
	var CATS = { indexing: [ 'search', t( 'Indexing' ) ], technical: [ 'wrench', t( 'Technical' ) ], content: [ 'content', t( 'Content' ) ], ctr: [ 'target', t( 'CTR' ) ], 'on-page': [ 'type', t( 'On-page' ) ], links: [ 'link', t( 'Links' ) ], products: [ 'bag', t( 'Products' ) ], images: [ 'image', t( 'Images' ) ], schema: [ 'braces', t( 'Schema' ) ], competitors: [ 'swords', t( 'Competitors' ) ], strategy: [ 'sparkles', t( 'Strategy' ) ] };
	var STAGES = [
		[ 'queued', t( 'Waiting to start…' ) ],
		[ 'recommendations', t( 'Reading Search Console and audit data' ) ],
		[ 'diagnose', t( 'Diagnosing every issue on the site' ) ],
		[ 'facts', t( 'Collecting new content, competitors and trends' ) ],
		[ 'ai', t( 'Studying the SEO knowledge base and writing the roadmap' ) ],
	];
	var PRIO = { critical: t( 'Critical' ), high: t( 'High' ), medium: t( 'Medium' ), low: t( 'Low' ) };

	function cat( c ) {
		var x = CATS[ c ] || [ 'info', c || t( 'Other' ) ];
		return h( 'span', { class: 'sl-rm-cat' }, SL.icon( x[ 0 ], 13 ), h( 'span', { text: x[ 1 ] } ) );
	}

	/*
	 * The SEO roadmap as ONE path ("treasure map"): start = data & analysis, then one station per
	 * area in the order it has to be done (canonical issues + the AI's strategic tasks of that area),
	 * the goal = position 1 on Google for the target queries. Done stations glow, the current one
	 * pulses with a "you are here" pin; click a station to open its tasks below the map.
	 */
	var AI_CAT = { indexing: 'indexability', indexability: 'indexability', technical: 'technical', schema: 'technical', '404': '404', duplicates: 'duplicates', cannibalization: 'duplicates', ctr: 'onpage', 'on-page': 'onpage', onpage: 'onpage', images: 'onpage', links: 'links', content: 'content', strategy: 'content', products: 'products', offpage: 'offpage', backlinks: 'offpage', competitors: 'competitors' };
	var ST_ICON = { indexability: 'search', technical: 'wrench', '404': 'unlink', duplicates: 'copy', onpage: 'type', links: 'link', content: 'content', products: 'bag', offpage: 'globe', competitors: 'swords', other: 'layers' };

	App.pages.actions = function ( v ) {
		v.appendChild( App.header( t( 'Road to position 1' ), t( 'One path from where your site is today to position 1 on Google. Every station is one area, in the order it should be done; click a station to see its tasks. Each task can be done by the AI (with a preview and your approval) or by hand, and is ticked only after the result was verified on the site.' ) ) );
		var hero = h( 'div', { class: 'sl-stack' } );
		var map = h( 'div', { class: 'sl-quest', role: 'list' } );
		var panel = h( 'div', { class: 'sl-stack sl-quest-panel', id: 'sl-quest-panel' } );
		v.appendChild( hero );
		v.appendChild( map );
		v.appendChild( panel );
		var timer = null;
		var data = { board: null, rm: null };
		var openId = null;

		function load() {
			clearTimeout( timer );
			Promise.all( [ SL.api( 'issues/board' ), SL.api( 'roadmap' ) ] ).then( function ( r ) {
				if ( ! d.body.contains( map ) ) {
					return;
				}
				data.board = r[ 0 ];
				data.rm = r[ 1 ];
				render();
				var busy = ( r[ 0 ].analysis && 'running' === r[ 0 ].analysis.status ) || ( r[ 1 ].running && 'running' === r[ 1 ].running.status );
				if ( busy ) {
					timer = setTimeout( load, 4000 );
				}
			} ).catch( SL.fail );
		}

		function aiItems( catId ) {
			var rm = data.rm && data.rm.roadmap;
			return rm ? rm.items.filter( function ( it ) {
				return ( AI_CAT[ it.category ] || 'other' ) === catId && 'dismissed' !== it.status;
			} ) : [];
		}

		function stations() {
			var b = data.board || {};
			var list = [ { id: 'start', label: t( 'Data & analysis' ), icon: 'pulse', kind: 'start' } ];
			( b.categories || [] ).forEach( function ( c ) {
				var ai = aiItems( c.id );
				var aiOpen = ai.filter( function ( x ) {
					return 'done' !== x.status;
				} ).length;
				list.push( { id: c.id, label: c.label, icon: ST_ICON[ c.id ] || 'layers', open: c.open || 0, aiOpen: aiOpen, aiTotal: ai.length, reason: c.reason, route: c.route, kind: 'area' } );
			} );
			list.push( { id: 'goal', label: t( 'Position 1 on Google' ), icon: 'sparkles', kind: 'goal' } );
			var current = null;
			list.forEach( function ( s ) {
				if ( 'area' === s.kind ) {
					s.done = ! s.open && ! s.aiOpen;
					if ( ! s.done && ! current ) {
						current = s;
					}
				}
			} );
			list[ 0 ].done = !! ( b.analysis && b.analysis.finished_at );
			if ( ! list[ 0 ].done ) {
				current = list[ 0 ];
			}
			var last = list[ list.length - 1 ];
			last.done = ! current;
			( current || last ).current = true;
			return list;
		}

		function render() {
			var list = stations();
			if ( null === openId ) {
				openId = list.filter( function ( s ) {
					return s.current;
				} )[ 0 ].id;
			}
			renderHero( list );
			renderMap( list );
			renderPanel( list.filter( function ( s ) {
				return s.id === openId;
			} )[ 0 ] || list[ 0 ] );
		}

		function renderHero( list ) {
			SL.clear( hero );
			var rm = data.rm && data.rm.roadmap;
			var s = rm ? rm.summary || {} : {};
			var areas = list.filter( function ( x ) {
				return 'area' === x.kind;
			} );
			var clear = areas.filter( function ( x ) {
				return x.done;
			} );
			var pct = areas.length ? Math.round( 100 * clear.length / areas.length ) : 0;
			var wins = ( s.wins && s.wins.length ? s.wins : s.strengths || [] ).slice( 0, 6 );
			clear.slice( 0, 3 ).forEach( function ( x ) {
				wins.push( t( '%s: all clear', x.label ) );
			} );
			var mission = s.mission || {};
			hero.appendChild( h( 'div', { class: 'sl-quest-hero' },
				h( 'div', { class: 'sl-quest-hero__ring', style: '--p:' + pct }, h( 'b', { text: SL.num( pct, 0 ) + '%' } ), h( 'small', { text: t( 'of the way' ) } ) ),
				h( 'div', { class: 'sl-quest-hero__text' },
					h( 'h2', { dir: 'auto', text: mission.goal || t( 'Mission: position 1 on Google for your most valuable searches' ) } ),
					h( 'p', { class: 'sl-muted', text: t( '%1$s of %2$s stations cleared — keep going, every station brings you closer.', clear.length, areas.length ) } ),
					wins.length ? h( 'div', { class: 'sl-quest-wins' }, wins.map( function ( w ) {
						return h( 'span', { class: 'sl-quest-win', dir: 'auto' }, SL.icon( 'check-circle', 14 ), h( 'span', { text: w } ) );
					} ) ) : null
				)
			) );
			if ( s.diagnosis ) {
				hero.appendChild( h( 'details', { class: 'sl-quest-guide' },
					h( 'summary', null, SL.icon( 'brain', 16 ), h( 'span', { text: t( 'What the AI guide says' ) } ) ),
					h( 'div', { class: 'sl-stack' },
						h( 'div', { class: 'sl-md', dir: 'auto' }, SL.markdown( s.diagnosis ) ),
						( s.focus || [] ).length ? h( 'div', null, h( 'b', { text: t( 'Focus this week' ) } ), h( 'ol', null, s.focus.map( function ( x ) {
							return h( 'li', { dir: 'auto', text: x } );
						} ) ) ) : null,
						( s.risks || [] ).length ? h( 'div', null, h( 'b', { text: t( 'Risks' ) } ), h( 'ul', null, s.risks.map( function ( x ) {
							return h( 'li', { dir: 'auto', text: x } );
						} ) ) ) : null,
						s.changes_note ? h( 'p', { class: 'sl-muted', dir: 'auto', text: s.changes_note } ) : null
					)
				) );
			}
		}

		function renderMap( list ) {
			SL.clear( map );
			var rtl = SL.config.rtl;
			list.forEach( function ( s, i ) {
				var side = i % 2 ? 'b' : 'a';
				var sub = 'start' === s.kind ? ( s.done ? t( 'Analysed' ) : t( 'Run the full analysis' ) ) : ( 'goal' === s.kind ? ( s.done ? t( 'Reached — now defend it' ) : t( 'The treasure' ) ) : ( s.done ? t( 'All clear' ) : t( '%s to do', SL.num( s.open + s.aiOpen, 0 ) ) ) );
				var node = h( 'button', { type: 'button', role: 'listitem', class: 'sl-quest__node sl-quest__node--' + s.kind + ( s.done ? ' is-done' : '' ) + ( s.current ? ' is-current' : '' ) + ( s.id === openId ? ' is-open' : '' ), 'aria-current': s.current ? 'step' : null, onClick: function () {
					openId = s.id;
					render();
					var p = d.getElementById( 'sl-quest-panel' );
					if ( p && p.scrollIntoView ) {
						p.scrollIntoView( { behavior: 'smooth', block: 'start' } );
					}
				} },
					h( 'span', { class: 'sl-quest__dot' }, s.done && 'goal' !== s.kind ? SL.icon( 'check', 22 ) : SL.icon( s.icon, 22 ), s.current ? h( 'span', { class: 'sl-quest__pin', 'aria-hidden': 'true' }, SL.icon( 'map', 14 ) ) : null ),
					h( 'span', { class: 'sl-quest__label' }, h( 'b', { text: ( 'area' === s.kind ? SL.num( i, 0 ) + '. ' : '' ) + s.label } ), h( 'small', { text: sub } ) )
				);
				map.appendChild( h( 'div', { class: 'sl-quest__row sl-quest__row--' + side }, node ) );
				if ( i < list.length - 1 ) {
					var from = 'a' === side ? ( rtl ? 80 : 20 ) : ( rtl ? 20 : 80 );
					var to = 100 - from;
					var svg = d.createElementNS( 'http://www.w3.org/2000/svg', 'svg' );
					svg.setAttribute( 'viewBox', '0 0 100 40' );
					svg.setAttribute( 'preserveAspectRatio', 'none' );
					svg.setAttribute( 'class', 'sl-quest__road' + ( s.done ? ' is-done' : '' ) );
					svg.setAttribute( 'aria-hidden', 'true' );
					var path = d.createElementNS( 'http://www.w3.org/2000/svg', 'path' );
					path.setAttribute( 'd', 'M ' + from + ' 0 C ' + from + ' 24, ' + to + ' 16, ' + to + ' 40' );
					path.setAttribute( 'vector-effect', 'non-scaling-stroke' );
					path.style.animationDelay = ( i * 0.12 ) + 's';
					svg.appendChild( path );
					map.appendChild( svg );
				}
			} );
			fitRoads();
		}

		/* Join every road exactly to the centres of the two station circles (layout depends on width). */
		function fitRoads() {
			w.requestAnimationFrame( function () {
				var box = map.getBoundingClientRect();
				var dots = map.querySelectorAll( '.sl-quest__dot' );
				var roads = map.querySelectorAll( '.sl-quest__road path' );
				if ( ! box.width ) {
					return;
				}
				Array.prototype.forEach.call( roads, function ( path, i ) {
					if ( ! dots[ i ] || ! dots[ i + 1 ] ) {
						return;
					}
					var a = dots[ i ].getBoundingClientRect();
					var b = dots[ i + 1 ].getBoundingClientRect();
					var x1 = ( 100 * ( a.left + a.width / 2 - box.left ) / box.width ).toFixed( 1 );
					var x2 = ( 100 * ( b.left + b.width / 2 - box.left ) / box.width ).toFixed( 1 );
					path.setAttribute( 'd', 'M ' + x1 + ' 0 C ' + x1 + ' 24, ' + x2 + ' 16, ' + x2 + ' 40' );
				} );
			} );
		}
		var resizeT;
		w.addEventListener( 'resize', function () {
			clearTimeout( resizeT );
			resizeT = setTimeout( function () {
				if ( d.body.contains( map ) ) {
					fitRoads();
				}
			}, 150 );
		} );

		function renderPanel( s ) {
			SL.clear( panel );
			if ( 'start' === s.kind ) {
				panel.appendChild( startPanel() );
				return;
			}
			if ( 'goal' === s.kind ) {
				panel.appendChild( goalPanel() );
				return;
			}
			var box = h( 'div', { class: 'sl-stack' } );
			panel.appendChild( App.card( s.label, box, { icon: s.icon, sub: s.reason || '', actions: s.route ? h( 'a', { class: 'sl-btn sl-btn--sm', href: s.route, text: t( 'Open the section' ) } ) : null } ) );
			if ( s.done ) {
				box.appendChild( h( 'div', { class: 'sl-alert sl-alert--success', text: t( 'Everything in this station is done and verified. Great work — on to the next station.' ) } ) );
			}
			var list = h( 'div', { class: 'sl-stack' } );
			box.appendChild( list );
			App.load( list, SL.api( 'issues/canonical', { query: { category: s.id, status: 'active', page: 1, per_page: 50 } } ), function ( r ) {
				( r.items || [] ).forEach( function ( it ) {
					list.appendChild( issueCard( it, load ) );
				} );
				var ai = aiItems( s.id );
				if ( ai.length ) {
					list.appendChild( h( 'h4', { class: 'sl-rm-phase' }, SL.icon( 'brain', 16 ), h( 'span', { text: t( 'The AI’s strategy for this station' ) } ) ) );
					ai.forEach( function ( it ) {
						list.appendChild( aiRow( it ) );
					} );
				}
				if ( ! list.children.length ) {
					list.appendChild( SL.empty( t( 'Nothing to do here' ), t( 'This area is clear.' ) ) );
				}
			} );
		}

		function startPanel() {
			var b = data.board || {};
			var a = b.analysis;
			var rmState = data.rm || {};
			var running = a && 'running' === a.status;
			var box = h( 'div', { class: 'sl-stack' },
				h( 'p', { text: t( 'The journey starts with fresh data: SEOlity crawls the site, reads Search Console, finds every problem and the AI writes the plan. Run it again whenever you have made changes.' ) } ),
				h( 'div', { class: 'sl-row' },
					App.cap( 'manage' ) ? App.abtn( SL.il( 'pulse', running ? t( 'Analysis running…' ) : t( 'Run full-site analysis' ), 15 ), function () {
						return SL.api( 'analysis/full', { method: 'POST' } ).then( load );
					}, 'primary', { disabled: running } ) : null,
					App.cap( 'ai' ) ? App.abtn( SL.il( 'refresh', t( 'Ask the AI to re-plan' ), 15 ), function () {
						return SL.api( 'roadmap/analyze', { method: 'POST' } ).then( load );
					} ) : null
				),
				a && a.finished_at ? h( 'p', { class: 'sl-muted sl-small', text: t( 'Last full analysis: %s', SL.date( a.finished_at ) ) } ) : null
			);
			if ( a && a.stages ) {
				box.appendChild( h( 'ol', { class: 'sl-rm-stages' }, Object.keys( a.stages ).map( function ( k ) {
					var x = a.stages[ k ];
					return h( 'li', { class: 'is-' + x.status }, h( 'span', { class: 'sl-rm-stages__ic', text: STAGE_ICON[ x.status ] || '○' } ), h( 'b', { text: x.label } ), x.detail ? h( 'span', { class: 'sl-muted', text: ' — ' + x.detail } ) : null );
				} ) ) );
			}
			if ( rmState.running && 'failed' === rmState.running.status ) {
				box.appendChild( h( 'div', { class: 'sl-alert sl-alert--error', text: t( 'The last analysis failed:' ) + ' ' + ( rmState.running.error || '' ) } ) );
			}
			if ( rmState.roadmap && rmState.roadmap.meta && rmState.roadmap.meta.ai_error ) {
				box.appendChild( h( 'div', { class: 'sl-alert sl-alert--warn', text: rmState.roadmap.meta.ai_error } ) );
			}
			return App.card( t( 'Data & analysis' ), box, { icon: 'pulse' } );
		}

		function goalPanel() {
			var rm = data.rm && data.rm.roadmap;
			var mission = rm && rm.summary && rm.summary.mission ? rm.summary.mission : {};
			var q = mission.target_queries || [];
			var box = h( 'div', { class: 'sl-stack' }, h( 'p', { dir: 'auto', text: mission.goal || t( 'The target: position 1 on Google for the searches that bring customers. The AI picks them from Search Console and checks who ranks above you.' ) } ) );
			if ( q.length ) {
				box.appendChild( App.table( [ { label: t( 'Search' ), render: function ( x ) {
					return h( 'b', { dir: 'auto', text: x.query } );
				} }, { label: t( 'Now' ), render: function ( x ) {
					return x.position ? SL.num( x.position, 1 ) : '—';
				} }, { label: t( 'Who is #1' ), render: function ( x ) {
					return h( 'span', { dir: 'ltr', text: x.leader || '—' } );
				} }, { label: t( 'Gap to close' ), render: function ( x ) {
					return h( 'span', { dir: 'auto', text: x.gap } );
				} }, { label: t( 'Next move' ), render: function ( x ) {
					return h( 'span', { dir: 'auto', text: x.next_move } );
				} } ], q ) );
			} else {
				box.appendChild( SL.empty( t( 'No target searches yet' ), t( 'Run the full analysis — the AI then chooses the searches to win.' ) ) );
			}
			box.appendChild( h( 'p', { class: 'sl-muted sl-small' }, t( 'Competitor details: ' ), h( 'a', { href: '#/competitors', text: t( 'Competitors' ) } ) ) );
			return App.card( t( 'Position 1 on Google' ), box, { icon: 'sparkles' } );
		}

		function aiRow( it ) {
			var cb = h( 'input', { type: 'checkbox', checked: 'done' === it.status, 'aria-label': t( 'Mark as done' ), onChange: function ( e ) {
				SL.api( 'roadmap/task', { body: { key: it.key, status: e.target.checked ? 'done' : 'todo' } } ).then( load ).catch( SL.fail );
			} } );
			return h( 'div', { class: 'sl-rm-item sl-rm-item--' + it.priority + ( 'done' === it.status ? ' is-done' : '' ) },
				App.cap( 'apply' ) ? h( 'label', { class: 'sl-rm-item__check' }, cb ) : null,
				h( 'button', { type: 'button', class: 'sl-rm-item__main', onClick: function () {
					guide( it, load );
				} },
					h( 'span', { class: 'sl-rm-item__title' }, SL.dot( it.priority ), h( 'b', { text: it.title } ) ),
					h( 'span', { class: 'sl-rm-item__meta' }, SL.priorityBadge( it.priority ), it.count ? SL.badge( t( '%s pages', it.count ) ) : null, SL.badge( t( 'AI strategy' ), 'brand' ) ),
					h( 'span', { class: 'sl-rm-item__why', text: it.analysis || it.why } )
				),
				h( 'div', { class: 'sl-rm-item__go' }, App.btn( h( 'span', { class: 'sl-row' }, SL.icon( 'book', 15 ), h( 'span', { text: t( 'Guide' ) } ) ), function () {
					guide( it, load );
				}, 'sm' ) )
			);
		}

		load();
	};

	/* ------------------------------------------------------------------ Task guide popup */
	function targetActions( it, tg, refresh ) {
		var acts = [];
		if ( tg.edit ) {
			acts.push( h( 'a', { class: 'sl-btn sl-btn--sm', href: tg.edit, target: '_blank', rel: 'noopener' }, SL.icon( 'edit', 14 ), ' ', t( 'Edit' ) ) );
		}
		if ( tg.url ) {
			acts.push( h( 'a', { class: 'sl-btn sl-btn--sm sl-btn--ghost', href: tg.url, target: '_blank', rel: 'noopener', title: t( 'View' ), 'aria-label': t( 'View' ) }, SL.icon( 'external', 14 ) ) );
		}
		if ( tg.inspect ) {
			acts.push( h( 'a', { class: 'sl-btn sl-btn--sm', href: tg.inspect, target: '_blank', rel: 'noopener' }, SL.icon( 'search', 14 ), ' ', t( 'Inspect in Search Console' ) ) );
		}
		var actions = ( it.guide || [] ).map( function ( s ) {
			return s.action;
		} );
		if ( App.cap( 'ai' ) && actions.indexOf( 'meta_targets' ) !== -1 && tg.object_id ) {
			acts.push( App.btn( h( 'span', { class: 'sl-row' }, SL.icon( 'sparkles', 14 ), h( 'span', { text: t( 'AI title & description' ) } ) ), function () {
				SL.metaModal( tg.object_type, tg.object_id, refresh, tg.label );
			}, 'primary sl-btn--sm' ) );
		} else if ( App.cap( 'ai' ) && actions.indexOf( 'ai_targets' ) !== -1 && tg.rec_id ) {
			acts.push( App.btn( h( 'span', { class: 'sl-row' }, SL.icon( 'sparkles', 14 ), h( 'span', { text: t( 'Do it with AI' ) } ) ), function () {
				App.agentPopup( tg.rec_id, refresh );
			}, 'primary sl-btn--sm' ) );
		} else if ( App.cap( 'ai' ) && actions.indexOf( 'ai_targets' ) !== -1 && tg.object_type === 'post' && tg.object_id ) {
			acts.push( App.btn( h( 'span', { class: 'sl-row' }, SL.icon( 'sparkles', 14 ), h( 'span', { text: t( 'Optimize with AI' ) } ) ), function () {
				SL.optimizer( { object_type: 'post', object_id: tg.object_id, field: 'post_content', label: tg.label } );
			}, 'primary sl-btn--sm' ) );
		}
		return acts;
	}

	function guide( it, refresh ) {
		var m = SL.modal( { title: it.title, wide: true } );
		var b = m.body;
		b.classList.add( 'sl-rm-guide' );
		b.appendChild( h( 'div', { class: 'sl-row' }, cat( it.category ), SL.priorityBadge( it.priority ), SL.badge( t( 'Impact' ) + ' ' + it.impact ), SL.badge( t( 'Effort' ) + ' ' + it.effort ), it.change_reason ? SL.badge( t( 'Changed' ), 'warn' ) : null ) );
		b.appendChild( h( 'section', null, h( 'h3', null, SL.icon( 'info', 17 ), h( 'span', { text: t( 'Why this matters' ) } ) ), h( 'p', { text: it.why } ), it.analysis && it.analysis !== it.why ? h( 'p', { class: 'sl-rm-ai' }, SL.icon( 'brain', 15 ), ' ', it.analysis ) : null, it.expected_result ? h( 'p', null, h( 'b', { text: t( 'Expected result:' ) + ' ' } ), it.expected_result ) : null, it.change_reason ? h( 'p', { class: 'sl-muted', text: t( 'Changed since the last analysis:' ) + ' ' + it.change_reason } ) : null ) );

		if ( it.targets && it.targets.length ) {
			var rows = it.targets.map( function ( tg ) {
				return h( 'tr', null,
					h( 'td', null, h( 'b', { dir: 'auto', text: tg.label || tg.url } ), tg.metric ? h( 'div', { class: 'sl-muted sl-small', dir: 'auto', text: tg.metric } ) : null, tg.problems && tg.problems.length ? h( 'div', { class: 'sl-rm-problems' }, tg.problems.map( function ( p ) {
						return SL.badge( p, 'warn' );
					} ) ) : null ),
					h( 'td', null, h( 'div', { class: 'sl-row' }, targetActions( it, tg, refresh ) ) )
				);
			} );
			b.appendChild( h( 'section', null, h( 'h3', null, SL.icon( 'list', 17 ), h( 'span', { text: t( 'Affected pages (%s)', it.targets.length ) } ) ), h( 'div', { class: 'sl-table-wrap' }, h( 'table', { class: 'sl-table' }, h( 'tbody', null, rows ) ) ) ) );
		}
		if ( it.plan && it.plan.length ) {
			b.appendChild( h( 'section', null, h( 'h3', null, SL.icon( 'brain', 17 ), h( 'span', { text: t( 'Plan for your site (AI)' ) } ) ), steps( it.plan ) ) );
		}
		if ( it.guide && it.guide.length ) {
			b.appendChild( h( 'section', null, h( 'h3', null, SL.icon( 'map', 17 ), h( 'span', { text: t( 'Step by step in your dashboard' ) } ) ), steps( it.guide ) ) );
		}
		if ( it.done_when ) {
			b.appendChild( h( 'div', { class: 'sl-alert sl-alert--success' }, SL.icon( 'check-circle', 16 ), ' ', h( 'b', { text: t( 'Done when:' ) + ' ' } ), it.done_when ) );
		}
		if ( App.cap( 'apply' ) ) {
			var set = function ( status ) {
				return SL.api( 'roadmap/task', { body: { key: it.key, status: status } } ).then( function () {
					m.close();
					refresh();
				} ).catch( SL.fail );
			};
			m.foot.appendChild( App.btn( t( 'Not relevant' ), function () {
				set( 'dismissed' );
			}, 'ghost' ) );
			m.foot.appendChild( App.btn( t( 'Working on it' ), function () {
				set( 'doing' );
			} ) );
			m.foot.appendChild( App.btn( h( 'span', { class: 'sl-row' }, SL.icon( 'check', 15 ), h( 'span', { text: t( 'Mark as done' ) } ) ), function () {
				set( 'done' );
			}, 'primary' ) );
		}
	}

	function steps( list ) {
		return h( 'ol', { class: 'sl-rm-steps' }, list.map( function ( s, i ) {
			return h( 'li', null,
				h( 'span', { class: 'sl-rm-steps__n', text: String( i + 1 ) } ),
				h( 'div', null,
					h( 'b', { text: s.title } ),
					s.detail ? h( 'p', { text: s.detail } ) : null,
					s.where || s.link ? h( 'div', { class: 'sl-row' }, s.where ? h( 'span', { class: 'sl-rm-where' }, SL.icon( 'map', 13 ), h( 'span', { text: s.where } ) ) : null, s.link ? h( 'a', { class: 'sl-btn sl-btn--sm', href: s.link, target: s.link.indexOf( 'http' ) === 0 && s.link.indexOf( location.host ) === -1 ? '_blank' : null, rel: 'noopener' }, t( 'Open' ), ' ', SL.icon( 'arrow-' + ( SL.config.rtl ? 'left' : 'right' ), 13 ) ) : null ) : null
				)
			);
		} ) );
	}

	/* ---------------------------------------------- canonical issue roadmap */
	var ST = {
		open: [ t( 'To do' ), '' ],
		in_progress: [ t( 'In progress' ), 'brand' ],
		done: [ t( 'Done' ), 'good' ],
		resolved: [ t( 'Resolved' ), 'good' ],
		dismissed: [ t( 'Dismissed' ), '' ],
	};
	var PRIO_CLS = { critical: 'bad', high: 'warn', medium: 'brand', low: '' };
	var STAGE_ICON = { pending: '○', running: '…', done: '✓', skipped: '–', failed: '✗' };

	function doneBadge( it ) {
		if ( 'done' !== it.status && 'resolved' !== it.status ) {
			var s = ST[ it.status ] || [ it.status, '' ];
			return SL.badge( s[ 0 ], s[ 1 ] );
		}
		var v = it.verification || {};
		if ( it.verified_at ) {
			return SL.badge( '✓ ' + t( 'Done & verified' ), 'good' );
		}
		if ( v.manual ) {
			return SL.badge( t( 'Marked done by you (not verified)' ), '' );
		}
		return SL.badge( t( 'Gone after the last check' ), 'good' );
	}

	function canAi( it ) {
		var a = it.action || {};
		return App.cap( 'ai' ) && !! it.auto_fixable && 'route' !== a.type;
	}

	/** One canonical issue (roadmap item with AI fix / manual guide / state). */
	function issueCard( it, refresh ) {
		var row = h( 'div', { class: 'sl-card sl-rm-item is-' + it.status } );
		row.appendChild( h( 'div', { class: 'sl-row' }, doneBadge( it ), it.priority ? SL.badge( PRIO[ it.priority ] || it.priority, PRIO_CLS[ it.priority ] || '' ) : null, h( 'b', { dir: 'auto', class: 'sl-rm-item__title', text: it.title } ) ) );
		if ( it.url ) {
			row.appendChild( h( 'a', { href: it.url, target: '_blank', rel: 'noopener', dir: 'ltr', class: 'sl-small', text: decodeURI( it.url ) } ) );
		}
		if ( it.reason ) {
			row.appendChild( h( 'p', { class: 'sl-small' }, h( 'b', { text: t( 'AI recommendation:' ) + ' ' } ), h( 'span', { dir: 'auto', text: it.reason } ) ) );
		}
		if ( it.verification && it.verification.lines ) {
			row.appendChild( h( 'details', { class: 'sl-small' }, h( 'summary', { text: t( 'Result and verification' ) } ), h( 'ul', {}, it.verification.lines.map( function ( l ) {
				return h( 'li', { dir: 'auto', text: l } );
			} ) ) ) );
		}
		var acts = h( 'div', { class: 'sl-row' } );
		var active = 'open' === it.status || 'in_progress' === it.status;
		if ( active && canAi( it ) ) {
			acts.appendChild( App.abtn( SL.il( 'wand', t( 'Do it with AI' ), 14 ), function () {
				App.agentPopup( it.id, refresh );
				return Promise.resolve();
			}, 'primary' ) );
		}
		if ( it.operation ) {
			acts.appendChild( App.abtn( t( 'View the change' ), function () {
				return SL.api( 'operations/' + it.operation.id ).then( function ( op ) {
					var m = SL.modal( { title: op.title, wide: true, onClose: refresh } );
					var b = h( 'div' );
					m.body.appendChild( b );
					App.operationView( b, op, refresh );
				} );
			} ) );
		}
		var a = it.action || {};
		if ( a.route ) {
			acts.appendChild( h( 'a', { class: 'sl-btn sl-btn--sm', href: a.route, text: t( 'Open in its section' ) } ) );
		}
		if ( it.edit ) {
			acts.appendChild( h( 'a', { class: 'sl-btn sl-btn--ghost sl-btn--sm', href: it.edit, text: t( 'Edit manually' ) } ) );
		}
		if ( App.cap( 'apply' ) ) {
			if ( active ) {
				acts.appendChild( App.abtn( t( 'Mark as done' ), function () {
					return SL.api( 'issues/' + it.id + '/state', { body: { status: 'done' } } ).then( refresh );
				}, 'ghost' ) );
				acts.appendChild( App.abtn( t( 'Dismiss' ), function () {
					return SL.api( 'issues/' + it.id + '/state', { body: { status: 'dismissed' } } ).then( refresh );
				}, 'ghost' ) );
			} else {
				acts.appendChild( App.abtn( t( 'Reopen' ), function () {
					return SL.api( 'issues/' + it.id + '/state', { body: { status: 'open' } } ).then( refresh );
				}, 'ghost' ) );
			}
		}
		row.appendChild( acts );
		return row;
	}

	App.roadmapOverview = function ( ov ) {
		var f = { category: '', status: 'active', page: 1 };
		var timer;
		var anBox = h( 'div' );
		var areaBox = h( 'div' );
		var catBox = h( 'div', { class: 'sl-rm-cats' } );
		var listBox = h( 'div', { class: 'sl-stack' } );
		ov.appendChild( anBox );
		ov.appendChild( areaBox );
		ov.appendChild( catBox );
		ov.appendChild( listBox );

		function analysis( a ) {
			SL.clear( anBox );
			var running = a && 'running' === a.status;
			var btn = App.cap( 'manage' ) ? App.abtn( SL.il( 'pulse', running ? t( 'Analysis running…' ) : t( 'Run full-site analysis' ), 15 ), function () {
				return SL.api( 'analysis/full', { method: 'POST' } ).then( loadBoard );
			}, 'primary', { disabled: running } ) : null;
			var info = a && a.finished_at ? t( 'Last full analysis: %s', SL.date( a.finished_at ) ) : t( 'Crawl, Search Console, issues, indexability, duplicates, 404 targets and the AI plan — all sections updated in one run.' );
			var card = h( 'div', { class: 'sl-card sl-rm-an' }, h( 'div', { class: 'sl-row' }, btn, h( 'span', { class: 'sl-muted sl-small', text: info } ) ) );
			if ( a && a.stages ) {
				card.appendChild( h( 'ol', { class: 'sl-rm-stages' }, Object.keys( a.stages ).map( function ( k ) {
					var x = a.stages[ k ];
					return h( 'li', { class: 'is-' + x.status }, h( 'span', { class: 'sl-rm-stages__ic', text: STAGE_ICON[ x.status ] || '○' } ), h( 'b', { text: x.label } ), x.detail ? h( 'span', { class: 'sl-muted', text: ' — ' + x.detail } ) : null );
				} ) ) );
			}
			anBox.appendChild( card );
		}

		function areas( list ) {
			SL.clear( areaBox );
			var shown = list.filter( function ( a ) {
				return a.score > 0;
			} );
			if ( ! shown.length ) {
				return;
			}
			var body = h( 'div', { class: 'sl-areas' } );
			shown.forEach( function ( a, i ) {
				body.appendChild( h( 'a', { class: 'sl-area', href: a.route },
					h( 'div', { class: 'sl-area__head' }, h( 'span', { class: 'sl-area__rank', text: SL.num( i + 1, 0 ) } ), h( 'b', { text: a.label } ), h( 'span', { class: 'sl-area__pct', text: SL.num( a.share, 0 ) + '%' } ) ),
					h( 'div', { class: 'sl-area__bar' }, h( 'span', { style: 'width:' + Math.max( 2, a.share ) + '%' } ) ),
					h( 'div', { class: 'sl-muted sl-small', text: t( '%1$s problems · %2$s critical · %3$s URLs affected', SL.num( a.issues, 0 ), SL.num( a.critical, 0 ), SL.num( a.affected_urls, 0 ) ) } ),
					a.top && a.top.length ? h( 'div', { class: 'sl-small', dir: 'auto', text: a.top.map( function ( x ) {
						return x.title + ' (' + x.count + ')';
					} ).join( ' · ' ) } ) : null,
					a.note ? h( 'div', { class: 'sl-small sl-muted', text: a.note } ) : null
				) );
			} );
			areaBox.appendChild( App.card( t( 'Where the site’s problems are' ), body, { icon: 'layers', sub: t( 'Share of all open problems by area, weighted by severity.' ) } ) );
		}

		function cats( list ) {
			SL.clear( catBox );
			catBox.appendChild( h( 'div', { class: 'sl-rm-catlink' + ( f.category ? '' : ' is-on' ) }, h( 'button', { type: 'button', class: 'sl-rm-catlink__main', onClick: function () {
				f.category = '';
				f.page = 1;
				loadBoard();
			} }, h( 'b', { text: t( 'All' ) } ) ) ) );
			list.forEach( function ( c ) {
				catBox.appendChild( h( 'div', { class: 'sl-rm-catlink' + ( f.category === c.id ? ' is-on' : '' ) + ( c.open ? '' : ' is-clear' ), title: c.reason },
					h( 'button', { type: 'button', class: 'sl-rm-catlink__main', onClick: function () {
						f.category = c.id;
						f.page = 1;
						loadBoard();
					} }, h( 'span', { class: 'sl-rm-catlink__pos', text: c.position ? SL.num( c.position, 0 ) : '✓' } ), h( 'b', { text: c.label } ), h( 'span', { class: 'sl-muted sl-small', text: c.open ? t( '%s open', SL.num( c.open, 0 ) ) : t( 'all clear' ) } ) ),
					c.route ? h( 'a', { class: 'sl-rm-catlink__go', href: c.route, title: t( 'Open the section' ), text: '↗' } ) : null
				) );
			} );
			var cur = list.filter( function ( c ) {
				return c.id === f.category;
			} )[ 0 ];
			if ( cur && cur.reason ) {
				catBox.appendChild( h( 'p', { class: 'sl-muted sl-small sl-rm-why', text: ( cur.position ? t( 'Step %s:', cur.position ) + ' ' : '' ) + cur.reason } ) );
			}
		}

		function item( it ) {
			return issueCard( it, loadBoard );
		}

		function list() {
			App.load( listBox, SL.api( 'issues/canonical', { query: f } ), function ( r ) {
				listBox.appendChild( h( 'div', { class: 'sl-toolbar' }, App.select( [ [ 'active', t( 'To do' ) ], [ 'done', t( 'Done' ) ], [ 'dismissed', t( 'Dismissed' ) ], [ 'all', t( 'All' ) ] ], f.status, function ( x ) {
					f.status = x;
					f.page = 1;
					list();
				} ), h( 'span', { class: 'sl-muted sl-small', text: t( '%s items', SL.num( r.total, 0 ) ) } ) ) );
				if ( ! r.items.length ) {
					listBox.appendChild( SL.empty( 'active' === f.status ? t( 'Nothing left to do here' ) : t( 'Nothing here yet' ), '' ) );
					return;
				}
				r.items.forEach( function ( it ) {
					listBox.appendChild( item( it ) );
				} );
				if ( r.total > r.page * r.per_page ) {
					listBox.appendChild( h( 'div', {}, App.abtn( t( 'Show more' ), function () {
						f.per_page = r.per_page * ( r.page + 1 );
						list();
						return Promise.resolve();
					} ) ) );
				}
			} );
		}

		function loadBoard() {
			clearTimeout( timer );
			if ( ! d.body.contains( ov ) ) {
				return;
			}
			SL.api( 'issues/board' ).then( function ( b ) {
				analysis( b.analysis );
				areas( b.areas || [] );
				cats( b.categories );
				list();
				if ( b.analysis && 'running' === b.analysis.status ) {
					timer = setTimeout( loadBoard, 4000 );
				}
			} ).catch( SL.fail );
		}
		loadBoard();
	};
} )( window, document );
