#!/usr/bin/env node
/**
 * check-tracker.js — what assets/tracker.js puts on donation links and posts.
 *
 * Runs the real tracker in a jsdom page and asserts on what it does:
 *   - the labeled packed source, byte for byte, both pair separators
 *   - donation links, Give Lively's Branded widget (data-widget-src, then its
 *     frame) and Simple widget (a frame inside a shadow root, set on click)
 *   - Donate clicks posted to the Visitors endpoint, through the outbox, and
 *     resent on the next page load when unconfirmed
 *   - untracked pages purged from storage
 * The fixtures in scripts/fixtures/packed-source.json are shared with
 * check-packing.php, so the PHP side must pack the same bytes.
 *
 * jsdom is not a dependency of the plugin. Install it anywhere and point
 * NODE_PATH at it:
 *   npm i --prefix /tmp/lk jsdom@24
 *   NODE_PATH=/tmp/lk/node_modules node scripts/check-tracker.js
 *
 * Exits non-zero on any failure.
 */
'use strict';

const fs = require( 'fs' );
const path = require( 'path' );
const { JSDOM, VirtualConsole } = require( 'jsdom' );

const SRC = fs.readFileSync( path.join( __dirname, '..', 'assets', 'tracker.js' ), 'utf8' );
const FIX = JSON.parse( fs.readFileSync( path.join( __dirname, 'fixtures', 'packed-source.json' ), 'utf8' ) );
const VISITOR_URL = 'https://3sgf.org/wp-json/leadkit/v1/visitor';
const GL = 'https://secure.givelively.org/donate/3strands-global-foundation';

let failed = 0;
let passed = 0;
function ok( cond, name, detail ) {
	if ( cond ) {
		passed++;
	} else {
		failed++;
		console.error( 'FAIL ' + name + ( detail !== undefined ? '\n     got: ' + JSON.stringify( detail ) : '' ) );
	}
}
function eq( got, want, name ) {
	ok( JSON.stringify( got ) === JSON.stringify( want ), name + ( JSON.stringify( got ) === JSON.stringify( want ) ? '' : '\n     want: ' + JSON.stringify( want ) ), got );
}
const tick = () => new Promise( ( r ) => setTimeout( r, 0 ) );

/**
 * A page with the tracker on it.
 *
 * @param {Object} o url, referrer, html, cfg, storage (key => string), status (fetch answer), noFetch
 */
function boot( o ) {
	o = o || {};
	const vc = new VirtualConsole(); // jsdom's "navigation not implemented" is noise here.
	const dom = new JSDOM( '<!doctype html><html><body>' + ( o.html || '' ) + '</body></html>', {
		url: o.url || 'https://3sgf.org/hope/',
		referrer: o.referrer || undefined,
		pretendToBeVisual: true,
		virtualConsole: vc,
		runScripts: 'outside-only',
	} );
	const w = dom.window;
	Object.keys( o.storage || {} ).forEach( ( k ) => w.localStorage.setItem( k, o.storage[ k ] ) );
	w.LeadKitCfg = Object.assign( { visitorUrl: VISITOR_URL, storagePrefix: 'leadkit' }, o.cfg || {} );
	const calls = [];
	const beacons = [];
	if ( ! o.noFetch ) {
		w.fetch = ( url, init ) => {
			calls.push( { url: url, body: init && init.body ? JSON.parse( init.body ) : null } );
			const status = typeof o.status === 'function' ? o.status( calls.length ) : ( o.status || 200 );
			return Promise.resolve( { ok: status >= 200 && status < 300, status: status } );
		};
	} else {
		w.fetch = () => {
			throw new TypeError( 'no fetch' );
		};
	}
	w.navigator.sendBeacon = ( url, blob ) => {
		beacons.push( url );
		return true;
	};
	w.eval( SRC );
	return { w: w, t: w.LeadKitAnalytics, calls: calls, beacons: beacons };
}
function session( extra ) {
	return JSON.stringify( Object.assign( {
		id: '8f3k2q9a',
		device: {},
		utm: {},
		page_views: [],
		events: [],
		started_at: '2026-10-01T15:00:00.000Z',
	}, extra || {} ) );
}
function storageOf( w ) {
	const out = {};
	for ( let i = 0; i < w.localStorage.length; i++ ) {
		const k = w.localStorage.key( i );
		out[ k ] = w.localStorage.getItem( k );
	}
	return out;
}
function query( url ) {
	return new URL( url ).searchParams;
}

