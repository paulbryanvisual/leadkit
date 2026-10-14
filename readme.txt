=== LeadKit — Lead Form & Visitor Tracking ===
Contributors: paulbryanvisual
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.5.0
License: GPL-2.0-or-later

The lead-capture form and first-party visitor tracker, packaged to travel
between projects.

== Description ==

Two pieces, one plugin:

* **The lead form** — name / email / phone / project description, a hidden
  projectType, optional Cloudflare Turnstile (lazy-mounted on first interaction
  with the form, with its box height reserved so the mount costs zero layout
  shift). Render it with the `[leadkit_form]` shortcode or from a theme with
  `leadkit_form( array( 'class_prefix' => 'your-prefix' ) )` — every element
  class derives from the prefix, so a theme can adopt the markup into its own
  design system without forking it. With the default prefix a minimal
  stylesheet ships; with any other prefix the plugin emits no CSS at all.

* **The visitor tracker** — first-party behavioural context kept in
  localStorage: page views, active time per page, max scroll depth, photo
  clicks, rage clicks, campaign (UTM) touches. Nothing is sent for anonymous
  visitors. The latest campaign is stamped onto donation links (Give Lively by
  default) with the visitor id, packed into utm_source as labeled pairs:
  medium.email__source.mailchimp__campaign.light-the-path__content.email-1__visitorid.<id>
  Give Lively's embedded widgets (Branded and Simple) get it too.
  The session attaches to the lead at the moment one identifies themself: it is
  injected into the form as a hidden `analyticsData` field on submit, and the
  first `tel:` or `mailto:` click posts it with the interaction. After that,
  background syncs keep the lead's context current.

The server side is deliberately NOT in this plugin, so each project can host
it wherever it likes (the reference implementation is a set of Cloudflare
Pages Functions). Configure the three URLs under Settings → LeadKit.

== Endpoint contract ==

`submit_url` (default `/api/submit`) — receives the form POST
(`application/x-www-form-urlencoded` / multipart):
  name, email, phone, message, projectType, analyticsData (JSON string),
  cf-turnstile-response (when Turnstile is enabled — verify it server-side).

