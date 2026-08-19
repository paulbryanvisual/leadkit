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
 *   { submitAction, syncUrl, trackUrl, storagePrefix }
 */
(function () {
	'use strict';

	if ( typeof window === 'undefined' ) {
		return;
	}

	var cfg = window.LeadKitCfg || {};
	var PREFIX = cfg.storagePrefix || 'leadkit';
	var SYNC_URL = cfg.syncUrl || '/api/sync-lead';
	var TRACK_URL = cfg.trackUrl || '/api/track-interaction';
	var SUBMIT_ACTION = cfg.submitAction || '/api/submit';

	class AnalyticsTracker {
		constructor() {
			this.sessionKey = PREFIX + '_analytics_session';
			this.leadKey = PREFIX + '_is_lead';
			this.data = this.loadData();
			this.pageEnterTime = Date.now();
			this.lastActiveTime = Date.now();
			this.pageActiveTimeMs = 0;
			this.maxScroll = 0;

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
				utm: this.getUtmParams(),
				page_views: [],
				events: [],
				started_at: new Date().toISOString(),
			};
		}

		saveData() {
			try {
				localStorage.setItem( this.sessionKey, JSON.stringify( this.data ) );
			} catch ( e ) {
				console.error( 'Analytics save error', e );
			}
		}

		syncToServer() {
			// Only sync in the background if they are already a known lead.
			if ( localStorage.getItem( this.leadKey ) !== 'true' ) return;
			if ( this.data.page_views.length === 0 && this.data.events.length === 0 ) return;

			this.updateActiveTime();

			try {
				fetch( SYNC_URL, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( { analyticsData: this.data } ),
					keepalive: true,
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

		getUtmParams() {
			var urlParams = new URLSearchParams( window.location.search );
			return {
				source: urlParams.get( 'utm_source' ) || '',
				medium: urlParams.get( 'utm_medium' ) || '',
				campaign: urlParams.get( 'utm_campaign' ) || '',
			};
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
				fetch( TRACK_URL, {
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
				this.saveData();

				// Mark them as a lead since they initiated contact.
				localStorage.setItem( this.leadKey, 'true' );
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

		recordPageView() {
			this.updateActiveTime();

			this.data.page_views.push( {
				path: window.location.pathname,
				active_time_ms: this.pageActiveTimeMs,
				max_scroll_percent: this.maxScroll,
				time: new Date().toISOString(),
			} );
			this.saveData();
		}

		init() {
			var self = this;

			// Track UTMs on first visit.
			var currentUtm = this.getUtmParams();
			if ( currentUtm.source && ! this.data.utm.source ) {
				this.data.utm = currentUtm;
				this.saveData();
			}

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

			// Page-unload tracking.
			window.addEventListener( 'beforeunload', function () {
				self.recordPageView();
			} );

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
			} );
		}
	}

	window.LeadKitAnalytics = new AnalyticsTracker();
})();
