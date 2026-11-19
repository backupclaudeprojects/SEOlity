/**
 * SEOlity admin — Site rules: the owner's lasting decisions, followed by every AI part of
 * SEOlity (chat, roadmap, Road to #1, page optimization, focus keywords). A rule is either
 * "this search belongs to this page" (other pages must not compete) or a free sentence.
 */
( function ( w, d ) {
	'use strict';
	var SL = w.SL;
	var App = SL.app;
	var t = SL.t;
	var h = SL.h;

	function path( u ) {
		try {
			return SL.dec( SL.path( u ) );
		} catch ( e ) {
			return SL.path( u );
		}
	}

	/** A small page picker: search the site, click a result. */
	function pagePicker( onPick, placeholder ) {
		var input = h( 'input', { class: 'sl-input', type: 'search', dir: 'auto', placeholder: placeholder || t( 'Search pages, posts, products, categories…' ) } );
		var list = h( 'div', { class: 'sl-options sl-rules__opts' } );
		var tmr;
		input.addEventListener( 'input', function () {
			clearTimeout( tmr );
			var q = input.value.trim();
			if ( /^https?:\/\//.test( q ) || 0 === q.indexOf( '/' ) ) {
				SL.clear( list );
				list.appendChild( h( 'button', { type: 'button', class: 'sl-option', onClick: function () {
					onPick( { url: q, label: q } );
					input.value = '';
					SL.clear( list );
				} }, t( 'Use this address: %s', q ) ) );
				return;
			}
			tmr = setTimeout( function () {
				if ( q.length < 2 ) {
					SL.clear( list );
					return;
				}
				SL.api( 'search', { query: { q: q } } ).then( function ( items ) {
					SL.clear( list );
					( items || [] ).filter( function ( x ) {
						return x.url;
					} ).slice( 0, 8 ).forEach( function ( x ) {
						list.appendChild( h( 'button', { type: 'button', class: 'sl-option', onClick: function () {
							onPick( { url: x.url, label: x.label } );
							input.value = '';
							SL.clear( list );
						} }, h( 'b', { dir: 'auto', text: x.label } ), h( 'small', { class: 'sl-muted', dir: 'ltr', text: ' ' + path( x.url ) } ) ) );
					} );
				} ).catch( function () {} );
			}, 300 );
		} );
		return h( 'div', { class: 'sl-rules__picker' }, input, list );
	}

	App.pages[ 'site-rules' ] = function ( v ) {
		v.appendChild( App.header( t( 'Site rules' ), t( 'Your lasting decisions about the site. Every AI part of SEOlity follows them — the chat, the roadmap, Road to #1, page optimization and focus keywords — and nothing that breaks a rule is proposed or applied.' ) ) );
		var formBox = h( 'div' );
		var body = h( 'div' );
		v.appendChild( formBox );
		v.appendChild( body );
		var q0 = App.query ? App.query() : {};
		var editing = null;
		form( q0.query ? { query: String( q0.query ) } : null );
		load();

		function load() {
			App.load( body, SL.api( 'site-rules' ), render );
		}

		function save( data ) {
			return SL.api( 'site-rules', { body: data } ).then( function () {
				SL.toast( t( 'Rule saved — SEOlity follows it from now on.' ), 'success' );
				editing = null;
				form( null );
				load();
			} );
		}

		/** Add / edit form. */
		function form( r ) {
			SL.clear( formBox );
			editing = r && r.id ? r : null;
			var kind = r && ! r.query && r.text ? 'text' : 'query';
			var owner = r && r.url ? { url: r.url, label: path( r.url ) } : null;
			var avoid = ( r && r.avoid ? r.avoid : [] ).map( function ( u ) {
				return { url: u, label: path( u ) };
			} );
			var query = h( 'input', { class: 'sl-input', type: 'text', dir: 'auto', value: r && r.query ? r.query : '', placeholder: t( 'The search, e.g. خرید کیمچی' ) } );
			var note = h( 'textarea', { class: 'sl-input', rows: 2, dir: 'auto', placeholder: t( 'Optional note for the AI (why, exceptions…)' ) } );
			var text = h( 'textarea', { class: 'sl-input', rows: 3, dir: 'auto', placeholder: t( 'The rule in your own words, e.g. “never redirect products to the home page”, “prices are always in Toman”.' ) } );
			note.value = r && r.query ? r.text || '' : '';
			text.value = r && ! r.query ? r.text || '' : '';
			var ownerBox = h( 'div' );
			var avoidBox = h( 'div', { class: 'sl-chips' } );
			function drawOwner() {
				SL.clear( ownerBox );
				ownerBox.appendChild( owner ? h( 'span', { class: 'sl-chip' }, SL.icon( 'check', 13 ), ' ', h( 'span', { dir: 'auto', text: owner.label } ), ' ', h( 'button', { type: 'button', class: 'sl-chat__x', 'aria-label': t( 'Remove' ), onClick: function () {
					owner = null;
					drawOwner();
				} }, '×' ) ) : pagePicker( function ( p ) {
					owner = p;
					drawOwner();
				}, t( 'The page that should rank for it…' ) ) );
			}
			function drawAvoid() {
				SL.clear( avoidBox );
				avoid.forEach( function ( p, i ) {
					avoidBox.appendChild( h( 'span', { class: 'sl-chip' }, h( 'span', { dir: 'auto', text: p.label } ), ' ', h( 'button', { type: 'button', class: 'sl-chat__x', 'aria-label': t( 'Remove' ), onClick: function () {
						avoid.splice( i, 1 );
						drawAvoid();
					} }, '×' ) ) );
				} );
			}
			drawOwner();
			drawAvoid();
			var qPane = h( 'div', { class: 'sl-stack' },
				h( 'label', { class: 'sl-field__label', text: t( 'Search' ) } ), query,
				h( 'label', { class: 'sl-field__label', text: t( 'Page that owns it' ) } ), ownerBox,
				h( 'label', { class: 'sl-field__label', text: t( 'Pages that must stop competing for it (optional)' ) } ), avoidBox,
				pagePicker( function ( p ) {
					avoid.push( p );
					drawAvoid();
				}, t( 'Add a page that must not target this search…' ) ),
				note
			);
			var tPane = h( 'div', { class: 'sl-stack' }, text );
			var panes = h( 'div' );
			function show() {
				SL.clear( panes );
				panes.appendChild( 'query' === kind ? qPane : tPane );
			}
			var seg = h( 'div', { class: 'sl-seg' },
				h( 'button', { type: 'button', class: 'query' === kind ? 'is-active' : '', onClick: function ( e ) {
					kind = 'query';
					e.target.classList.add( 'is-active' );
					e.target.nextSibling.classList.remove( 'is-active' );
					show();
				} }, t( 'A search belongs to one page' ) ),
				h( 'button', { type: 'button', class: 'text' === kind ? 'is-active' : '', onClick: function ( e ) {
					kind = 'text';
					e.target.classList.add( 'is-active' );
					e.target.previousSibling.classList.remove( 'is-active' );
					show();
				} }, t( 'General rule' ) )
			);
			show();
			formBox.appendChild( h( 'section', { class: 'sl-panel sl-rules__form' },
				h( 'div', { class: 'sl-panel__title', text: editing ? t( 'Edit rule' ) : t( 'Add a rule' ) } ),
				seg, panes,
				h( 'div', { class: 'sl-row' },
					App.abtn( t( 'Save rule' ), function () {
						if ( 'query' === kind ) {
							if ( ! query.value.trim() || ! owner ) {
								SL.toast( t( 'Write the search and choose the page that owns it.' ), 'error' );
								return Promise.resolve();
							}
							return save( { id: editing ? editing.id : '', query: query.value.trim(), url: owner.url, avoid: avoid.map( function ( p ) {
								return p.url;
							} ), text: note.value.trim() } );
						}
						if ( ! text.value.trim() ) {
							SL.toast( t( 'Write the rule first.' ), 'error' );
							return Promise.resolve();
						}
						return save( { id: editing ? editing.id : '', text: text.value.trim() } );
					}, 'primary' ),
					editing ? App.btn( t( 'Cancel' ), function () {
						form( null );
					} ) : null
				)
			) );
		}

		function render( r ) {
			// Searches where several of the site's pages compete: choose the owner in one click.
			if ( r.suggestions && r.suggestions.length ) {
				var sug = h( 'section', { class: 'sl-panel' }, h( 'div', { class: 'sl-panel__title', text: t( 'Searches where your own pages compete' ) } ), h( 'p', { class: 'sl-muted sl-small', text: t( 'Google splits these searches between two or more of your pages (Search Console, last 28 days), so none of them gets the full strength. Choose the page that should rank; the others will stop competing and link to it instead.' ) } ) );
				r.suggestions.forEach( function ( s ) {
					var chosen = s.pages[ 0 ].url;
					var name = 'sl-own-' + Math.random().toString( 36 ).slice( 2 );
					sug.appendChild( h( 'div', { class: 'sl-rules__sug' },
						h( 'div', { class: 'sl-rules__sughead' }, h( 'b', { dir: 'auto', text: s.query } ), h( 'span', { class: 'sl-muted sl-small', text: t( '%s impressions', SL.num( s.impressions, 0 ) ) } ) ),
						h( 'div', { class: 'sl-rules__pages' }, s.pages.map( function ( p, i ) {
							return h( 'label', { class: 'sl-check' }, h( 'input', { type: 'radio', name: name, checked: 0 === i, onChange: function () {
								chosen = p.url;
							} } ), h( 'span', { dir: 'ltr', text: path( p.url ) } ), h( 'small', { class: 'sl-muted', text: ' · ' + t( '%1$s impressions · position %2$s', SL.num( p.impressions, 0 ), p.position ) } ) );
						} ) ),
						App.cap( 'apply' ) ? App.abtn( t( 'This page should rank' ), function () {
							return save( { query: s.query, url: chosen, avoid: s.pages.map( function ( p ) {
								return p.url;
							} ).filter( function ( u ) {
								return u !== chosen;
							} ) } );
						}, 'sm' ) : null
					) );
				} );
				body.appendChild( sug );
			}
			var rules = r.rules || [];
			if ( ! rules.length ) {
				body.appendChild( SL.empty( t( 'No rules yet' ), t( 'Add your first rule above, or tell the AI in the chat, e.g. “from now on «خرید کیمچی» belongs to the kimchi category page”.' ) ) );
				return;
			}
			body.appendChild( h( 'h3', { class: 'sl-panel__title sl-rules__h', text: t( 'Rules SEOlity follows (%s)', rules.length ) } ) );
			rules.forEach( function ( x ) {
				var on = h( 'input', { type: 'checkbox', checked: !! x.active, 'aria-label': t( 'Active' ), onChange: function () {
					SL.api( 'site-rules/' + x.id + '/active', { body: { active: on.checked ? 1 : 0 } } ).then( load ).catch( SL.fail );
				} } );
				body.appendChild( h( 'div', { class: 'sl-rules__rule' + ( x.active ? '' : ' is-off' ) },
					h( 'div', { class: 'sl-rules__main' },
						h( 'div', { class: 'sl-row' }, h( 'b', { dir: 'auto', text: x.title } ), SL.badge( 'chat' === x.source ? t( 'from the chat' ) : t( 'by you' ), 'chat' === x.source ? 'brand' : '' ), x.active ? null : SL.badge( t( 'Paused' ), 'warn' ) ),
						x.query ? h( 'p', { class: 'sl-small', dir: 'auto' }, t( 'Search' ), ' «', h( 'b', { text: x.query } ), '» → ', h( 'span', { dir: 'ltr', text: path( x.url ) } ) ) : null,
						x.avoid && x.avoid.length ? h( 'p', { class: 'sl-small sl-muted', dir: 'auto' }, t( 'Must stop competing:' ) + ' ', h( 'span', { dir: 'ltr', text: x.avoid.map( path ).join( '  ·  ' ) } ) ) : null,
						x.text ? h( 'p', { class: 'sl-small', dir: 'auto', text: x.text } ) : null
					),
					App.cap( 'apply' ) ? h( 'div', { class: 'sl-rules__acts' },
						h( 'label', { class: 'sl-check' }, on, h( 'span', { text: t( 'Active' ) } ) ),
						App.btn( t( 'Edit' ), function () {
							form( x );
							w.scrollTo( 0, 0 );
						}, 'sm' ),
						App.btn( SL.icon( 'trash', 15 ), function () {
							SL.confirm( t( 'Delete this rule? The AI will no longer follow it.' ), function () {
								SL.api( 'site-rules/' + x.id + '/delete', { method: 'POST' } ).then( load ).catch( SL.fail );
							}, true );
						}, 'ghost', { 'aria-label': t( 'Delete' ) } )
					) : null
				) );
			} );
		}
	};
}( window, document ) );
