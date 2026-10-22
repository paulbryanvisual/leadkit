/**
 * LeadKit visitor tracker.
 *
 * A faithful port of the production AnalyticsTracker (Astro build): first-party
 * behavioural context — page views, active time, scroll depth, photo clicks,
 * rage clicks, UTM capture — kept in localStorage and attached to the lead the
 * moment one identifies themself (form submit, tel: or mailto: click). Nothing
 * is sent for anonymous visitors except that first identifying interaction.
 *
 * Configuration arrives via window.LeadKitCfg (printed by the plugin):
 *   { submitAction, syncUrl, trackUrl, visitorUrl, storagePrefix, donationHosts,
 *     attributionDays, formHosts, historyParam, packPairSep }
 *
 * Campaigns: all five UTM fields are captured on every landing, the latest one
 * keeps the credit until a newer one arrives (or for attributionDays, when a
 * site sets a limit), and it is stamped onto every donation link (Give
 * Lively by default) packed into utm_source, the only field Give Lively keeps,
 * as labeled pairs with the visitor id:
 *   medium.email__source.mailchimp__campaign.light-the-path__content.email-1__visitorid.<id>
 * Each Donate click is posted to visitorUrl, which keeps the visitor's row on
 * a monday.com Visitors board; a post that fails is queued and resent on the
 * next page load. window.LeadKitAnalytics.attribution() / .packedSource()
 * expose the same to other scripts on the page.
 */
