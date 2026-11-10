/**
 * SEOlity admin — Road to #1, one search at a time.
 *
 * Every search the site appears for (real Search Console positions), an honest chance of #1
 * and what #1 would bring; one focused search with the AI's plan against the result that ranks
 * first, steps prepared with one click, and progress / wins shown only from real data.
 */
( function ( w, d ) {
	'use strict';
	var SL = w.SL;
	var App = SL.app;
	var t = SL.t;
	var h = SL.h;

	var CHANCE = {
		won: [ 'Already #1', 'good' ],
		close: [ 'Very close', 'good' ],
		realistic: [ 'Realistic', 'brand' ],
		hard: [ 'Hard', 'warn' ],
		long_shot: [ 'Long shot for now', 'default' ],
	};
	var INTENT = { buy: 'Buying', compare: 'Comparing', learn: 'Learning' };
	var VERDICT = { already_first: 'Already #1', close: 'Very close', realistic: 'Realistic', hard: 'Hard', long_shot: 'Long shot for now' };

	function chanceBadge( c ) {
		var x = CHANCE[ c ] || [ c, 'default' ];
		return SL.badge( t( x[ 0 ] ), x[ 1 ] );
	}

	/** Weekly real positions as a small line (lower = better, so the line rises when you climb). */
	function spark( series ) {
		var pts = ( series || [] ).filter( function ( p ) {
			return p.position !== null && p.position !== undefined;
		} );
		if ( pts.length < 2 ) {
			return null;
		}
		var W = 260;
		var H = 64;
		var max = Math.max.apply( null, pts.map( function ( p ) {
			return +p.position;
		} ) );
		var min = Math.min.apply( null, pts.map( function ( p ) {
			return +p.position;
		} ) );
		var span = Math.max( 1, max - min );
		var xy = pts.map( function ( p, i ) {
			return [ Math.round( i * ( W - 12 ) / ( pts.length - 1 ) ) + 6, Math.round( 8 + ( ( +p.position - min ) / span ) * ( H - 16 ) ) ];
		} );
		var ns = 'http://www.w3.org/2000/svg';
		var svg = d.createElementNS( ns, 'svg' );
		svg.setAttribute( 'viewBox', '0 0 ' + W + ' ' + H );
		svg.setAttribute( 'class', 'sl-r1__spark' );
		svg.setAttribute( 'role', 'img' );
		svg.setAttribute( 'aria-label', t( 'Weekly average position' ) );
		var line = d.createElementNS( ns, 'polyline' );
		line.setAttribute( 'points', xy.map( function ( p ) {
			return p.join( ',' );
		} ).join( ' ' ) );
		svg.appendChild( line );
		xy.forEach( function ( p, i ) {
			var c = d.createElementNS( ns, 'circle' );
			c.setAttribute( 'cx', p[ 0 ] );
			c.setAttribute( 'cy', p[ 1 ] );
			c.setAttribute( 'r', i === xy.length - 1 ? 4 : 2.5 );
			var tt = d.createElementNS( ns, 'title' );
			tt.textContent = SL.date( pts[ i ].week ) + ' · ' + t( 'position %s', pts[ i ].position );
			c.appendChild( tt );
			svg.appendChild( c );
		} );
		return h( 'div', { class: 'sl-r1__sparkbox', dir: 'ltr' }, svg, h( 'div', { class: 'sl-r1__sparklab' }, h( 'span', { text: '#' + Math.round( min ) } ), h( 'span', { text: '#' + Math.round( max ) } ) ) );
	}

	// A finished plan card in the task dock opens this page.
	if ( SL.dock && SL.dock.opener ) {
		SL.dock.opener( 'rank1', function () {
			App.go( '#/rank1' );
		} );
	}

	App.pages.rank1 = function ( v ) {
		v.appendChild( App.header( t( 'Road to #1' ), t( 'One search at a time: where you really rank in Google, an honest chance of position 1, the AI’s plan against the page that ranks first — and real progress, measured in Search Console.' ) ) );
		var body = h( 'div' );
		v.appendChild( body );
		var f = { chance: '', search: '' };
		var polls = 0;
		load();

		function load() {
			App.load( body, SL.api( 'rank1' ), render );
		}

		function render( r ) {
			if ( ! r.connected || ! r.stats ) {
				body.appendChild( SL.empty( t( 'Connect Google Search Console first' ), t( 'Positions here are only real ones from Google itself — connect Search Console and wait for the first data.' ), App.btn( t( 'Connect Search Console' ), function () {
					App.go( '#/gsc' );
				}, 'primary' ) ) );
				return;
			}
			body.appendChild( h( 'p', { class: 'sl-muted sl-small sl-r1__honest' }, SL.icon( 'info', 14 ), ' ', t( 'Positions: Google Search Console, average of %1$s – %2$s (Google reports 2–3 days late). “If #1” is an estimate at a typical #1 click rate and is marked as such. No promises — only measured results.', SL.date( r.period[ 0 ] ), SL.date( r.period[ 1 ] ) ) ) );
			body.appendChild( wins( r.stats ) );
			if ( r.focus ) {
				body.appendChild( focusCard( r.focus, r ) );
			} else {
				body.appendChild( h( 'div', { class: 'sl-alert sl-alert--info' }, t( 'Pick ONE search below and press “Focus on this”. Finish it, then go to the next — that is how #1 is reached, search by search.' ) ) );
			}
			body.appendChild( board( r ) );
		}

		/** What is already won — real numbers only. */
		function wins( s ) {
			var items = [
				[ 'trophy', SL.num( s.won, 0 ), t( 'searches where you are #1' ) ],
				[ 'trending', SL.num( s.top3, 0 ), t( 'in the top 3' ) ],
				[ 'arrow-up', SL.num( s.climbed, 0 ), t( 'climbed at least 1 position this month' ) ],
			];
			return h( 'div', { class: 'sl-r1__wins' }, items.map( function ( x ) {
				return h( 'div', { class: 'sl-r1__win' }, SL.icon( SL.ICONS[ x[ 0 ] ] ? x[ 0 ] : 'check', 18 ), h( 'b', { text: x[ 1 ] } ), h( 'span', { text: x[ 2 ] } ) );
			} ), s.best_climb ? h( 'div', { class: 'sl-r1__win sl-r1__win--best' }, SL.icon( 'rocket', 18 ), h( 'span', { dir: 'auto', text: t( 'Best climb: “%1$s” from %2$s to %3$s', s.best_climb.query, s.best_climb.from, s.best_climb.to ) } ) ) : null );
		}

		function focusCard( fc, r ) {
			var card = h( 'section', { class: 'sl-panel sl-r1__focus' } );
			var now = fc.last7 && fc.last7.position !== null ? fc.last7.position : null;
			card.appendChild( h( 'div', { class: 'sl-r1__fhead' },
				h( 'div', null,
					h( 'small', { class: 'sl-muted', text: t( 'Focused search' ) } ),
					h( 'h3', { class: 'sl-r1__q', dir: 'auto', text: fc.query } ),
					fc.page ? App.ext( fc.page ) : null
				),
				h( 'div', { class: 'sl-r1__pos' },
					h( 'div', null, h( 'small', { text: t( 'At the start' ) } ), h( 'b', { text: fc.start && fc.start.position !== null ? '#' + fc.start.position : '—' } ) ),
					SL.icon( 'arrow-right', 18 ),
					h( 'div', null, h( 'small', { text: t( 'Last 7 days' ) } ), h( 'b', { class: now !== null && fc.start.position !== null && now < fc.start.position ? 'is-up' : '', text: now !== null ? '#' + now : '—' } ) ),
					h( 'div', { class: 'sl-r1__goal' }, h( 'small', { text: t( 'Goal' ) } ), h( 'b', { text: '#1' } ) )
				)
			) );
			card.appendChild( h( 'div', { class: 'sl-r1__news sl-r1__news--' + fc.news.kind }, SL.icon( { won: 'check-circle', up: 'trending', down: 'trending-down', flat: 'pulse', wait: 'clock' }[ fc.news.kind ] || 'info', 18 ), h( 'span', { dir: 'auto', text: fc.news.text } ) ) );
			var sp = spark( fc.series );
			if ( sp ) {
				card.appendChild( sp );
			}
			// Google itself: the last check (live or imported) and how to check again.
			var g = h( 'div', { class: 'sl-r1__google' } );
			if ( fc.serp ) {
				g.appendChild( h( 'p', { class: 'sl-small', dir: 'auto' },
					h( 'b', { text: t( 'Google check' ) + ' (' + SL.date( fc.serp.fetched ) + '): ' } ),
					fc.serp.ours ? t( 'your page is #%s', fc.serp.ours.position ) : t( 'your site is not in the first %s results', fc.serp.count ),
					' · ',
					t( '#1 is %s', fc.serp.leader.domain ),
					' — ',
					h( 'span', { dir: 'auto', text: fc.serp.leader.title } )
				) );
			}
			g.appendChild( h( 'div', { class: 'sl-row' },
				r.serp.automatic ? App.abtn( SL.il( 'search', t( 'Check Google now' ), 15 ), function () {
					return SL.api( 'rank1/check', { body: { query: fc.query } } ).then( function () {
						SL.toast( t( 'Checked in Google.' ), 'good' );
						load();
					} );
				}, 'sm' ) : null,
				h( 'a', { class: 'sl-btn sl-btn--sm', href: fc.google, target: '_blank', rel: 'noopener noreferrer' }, SL.icon( 'external', 14 ), ' ', t( 'Open in Google' ) )
			) );
			g.appendChild( h( 'p', { class: 'sl-muted sl-small', text: r.serp.automatic ? t( 'Search Console is the real average; “Check Google now” shows today’s results from your results source (one search of its quota).' ) : t( 'Open it in a private (incognito) window so your own history does not change the results. To let the AI see the page that ranks first, use the free “your browser” Google source (Competitors page) — one click on its bookmark sends the results to SEOlity.' ) } ) );
			card.appendChild( g );
			card.appendChild( plan( fc ) );
			card.appendChild( h( 'div', { class: 'sl-row sl-r1__finish' },
				App.abtn( SL.il( 'check-circle', t( 'Done — next search' ), 15 ), function () {
					return SL.api( 'rank1/finish', { body: { how: 'done' } } ).then( load );
				}, 'sm' ),
				App.abtn( t( 'Skip this search' ), function () {
					return SL.api( 'rank1/finish', { body: { how: 'skip' } } ).then( load );
				}, 'sm' )
			) );
			return card;
		}

		function plan( fc ) {
			var box = h( 'div', { class: 'sl-r1__plan' } );
			var p = fc.plan && fc.plan.data;
			var ask = App.cap( 'ai' ) ? App.abtn( SL.il( 'wand', p ? t( 'Plan again' ) : t( 'Make the plan with AI' ), 15 ), function () {
				return SL.api( 'rank1/plan', { body: {} } ).then( function () {
					SL.toast( t( 'The AI is comparing your page with the result that ranks first — the plan appears here in a minute or two.' ) );
					polls = 0;
					wait();
				} );
			}, p ? 'sm' : 'primary' ) : null;
			if ( ! p ) {
				box.appendChild( h( 'p', { class: 'sl-muted', text: t( 'No plan yet. The AI compares your page with the page that ranks first, says honestly how realistic #1 is, and gives the few steps that move THIS search.' ) } ) );
				if ( ask ) {
					box.appendChild( ask );
				}
				return box;
			}
			box.appendChild( h( 'div', { class: 'sl-r1__verdict' }, SL.badge( t( VERDICT[ p.verdict ] || p.verdict ), 'long_shot' === p.verdict || 'hard' === p.verdict ? 'warn' : 'good' ), h( 'span', { class: 'sl-muted sl-small', text: p.weeks_max ? t( 'Realistic time: %1$s–%2$s weeks', p.weeks_min, p.weeks_max ) : '' } ), ask ) );
			box.appendChild( h( 'p', { dir: 'auto', text: p.honest_assessment } ) );
			if ( p.encouragement ) {
				box.appendChild( h( 'p', { class: 'sl-r1__cheer', dir: 'auto' }, SL.icon( 'sparkles', 15 ), ' ', p.encouragement ) );
			}
			if ( p.what_it_takes ) {
				box.appendChild( h( 'p', { class: 'sl-small', dir: 'auto' }, h( 'b', { text: t( 'What it takes:' ) + ' ' } ), p.what_it_takes ) );
			}
			if ( p.leader && p.leader.who ) {
				box.appendChild( h( 'p', { class: 'sl-small', dir: 'auto' }, h( 'b', { text: t( 'Who is #1 and why:' ) + ' ' } ), p.leader.who + ' — ' + p.leader.why_they_rank ) );
			}
			if ( p.gaps && p.gaps.length ) {
				box.appendChild( h( 'h4', { text: t( 'What your page is missing' ) } ) );
				box.appendChild( h( 'ul', { class: 'sl-r1__gaps' }, p.gaps.map( function ( x ) {
					return h( 'li', { dir: 'auto', text: x } );
				} ) ) );
			}
			box.appendChild( h( 'h4', { text: t( 'Steps, most effective first' ) } ) );
			var done = fc.steps || {};
			box.appendChild( h( 'ol', { class: 'sl-r1__steps' }, ( p.steps || [] ).map( function ( s, i ) {
				var did = done[ i ];
				return h( 'li', { class: did ? 'is-done' : '' },
					h( 'div', { class: 'sl-r1__step' },
						h( 'div', null,
							h( 'b', { dir: 'auto', text: s.title } ), ' ', SL.badge( t( 'Impact: %s', t( s.impact ) ), 'high' === s.impact ? 'good' : 'default' ),
							h( 'p', { class: 'sl-small', dir: 'auto', text: s.detail } ),
							s.why_it_moves_this_query ? h( 'p', { class: 'sl-small sl-muted', dir: 'auto', text: t( 'Why it moves this search: %s', s.why_it_moves_this_query ) } ) : null
						),
						did ? h( 'a', { class: 'sl-btn sl-btn--sm', href: did.route || '#/actions' }, SL.icon( 'check', 14 ), ' ', t( 'Prepared — open' ) ) : App.cap( 'ai' ) ? App.abtn( 'task' === s.action ? t( 'Add to the roadmap' ) : t( 'Prepare it' ), function () {
							return SL.api( 'rank1/step', { body: { index: i } } ).then( function ( x ) {
								SL.toast( x.note || t( 'Prepared.' ), x.ok ? 'success' : 'error' );
								load();
							} );
						}, 'sm' ) : null
					)
				);
			} ) ) );
			if ( p.ignore && p.ignore.length ) {
				box.appendChild( h( 'details', { class: 'sl-r1__ignore' }, h( 'summary', { text: t( 'Not worth your time for this search' ) } ), h( 'ul', null, p.ignore.map( function ( x ) {
					return h( 'li', { dir: 'auto', text: x } );
				} ) ) ) );
			}
			if ( p.measure ) {
				box.appendChild( h( 'p', { class: 'sl-small sl-muted', dir: 'auto' }, h( 'b', { text: t( 'How we will know it worked:' ) + ' ' } ), p.measure ) );
			}
			box.appendChild( h( 'p', { class: 'sl-muted sl-small', text: t( 'Plan from %s', SL.date( fc.plan.at ) ) + ( fc.plan.serp_used ? '' : ' · ' + t( 'made without live Google results' ) ) } ) );
			return box;
		}

		function wait() {
			if ( polls++ > 25 ) {
				return;
			}
			setTimeout( function () {
				if ( ! d.body.contains( body ) ) {
					return;
				}
				SL.api( 'rank1' ).then( function ( r ) {
					if ( r.focus && r.focus.plan && ( ! body._planAt || body._planAt !== r.focus.plan.at ) ) {
						body._planAt = r.focus.plan.at;
						SL.clear( body );
						render( r );
						SL.toast( t( 'The plan is ready.' ), 'good' );
						return;
					}
					wait();
				} ).catch( wait );
			}, 8000 );
		}

		function board( r ) {
			var wrap = h( 'section', { class: 'sl-r1__board' } );
			var table = h( 'div' );
			var q = h( 'input', { class: 'sl-input sl-input--sm', type: 'search', placeholder: t( 'Search…' ), value: f.search } );
			q.addEventListener( 'input', function () {
				f.search = q.value;
				draw();
			} );
			wrap.appendChild( h( 'div', { class: 'sl-toolbar' },
				h( 'h3', { class: 'sl-panel__title', text: t( 'All your searches, best chance of real gain first' ) } ),
				App.select( [ [ '', t( 'Every chance' ) ], [ 'close', t( 'Very close' ) ], [ 'realistic', t( 'Realistic' ) ], [ 'hard', t( 'Hard' ) ], [ 'long_shot', t( 'Long shot for now' ) ], [ 'won', t( 'Already #1' ) ] ], f.chance, function ( x ) {
					f.chance = x;
					draw();
				} ),
				q
			) );
			wrap.appendChild( table );
			function draw() {
				SL.clear( table );
				var rows = r.items.filter( function ( x ) {
					return ( ! f.chance || x.chance === f.chance ) && ( ! f.search || x.query.toLowerCase().indexOf( f.search.toLowerCase() ) > -1 );
				} ).slice( 0, 100 );
				table.appendChild( App.table( [
					{ label: t( 'Search' ), render: function ( x ) {
						return h( 'div', { class: 'sl-r1__qcell' }, h( 'b', { dir: 'auto', text: x.query } ), x.brand ? SL.badge( t( 'Brand' ) ) : null, x.competing > 1 ? SL.badge( t( '%s of your pages compete', x.competing ), 'warn' ) : null, x.done ? SL.badge( t( 'Done' ), 'good' ) : null );
					} },
					{ label: t( 'Position' ), render: function ( x ) {
						var up = x.prev_position !== null && x.prev_position - x.position >= 0.5;
						var down = x.prev_position !== null && x.position - x.prev_position >= 0.5;
						return h( 'span', { class: 'sl-r1__posc' + ( up ? ' is-up' : down ? ' is-down' : '' ), dir: 'ltr', title: x.prev_position !== null ? t( '28 days before: %s', x.prev_position ) : '' }, '#' + x.position, up ? ' ▲' : down ? ' ▼' : '' );
					} },
					{ label: t( 'Impressions' ), render: function ( x ) {
						return SL.num( x.impressions, 0 );
					} },
					{ label: t( 'Clicks' ), render: function ( x ) {
						return SL.num( x.clicks, 0 );
					} },
					{ label: t( 'If #1 (estimate)' ), render: function ( x ) {
						return x.gain ? '+' + SL.num( x.gain, 0 ) : '—';
					} },
					{ label: t( 'Chance' ), render: function ( x ) {
						return chanceBadge( x.chance );
					} },
					{ label: t( 'Intent' ), render: function ( x ) {
						return t( INTENT[ x.intent ] );
					} },
					{ label: '', render: function ( x ) {
						if ( ! App.cap( 'ai' ) ) {
							return null;
						}
						var on = r.focus && r.focus.query === x.query;
						return on ? SL.badge( t( 'In focus' ), 'good' ) : App.abtn( t( 'Focus on this' ), function () {
							var go = function () {
								return SL.api( 'rank1/focus', { body: { query: x.query, page: x.page } } ).then( function () {
									w.scrollTo( 0, 0 );
									load();
								} );
							};
							if ( r.focus ) {
								return new Promise( function ( res ) {
									SL.confirm( t( 'Leave “%s” and focus on this search instead? One search at a time works best.', r.focus.query ), function () {
										SL.api( 'rank1/finish', { body: { how: 'skip' } } ).then( go ).then( res ).catch( SL.fail );
									} );
								} );
							}
							return go();
						}, 'sm' );
					} },
				], rows, { empty: t( 'No searches match.' ) } ) );
			}
			draw();
			return wrap;
		}
	};
}( window, document ) );
