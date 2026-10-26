=== LeadKit — Lead Form & Visitor Tracking ===
Contributors: paulbryanvisual
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.9.0
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
  medium.email__source.mailchimp__campaign.spring-appeal__content.email-1__visitorid.<id>
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

Visits Worker (`<Visits Worker URL>/visit`) — POST from every browser each
time a page is hidden or left, when a Visits Worker URL is set (the tracker
gets no URL otherwise). The same body as leadkit/v1/visitor, sent as
text/plain (no CORS preflight) and not queued, since the next post carries
everything. The Worker keeps it in Cloudflare D1. WordPress calls the
Worker's private routes with `Authorization: Bearer <LEADKIT_VISITS_SECRET>`:
  GET /warm?limit=25   { browsers: [ { visitor, hash, story, dk? } ] }, the
                       warm browsers whose story changed; dk { prefix, id,
                       key, first_click_at } only on a browser with a
                       matched donor key. &visitor=<id>: that browser alone,
                       if pending
  POST /sent           { sent: [ { visitor, hash } ] }, cleared only where
                       the hash still matches
  POST /known          { visitors: [ id ] }, a browser now on the Visitors board
  POST /donor          { visitor, dk }, a donor key WordPress matched: kept
                       on the browser's row, which is known from then on
  GET /stats           { browsers, warm, pending, sent }
Which browsers are warm is the Worker's WARM_RULES setting: by default
donate, form and known (a browser on the Visitors board); returning and
campaign can be added. A browser WordPress has written stays warm, so its
new visits keep coming.

The packed source on donation links: key.value pairs in this order, "__"
between pairs (or "-" with the leadkit/packed_pair_separator filter), empty
pairs left out, values folded to [a-z0-9] and single dashes:
  medium · source · campaign · content · term · visitorid
With a Donations board, LeadKit splits it back itself: a gift's "Packed
source (raw)" column fills its empty utm_medium … utm_term and visitorid
columns, so the Zap that creates the gift needs no Code step.
scripts/zapier-code-step.js is that Code by Zapier step, for a Zap that
still wants one.

`leadkit/v1/donor-key` — JSON POST { visitor_id, dk } from the tracker when
an email link brought a donor key, once the Visits Worker has that page's
visit; when both a Visits Worker and a Visitors board are set. Answers 200
before it looks the key up (and the same whether or not it is anyone's),
400 for a bad id or key, 413 over 4 KB, 429 over 10 an IP in ten minutes;
each browser is run at most once a minute (a newer key waits for the next
background run). The tracker resends it on the next page load until it gets
an answer other than 5xx or 429.

Donor keys — an email link can carry ?dk=<prefix>-<id>-<code>, lowercase:
b-22758-7cf7 (a CRM account) or m-13365350571-629a (a People item). The
whole key is the person's "Donor key" on the People board, which the
board's "Tracked link" formula puts on the link; LeadKit only reads that
column. No secret: a key is good when it is exactly one person's Donor key,
so a changed number matches no one. The tracker takes dk out of the address
bar on landing (every other parameter kept), keeps the latest well-formed
one with the visitor id, and sends it only to leadkit/v1/donor-key — never
to the Worker, on a donation link or in a packed source. WordPress looks it
up. No one's (or two people's): noted, and kept and sent nowhere. Someone's:
POST /donor to the Worker, then that browser is written at once, earlier
visits included, and: the Visitors row's Person and Open person become the
donor, never over a different person already there; and the donor's
"Clicked email link" is set to today if it is empty. No other People column
is written and none is created; the leadkit/donor_key_columns filter
changes the two titles. A key that cannot be looked up (monday.com or the
Worker not answering) waits in the leadkit_dk_pending option for the next
background run, for up to a week. The last 50 notes are in the
leadkit_dk_log option and the last 5 on the settings screen.