( async function () {
	// ---- the packed format, from the shared fixtures ---------------------------
	FIX.pack.forEach( ( c ) => {
		const p = boot( { storage: { leadkit_analytics_session: session( { id: c.visitor } ) }, cfg: { packPairSep: c.sep } } );
		eq( p.t.packedSource( c.fields ), c.packed, 'pack: ' + c.name );
		const back = p.t.unpackSource( c.packed );
		Object.keys( c.unpacked ).forEach( ( k ) => eq( back[ k ], c.unpacked[ k ], 'unpack ' + k + ': ' + c.name ) );
	} );
	FIX.unpack.forEach( ( c ) => {
		const p = boot();
		eq( p.t.unpackSource( c.raw ), c.fields, 'unpack: ' + c.name );
	} );

	// The Zapier Code step, exactly as pasted, reads every fixture back.
	const zap = new Function( 'inputData', fs.readFileSync( path.join( __dirname, 'zapier-code-step.js' ), 'utf8' ) );
	FIX.pack.forEach( ( c ) => {
		const got = zap( { raw: c.packed } );
		Object.keys( c.unpacked ).forEach( ( k ) => eq( got[ k ], c.unpacked[ k ], 'Zapier step ' + k + ': ' + c.name ) );
		eq( Object.keys( got ), [ 'medium', 'source', 'campaign', 'content', 'term', 'visitorid' ], 'Zapier step returns all six outputs: ' + c.name );
	} );
	eq( zap( { raw: '' } ).visitorid, '', 'Zapier step: blank Utm Source gives blank outputs' );
	eq( zap( { raw: 'medium.email__ref.abc__source.mailchimp' } ).source, 'mailchimp', 'Zapier step: unknown keys ignored' );

	// ---- what a landing does -----------------------------------------------------
	let p = boot( {
		url: 'https://3sgf.org/hope/?utm_medium=email&utm_source=mailchimp&utm_campaign=light-the-path&utm_content=email-1',
		storage: { leadkit_analytics_session: session() },
	} );
	const DOC = 'medium.email__source.mailchimp__campaign.light-the-path__content.email-1__visitorid.8f3k2q9a';
	eq( p.t.packedSource(), DOC, 'the plan\'s example string, from a real landing' );
	eq( p.t.historyString(), 'email__mailchimp__light-the-path__email-1', 'campaign_history stays positional' );
	eq( p.t.formParams().utm_source, 'mailchimp', 'forms still get plain utm_source' );

	p = boot( { referrer: 'https://www.google.com/', storage: { leadkit_analytics_session: session() } } );
	eq( p.t.packedSource(), 'medium.organic__source.google__visitorid.8f3k2q9a', 'organic search: the engine\'s name, as Google Analytics writes it' );
	p = boot( { referrer: 'https://news.example.org/story', storage: { leadkit_analytics_session: session() } } );
	eq( p.t.packedSource(), 'medium.referral__source.news-example-org__visitorid.8f3k2q9a', 'referral' );
	p = boot( { storage: { leadkit_analytics_session: session() } } );
	eq( p.t.packedSource(), 'visitorid.8f3k2q9a', 'typed in: no medium or source, only the id' );

	// ---- the referring site, on every landing -----------------------------------------
	const ago = ( ms ) => new Date( Date.now() - ms ).toISOString();
	p = boot( { referrer: 'https://www.google.com/search?q=3strands' } );
	eq( p.t.data.first_referrer, 'google.com', 'new browser from Google: first_referrer google.com' );
	eq( ( p.t.data.referrers || [] ).map( ( r ) => [ r.domain, r.landing ] ), [ [ 'google.com', '/hope/' ] ], 'and one referrer landing, domain only' );
	eq( p.t.visitorBody().first_referrer, 'google.com', 'first_referrer sent to the Visitors endpoint' );
	eq( p.t.visitorBody().referrers.length, 1, 'referrers sent too' );
	p = boot();
	eq( p.t.data.first_referrer, '', 'new browser, typed in: first_referrer is empty, not missing' );
	p = boot( { storage: { leadkit_analytics_session: session( { page_views: [ { id: 'x', path: '/', time: ago( 864e5 ) } ] } ) }, referrer: 'https://www.google.com/' } );
	ok( ! ( 'first_referrer' in p.t.data ), 'a browser from before 1.5: its first visit is unknown, so none is guessed' );
	ok( ! ( 'first_referrer' in p.t.visitorBody() ), 'and none is sent' );

	p = boot( { referrer: 'https://l.facebook.com/l.php?u=x', storage: { leadkit_analytics_session: session( { referrer: 'google.com', referrers: [ { domain: 'google.com', time: ago( 2 * 864e5 ), landing: '/' } ] } ) } } );
	eq( p.t.data.referrers.map( ( r ) => r.domain ), [ 'google.com', 'facebook.com' ], 'a later landing is added; l.facebook.com is facebook.com' );
	eq( p.t.data.referrer, 'google.com', 'referrer stays the first ever (what forms have always had)' );
	eq( p.t.packedSource(), 'medium.referral__source.facebook-com__visitorid.8f3k2q9a', 'no campaign: the LATEST site gets the credit, as referral' );

	p = boot( { referrer: 'https://www.google.com/', storage: { leadkit_analytics_session: session( { utm: { medium: 'email', source: 'mailchimp', campaign: 'light-the-path', content: 'email-1', time: ago( 5 * 864e5 ) } } ) } } );
	eq( p.t.packedSource(), DOC, 'a campaign keeps the credit over a later search visit (approved rule)' );
	eq( p.t.data.referrers.map( ( r ) => r.domain ), [ 'google.com' ], 'but the search visit is still recorded' );
	p = boot( { cfg: { attributionDays: 3 }, referrer: 'https://www.google.com/', storage: { leadkit_analytics_session: session( { utm: { medium: 'email', source: 'mailchimp', campaign: 'light-the-path', content: 'email-1', time: ago( 5 * 864e5 ) } } ) } } );
	eq( p.t.packedSource(), 'medium.organic__source.google__visitorid.8f3k2q9a', 'past the campaign-credit window, the search gets it' );

	[
		[ 'https://mail.google.com/mail/u/0/', 'medium.referral__source.mail-google-com', 'Gmail on the web is a referral, not a search' ],
		[ 'https://www.google.co.uk/', 'medium.organic__source.google', 'google.co.uk is Google search' ],
		[ 'https://r.search.yahoo.com/x', 'medium.organic__source.yahoo', 'Yahoo search' ],
		[ 'https://duckduckgo.com/', 'medium.organic__source.duckduckgo', 'DuckDuckGo' ],
		[ 'https://www.bing.com/search?q=x', 'medium.organic__source.bing', 'Bing' ],
	].forEach( ( c ) => {
		const q = boot( { referrer: c[ 0 ], storage: { leadkit_analytics_session: session() } } );
		eq( q.t.packedSource(), c[ 1 ] + '__visitorid.8f3k2q9a', c[ 2 ] );
	} );
	[
		[ 'https://www.3sgf.org/about/', 'this site, with www.' ],
		[ 'https://new.3sgf.org/', 'a subdomain of this site' ],
		[ 'https://secure.givelively.org/donate/x', 'the donation form coming back' ],
		[ 'https://forms.monday.com/forms/abc', 'an outside form redirecting back' ],
		[ 'android-app://com.google.android.gm/', 'an app' ],
	].forEach( ( c ) => {
		const q = boot( { referrer: c[ 0 ], storage: { leadkit_analytics_session: session() } } );
		eq( ( q.t.data.referrers || [] ).length, 0, 'not a referrer: ' + c[ 1 ] );
	} );
	p = boot( { referrer: 'https://www.google.com/', storage: { leadkit_analytics_session: session( { referrers: [ { domain: 'google.com', time: ago( 10 * 6e4 ), landing: '/' } ] } ) } } );
	eq( p.t.data.referrers.length, 1, 'back to Google results and in again within 30 minutes: the same visit' );
	p = boot( { referrer: 'https://www.google.com/', storage: { leadkit_analytics_session: session( { referrers: [ { domain: 'google.com', time: ago( 31 * 6e4 ), landing: '/' } ] } ) } } );
	eq( p.t.data.referrers.length, 2, 'after 30 minutes: a new landing' );
	const lots = [];
	for ( let i = 0; i < 30; i++ ) lots.push( { domain: 'site' + i + '.org', time: ago( ( 40 - i ) * 864e5 ), landing: '/' } );
	p = boot( { referrer: 'https://news.example.org/', storage: { leadkit_analytics_session: session( { referrers: lots } ) } } );
	eq( [ p.t.data.referrers.length, p.t.data.referrers[ 29 ].domain, p.t.data.referrers[ 0 ].domain ], [ 30, 'news.example.org', 'site1.org' ], 'kept to the last 30 landings' );

	// A Give Lively-style link pointed at the site: its fields, not its visitorid.
	p = boot( {
		url: 'https://3sgf.org/hope/?utm_source=' + encodeURIComponent( 'medium.social__source.facebook__campaign.light-the-path__content.post-3__visitorid.someoneelse' ),
		storage: { leadkit_analytics_session: session() },
	} );
	eq( [ p.t.data.utm.medium, p.t.data.utm.source, p.t.data.utm.campaign, p.t.data.utm.content ], [ 'social', 'facebook', 'light-the-path', 'post-3' ], 'packed utm_source on a site page is read as its fields' );
	ok( p.t.packedSource().endsWith( '__visitorid.8f3k2q9a' ), 'and the stamp carries our id, not the link maker\'s', p.t.packedSource() );

	// ---- donation links ------------------------------------------------------------
	p = boot( {
		url: 'https://3sgf.org/hope/?utm_medium=email&utm_source=mailchimp&utm_campaign=light-the-path&utm_content=email-1',
		html: '<a id="d" href="' + GL + '">Donate</a><a id="k" data-leadkit-keep href="' + GL + '?utm_source=keep">Keep</a>',
		storage: { leadkit_analytics_session: session() },
	} );
	let href = p.w.document.getElementById( 'd' ).getAttribute( 'href' );
	eq( query( href ).getAll( 'utm_source' ), [ DOC ], 'Donate link stamped on load' );
	eq( query( href ).get( 'utm_content' ), 'email-1', 'plain UTMs ride along when there is a campaign' );
	eq( p.t.decorateUrl( href ), null, 'stamping is idempotent (no change, no frame reload)' );
	eq( p.w.document.getElementById( 'k' ).getAttribute( 'href' ), GL + '?utm_source=keep', 'data-leadkit-keep is left alone' );

	p = boot( { storage: { leadkit_analytics_session: session() } } );
	href = p.t.decorateUrl( GL + '?utm_source=qr&utm_medium=print&utm_campaign=light-the-path&utm_content=rack-card' );
	eq( query( href ).get( 'utm_source' ), 'medium.print__source.qr__campaign.light-the-path__content.rack-card__visitorid.8f3k2q9a', 'no campaign: a link\'s own UTMs are kept, packed, with our id' );
	href = p.t.decorateUrl( GL + '?utm_source=medium.email__source.mailchimp__campaign.light-the-path__content.email-2__visitorid.staffmember' );
	eq( query( href ).get( 'utm_source' ), 'medium.email__source.mailchimp__campaign.light-the-path__content.email-2__visitorid.8f3k2q9a', 'no campaign: a packed link keeps its campaign, gets our id' );
	href = p.t.decorateUrl( GL + '?utm_source=email__mailchimp__light-the-path__email-1' );
	eq( query( href ).get( 'utm_source' ), DOC, 'no campaign: a 1.3 positional link is converted' );
	href = p.t.decorateUrl( GL + '?utm_source=a&utm_source=b' );
	eq( query( href ).getAll( 'utm_source' ).length, 1, 'two utm_source values become one' );
	eq( p.t.decorateUrl( 'https://3sgf.org/about/' ), null, 'other links untouched' );

	// ---- a Donate link click -----------------------------------------------------------
	p = boot( {
		url: 'https://3sgf.org/hope/?utm_medium=email&utm_source=mailchimp&utm_campaign=light-the-path&utm_content=email-1',
		html: '<a id="d" href="' + GL + '">Donate</a><a id="o" href="/about/">About</a>',
		storage: { leadkit_analytics_session: session() },
	} );
	p.w.document.getElementById( 'o' ).click();
	eq( p.calls.length, 0, 'an ordinary link posts nothing' );
	p.w.document.getElementById( 'd' ).click();
	await tick();
	eq( p.calls.length, 1, 'a Donate click posts once' );
	eq( p.calls[ 0 ] && p.calls[ 0 ].url, VISITOR_URL, 'to the Visitors endpoint' );
	const body = p.calls[ 0 ] ? p.calls[ 0 ].body : {};
	eq( body.visitor_id, '8f3k2q9a', 'with the visitor id' );
	eq( ( body.donate_clicks || [] ).length, 1, 'and the click' );
	eq( ( body.donate_clicks || [] )[ 0 ] && body.donate_clicks[ 0 ].how, 'link', 'marked as a link click' );
	ok( ( body.page_views || [] ).some( ( v ) => v.path === '/hope/' ), 'and this page, recorded at the click', body.page_views );
	eq( body.utm && body.utm.content, 'email-1', 'and the campaign' );
	eq( p.w.localStorage.getItem( 'leadkit_outbox' ), null, 'confirmed post leaves the outbox' );

	// ---- the outbox: kept on failure, resent next page, dropped when refused -----------
	p = boot( {
		html: '<a id="d" href="' + GL + '">Donate</a>',
		storage: { leadkit_analytics_session: session() },
		status: 502,
	} );
	p.w.document.getElementById( 'd' ).click();
	await tick();
	let box = JSON.parse( p.w.localStorage.getItem( 'leadkit_outbox' ) || '[]' );
	eq( box.length, 1, '502: post kept in the outbox' );
	eq( box[ 0 ] && box[ 0 ].tries, 1, 'with one try counted' );
	p.w.document.getElementById( 'd' ).click();
	await tick();
	box = JSON.parse( p.w.localStorage.getItem( 'leadkit_outbox' ) || '[]' );
	eq( box.length, 1, 'a second click replaces, not adds (the newest story covers both)' );

	let next = boot( { url: 'https://3sgf.org/thank-you/', storage: storageOf( p.w ), status: 200 } );
	await tick();
	eq( next.calls.length, 1, 'next page load resends it' );
	eq( ( next.calls[ 0 ] && next.calls[ 0 ].body.donate_clicks || [] ).length, 2, 'with both clicks' );
	eq( next.w.localStorage.getItem( 'leadkit_outbox' ), null, 'and clears it once confirmed' );

	next = boot( { url: 'https://3sgf.org/thank-you/', storage: storageOf( p.w ), status: 429 } );
	await tick();
	box = JSON.parse( next.w.localStorage.getItem( 'leadkit_outbox' ) || '[]' );
	eq( box.length, 1, '429: kept for later' );
	next = boot( { url: 'https://3sgf.org/thank-you/', storage: storageOf( p.w ), status: 400 } );
	await tick();
	eq( next.w.localStorage.getItem( 'leadkit_outbox' ), null, '400: dropped, it would be refused again' );

	const tired = JSON.stringify( [ { key: 'visitor', at: Date.now(), tries: 8 } ] );
	next = boot( { storage: { leadkit_analytics_session: session(), leadkit_outbox: tired } } );
	await tick();
	eq( next.calls.length, 0, 'eight tries: given up' );
	const stale = JSON.stringify( [ { key: 'visitor', at: Date.now() - 31 * 864e5, tries: 1 } ] );
	next = boot( { storage: { leadkit_analytics_session: session(), leadkit_outbox: stale } } );
	await tick();
	eq( next.calls.length, 0, 'older than 30 days: given up' );

	p = boot( { html: '<a id="d" href="' + GL + '">Donate</a>', storage: { leadkit_analytics_session: session() }, noFetch: true } );
	p.w.document.getElementById( 'd' ).click();
	eq( p.beacons, [ VISITOR_URL ], 'no fetch: the beacon gets the post' );
	eq( JSON.parse( p.w.localStorage.getItem( 'leadkit_outbox' ) || '[]' ).length, 1, 'and the outbox still has it' );

	p = boot( { cfg: { visitorUrl: '' }, html: '<a id="d" href="' + GL + '">Donate</a>', storage: { leadkit_analytics_session: session() } } );
	p.w.document.getElementById( 'd' ).click();
	await tick();
	eq( p.calls.length, 0, 'no Visitors board: nothing posted' );
	ok( ( p.t.data.donate_clicks || [] ).length === 1, 'but the click is still logged' );

	// ---- the post stays small enough to send while the page unloads --------------
	const many = [];
	for ( let i = 0; i < 400; i++ ) {
		many.push( { id: 'v' + i, path: '/a-rather-long-page-path/that-goes-on-for-a-while/' + i + '/', active_time_ms: 12345, max_scroll_percent: 80, time: new Date( Date.parse( '2026-10-01T15:00:00Z' ) + i * 6e4 ).toISOString(), updated: 'x' } );
	}
	p = boot( { storage: { leadkit_analytics_session: session( { page_views: many } ) } } );
	const big = JSON.stringify( p.t.visitorBody() );
	ok( big.length < 60000, 'a long history still fits a keepalive request (< 60 KB)', big.length );
	eq( p.t.visitorBody().page_views.length, 100, 'last 100 page views' );

	// ---- Give Lively Branded widget -----------------------------------------------------
	p = boot( {
		url: 'https://3sgf.org/give/?utm_medium=email&utm_source=mailchimp&utm_campaign=light-the-path&utm_content=email-1',
		html: '<div data-widget-src="' + GL + '?ref=sd_widget" id="give-lively-widget" class="gl-branded-donation-widget"></div>',
		storage: { leadkit_analytics_session: session() },
	} );
	const host = p.w.document.getElementById( 'give-lively-widget' );
	const widgetSrc = host.getAttribute( 'data-widget-src' );
	eq( query( widgetSrc ).get( 'utm_source' ), DOC, 'Branded: data-widget-src stamped before the widget reads it' );
	eq( query( widgetSrc ).get( 'ref' ), 'sd_widget', 'Branded: its own ref kept' );
	// What branded_donation/<slug>.js does: dataset.widgetSrc + its params + the PAGE's utm_source.
	const frame = p.w.document.createElement( 'iframe' );
	const built = host.dataset.widgetSrc + '&widget_url=' + encodeURIComponent( p.w.location.href ) + '&referrer_url=&show_donate_now_button=false&show_v1_form=branded_widget&isWixEmbedded=false&utm_source=mailchimp';
	frame.setAttribute( 'src', built );
	frame.id = 'give-lively-widget-inner';
	host.appendChild( frame );
	await tick();
	eq( query( frame.getAttribute( 'src' ) ).getAll( 'utm_source' ), [ DOC ], 'Branded: the frame ends with only our utm_source' );
	eq( p.calls.length, 0, 'Branded: drawing the widget is not a click' );
	// A click inside the frame: the page loses focus to it.
	Object.defineProperty( p.w.document, 'activeElement', { configurable: true, get: () => frame } );
	p.w.dispatchEvent( new p.w.Event( 'blur' ) );
	await new Promise( ( r ) => setTimeout( r, 5 ) );
	p.w.dispatchEvent( new p.w.Event( 'blur' ) );
	await new Promise( ( r ) => setTimeout( r, 5 ) );
	eq( ( p.t.data.donate_clicks || [] ).map( ( c ) => c.how ), [ 'widget' ], 'Branded: focus moving into the frame is one Donate click, once' );

	// A Branded widget that drew itself before the tracker ran (src already built, undecorated).
	p = boot( {
		html: '<div id="give-lively-widget" class="gl-branded-donation-widget"><iframe id="give-lively-widget-inner" src="' + GL + '?ref=sd_widget&widget_url=x"></iframe></div>',
		storage: { leadkit_analytics_session: session() },
	} );
	await tick();
	eq( query( p.w.document.getElementById( 'give-lively-widget-inner' ).getAttribute( 'src' ) ).get( 'utm_source' ), 'visitorid.8f3k2q9a', 'Branded, drawn first: the frame is stamped (a typed-in visitor still gets the id)' );
	eq( ( p.t.data.donate_clicks || [] ).length, 0, 'Branded, drawn first: and that is not a click' );

	// ---- Give Lively Simple widget (shadow root; URL built on click) -------------------
	p = boot( {
		url: 'https://3sgf.org/give/?utm_medium=email&utm_source=mailchimp&utm_campaign=light-the-path&utm_content=email-1',
		html: '<div id="give-lively-widget" class="gl-simple-donation-widget"></div>',
		storage: { leadkit_analytics_session: session() },
	} );
	const d = p.w.document;
	const shell = d.createElement( 'gl-donation-widget-container' );
	d.getElementById( 'give-lively-widget' ).appendChild( shell );
	const root = shell.attachShadow( { mode: 'open' } );
	root.innerHTML = '<div class="gl-widget"><button class="gl-widget__donation-submit-button">Donate $25</button></div><dialog id="gl-widget-modal"><iframe id="gl-widget-modal-iframe" src=""></iframe></dialog>';
	const modal = root.getElementById( 'gl-widget-modal-iframe' );
	// What simple_donation/<slug>.js does on the Donate button.
	root.querySelector( 'button' ).addEventListener( 'click', () => {
		const q = new p.w.URLSearchParams( p.w.location.search );
		modal.src = GL + '/light-the-path?recurring=false&override_amount=25&widget_type=simple_donation&widget_url=' + encodeURIComponent( p.w.location.href ) + '&referrer_url=&' + q.toString() + '&isWixEmbedded=false';
	} );
	root.querySelector( 'button' ).click();
	await tick();
	const msrc = modal.getAttribute( 'src' );
	eq( query( msrc ).getAll( 'utm_source' ), [ DOC ], 'Simple: the pop-up frame gets our utm_source' );
	eq( query( msrc ).get( 'override_amount' ), '25', 'Simple: the widget\'s own parameters kept' );
	eq( ( p.t.data.donate_clicks || [] ).map( ( c ) => c.how ), [ 'widget' ], 'Simple: one Donate click' );
	await tick();
	eq( p.calls.length, 1, 'Simple: posted' );

	// ---- untracked pages ------------------------------------------------------------------
	p = boot( {
		cfg: { untrackedPaths: [ '/help/' ] },
		storage: {
			leadkit_analytics_session: session( {
				page_views: [ { id: 'a', path: '/help/', time: '2026-10-01T15:00:00Z' }, { id: 'b', path: '/help/hotline/', time: '2026-10-01T15:01:00Z' }, { id: 'c', path: '/helpful/', time: '2026-10-01T15:02:00Z' }, { id: 'd', path: '/hope/', time: '2026-10-01T15:03:00Z' } ],
				events: [ { type: 'photo_click', path: '/Help/', time: '2026-10-01T15:00:30Z' } ],
				utm: { source: 'x', landing: '/help/' },
			} ),
		},
	} );
	eq( p.t.data.page_views.filter( ( v ) => v.id !== p.t.viewId ).map( ( v ) => v.path ), [ '/helpful/', '/hope/' ], 'untracked: /help/ pages purged, /helpful/ kept' );
	eq( p.t.data.events.length, 0, 'untracked: events there purged (case-insensitive)' );
	eq( p.t.data.utm.landing, '', 'untracked: a landing there is blanked' );

	// ---- back from Give Lively -------------------------------------------------------------
	p = boot( { url: 'https://3sgf.org/thank-you/', referrer: 'https://secure.givelively.org/donate/3strands-global-foundation', storage: { leadkit_analytics_session: session() } } );
	const ret = p.t.data.events.filter( ( e ) => e.type === 'donation_return' );
	eq( ret.length, 1, 'donation return logged' );
	eq( p.t.visitorBody().donation_returns.length, 1, 'and sent with the next Visitors post' );

	console.log( ( failed ? 'FAILED' : 'ok' ) + ' — ' + passed + ' passed, ' + failed + ' failed' );
	process.exit( failed ? 1 : 0 );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 2 );
} );
