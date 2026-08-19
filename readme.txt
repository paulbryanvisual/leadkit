=== LeadKit — Lead Form & Visitor Tracking ===
Contributors: paulbryanvisual
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.2.1
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
  clicks, rage clicks, UTM capture. Nothing is sent for anonymous visitors.
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

`track_url` (default `/api/track-interaction`) — JSON POST on the first
identifying click:
  { "analyticsData": { …session… }, "interactionType": "phone_click"|"email_click",
    "interactionValue": "903-…"|"someone@…" }

`sync_url` (default `/api/sync-lead`) — JSON POST on visibility change for
known leads:
  { "analyticsData": { id, device, utm, page_views: [ { path, active_time_ms,
    max_scroll_percent, time } ], events: [ { type, value, time, path } ],
    started_at } }

== Settings ==

* Form submit endpoint, tracker sync endpoint, interaction endpoint.
* Turnstile site key — empty renders the form without the widget. Cloudflare's
  testing key `1x00000000000000000000AA` renders a dummy widget on any domain,
  which is useful for local layout parity.
* Storage prefix for the localStorage keys, in case two projects share a
  domain during development.

On first activation the plugin seeds its settings from the legacy
`roger_form_endpoint` / `roger_turnstile_sitekey` options when they exist, so
moving an existing site onto the plugin needs no re-typing.

== Changelog ==

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