Email clicks — an officer's draft script puts each email's own id on its
link as utm_term: "e" and when the draft was made (e20261026-104312), and saves
the same id in the "Email ID" column of the Emailed subitem it adds under the
donor once the email is sent. When a browser's campaign touch carries such an
id, LeadKit finds that subitem on the People board's subitems board and sets
its "Clicked" date (the click's day, first time only). Two subitems with the
same id (drafts made in the same second): the one under the donor the
browser's donor key names. Not there yet: the id waits in the
leadkit_email_clicks option and is retried each background run for three
days. Columns are found by title and never created; the
leadkit/email_click_columns filter changes the titles. Notes go with the donor
keys' notes.

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
* monday.com Donations board — with a People board, each background run
  LeadKit links the newest gifts: unpacks "Packed source (raw)" into the
  empty utm_ and visitorid columns the board has (none is created),
  connects the gift's Visitor by visitorid (retried for a week while the
  browser's row is not written yet), finds or creates the donor by email —
  named from the gift's "Donor name" column when it has one (else the
  gift's own name), and copying each other "Donor X" column to People's "X"
  column, text to text and a phone column's value, as stored, to a phone
  column — gives the gift's
  browser the same person, and fills Open person links. Its Person column
  must be two-way for People's Total given.
* Group for new people — the People group a donor LeadKit makes goes in, by
  title or id. Empty = the board's top group. A name the board does not have
  stops LeadKit making donors (the settings screen says so) rather than put
  them in the wrong group.
* Wait before making a donor (minutes) — how long a gift with no Person is
  left to Zapier and monday.com automations first (default 10, 0–60).
* Background runs — every 15 minutes (default) or 5, for the gift linker and
  the warm-browser sender. A changed setting reschedules both. On a cached
  host, run wp-cron.php from a real cron at the same interval.
* Column ids are cached a day, and forgotten — found again by title — when
  monday.com refuses one (InvalidColumnIdException), when a read leaves a
  cached column out, and when the settings change.
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

== Changelog ==

= 1.9.1 =
* Email clicks: an officer email's id on its link (utm_term e<date>-<time>)
  sets "Clicked" on that email's Emailed subitem, the first time the donor
  opens it. Waits up to three days for the subitem to appear.

= 1.9.0 =
* Donor keys: an email link with ?dk=<the person's Donor key> ties the
  browser to the donor. The tracker takes the key out of the address bar
  and posts it to the new leadkit/v1/donor-key route; WordPress matches the
  whole key against the People board's Donor key column (no secret). On a
  match the Visits Worker keeps it (POST /donor) and the browser is written
  to monday.com at once with every earlier visit, its Person and Open
  person set to the donor unless someone else is there already, and the
  donor's "Clicked email link" set if empty. Needs Visits Worker 2026-10-26
  or later (migration 0002, POST /donor).

= 1.8.1 =
* A person the gift linker makes is named from the gift's "Donor name"
  column when the board has one, so gifts can be named the way gift
  history is ("Oct 24, 2026 – $50 – Jane Doe") without that becoming the
  person's name.

= 1.8.0 =
* Gift linker does the general linking, so Zapier only has to create the
  gift: it unpacks the packed source into the gift's utm and visitorid
  columns, connects the gift's Visitor by visitorid, and puts a donor it
  makes in a chosen group with their phone and address.
* Settings: Group for new people, Wait before making a donor, and
  Background runs (every 15 or 5 minutes).
* Column ids heal themselves: a renamed or deleted column is found again by
  title on the next run, not a day later.

= 1.7.1 =
* "Check again" under Dashboard → Updates finds a new LeadKit release at
  once, instead of up to twelve hours later.
* Help text: visits stay in the Visits Worker until a browser reaches a goal;
  the Worker's WARM_RULES setting decides which.

= 1.7.0 =
* Visits Worker: every browser's visits go straight to a Cloudflare Worker
  that keeps them in D1, anonymous ones included; nothing touches
  WordPress. Every 15 minutes LeadKit fetches the browsers that turned warm
  (came back, arrived on a campaign link, clicked Donate or sent a form) and
  writes them to the Visitors and Visits boards with their whole history.
  Off until a Worker URL is set.
* Generic out of the box: no client's name or address in the plugin's text;
  every difference between sites is a setting. First activation no longer
  copies settings from an old theme. By Paul Bryan, PrettyGrand.com.

= 1.6.0 =
* Gift linker: finds or creates donors on People (after giving Zapier 10
  minutes), connects each gift's browser to its person, and fills Open person
  links — every 15 minutes.
* Open person links on form submissions, Visitors and Visits rows, and gifts.
* Return visits keep the Visitors and Visits boards current.
* The page a form is sent from is part of the journey.

= 1.5.0 =
* The referring site is recorded on every landing; with no campaign the source
  is medium.organic__source.<engine> or medium.referral__source.<domain>, and
  nothing for typed-in visits. A campaign keeps the credit over a later visit.
* Visitors board: First referrer and Latest referrer.
* monday.com Visits board: one row per visit, with its referrer and source,
  connected to the visitor's row.

= 1.4.0 =
* Donation links carry labeled pairs with the visitor id:
  medium.email__source.mailchimp__campaign.spring-appeal__content.email-1__visitorid.<id>
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
* First extraction from a client theme: form renderer, Turnstile
  lazy-mount, AnalyticsTracker port, settings screen.