`track_url` (default: this plugin's `leadkit/v1/track`) — JSON POST on the first
identifying click:
  { "analyticsData": { …session… }, "interactionType": "phone_click"|"email_click",
    "interactionValue": "903-…"|"someone@…" }

`sync_url` (default: this plugin's `leadkit/v1/sync`) — JSON POST when the
page is hidden, for known leads: { visitor_id, lead_at, lead_forms,
page_views, device, utm }. Older builds sent:
  { "analyticsData": { id, device, utm, utm_first, utm_touches, referrer,
    page_views: [ { path, active_time_ms, max_scroll_percent, time } ],
    events: [ { type, value, time, path } ], started_at } }

`leadkit/v1/visitor` — JSON POST on each Donate click, when a Visitors board
is set (the tracker gets no URL otherwise). Queued in the browser and resent
on the next page load until a 2xx (or a 4xx other than 429):
  { visitor_id, started_at, referrer, utm, utm_first, utm_touches (last 30),
    page_views (last 100: id, path, active_time_ms, max_scroll_percent, time),
    referrers (last 30: domain, time, landing), first_referrer (when known),
    donate_clicks: [ { id, time, path, how: "link"|"widget" } ],
    lead_forms, donation_returns: [ { time, path } ], timezone }
Answers 200 once the row is written (or already said the same), 400/413 for
a body it will never take, 429 when braked, 502 when monday.com failed.

The packed source on donation links: key.value pairs in this order, "__"
between pairs (or "-" with the leadkit/packed_pair_separator filter), empty
pairs left out, values folded to [a-z0-9] and single dashes:
  medium · source · campaign · content · term · visitorid
scripts/zapier-code-step.js is the Code by Zapier step that splits it back.

A campaign touch (`utm`, `utm_first`, each of `utm_touches`) is
  { source, medium, campaign, content, term, click_id?, landing, time }
`utm` is the latest touch; sessions from 1.2.x carry only source / medium /
campaign in it. Event types include `donation_return` (value = the packed
campaign credited when the visitor came back from the donation host).

== Settings ==

* Form submit endpoint, tracker sync endpoint, interaction endpoint.
* Turnstile site key — empty renders the form without the widget. Cloudflare's
  testing key `1x00000000000000000000AA` renders a dummy widget on any domain,
  which is useful for local layout parity.
* Donation hosts — comma-separated domains whose links get the visitor's
  campaign (default givelively.org; subdomains match). Empty turns it off.
* monday.com Visitors board — one row per browser, keyed by visitorid, written
  on each Donate click and form submission: First and Last seen, Visits, First
  and Latest source, Donate clicks, Journey log, and connected to its form
  submissions and its person. Missing columns are created.
* monday.com Visits board — with a Visitors board, one row per visit (pages
  with no gap over 30 minutes), connected to its visitor: Started, Referrer,
  Source, Landing page, Pages, Seconds on site, Donate clicks, Forms, Journey.
* Untracked pages — path prefixes (e.g. /help/) where the tracker is not
  loaded and nothing is recorded or sent.
* Form hosts — comma-separated domains of outside forms that take hidden
  fields (default forms.monday.com, wkf.ms). They get utm_source … utm_term
  plus the campaign history parameter and first_touch, landing_page,
  referrer, click_id, form_page, pages_viewed, page_path, page_detail, time_on_site,
  photos_viewed, device, first_visit, timezone and visitor_id.
* History parameter — name of that parameter (default campaign_history).
* Campaign credit (days) — how long the latest campaign keeps the credit.
  0 (default) = for as long as the visitor's browser keeps the data.
* Storage prefix for the localStorage keys, in case two projects share a
  domain during development.

On first activation the plugin seeds its settings from the legacy
`roger_form_endpoint` / `roger_turnstile_sitekey` options when they exist, so
moving an existing site onto the plugin needs no re-typing.

== Changelog ==

= 1.5.0 =
* The referring site is recorded on every landing; with no campaign the source
  is medium.organic__source.<engine> or medium.referral__source.<domain>, and
  nothing for typed-in visits. A campaign keeps the credit over a later visit.
* Visitors board: First referrer and Latest referrer.
* monday.com Visits board: one row per visit, with its referrer and source,
  connected to the visitor's row.

= 1.4.0 =
* Donation links carry labeled pairs with the visitor id:
  medium.email__source.mailchimp__campaign.light-the-path__content.email-1__visitorid.<id>
  Give Lively's Branded and Simple widgets get the same.
* monday.com Visitors board: one row per browser, updated on every Donate click
  and form submission, connected to the submission and the person.
* Donate clicks that fail to send are queued in the browser and resent.
* Untracked pages: no tracker, nothing recorded or sent (e.g. /help/).

= 1.3.0 =
* Captures all five UTM fields plus Google Ads click IDs.
* The latest campaign keeps the credit for as long as the browser keeps the
  data (or a window you set); the first campaign and every touch are kept too.
  1.2.x sessions are migrated.
* Donation links (Give Lively by default) carry the visitor's campaign packed
  into utm_source, the one field Give Lively keeps.
* Outside forms (monday.com by default) get the UTM fields and the full
  campaign history, oldest first, as campaign_history.
* Return visits: the sync and track endpoints the tracker always called are
  now built in. Return visits are saved on LeadKit leads (optional email) and
  added to matching monday.com items (log, last seen, count, daily update).
* [leadkit_monday_form form="" board="" name=""]: a monday.com form rendered as
  plain, unstyled HTML on the site and submitted through the monday.com API,
  with all tracking values mapped to same-named columns.
* Returns from the donation form are logged with their campaign.
* Leads list has a Campaign column; campaign fields are stored as lead meta and
  shown in the notification email.

= 1.2.1 =
* The repository is public, so updates need no authentication. The GitHub token
  setting is removed, and a token stored by 1.2.0 is deleted from the database
  on upgrade. Revoke it on GitHub too — nothing here can do that for you.

= 1.2.0 =
* Block: "Lead form" can now be placed from the editor, with the heading,
  project types, labels and button text as block settings. The editor preview is
  the real rendered form, not a mock-up of one.
* The plugin updates itself from its own repository — new versions appear under
  Dashboard → Updates like any other plugin. Add a GitHub token in settings only
  while the repository is private.

= 1.1.0 =
* Submissions are handled by the plugin. LeadKit previously POSTed to an endpoint
  you configured elsewhere; when that endpoint moved, forms 404ed with no error
  anywhere. There is now a REST route, `leadkit/v1/submit`.
* Leads are stored as a `leadkit_lead` post type — visible in wp-admin the moment
  they arrive, and stored BEFORE the notification is attempted, so a mail failure
  cannot lose one.
* Notification email is HTML and carries the visitor's journey: the pages they
  read, how long for, how far they scrolled, the photographs they opened and
  whether they tapped the phone number.
* Optional delivery through an HTTPS mail API, for hosts whose own mail transport
  is unauthenticated or blocked.
* Turnstile is verified properly: `success`, `action` and `hostname`, failing
  closed when Cloudflare is unreachable.
* Several recipients, comma-separated.
* Honeypot field and a per-IP rate limit.
* Fix: on a page with two forms, only the first ever mounted a Turnstile widget.
* Fix: photographs viewed by advancing through a lightbox were not counted.

= 1.0.0 =
* First extraction from the Roger England theme: form renderer, Turnstile
  lazy-mount, AnalyticsTracker port, settings screen.