(function () {
	'use strict';

	if ( typeof window === 'undefined' ) {
		return;
	}

	var cfg = window.LeadKitCfg || {};
	var PREFIX = cfg.storagePrefix || 'leadkit';
	var SYNC_URL = typeof cfg.syncUrl === 'string' ? cfg.syncUrl : '';
	var TRACK_URL = typeof cfg.trackUrl === 'string' ? cfg.trackUrl : '';
	var VISITOR_URL = typeof cfg.visitorUrl === 'string' ? cfg.visitorUrl : '';
	var VISITS_URL = typeof cfg.visitsUrl === 'string' ? cfg.visitsUrl : '';
	var SUBMIT_ACTION = cfg.submitAction || '/api/submit';

	var UTM_KEYS = [ 'source', 'medium', 'campaign', 'content', 'term' ];
	var CLICK_IDS = [ 'gclid', 'gbraid', 'wbraid', 'fbclid', 'msclkid' ];
	var GOOGLE_CLICK_IDS = [ 'gclid', 'gbraid', 'wbraid' ];
	var SEARCH_ENGINES = [ 'google', 'bing', 'duckduckgo', 'yahoo', 'ecosia', 'baidu', 'yandex' ];
	// The hosts that are each engine's search (domains already without www.).
	var SEARCH_HOSTS = {
		google: /^google\.[a-z]{2,3}(?:\.[a-z]{2})?$/,
		bing: /(?:^|\.)bing\.com$/,
		duckduckgo: /^duckduckgo\.com$/,
		yahoo: /^(?:[a-z.]+\.)?search\.yahoo\.com$|^yahoo\.com$/,
		ecosia: /^ecosia\.org$/,
		baidu: /^baidu\.com$/,
		yandex: /^yandex\.[a-z]{2,3}$/,
	};
	// Positional packing (medium__source__campaign__content) for history strings.
	var PACK_SEP = '__';
	/*
	 * Labeled packing for donation links: key.value pairs. The key names and
	 * their order are fixed — monday.com formulas and the Zapier step look for
	 * them by name. The pair separator is "__" unless a site switches it to
	 * "-" (Give Lively stripping underscores is the one reason to).
	 */
	var PACK_KEYS = [ 'medium', 'source', 'campaign', 'content', 'term', 'visitorid' ];
	var PAIR_SEP = cfg.packPairSep === '-' ? '-' : '__';
	var PAIR_SPLIT = /-(?=(?:medium|source|campaign|content|term|visitorid)\.)/;
	var OUTBOX_MAX_TRIES = 8;
	var OUTBOX_MAX_AGE = 30 * 864e5;
	// Prefixes like "/help/". The plugin does not load this script on them;
	// this drops what a browser recorded there before they were set.
	var UNTRACKED = ( Array.isArray( cfg.untrackedPaths ) ? cfg.untrackedPaths : [] ).map( function ( p ) {
		return String( p ).toLowerCase();
	} );
	// 0 = no expiry: the latest campaign keeps the credit for as long as the
	// browser keeps the data. A positive number is a window in days.
	var ATTRIBUTION_DAYS = parseInt( cfg.attributionDays, 10 ) > 0 ? parseInt( cfg.attributionDays, 10 ) : 0;
	var HISTORY_SEP = ' > ';
	var HISTORY_MAX = 1500;
	var PATH_MAX = 600;
	var DETAIL_MAX = 1000;
	var LEAD_PARAM = 'leadkit_lead';
	var HISTORY_PARAM = typeof cfg.historyParam === 'string' ? cfg.historyParam : 'campaign_history';
	var FORM_HOSTS = ( Array.isArray( cfg.formHosts ) ? cfg.formHosts : [ 'forms.monday.com', 'wkf.ms' ] )
		.map( function ( h ) {
			return String( h ).trim().toLowerCase().replace( /^https?:\/\//, '' ).replace( /\/.*$/, '' );
		} )
		.filter( Boolean );
	var DONATION_HOSTS = ( Array.isArray( cfg.donationHosts ) ? cfg.donationHosts : [ 'givelively.org' ] )
		.map( function ( h ) {
			return String( h ).trim().toLowerCase().replace( /^https?:\/\//, '' ).replace( /\/.*$/, '' );
		} )
		.filter( Boolean );

	class AnalyticsTracker {
		constructor() {
			this.sessionKey = PREFIX + '_analytics_session';
			this.leadKey = PREFIX + '_is_lead';
			this.outboxKey = PREFIX + '_outbox';
			this.watchedRoots = window.WeakSet ? new WeakSet() : null;
			this.data = this.loadData();
			this.pageEnterTime = Date.now();
			this.lastActiveTime = Date.now();
			this.pageActiveTimeMs = 0;
			this.maxScroll = 0;
			this.viewStartedAt = new Date().toISOString();
			this.viewId = this.viewStartedAt + ' ' + Math.random().toString( 36 ).slice( 2, 8 );

			this.init();
		}

		loadData() {
			try {
				var stored = localStorage.getItem( this.sessionKey );
				if ( stored ) return JSON.parse( stored );
			} catch ( e ) {
				console.error( 'Analytics load error', e );
			}

			return {
				id: crypto.randomUUID ? crypto.randomUUID() : Math.random().toString( 36 ).substring( 2 ),
				device: this.getDeviceContext(),
				utm: {},
				page_views: [],
				events: [],
				started_at: new Date().toISOString(),
			};
		}

		/*
		 * Campaign touches are kept with no cap, so in principle storage can
		 * fill. When it does, the oldest MIDDLE touches go first (the first
		 * touch and the latest are what credit is built on), then the oldest
		 * page views — rather than failing to save anything at all.
		 */
		saveData() {
			for ( var attempt = 0; attempt < 6; attempt++ ) {
				try {
					localStorage.setItem( this.sessionKey, JSON.stringify( this.data ) );
					return;
				} catch ( e ) {
					if ( ! this.shrinkData() ) {
						console.error( 'Analytics save error', e );
						return;
					}
				}
			}
		}

		shrinkData() {
			var t = this.data.utm_touches;
			if ( t && t.length > 2 ) {
				var drop = Math.max( 1, Math.floor( ( t.length - 2 ) / 2 ) );
				t.splice( 1, drop );
				return true;
			}
			var v = this.data.page_views;
			if ( v && v.length > 20 ) {
				v.splice( 0, Math.floor( v.length / 2 ) );
				return true;
			}
			return false;
		}

		isLead() {
			try {
				return localStorage.getItem( this.leadKey ) === 'true';
			} catch ( e ) {
				return false;
			}
		}

		/**
		 * Mark this visitor as someone who identified themself, once.
		 *
		 * lead_at is the line the server draws: pages before it are the
		 * journey that led to the enquiry (sent with it), pages after it are
		 * return visits. Every later identification adds to lead_forms but
		 * never moves lead_at.
		 */
		markLead( form ) {
			try {
				localStorage.setItem( this.leadKey, 'true' );
			} catch ( e ) {}
			var now = new Date().toISOString();
			if ( ! this.data.lead_at ) this.data.lead_at = now;
			this.data.lead_forms = this.data.lead_forms || [];
			this.data.lead_forms.push( { form: String( form || 'form' ).slice( 0, 60 ), time: now, path: window.location.pathname } );
			this.saveData();
		}

		/**
		 * Return visits: a known lead's pages since they identified themself.
		 *
		 * Sent whenever the page is hidden or left. The current page is
		 * recorded (updated in place, never duplicated) before sending, so the
		 * server always has it. The server works out what is new; sending the
		 * whole session each time keeps this side stateless and lets a sync
		 * that failed simply be caught up by the next one.
		 */
		syncToServer() {
			if ( ! SYNC_URL || ! this.isLead() || ! this.data.lead_at ) return;

			this.recordPageView();

			var since = Date.parse( this.data.lead_at );
			var views = this.data.page_views.filter( function ( v ) {
				return v.time && Date.parse( v.time ) >= since;
			} );
			if ( ! views.length ) return;

			/*
			 * The whole recent story, as a Donate click sends it — so the
			 * server can keep the Visitors board current with return visits
			 * too — plus the lead's own fields. The server only takes pages
			 * after lead_at as return visits, and merges by id.
			 */
			var body = this.visitorBody();
			body.lead_at = this.data.lead_at;
			body.lead_forms = this.data.lead_forms || [];
			body.device = this.data.device;
			try {
				fetch( SYNC_URL, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( body ),
					keepalive: true,
					credentials: 'omit',
				} );
			} catch ( e ) {
				console.error( 'Analytics sync failed', e );
			}
		}

		/**
		 * Every visit, anonymous ones included (a Visits Worker set): the
		 * whole recent story, each time the page is hidden or left, straight
		 * to the Cloudflare Worker that keeps it in D1. WordPress later takes
		 * only the warm or known browsers on to monday.com. hidden and
		 * pagehide fire together, so a story that has not changed since the
		 * last post is not sent again; one that is lost is covered by the
		 * next, since the Worker merges. Sent as text/plain, a "simple"
		 * request: no CORS preflight, so one request per post, not two.
		 */
		postVisit() {
			if ( ! VISITS_URL || ! this.data.id ) return;
			this.recordPageView();
			var views = this.data.page_views || [];
			if ( ! views.length ) return;
			var last = views[ views.length - 1 ];
			var sig = [ views.length, last.id, last.active_time_ms, last.max_scroll_percent, ( this.data.donate_clicks || [] ).length, ( this.data.lead_forms || [] ).length ].join( '|' );
			if ( sig === this.lastVisitSig ) return;
			this.lastVisitSig = sig;
			var json = JSON.stringify( this.visitorBody() );
			try {
				fetch( VISITS_URL, {
					method: 'POST',
					headers: { 'Content-Type': 'text/plain;charset=UTF-8' },
					body: json,
					keepalive: true,
					credentials: 'omit',
					mode: 'cors',
				} ).catch( function () {} );
			} catch ( e ) {
				try {
					if ( navigator.sendBeacon ) navigator.sendBeacon( VISITS_URL, new Blob( [ json ], { type: 'text/plain;charset=UTF-8' } ) );
				} catch ( e2 ) {}
			}
		}

		/** The browser's IANA time zone, or ''. */
		timeZone() {
			try {
				return Intl.DateTimeFormat().resolvedOptions().timeZone || '';
			} catch ( e ) {
				return '';
			}
		}

		getDeviceContext() {
			return {
				userAgent: navigator.userAgent,
				width: window.innerWidth,
				height: window.innerHeight,
				platform: navigator.platform,
			};
		}

		/**
		 * The campaign this page view arrived with, or an empty touch.
		 *
		 * All five UTM fields, not the three the Astro build read — utm_content
		 * is the one that says WHICH email or post, and it is the field the
		 * reports are actually built on. Google Ads click IDs count as a touch
		 * too: an Ad Grant click auto-tagged with gclid often carries no UTMs at
		 * all, and without this it would be filed as no campaign.
		 */
		getUtmParams() {
			var q = new URLSearchParams( window.location.search );
			var touch = {};
			UTM_KEYS.forEach( function ( k ) {
				touch[ k ] = ( q.get( 'utm_' + k ) || '' ).trim();
			} );

			// A link built for Give Lively but pointed at this site carries the
			// whole campaign packed into utm_source. Read it as the fields it
			// holds; its visitorid belongs to whoever made the link, not to us.
			var packed = this.unpackSource( touch.source );
			if ( packed ) {
				UTM_KEYS.forEach( function ( k ) {
					touch[ k ] = packed[ k ] || ( k === 'source' ? '' : touch[ k ] );
				} );
			}

			var click = '';
			CLICK_IDS.forEach( function ( k ) {
				if ( ! click && q.get( k ) ) click = k;
			} );
			if ( click ) {
				touch.click_id = click;
				if ( ! touch.source && GOOGLE_CLICK_IDS.indexOf( click ) !== -1 ) {
					touch.source = 'google';
					touch.medium = touch.medium || 'cpc';
				}
			}

			return touch;
		}

		hasCampaign( touch ) {
			return !! ( touch && ( touch.source || touch.medium || touch.campaign || touch.content ) );
		}

		sameCampaign( a, b ) {
			if ( ! a || ! b ) return false;
			return UTM_KEYS.every( function ( k ) {
				return ( a[ k ] || '' ) === ( b[ k ] || '' );
			} );
		}

		/**
		 * Record the campaign in the URL, if there is one.
		 *
		 * The Astro build kept the FIRST source forever, which credits a donor's
		 * December gift to whatever brought them in last spring. Now:
		 *   utm         — the latest campaign (same key as before, so the email
		 *                 template and any existing endpoint still read it)
		 *   utm_first   — the first campaign ever, kept for the full story
		 *   utm_touches — every distinct campaign touch, newest last, kept for as
		 *                 long as the browser keeps the data
		 * A reload of the same URL is not a new touch.
		 */
		captureCampaign() {
			// Sessions stored by 1.2.x carry only `utm`: that was the first touch.
			var changed = false;
			if ( this.hasCampaign( this.data.utm ) && ! this.data.utm_first ) {
				this.data.utm_first = this.data.utm;
				changed = true;
			}
			if ( ! this.data.utm_touches ) {
				this.data.utm_touches = this.data.utm_first ? [ this.data.utm_first ] : [];
				changed = true;
			}

			/*
			 * The outside site that sent this landing, every time — not only
			 * the first. `referrer` stays the first ever (forms have always
			 * sent it); `first_referrer` is the very first visit's ('' when it
			 * was typed in); `referrers` is each landing from another site,
			 * newest last. A second landing from the same site within half an
			 * hour is the same visit, not a new one.
			 */
			var refDomain = this.referrerDomain();
			if ( ! ( 'first_referrer' in this.data ) && ! ( this.data.page_views || [] ).length ) {
				this.data.first_referrer = refDomain;
				changed = true;
			}
			if ( refDomain ) {
				if ( ! this.data.referrer ) this.data.referrer = refDomain;
				var refs = this.data.referrers = this.data.referrers || [];
				var lastRef = refs[ refs.length - 1 ];
				if ( ! ( lastRef && lastRef.domain === refDomain && Date.now() - Date.parse( lastRef.time ) < 30 * 6e4 ) ) {
					refs.push( { domain: refDomain, time: new Date().toISOString(), landing: window.location.pathname } );
					if ( refs.length > 30 ) refs.splice( 0, refs.length - 30 );
				}
				changed = true;
			}

			/*
			 * Back from the donation form. Give Lively redirects donors to the
			 * site's own thank-you page, and this is the one moment the site
			 * knows a gift happened — so it is logged with the campaign that had
			 * the credit, and announced for anything else on the page (an
			 * analytics tag, the thank-you copy) as `leadkit:donation-return`.
			 */
			try {
				if ( document.referrer && this.isDonationUrl( new URL( document.referrer ) ) ) {
					var credit = this.packedSource();
					this.logEvent( 'donation_return', credit );
					changed = false; // logEvent saved.
					document.dispatchEvent(
						new CustomEvent( 'leadkit:donation-return', {
							detail: { source: credit, attribution: this.attribution() },
						} )
					);
				}
			} catch ( e ) {}

			var touch = this.getUtmParams();

			/*
			 * A link from this same site that happens to carry UTMs (a /givingtuesday
			 * redirect built for a Facebook post, say) is not a new campaign — the
			 * visitor was already here. Counting it would hand their credit to
			 * whatever campaign that internal link was tagged with.
			 */
			try {
				if ( this.hasCampaign( touch ) && document.referrer && new URL( document.referrer ).host === window.location.host ) {
					touch = {};
				}
			} catch ( e ) {}

			if ( ! this.hasCampaign( touch ) ) {
				if ( changed ) this.saveData();
				return;
			}

			touch.landing = window.location.pathname;
			touch.time = new Date().toISOString();

			var last = this.data.utm_touches[ this.data.utm_touches.length - 1 ];
			if ( this.sameCampaign( last, touch ) && this.sameCampaign( this.data.utm, touch ) ) {
				// Same link again: refresh the clock (matters when a window is set).
				this.data.utm.time = touch.time;
				last.time = touch.time;
				this.saveData();
				return;
			}

			this.data.utm = touch;
			if ( ! this.hasCampaign( this.data.utm_first ) ) {
				this.data.utm_first = touch;
			}
			this.data.utm_touches.push( touch );
			this.saveData();
		}

		/**
		 * The campaign that should get credit right now, or null.
		 *
		 * The latest touch. By default it never expires: it keeps the credit
		 * until a newer campaign replaces it or the browser clears its storage.
		 * A site can set a window in days; a touch with no time (stored by
		 * 1.2.x) never expires even then, since its age is unknown.
		 */
		attribution() {
			var t = this.data.utm;
			if ( ! this.hasCampaign( t ) ) return null;
			if ( ATTRIBUTION_DAYS && t.time ) {
				var age = Date.now() - Date.parse( t.time );
				if ( age > ATTRIBUTION_DAYS * 864e5 ) return null;
			}
			return t;
		}

		/**
		 * The fields that get the credit right now.
		 *
		 * The latest campaign keeps it, even over a later visit from a search
		 * engine: the question these links answer is which email or post
		 * brought the donor. With no campaign, the source is filled the way
		 * Google Analytics fills it, from the latest outside site that sent
		 * them — medium organic and the engine's name for a search engine,
		 * medium referral and the domain for any other site — and left empty
		 * for someone who only ever typed the address in.
		 *
		 * @return {Object} { medium, source, campaign?, content?, term? } or {}
		 */
		creditFields() {
			var t = this.attribution();
			if ( t ) return t;
			var refs = this.data.referrers || [];
			return this.referrerFields( refs.length ? refs[ refs.length - 1 ].domain : this.cleanDomain( this.data.referrer ) );
		}

		/** organic + engine, referral + domain, or {} for no outside site. */
		referrerFields( domain ) {
			if ( ! domain ) return {};
			var engine = this.searchEngine( domain );
			return engine ? { medium: 'organic', source: engine } : { medium: 'referral', source: domain };
		}

		/*
		 * A search engine's own name, or ''. Search hosts only: mail.google.com
		 * (Gmail on the web) and news.google.com are sites that sent someone,
		 * not a search.
		 */
		searchEngine( domain ) {
			var d = String( domain || '' );
			var hit = '';
			SEARCH_ENGINES.some( function ( s ) {
				if ( SEARCH_HOSTS[ s ].test( d ) ) hit = s;
				return !! hit;
			} );
			return hit;
		}

		/** A host as a domain: lowercase, without www., m., l. and the like. */
		cleanDomain( host ) {
			return String( host || '' ).toLowerCase().replace( /:\d+$/, '' ).replace( /^(?:www\d?|m|l|lm|mobile|amp)\./, '' );
		}

		/**
		 * The domain of the outside site that sent this page view, or ''.
		 * Not this site (or its subdomains), not the donation form coming
		 * back, not an outside form redirecting back, not an app.
		 */
		referrerDomain() {
			if ( ! document.referrer ) return '';
			try {
				var ref = new URL( document.referrer );
				if ( ref.protocol !== 'https:' && ref.protocol !== 'http:' ) return '';
				var host = this.cleanDomain( ref.hostname );
				var own = this.cleanDomain( window.location.hostname );
				if ( ! host || host === own || host.slice( -( own.length + 1 ) ) === '.' + own ) return '';
				if ( this.isDonationUrl( ref ) || this.isFormUrl( ref ) ) return '';
				return host;
			} catch ( e ) {
				return '';
			}
		}

		/**
		 * Everything Give Lively needs, in the one field it keeps.
		 *
		 * Give Lively records utm_source and drops the rest, so the five fields
		 * and this visitor's id travel packed into it as labeled pairs:
		 *
		 *   medium.email__source.mailchimp__campaign.light-the-path__content.email-1__visitorid.<id>
		 *
		 * A period between key and value, "__" between pairs, empty pairs left
		 * out, always in this order. Values are folded to lowercase letters,
		 * numbers and single dashes, so no value can hold either separator
		 * (google.com becomes google-com). The visitorid is the same id the
		 * forms send, which is what joins a gift to the visitor's row.
		 *
		 * @param {Object} [fields] Campaign fields; the credited ones by default.
		 * @return {string}
		 */
		packedSource( fields ) {
			var self = this;
			fields = fields || this.creditFields();
			var pairs = [];
			PACK_KEYS.forEach( function ( k ) {
				var v = self.packValue( k === 'visitorid' ? self.data.id : fields[ k ] );
				if ( v ) pairs.push( k + '.' + v );
			} );
			return pairs.join( PAIR_SEP );
		}

		packValue( v ) {
			return String( v || '' )
				.toLowerCase()
				.replace( /[^a-z0-9]+/g, '-' )
				.replace( /^-+|-+$/g, '' );
		}

		/**
		 * A labeled packed source back into its fields, or null when the value
		 * is not one. Splits on "__" and on "-" before a known key, so either
		 * pair separator reads; unknown keys are ignored.
		 *
		 * @param {string} raw utm_source value.
		 * @return {Object|null}
		 */
		unpackSource( raw ) {
			raw = String( raw || '' );
			if ( ! /^(?:medium|source|campaign|content|term|visitorid)\./.test( raw ) ) return null;
			var out = {};
			raw.split( '__' ).forEach( function ( chunk ) {
				chunk.split( PAIR_SPLIT ).forEach( function ( pair ) {
					var dot = pair.indexOf( '.' );
					if ( dot < 1 ) return;
					var k = pair.slice( 0, dot );
					if ( PACK_KEYS.indexOf( k ) !== -1 && ! out[ k ] ) out[ k ] = pair.slice( dot + 1 );
				} );
			} );
			return out;
		}

		packParts( parts ) {
			parts = parts.map( function ( p ) {
				return String( p || '' )
					.toLowerCase()
					.replace( /[^a-z0-9.-]+/g, '-' )
					.replace( /^-+|-+$/g, '' );
			} );
			while ( parts.length && ! parts[ parts.length - 1 ] ) parts.pop();

			return parts
				.map( function ( p ) {
					return p || 'none';
				} )
				.join( PACK_SEP );
		}

		/**
		 * Every campaign this visitor has arrived from, oldest first, in one
		 * string — for a single form field a report can filter on ("contains
		 * light-the-path") or read as the visitor's path:
		 *
		 *   email__mailchimp__light-the-path__email-1 > social__instagram__light-the-path__post-3
		 *
		 * Each touch is packed by position (medium__source__campaign__content),
		 * as form reports have always split it; only donation links use the
		 * labeled pairs. URLs have practical length limits, so past
		 * HISTORY_MAX characters the oldest middle touches give way to "…" —
		 * the first touch and the most recent ones always stay.
		 */
		historyString() {
			var self = this;
			var touches = ( this.data.utm_touches || [] ).filter( function ( t ) {
				return self.hasCampaign( t );
			} );
			var packed = touches.map( function ( t ) {
				return self.packParts( [ t.medium, t.source, t.campaign, t.content, t.term ] );
			} );

			var out = packed.join( HISTORY_SEP );
			if ( out.length <= HISTORY_MAX ) return out;

			var head = packed[ 0 ];
			var tail = packed.slice( 1 );
			while ( tail.length > 1 && ( head + HISTORY_SEP + '…' + HISTORY_SEP + tail.join( HISTORY_SEP ) ).length > HISTORY_MAX ) {
				tail.shift();
			}
			return head + HISTORY_SEP + '…' + HISTORY_SEP + tail.join( HISTORY_SEP );
		}

		matchesHost( url, hosts ) {
			return hosts.some( function ( h ) {
				return url.hostname === h || url.hostname.slice( -( h.length + 1 ) ) === '.' + h;
			} );
		}

		isFormUrl( url ) {
			return this.matchesHost( url, FORM_HOSTS );
		}

		isDonationUrl( url ) {
			return this.matchesHost( url, DONATION_HOSTS );
		}

		/**
		 * Put the visitor's campaign on a donation URL.
		 *
		 * The visitor's attribution wins over whatever the link carried — a
		 * site's Donate button is the same link for everyone, and what matters
		 * is how THIS donor got here. With no attribution, a link that already
		 * has its own utm_source is left alone.
		 *
		 * @return {string|null} The new URL, or null when nothing changes.
		 */
		decorateUrl( raw ) {
			var url;
			try {
				url = new URL( raw, window.location.href );
			} catch ( e ) {
				return null;
			}
			if ( this.isDonationUrl( url ) ) return this.decorateDonationUrl( url );
			if ( this.isFormUrl( url ) ) return this.decorateFormUrl( url );
			return null;
		}

		/**
		 * A form that takes hidden fields (monday.com WorkForms by default):
		 * the five UTM fields of the campaign with the credit, each in its own
		 * parameter, plus the whole history in one. On a monday.com form each
		 * parameter becomes a column through a form tag of the same name.
		 */
		/**
		 * Everything the tracker knows, as flat form parameters — for an
		 * outside form (monday.com WorkForms by default) that can only take
		 * hidden values from its URL. On a monday.com form, a form tag with
		 * each name captures it as a text column. Empty values are left off.
		 *
		 *   utm_source … utm_term  the campaign with the credit
		 *   campaign_history       every campaign touch, oldest first
		 *   first_touch            the first campaign, packed
		 *   landing_page           where the credited campaign landed
		 *   referrer               the site they came from, if any
		 *   click_id               gclid / gbraid / wbraid / fbclid / msclkid
		 *   form_page              the page the form was opened from
		 *   pages_viewed           pages seen, this one included
		 *   page_path              those pages in order, " > "
		 *   page_detail            each page with active seconds and how far
		 *                          they scrolled: /protect/ 45s 80% > …
		 *   page_log               the same, each led by the UTC time they
		 *                          arrived: 2026-10-13T02:58:04Z /protect/ 45s 80%
		 *   time_on_site           active seconds across the visit
		 *   photos_viewed          distinct photographs opened
		 *   device                 mobile / tablet / desktop and width
		 *   first_visit            date of the first visit (YYYY-MM-DD)
		 *   timezone               the browser's time zone, e.g. America/Chicago
		 *   visitor_id             LeadKit's id, to join with lead records
		 */
		formParams( full ) {
			var self = this;
			var d = this.data;
			var out = {};
			var t = this.attribution();

			if ( t ) {
				UTM_KEYS.forEach( function ( k ) {
					if ( t[ k ] ) out[ 'utm_' + k ] = t[ k ];
				} );
				if ( t.landing ) out.landing_page = t.landing;
				if ( t.click_id ) out.click_id = t.click_id;
			}
			var hist = this.historyString();
			if ( hist && HISTORY_PARAM ) out[ HISTORY_PARAM ] = hist;
			if ( this.hasCampaign( d.utm_first ) ) {
				var f = d.utm_first;
				out.first_touch = this.packParts( [ f.medium, f.source, f.campaign, f.content, f.term ] );
			}
			if ( d.referrer ) out.referrer = d.referrer;

			out.form_page = window.location.pathname;

			// Every page so far, plus this one as it stands right now.
			this.updateActiveTime();
			this.handleScroll();
			var here = this.viewId;
			var views = ( d.page_views || [] ).filter( function ( v ) {
				return v.id !== here;
			} ).concat( [ {
				path: window.location.pathname,
				active_time_ms: this.pageActiveTimeMs,
				max_scroll_percent: this.maxScroll,
				time: this.viewStartedAt,
			} ] );

			out.pages_viewed = String( views.length );
			out.page_path = this.joinCapped( views.map( function ( v ) {
				return v.path;
			} ), PATH_MAX );
			out.page_detail = this.joinCapped( views.map( function ( v ) {
				return v.path + ' ' + Math.round( ( +v.active_time_ms || 0 ) / 1000 ) + 's ' + ( +v.max_scroll_percent || 0 ) + '%';
			} ), DETAIL_MAX );
			// The same, with the moment they arrived on each page (UTC).
			// Uncapped when the form posts to LeadKit; capped in a URL.
			var log = views.map( function ( v ) {
				var at = v.time ? String( v.time ).slice( 0, 19 ) + 'Z' : '';
				return ( at ? at + ' ' : '' ) + v.path + ' ' + Math.round( ( +v.active_time_ms || 0 ) / 1000 ) + 's ' + ( +v.max_scroll_percent || 0 ) + '%';
			} );
			out.page_log = full ? log.join( HISTORY_SEP ) : this.joinCapped( log, DETAIL_MAX );

			var ms = views.reduce( function ( sum, v ) {
				return sum + ( +v.active_time_ms || 0 );
			}, 0 );
			out.time_on_site = String( Math.round( ms / 1000 ) );

			var photos = ( d.photos_seen || [] ).length;
			if ( photos ) out.photos_viewed = String( photos );

			var w = ( d.device && d.device.width ) || window.innerWidth;
			out.device = ( w < 768 ? 'mobile' : w < 1100 ? 'tablet' : 'desktop' ) + ' ' + w + 'px';

			if ( d.started_at ) out.first_visit = String( d.started_at ).slice( 0, 10 );
			var tz = this.timeZone();
			if ( tz ) out.timezone = tz;
			if ( d.id ) out.visitor_id = d.id;

			return out;
		}

		/**
		 * Join with " > ", keeping the first entry and as many of the most
		 * recent as fit in max characters, with "…" where the middle was.
		 */
		joinCapped( parts, max ) {
			var all = parts.join( HISTORY_SEP );
			if ( all.length <= max || parts.length < 3 ) return all;
			var tail = parts.slice( 1 );
			var build = function () {
				return parts[ 0 ] + HISTORY_SEP + '…' + HISTORY_SEP + tail.join( HISTORY_SEP );
			};
			while ( tail.length > 1 && build().length > max ) tail.shift();
			return build();
		}

		decorateFormUrl( url ) {
			var params = this.formParams();
			var before = url.toString();
			Object.keys( params ).forEach( function ( k ) {
				url.searchParams.set( k, params[ k ] );
			} );
			var after = url.toString();
			return after === before ? null : after;
		}

		/*
		 * The visitor's campaign wins. With none, a link that carries its own
		 * campaign (a hand-built link on a page, packed or as plain UTMs) keeps
		 * it — and either way the packed source gets this visitor's id, so the
		 * gift can be joined to their row.
		 *
		 * Give Lively's Branded widget adds the page's own utm_source to its
		 * frame, which can leave two; set() leaves exactly ours. Nothing changes
		 * (null) when the URL already says the same, because a changed frame
		 * src reloads the frame.
		 */
		decorateDonationUrl( url ) {
			var t = this.attribution();
			var packed = this.packedSource( t || this.linkCampaign( url ) || undefined );
			var want = { utm_source: packed };
			if ( t ) {
				// Harmless where they are ignored, and readable by analytics on
				// the far side where they are not.
				[ 'medium', 'campaign', 'content', 'term' ].forEach( function ( k ) {
					if ( t[ k ] ) want[ 'utm_' + k ] = t[ k ];
				} );
			}

			var same = Object.keys( want ).every( function ( k ) {
				var have = url.searchParams.getAll( k );
				return have.length === 1 && have[ 0 ] === want[ k ];
			} );
			if ( same ) return null;

			Object.keys( want ).forEach( function ( k ) {
				url.searchParams.set( k, want[ k ] );
			} );
			return url.toString();
		}

		/**
		 * The campaign a donation link was built with, if any.
		 *
		 * @param {URL} url Donation URL.
		 * @return {Object|null}
		 */
		linkCampaign( url ) {
			var source = url.searchParams.get( 'utm_source' ) || '';
			var packed = this.unpackSource( source );
			if ( packed ) return packed;
			if ( source.indexOf( PACK_SEP ) !== -1 ) {
				// Packed by position, as LeadKit 1.3 wrote it.
				var p = source.split( PACK_SEP ).map( function ( v ) {
					return v === 'none' ? '' : v;
				} );
				return { medium: p[ 0 ], source: p[ 1 ], campaign: p[ 2 ], content: p[ 3 ], term: p[ 4 ] };
			}
			var own = { source: source };
			[ 'medium', 'campaign', 'content', 'term' ].forEach( function ( k ) {
				own[ k ] = url.searchParams.get( 'utm_' + k ) || '';
			} );
			return this.hasCampaign( own ) ? own : null;
		}

		/**
		 * Every donation link, button and embed on the page.
		 *
		 * Links are rewritten up front (so "copy link address" carries it) and
		 * again at the moment of the click (in case the attribution changed, or
		 * the link was added after load). Give Lively widgets read their target
		 * from data-widget-src; iframes are only touched when the URL would
		 * change, because a new src reloads the frame.
		 */
		decorateDonationLinks( root ) {
			var self = this;
			if ( ! root || ! root.querySelectorAll ) return;

			root.querySelectorAll( 'a[href]' ).forEach( function ( a ) {
				if ( a.hasAttribute( 'data-leadkit-keep' ) ) return;
				var next = self.decorateUrl( a.getAttribute( 'href' ) );
				if ( next ) a.setAttribute( 'href', next );
			} );

			root.querySelectorAll( '[data-widget-src]' ).forEach( function ( el ) {
				if ( el.hasAttribute( 'data-leadkit-keep' ) ) return;
				var next = self.decorateUrl( el.getAttribute( 'data-widget-src' ) );
				if ( next ) el.setAttribute( 'data-widget-src', next );
			} );

			// A new src reloads the frame: donation embeds only when the URL
			// would actually change; form embeds once, on load (their values are
			// a snapshot at that moment — a link click gets fresh ones).
			root.querySelectorAll( 'iframe[src]' ).forEach( function ( f ) {
				if ( f.hasAttribute( 'data-leadkit-keep' ) || f.hasAttribute( 'data-leadkit-done' ) ) return;
				var u;
				try {
					u = new URL( f.getAttribute( 'src' ), window.location.href );
				} catch ( e ) {
					return;
				}
				if ( self.isFormUrl( u ) ) f.setAttribute( 'data-leadkit-done', '' );
				var next = self.decorateUrl( f.getAttribute( 'src' ) );
				if ( next ) self.setFrameSrc( f, next );
			} );
		}

		/** Change a frame's src, remembering it was us (see watchFrames). */
		setFrameSrc( f, src ) {
			f.__leadkitWrote = src;
			f.setAttribute( 'src', src );
		}

		/**
		 * Donation forms that open in a frame on this page.
		 *
		 * Give Lively's Simple widget builds its donation URL at the moment of
		 * the click and sets it as the src of a frame in its pop-up — inside a
		 * shadow root, where nothing outside sees it. So every root the tracker
		 * meets (the page, and each open shadow root a click passes through) is
		 * watched for a frame src change: a donation URL gets the packed source,
		 * and the widget opening its form counts as a Donate click. A src this
		 * tracker wrote itself is neither.
		 *
		 * @param {Node} root document.body or a shadow root.
		 */
		watchFrames( root ) {
			var self = this;
			if ( ! root || ! window.MutationObserver || ! this.watchedRoots || this.watchedRoots.has( root ) ) return;
			this.watchedRoots.add( root );
			new MutationObserver( function ( records ) {
				records.forEach( function ( r ) {
					var f = r.target;
					if ( ! f || f.tagName !== 'IFRAME' || f.hasAttribute( 'data-leadkit-keep' ) ) return;
					var src = f.getAttribute( 'src' ) || '';
					if ( ! src || src === f.__leadkitWrote ) return;
					var u;
					try {
						u = new URL( src, window.location.href );
					} catch ( e ) {
						return;
					}
					if ( ! self.isDonationUrl( u ) ) return;
					var next = self.decorateUrl( src );
					if ( next ) self.setFrameSrc( f, next );
					// Only the widget's own pop-up is a click; a lazy-loaded
					// embed swapping in its src is not.
					if ( root.host || f.id === 'gl-widget-modal-iframe' ) {
						f.__leadkitClicked = true; // Not counted again when it takes focus.
						self.donateClick( 'widget' );
					}
				} );
			} ).observe( root, { subtree: true, attributes: true, attributeFilter: [ 'src' ] } );
		}

		/*
		 * The photograph itself, independent of which rendition was on screen.
		 *
		 * A thumbnail is foo-400x300.jpg and the lightbox shows foo-1024x768.jpg
		 * — the same picture. Counting the raw src would count one photograph
		 * twice, which is how "opened 6 photographs" happens when they opened
		 * three.
		 */
		photoStem( src ) {
			try {
				var file = decodeURIComponent( String( src ).split( '?' )[ 0 ].split( '/' ).pop() );
				return file
					.replace( /\.(jpe?g|png|webp|gif|avif)$/i, '' )
					.replace( /-\d+x\d+$/i, '' )
					.replace( /-scaled$/i, '' )
					.toLowerCase();
			} catch ( e ) {
				return String( src );
			}
		}

		/**
		 * Log a photograph the visitor chose to look at, once per photograph.
		 */
		logPhoto( src ) {
			if ( ! src ) return;
			var stem = this.photoStem( src );
			if ( ! stem ) return;

			this.data.photos_seen = this.data.photos_seen || [];
			if ( this.data.photos_seen.indexOf( stem ) !== -1 ) return;

			this.data.photos_seen.push( stem );
			this.logEvent( 'photo_click', src );
		}

		logEvent( type, value ) {
			this.data.events.push( {
				type: type,
				value: value,
				time: new Date().toISOString(),
				path: window.location.pathname,
			} );
			this.saveData();
		}

		trackDirectInteraction( type, value ) {
			if ( this.data.tracked_interaction ) return; // Prevent spamming if they click multiple times.

			this.updateActiveTime();
			this.logEvent( type, value );

			try {
				if ( TRACK_URL ) fetch( TRACK_URL, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( {
						analyticsData: this.data,
						interactionType: type,
						interactionValue: value,
					} ),
					keepalive: true,
				} );
				this.data.tracked_interaction = true;
				// Mark them as a lead since they initiated contact.
				this.markLead( type );
			} catch ( e ) {
				console.error( 'Analytics tracking failed', e );
			}
		}

		/**
		 * A Donate click: a donation link, or a Give Lively widget opening its
		 * form. Logged, then posted to the Visitors board endpoint.
		 *
		 * @param {string} how 'link' or 'widget'.
		 */
		donateClick( how ) {
			var now = new Date().toISOString();
			this.data.donate_clicks = ( this.data.donate_clicks || [] ).concat( [ {
				id: now + ' ' + Math.random().toString( 36 ).slice( 2, 8 ),
				time: now,
				path: window.location.pathname,
				how: how,
			} ] ).slice( -200 );
			this.recordPageView(); // Saves.
			this.postVisitor();
		}

		/**
		 * What the Visitors endpoint needs to write this visitor's row.
		 *
		 * The whole recent story each time, not what changed: the server merges
		 * it by id, so a post that is resent, or arrives twice, changes nothing,
		 * and one that never arrived is covered by the next. Kept well under the
		 * 64 KB a request sent while the page unloads may carry.
		 */
		visitorBody() {
			var d = this.data;
			var returns = ( d.events || [] ).filter( function ( e ) {
				return e.type === 'donation_return';
			} );
			var body = {
				visitor_id: d.id,
				started_at: d.started_at,
				referrer: d.referrer || '',
				referrers: ( d.referrers || [] ).slice( -30 ),
				utm: d.utm || {},
				utm_first: d.utm_first || {},
				utm_touches: ( d.utm_touches || [] ).slice( -30 ),
				page_views: ( d.page_views || [] ).slice( -100 ).map( function ( v ) {
					return { id: v.id, path: v.path, active_time_ms: v.active_time_ms, max_scroll_percent: v.max_scroll_percent, time: v.time };
				} ),
				donate_clicks: ( d.donate_clicks || [] ).slice( -50 ),
				lead_forms: ( d.lead_forms || [] ).slice( -20 ),
				donation_returns: returns.slice( -20 ).map( function ( e ) {
					return { time: e.time, path: e.path };
				} ),
				timezone: this.timeZone(),
			};
			// Absent on sessions from before 1.5, where the first visit is unknown.
			if ( 'first_referrer' in d ) body.first_referrer = d.first_referrer;
			return body;
		}

		/**
		 * Post this visitor to the Visitors endpoint, through the outbox.
		 *
		 * A Donate click usually leaves the page, so the post is written to the
		 * outbox first and only taken out when the server says it worked. A
		 * post the page did not live to see answered is sent again on the next
		 * page load — harmless, since the server merges.
		 */
		postVisitor() {
			if ( ! VISITOR_URL || ! this.data.id ) return;
			var box = this.loadOutbox().filter( function ( e ) {
				return e.key !== 'visitor';
			} );
			var entry = { key: 'visitor', at: Date.now(), tries: 0 };
			box.push( entry );
			this.saveOutbox( box );
			this.sendQueued( entry );
		}

		loadOutbox() {
			try {
				var box = JSON.parse( localStorage.getItem( this.outboxKey ) || '[]' );
				return Array.isArray( box ) ? box : [];
			} catch ( e ) {
				return [];
			}
		}

		saveOutbox( box ) {
			try {
				if ( box.length ) localStorage.setItem( this.outboxKey, JSON.stringify( box ) );
				else localStorage.removeItem( this.outboxKey );
			} catch ( e ) {}
		}

		/*
		 * The body is built when it is sent, not when it was queued: the
		 * latest story includes everything the queued one had.
		 */
		sendQueued( entry ) {
			var self = this;
			var url = entry.key === 'visitor' ? VISITOR_URL : '';
			if ( ! url ) return;

			entry.tries = ( entry.tries || 0 ) + 1;
			this.saveOutbox( this.loadOutbox().map( function ( e ) {
				return e.key === entry.key && e.at === entry.at ? entry : e;
			} ) );

			var json = JSON.stringify( this.visitorBody() );
			var done = function ( res ) {
				// Kept for another try: no answer, a server error, or "slow down".
				// A refusal (400) would be refused again, so it goes.
				if ( ! res || res.status >= 500 || res.status === 429 ) return;
				self.saveOutbox( self.loadOutbox().filter( function ( e ) {
					return ! ( e.key === entry.key && e.at === entry.at );
				} ) );
			};
			try {
				fetch( url, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: json,
					keepalive: true,
					credentials: 'omit',
				} ).then( done, function () {} );
			} catch ( e ) {
				// No fetch, or it refused the body: the beacon gets one try, and
				// the outbox keeps the post for the next page either way.
				try {
					if ( navigator.sendBeacon ) navigator.sendBeacon( url, new Blob( [ json ], { type: 'application/json' } ) );
				} catch ( e2 ) {}
			}
		}

		/** On page load: resend what an earlier page could not confirm. */
		flushOutbox() {
			var self = this;
			var now = Date.now();
			var box = this.loadOutbox().filter( function ( e ) {
				return e && e.key && ( e.tries || 0 ) < OUTBOX_MAX_TRIES && now - ( +e.at || 0 ) < OUTBOX_MAX_AGE;
			} );
			this.saveOutbox( box );
			box.forEach( function ( e ) {
				self.sendQueued( e );
			} );
		}

		updateActiveTime() {
			var now = Date.now();
			if ( document.visibilityState === 'visible' ) {
				this.pageActiveTimeMs += now - this.lastActiveTime;
			}
			this.lastActiveTime = now;
		}

		handleScroll() {
			var scrollHeight = document.documentElement.scrollHeight - window.innerHeight;
			if ( scrollHeight <= 0 ) {
				this.maxScroll = 100;
				return;
			}
			var scrollTop = window.scrollY || document.documentElement.scrollTop;
			var scrollPercent = Math.min( 100, Math.round( ( scrollTop / scrollHeight ) * 100 ) );
			if ( scrollPercent > this.maxScroll ) {
				this.maxScroll = scrollPercent;
			}
		}

		/*
		 * This page load's entry in page_views, created the first time and
		 * updated after that. It used to be pushed once, on beforeunload —
		 * which mobile browsers often never fire, and which runs after the
		 * last chance to send anything. Now it is written whenever the page
		 * is hidden too, and kept to one entry per load.
		 */
		recordPageView() {
			this.updateActiveTime();
			this.handleScroll();

			var entry = {
				id: this.viewId,
				path: window.location.pathname,
				active_time_ms: this.pageActiveTimeMs,
				max_scroll_percent: this.maxScroll,
				time: this.viewStartedAt,
				updated: new Date().toISOString(),
			};
			var views = this.data.page_views;
			var found = false;
			for ( var i = views.length - 1; i >= 0 && i >= views.length - 5; i-- ) {
				if ( views[ i ].id === this.viewId ) {
					views[ i ] = entry;
					found = true;
					break;
				}
			}
			if ( ! found ) views.push( entry );
			this.saveData();
		}

		isUntrackedPath( path ) {
			var p = ( '/' + String( path || '' ).toLowerCase().replace( /^\/+|\/+$/g, '' ) + '/' ).replace( '//', '/' );
			return UNTRACKED.some( function ( x ) {
				return p.indexOf( x ) === 0;
			} );
		}

		/** Forget anything recorded on a page that is now untracked. */
		purgeUntracked() {
			var self = this;
			if ( ! UNTRACKED.length ) return;
			var changed = false;
			[ 'page_views', 'events', 'lead_forms', 'donate_clicks' ].forEach( function ( k ) {
				if ( ! Array.isArray( self.data[ k ] ) ) return;
				var kept = self.data[ k ].filter( function ( e ) {
					return ! ( e && self.isUntrackedPath( e.path ) );
				} );
				if ( kept.length !== self.data[ k ].length ) {
					self.data[ k ] = kept;
					changed = true;
				}
			} );
			[ this.data.utm, this.data.utm_first ].concat( this.data.utm_touches || [] ).forEach( function ( t ) {
				if ( t && t.landing && self.isUntrackedPath( t.landing ) ) {
					t.landing = '';
					changed = true;
				}
			} );
			if ( changed ) this.saveData();
		}

		init() {
			var self = this;

			this.purgeUntracked();

			// Campaign touches: latest, first, and the run between.
			this.captureCampaign();

			// Donation links carry the campaign to Give Lively (or any host set
			// under Settings → LeadKit → Donation hosts).
			this.decorateDonationLinks( document );
			if ( window.MutationObserver ) {
				new MutationObserver( function ( records ) {
					records.forEach( function ( r ) {
						r.addedNodes.forEach( function ( n ) {
							if ( n.nodeType !== 1 ) return;
							if ( n.parentNode ) self.decorateDonationLinks( n.parentNode );
						} );
					} );
				} ).observe( document.body, { childList: true, subtree: true } );
			}
			[ 'click', 'auxclick', 'contextmenu' ].forEach( function ( type ) {
				document.addEventListener(
					type,
					function ( e ) {
						// Before the click reaches a widget inside a shadow root,
						// so its frame is watched when the widget sets the src.
						if ( type === 'click' && e.composedPath ) {
							e.composedPath().forEach( function ( n ) {
								if ( n && n.shadowRoot ) self.watchFrames( n.shadowRoot );
							} );
						}

						var a = e.target && e.target.closest ? e.target.closest( 'a[href]' ) : null;
						if ( ! a || a.hasAttribute( 'data-leadkit-keep' ) ) return;
						var next = self.decorateUrl( a.getAttribute( 'href' ) );
						if ( next ) a.setAttribute( 'href', next );
						if ( type !== 'contextmenu' ) {
							try {
								if ( self.isDonationUrl( new URL( a.getAttribute( 'href' ), window.location.href ) ) ) self.donateClick( 'link' );
							} catch ( err ) {}
						}
					},
					true
				);
			} );
			this.watchFrames( document.body );

			/*
			 * A click inside Give Lively's Branded widget — a frame from another
			 * site, where no click can be seen. The page loses focus to the
			 * frame, and that is the signal: once per frame per page.
			 */
			window.addEventListener( 'blur', function () {
				setTimeout( function () {
					var f = document.activeElement;
					if ( ! f || f.tagName !== 'IFRAME' || f.__leadkitClicked ) return;
					try {
						if ( ! self.isDonationUrl( new URL( f.getAttribute( 'src' ) || '', window.location.href ) ) ) return;
					} catch ( err ) {
						return;
					}
					f.__leadkitClicked = true;
					self.donateClick( 'widget' );
				}, 0 );
			} );

			// Anything an earlier page could not confirm it sent.
			this.flushOutbox();

			// Active-time tracking.
			document.addEventListener( 'visibilitychange', function () {
				self.updateActiveTime();
				if ( document.visibilityState === 'hidden' ) {
					self.syncToServer();
					self.postVisit();
				}
			} );

			// Scroll tracking (throttled).
			var scrollTimeout;
			window.addEventListener(
				'scroll',
				function () {
					if ( ! scrollTimeout ) {
						scrollTimeout = setTimeout( function () {
							self.handleScroll();
							scrollTimeout = null;
						}, 500 );
					}
				},
				{ passive: true }
			);
			this.handleScroll();

			// Page-unload tracking. pagehide is the one mobile Safari fires.
			window.addEventListener( 'beforeunload', function () {
				self.recordPageView();
			} );
			window.addEventListener( 'pagehide', function () {
				self.recordPageView();
				self.syncToServer();
				self.postVisit();
			} );

			/*
			 * Back from an outside form that identified them — a monday.com
			 * form set to redirect to /thank-you/?leadkit_lead=volunteer, say.
			 * The site cannot see inside the form, so this redirect is the one
			 * reliable signal that they submitted it. The parameter is removed
			 * from the address bar so a reload or a shared link does not
			 * repeat it.
			 */
			try {
				var q = new URLSearchParams( window.location.search );
				var lead = q.get( LEAD_PARAM );
				if ( lead ) {
					this.markLead( lead );
					q.delete( LEAD_PARAM );
					var rest = q.toString();
					if ( window.history && window.history.replaceState ) {
						window.history.replaceState( null, '', window.location.pathname + ( rest ? '?' + rest : '' ) + window.location.hash );
					}
				}
			} catch ( e ) {}

			/*
			 * Photographs viewed by advancing through a lightbox.
			 *
			 * The click handler below only sees images inside <main> or
			 * <article>. A lightbox lives outside the content — so opening one
			 * photograph and then browsing to five more with the arrows recorded
			 * exactly one, which is the opposite of the signal being collected:
			 * the person who looks at six photographs of the same wet room is the
			 * one worth ringing first.
			 *
			 * Watching the `src` attribute rather than the arrow buttons is
			 * deliberate. Every lightbox swaps the source of one <img>, whatever
			 * it calls its controls, so this also catches keyboard arrows and
			 * swipes — and works in a theme this plugin has never met.
			 */
			if ( window.MutationObserver ) {
				var viewer = new MutationObserver( function ( records ) {
					records.forEach( function ( r ) {
						var img = r.target;
						if ( ! img || img.tagName !== 'IMG' ) return;
						// Content images are already handled by the click below.
						if ( img.closest( 'main' ) || img.closest( 'article' ) ) return;
						var src = img.getAttribute( 'src' );
						if ( src ) self.logPhoto( src );
					} );
				} );
				viewer.observe( document.body, {
					subtree: true,
					attributes: true,
					attributeFilter: [ 'src' ],
				} );
			}

			// Photo clicks (lightbox images in the content), tel: and mailto: clicks.
			document.body.addEventListener(
				'click',
				function ( e ) {
					var targetImg = null;
					if ( e.target.tagName === 'IMG' ) {
						targetImg = e.target;
					} else {
						var btn = e.target.closest( 'button' );
						if ( btn && btn.querySelector( 'img' ) ) {
							targetImg = btn.querySelector( 'img' );
						}
					}

					if ( targetImg && ( targetImg.closest( 'main' ) || targetImg.closest( 'article' ) ) ) {
						if (
							targetImg.classList.contains( 'no-lightbox' ) ||
							targetImg.closest( '.main-header' ) ||
							targetImg.closest( '.main-footer' )
						) {
							return;
						}
						var src = targetImg.getAttribute( 'src' );
						if ( src ) {
							self.logPhoto( src );
						}
					}

					var link = e.target.closest( 'a' );
					if ( link ) {
						var href = link.getAttribute( 'href' ) || '';
						if ( href.indexOf( 'tel:' ) === 0 ) {
							self.trackDirectInteraction( 'phone_click', href.replace( 'tel:', '' ) );
						} else if ( href.indexOf( 'mailto:' ) === 0 ) {
							self.trackDirectInteraction( 'email_click', href.replace( 'mailto:', '' ) );
						}
					}
				},
				{ passive: true }
			);

			// Rage clicks.
			var clickCount = 0;
			var lastClickTime = 0;
			document.body.addEventListener(
				'click',
				function ( e ) {
					var now = Date.now();
					if ( now - lastClickTime < 400 ) {
						clickCount++;
						if ( clickCount === 4 ) {
							self.logEvent( 'rage_click', e.target.tagName + ( e.target.className ? '.' + e.target.className : '' ) );
							clickCount = 0;
						}
					} else {
						clickCount = 1;
					}
					lastClickTime = now;
				},
				{ passive: true }
			);

			// Attach the session to lead forms just before submit.
			document.addEventListener( 'submit', function ( e ) {
				var form = e.target;
				if ( form.tagName !== 'FORM' ) return;
				var action = form.getAttribute( 'action' ) || '';
				var isLeadForm =
					form.hasAttribute( 'data-leadkit' ) ||
					action === SUBMIT_ACTION ||
					action.indexOf( 'formspree' ) !== -1;
				if ( ! isLeadForm ) return;

				// This page, recorded before the session is packed into the
				// form — or the page the form was sent from is missing from
				// the journey.
				self.recordPageView();

				var hiddenInput = form.querySelector( 'input[name="analyticsData"]' );
				if ( ! hiddenInput ) {
					hiddenInput = document.createElement( 'input' );
					hiddenInput.type = 'hidden';
					hiddenInput.name = 'analyticsData';
					form.appendChild( hiddenInput );
				}
				hiddenInput.value = JSON.stringify( self.data );

				// A monday.com form rendered by LeadKit: every tracking value,
				// as the server maps them onto the board's columns by title.
				if ( form.hasAttribute( 'data-leadkit-monday' ) ) {
					var tracking = form.querySelector( 'input[name="tracking"]' );
					if ( ! tracking ) {
						tracking = document.createElement( 'input' );
						tracking.type = 'hidden';
						tracking.name = 'tracking';
						form.appendChild( tracking );
					}
					tracking.value = JSON.stringify( self.formParams( true ) );
				}
				self.markLead( form.getAttribute( 'data-leadkit-form' ) || 'leadkit-form' );
			} );
		}
	}

	window.LeadKitAnalytics = new AnalyticsTracker();
})();
