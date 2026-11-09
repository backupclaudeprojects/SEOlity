/**
 * SEOlity admin — Index coverage: Search Console "Pages" exports, Search Console messages,
 * findings with approved fixes, the AI analysis and the daily URL Inspection watch.
 */
( function ( w, d ) {
	'use strict';
	var SL = w.SL;
	var App = SL.app;
	var t = SL.t;
	var h = SL.h;

	var SEV = { critical: [ '#b91c1c', 'Critical' ], high: [ '#dc2626', 'High' ], medium: [ '#d97706', 'Medium' ], low: [ '#2563eb', 'Low' ], info: [ '#16a34a', 'OK' ] };

	App.pages.coverage = function ( v ) {
		v.appendChild( App.header( t( 'Index coverage' ), t( 'Why Google does or does not index your pages: Search Console exports and messages, Google’s own URL Inspection, redirects — explained, with fixes you approve.' ), App.cap( 'ai' ) ? [ App.abtn( SL.il( 'wand', t( 'AI analysis' ), 15 ), function () {
			return SL.api( 'coverage/ai', { method: 'POST' } ).then( function () {
				SL.toast( t( 'The AI is analysing the indexing data — the result arrives as a notification and here.' ) );
			} );
		}, 'primary' ) ] : null ) );
		var body = h( 'div' );
		v.appendChild( body );
		load();

		function load() {
			App.load( body, SL.api( 'coverage' ), function ( r ) {
				body.appendChild( uploadCard( r ) );
				if ( r.summary ) {
					body.appendChild( summary( r ) );
				}
				if ( r.ai ) {
					body.appendChild( aiCard( r.ai ) );
				}
				body.appendChild( findings( r.findings || [] ) );
				body.appendChild( messages( r.messages || [] ) );
				body.appendChild( watchCard( r.watch || {} ) );
			} );
		}

		function uploadCard( r ) {
			var input = h( 'input', { type: 'file', multiple: true, accept: '.zip,.csv', class: 'sl-input' } );
			var drill = Object.keys( r.drilldowns || {} ).map( function ( k ) {
				var x = r.drilldowns[ k ];
				return h( 'span', { class: 'sl-chip', dir: 'auto', text: x.reason + ' · ' + SL.num( x.total, 0 ) } );
			} );
			return App.card( t( 'Search Console exports' ), h( 'div', { class: 'sl-stack' },
				h( 'ol', { class: 'sl-steps' },
					h( 'li', { text: t( 'Search Console → Indexing → Pages → Export (top right) → Download ZIP. This is the summary with the timeline.' ) } ),
					h( 'li', { text: t( 'Click each reason in the list “Why pages aren’t indexed” → Export → Download ZIP. These contain the URLs.' ) } ),
					h( 'li', { text: t( 'Choose all ZIP files here at once. Re-upload whenever Search Console e-mails you.' ) } )
				),
				h( 'div', { class: 'sl-row' }, input, App.abtn( SL.il( 'upload', t( 'Upload & analyse' ), 15 ), function () {
					if ( ! input.files.length ) {
						SL.toast( t( 'Choose the ZIP files exported from Search Console.' ), 'error' );
						return Promise.resolve();
					}
					var fd = new w.FormData();
					Array.prototype.forEach.call( input.files, function ( f, i ) {
						fd.append( 'file' + i, f, f.name );
					} );
					return SL.api( 'coverage/import', { method: 'POST', body: fd } ).then( function ( res ) {
						var bad = ( res.imported || [] ).filter( function ( x ) {
							return x.error;
						} );
						SL.toast( bad.length ? bad.map( function ( x ) {
							return x.file + ': ' + x.error;
						} ).join( ' · ' ) : t( 'Imported. %d findings.', res.findings ), bad.length ? 'error' : 'success' );
						load();
					} );
				}, 'primary' ) ),
				r.summary ? h( 'p', { class: 'sl-muted sl-small', text: t( 'Last summary: %s', SL.date( r.summary.imported_at ) ) } ) : null,
				drill.length ? h( 'div', { class: 'sl-chips' }, drill ) : null
			), { icon: 'upload', sub: t( 'Google offers no API for this report, so SEOlity reads the exports you download.' ) } );
		}

		function summary( r ) {
			var wrap = h( 'div', { class: 'sl-stack' } );
			var chart = ( r.summary.chart || [] ).filter( function ( p ) {
				return null !== p.indexed;
			} );
			if ( chart.length > 1 ) {
				wrap.appendChild( App.card( t( 'Indexed vs not indexed' ), SL.charts.line( chart, { x: 'date', series: [ { key: 'indexed', label: t( 'Indexed' ) }, { key: 'not_indexed', label: t( 'Not indexed' ) }, { key: 'impressions', label: t( 'Impressions' ), axis: 'right' } ], height: 220 } ), { icon: 'chart' } ) );
			}
			var bad = {};
			( r.findings || [] ).forEach( function ( f ) {
				if ( ! f.intentional && 'info' !== f.severity ) {
					bad[ f.reason ] = true;
				}
			} );
			var cards = h( 'div', { class: 'sl-mapcards' } );
			( r.summary.reasons || [] ).forEach( function ( x ) {
				var has = r.drilldowns && r.drilldowns[ x.key ];
				var col = bad[ x.reason ] ? '#dc2626' : ( has ? '#16a34a' : '#64748b' );
				cards.appendChild( h( 'article', { class: 'sl-mapcard', style: '--c:' + col },
					h( 'div', { class: 'sl-mapcard__head' },
						h( 'span', { class: 'sl-mapcard__ic', 'aria-hidden': 'true' }, SL.icon( bad[ x.reason ] ? 'alert' : 'eye-off', 22 ) ),
						h( 'div', { class: 'sl-mapcard__titles' }, h( 'b', { class: 'sl-mapcard__title', dir: 'auto', text: x.reason } ), h( 'span', { class: 'sl-muted sl-small', text: ( x.source ? x.source + ' · ' : '' ) + t( 'Validation: %s', x.validation || '—' ) } ) ),
						h( 'span', { class: 'sl-mapcard__status' }, h( 'b', { text: SL.num( x.pages, 0 ) } ) )
					),
					h( 'p', { class: 'sl-small ' + ( has ? 'sl-muted' : '' ), text: has ? ( bad[ x.reason ] ? t( 'Needs attention — see the findings below.' ) : t( 'Checked: nothing harmful found.' ) ) : t( 'Export this reason too to see its URLs.' ) } )
				) );
			} );
			wrap.appendChild( App.card( t( 'Why pages aren’t indexed' ), cards, { icon: 'eye-off' } ) );
			return wrap;
		}

		function aiCard( ai ) {
			var rows = ( ai.reasons || [] ).map( function ( x ) {
				var col = 'problem' === x.status ? 'sl-chip--bad' : ( 'check' === x.status ? 'sl-chip--warn' : 'sl-chip--good' );
				return h( 'li', null, h( 'span', { class: 'sl-chip ' + col, text: { problem: t( 'Problem' ), check: t( 'Check' ), ok: t( 'OK' ) }[ x.status ] || x.status } ), ' ', h( 'b', { dir: 'auto', text: x.reason } ), h( 'p', { class: 'sl-small', dir: 'auto', text: x.explanation + ( x.impact ? ' — ' + x.impact : '' ) } ) );
			} );
			return App.card( t( 'AI analysis' ), h( 'div', { class: 'sl-stack' },
				h( 'div', { class: 'sl-md', dir: 'auto' }, SL.markdown( ai.summary || '' ) ),
				ai.timeline ? h( 'p', { class: 'sl-muted', dir: 'auto', text: ai.timeline } ) : null,
				rows.length ? h( 'ul', { class: 'sl-notice__lines' }, rows ) : null,
				( ai.plan || [] ).length ? h( 'div', null, h( 'b', { text: t( 'Plan (added to the SEO roadmap)' ) } ), h( 'ol', null, ai.plan.map( function ( p ) {
					return h( 'li', { dir: 'auto' }, h( 'b', { text: p.title } ), p.why ? h( 'span', { class: 'sl-muted', text: ' — ' + p.why } ) : null );
				} ) ) ) : null,
				h( 'p', { class: 'sl-muted sl-small', text: SL.date( ai.at ) } )
			), { icon: 'wand' } );
		}

		function findings( list ) {
			var box = h( 'div', { class: 'sl-mapcards' } );
			if ( ! list.length ) {
				return App.card( t( 'Findings' ), SL.empty( t( 'Nothing found yet' ), t( 'Upload the Search Console exports, or wait for the daily index watch.' ) ), { icon: 'search' } );
			}
			list.forEach( function ( f ) {
				var s = SEV[ f.severity ] || SEV.low;
				var urls = h( 'details', null, h( 'summary', { text: t( '%d URLs', f.count ) } ), h( 'ul', { class: 'sl-urls' }, ( f.urls || [] ).map( function ( u ) {
					return h( 'li', null, h( 'a', { href: u, target: '_blank', rel: 'noopener noreferrer', dir: 'ltr', text: safeDecode( u ) } ) );
				} ) ) );
				box.appendChild( h( 'article', { class: 'sl-mapcard', style: '--c:' + s[ 0 ] },
					h( 'div', { class: 'sl-mapcard__head' },
						h( 'span', { class: 'sl-mapcard__ic', 'aria-hidden': 'true' }, SL.icon( f.intentional ? 'check-circle' : 'alert', 22 ) ),
						h( 'div', { class: 'sl-mapcard__titles' }, h( 'b', { class: 'sl-mapcard__title', dir: 'auto', text: f.title } ), h( 'span', { class: 'sl-muted sl-small', dir: 'auto', text: f.reason } ) ),
						h( 'span', { class: 'sl-mapcard__status' }, h( 'b', { text: t( s[ 1 ] ) } ) )
					),
					h( 'p', { class: 'sl-small', dir: 'auto', text: f.detail } ),
					f.urls && f.urls.length ? urls : null,
					f.fix && f.fix.type && App.cap( 'manage' ) ? h( 'div', { class: 'sl-mapcard__foot' }, h( 'span', { class: 'sl-muted sl-small', text: t( 'Nothing changes until you approve.' ) } ), h( 'div', { class: 'sl-mapcard__actions' }, App.abtn( f.fix.label || t( 'Fix' ), function () {
						return SL.api( 'coverage/fix', { body: { id: f.id } } ).then( function ( op ) {
							var m = SL.modal( { title: f.title, wide: true, onClose: load } );
							App.operationView( m.body, op, load );
						} );
					}, 'primary' ) ) ) : null
				) );
			} );
			return App.card( t( 'Findings' ), box, { icon: 'search', sub: t( 'From the exports and SEOlity’s own data (redirects, posts, changes).' ) } );
		}

		function messages( list ) {
			var txt = h( 'textarea', { class: 'sl-input', rows: 4, dir: 'auto', placeholder: t( 'Paste the whole e-mail from Search Console here (e.g. “New reasons prevent pages in a sitemap from being indexed…”).' ) } );
			var items = list.map( function ( m ) {
				return h( 'article', { class: 'sl-mapcard', style: '--c:' + ( SEV[ m.severity ] || SEV.medium )[ 0 ] },
					h( 'div', { class: 'sl-mapcard__head' },
						h( 'span', { class: 'sl-mapcard__ic', 'aria-hidden': 'true' }, SL.icon( 'bell', 22 ) ),
						h( 'div', { class: 'sl-mapcard__titles' }, h( 'b', { class: 'sl-mapcard__title', dir: 'auto', text: ( m.text || '' ).split( '\n' )[ 0 ].slice( 0, 160 ) } ), h( 'span', { class: 'sl-muted sl-small', text: SL.date( m.at ) } ) )
					),
					m.ai ? h( 'div', { class: 'sl-md', dir: 'auto' }, SL.markdown( m.ai.summary || '' ) ) : h( 'p', { class: 'sl-muted sl-small', text: t( 'The AI explanation arrives in a moment as a notification.' ) } )
				);
			} );
			return App.card( t( 'Search Console messages' ), h( 'div', { class: 'sl-stack' },
				h( 'p', { class: 'sl-muted', text: t( 'Google has no API for Search Console messages. Paste or forward the text and SEOlity explains it, connects it with your data and notifies you (also by e-mail). The daily index watch below finds most of these problems on its own.' ) } ),
				txt,
				h( 'div', { class: 'sl-row' }, App.abtn( t( 'Read the message' ), function () {
					return SL.api( 'coverage/message', { body: { text: txt.value } } ).then( function () {
						SL.toast( t( 'Message stored — see Notifications.' ), 'success' );
						load();
					} );
				}, 'primary' ) ),
				items.length ? h( 'div', { class: 'sl-mapcards' }, items ) : null
			), { icon: 'bell' } );
		}

		function watchCard( wv ) {
			var states = Object.keys( wv.states || {} ).map( function ( k ) {
				return h( 'span', { class: 'sl-chip', text: k + ' · ' + wv.states[ k ] } );
			} );
			return App.card( t( 'Daily index watch (Google URL Inspection)' ), h( 'div', { class: 'sl-stack' },
				h( 'p', { class: 'sl-muted', text: t( 'Every day SEOlity asks Google about %d of your pages in turn (Settings → Crawler) and notifies you when a new reason appears — like Search Console’s e-mails, often earlier. It also watches the sitemap and the redirect rules.', wv.daily || 0 ) } ),
				h( 'p', { text: t( '%d pages checked so far.', wv.checked || 0 ) } ),
				states.length ? h( 'div', { class: 'sl-chips' }, states ) : null,
				( wv.problems || [] ).length ? h( 'details', null, h( 'summary', { text: t( '%d pages with a problem', wv.problems.length ) } ), h( 'ul', { class: 'sl-urls' }, wv.problems.map( function ( p ) {
					return h( 'li', { dir: 'ltr' }, h( 'a', { href: p.url, target: '_blank', rel: 'noopener noreferrer', text: safeDecode( p.url ) } ), ' — ' + p.state );
				} ) ) ) : null,
				App.cap( 'manage' ) ? h( 'div', { class: 'sl-row' }, App.abtn( t( 'Check now' ), function () {
					return SL.api( 'coverage/watch', { method: 'POST' } ).then( load );
				} ) ) : null
			), { icon: 'eye' } );
		}
	};

	function safeDecode( u ) {
		try {
			return decodeURI( u );
		} catch ( e ) {
			return u;
		}
	}
} )( window, document );
