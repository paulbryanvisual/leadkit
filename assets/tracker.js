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
 *   { submitAction, syncUrl, trackUrl, storagePrefix, donationHosts, attributionDays,
 *     formHosts, historyParam }
 *
 * Campaigns: all five UTM fields are captured on every landing, the latest one
 * keeps the credit until a newer one arrives (or for attributionDays, when a
 * site sets a limit), and it is stamped onto every donation link (Give
 * Lively by default) packed into utm_source, the only field Give Lively keeps.
 * window.LeadKitAnalytics.attribution() / .packedSource() expose the same to
 * other scripts on the page.
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
	var SUBMIT_ACTION = cfg.submitAction || '/api/submit';

	var UTM_KEYS = [ 'source', 'medium', 'campaign', 'content', 'term' ];
	var CLICK_IDS = [ 'gclid', 'gbraid', 'wbraid', 'fbclid', 'msclkid' ];
	var GOOGLE_CLICK_IDS = [ 'gclid', 'gbraid', 'wbraid' ];
	var SEARCH_ENGINES = [ 'google', 'bing', 'duckduckgo', 'yahoo', 'ecosia', 'baidu', 'yandex' ];
	var PACK_SEP = '__';
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

			try {
				fetch( SYNC_URL, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( {
						visitor_id: this.data.id,
						lead_at: this.data.lead_at,
						lead_forms: this.data.lead_forms || [],
						page_views: views,
						device: this.data.device,
						utm: this.data.utm,
					} ),
					keepalive: true,
					credentials: 'omit',
				} );
			} catch ( e ) {
				console.error( 'Analytics sync failed', e );
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

			if ( ! this.data.referrer && document.referrer ) {
				try {
					var ref = new URL( document.referrer );
					if ( ref.host && ref.host !== window.location.host && ! this.isDonationUrl( ref ) ) {
						this.data.referrer = ref.host;
						changed = true;
					}
				} catch ( e ) {}
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
		 * Everything Give Lively needs, in the one field it keeps.
		 *
		 * Give Lively records utm_source and drops the rest, so the five fields
		 * travel packed into it: medium__source__campaign__content[__term].
		 * Fixed positions, double-underscore separator, trailing empties trimmed,
		 * an empty middle field written as "none". Reporting splits it back on
		 * "__". Values are folded to lowercase-and-dashes so no value can
		 * contain the separator.
		 *
		 * With no campaign it still says something useful — organic search,
		 * another site, or direct — rather than nothing.
		 */
		packedSource() {
			var t = this.attribution();
			var parts;

			if ( t ) {
				parts = [ t.medium, t.source, t.campaign, t.content, t.term ];
			} else if ( this.data.referrer ) {
				var host = this.data.referrer.replace( /^www\./, '' );
				var organic = SEARCH_ENGINES.some( function ( s ) {
					return host === s || host.indexOf( s + '.' ) === 0 || host.indexOf( '.' + s + '.' ) !== -1;
				} );
				parts = [ organic ? 'organic' : 'referral', host ];
			} else {
				parts = [ 'direct', 'website' ];
			}

			return this.packParts( parts );
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
		 * Each touch is packed exactly as utm_source is for Give Lively, so the
		 * same split works on both. URLs have practical length limits, so past
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
		 *   time_on_site           active seconds across the visit
		 *   photos_viewed          distinct photographs opened
		 *   device                 mobile / tablet / desktop and width
		 *   first_visit            date of the first visit (YYYY-MM-DD)
		 *   visitor_id             LeadKit's id, to join with lead records
		 */
		formParams() {
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
			} ] );

			out.pages_viewed = String( views.length );
			out.page_path = this.joinCapped( views.map( function ( v ) {
				return v.path;
			} ), PATH_MAX );
			out.page_detail = this.joinCapped( views.map( function ( v ) {
				return v.path + ' ' + Math.round( ( +v.active_time_ms || 0 ) / 1000 ) + 's ' + ( +v.max_scroll_percent || 0 ) + '%';
			} ), DETAIL_MAX );

			var ms = views.reduce( function ( sum, v ) {
				return sum + ( +v.active_time_ms || 0 );
			}, 0 );
			out.time_on_site = String( Math.round( ms / 1000 ) );

			var photos = ( d.photos_seen || [] ).length;
			if ( photos ) out.photos_viewed = String( photos );

			var w = ( d.device && d.device.width ) || window.innerWidth;
			out.device = ( w < 768 ? 'mobile' : w < 1100 ? 'tablet' : 'desktop' ) + ' ' + w + 'px';

			if ( d.started_at ) out.first_visit = String( d.started_at ).slice( 0, 10 );
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

		decorateDonationUrl( url ) {
			var t = this.attribution();
			if ( ! t && url.searchParams.get( 'utm_source' ) ) return null;

			var packed = this.packedSource();
			if ( url.searchParams.get( 'utm_source' ) === packed ) return null;

			url.searchParams.set( 'utm_source', packed );
			if ( t ) {
				// Harmless where they are ignored, and readable by analytics on
				// the far side where they are not.
				[ 'medium', 'campaign', 'content', 'term' ].forEach( function ( k ) {
					if ( t[ k ] ) url.searchParams.set( 'utm_' + k, t[ k ] );
				} );
			}
			return url.toString();
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

			// A new src reloads the frame: donation embeds only when there is a
			// real campaign; form embeds once, on load (their values are a
			// snapshot at that moment — a link click gets fresh ones).
			root.querySelectorAll( 'iframe[src]' ).forEach( function ( f ) {
				if ( f.hasAttribute( 'data-leadkit-keep' ) || f.hasAttribute( 'data-leadkit-done' ) ) return;
				var u;
				try {
					u = new URL( f.getAttribute( 'src' ), window.location.href );
				} catch ( e ) {
					return;
				}
				if ( self.isDonationUrl( u ) && ! self.attribution() ) return;
				if ( self.isFormUrl( u ) ) f.setAttribute( 'data-leadkit-done', '' );
				var next = self.decorateUrl( f.getAttribute( 'src' ) );
				if ( next ) f.setAttribute( 'src', next );
			} );
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

		init() {
			var self = this;

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
						var a = e.target && e.target.closest ? e.target.closest( 'a[href]' ) : null;
						if ( ! a || a.hasAttribute( 'data-leadkit-keep' ) ) return;
						var next = self.decorateUrl( a.getAttribute( 'href' ) );
						if ( next ) a.setAttribute( 'href', next );
					},
					true
				);
			} );

			// Active-time tracking.
			document.addEventListener( 'visibilitychange', function () {
				self.updateActiveTime();
				if ( document.visibilityState === 'hidden' ) {
					self.syncToServer();
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

				self.updateActiveTime();

				var hiddenInput = form.querySelector( 'input[name="analyticsData"]' );
				if ( ! hiddenInput ) {
					hiddenInput = document.createElement( 'input' );
					hiddenInput.type = 'hidden';
					hiddenInput.name = 'analyticsData';
					form.appendChild( hiddenInput );
				}
				hiddenInput.value = JSON.stringify( self.data );
				self.markLead( form.getAttribute( 'data-leadkit-form' ) || 'leadkit-form' );
			} );
		}
	}

	window.LeadKitAnalytics = new AnalyticsTracker();
})();
