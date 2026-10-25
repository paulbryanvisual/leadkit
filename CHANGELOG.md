# Changelog

## 1.8.0

The gift linker does the general linking, so a Zap only has to create the
gift — one Zapier task instead of about five.

- **Unpacks the packed source.** For each gift it has not finished, LeadKit
  reads "Packed source (raw)" (the `utm_source` a donation link carried) and
  fills the gift's empty `utm_medium`, `utm_source`, `utm_campaign`,
  `utm_content`, `utm_term` and `visitorid` columns with
  `leadkit_unpack_source()`. Only text columns the board already has, and
  only empty ones: a column filled by hand is kept, and a field with no
  column is left out — none is created.
- **Connects the gift's Visitor** by visitorid, the rows for every waiting
  gift found in one request (`leadkit_visitor_find_rows()`), and in the
  same run gives that browser the donor. A gift whose browser's row is not
  written yet is tried again each run for a week, as before. No monday.com
  visitorid automation is needed any more.
- **New donors** go in **Group for new people** (Settings → LeadKit; a
  group's title or id), not the People board's top group. A name the board
  does not have stops LeadKit making donors, and the settings screen says
  so, rather than put them in the wrong group; their gifts are linked once
  it is fixed. Each "Donor X" column on the gift is copied to People's "X"
  column where both exist: text to text, and a phone to a phone column only
  with its country, so a number monday.com would refuse cannot stop the
  person being made.
- **Wait before making a donor (minutes)**: the grace a gift with no Person
  gets before LeadKit finds or makes the donor, 10 by default as before
  (`LEADKIT_GIFT_GRACE`), 0–60. 2 is enough once Zapier only creates the
  gift.
- **Background runs**: every 15 minutes (default) or 5, for the gift linker
  and the warm-browser sender (`leadkit_five_minutes`, 300 seconds). Each
  hook is moved to the new schedule on the next page load after the setting
  changes (`leadkit_keep_scheduled()`).
- **Column ids heal themselves.** Every cached column-id transient carries
  a generation number (`leadkit_monday_columns_key()`), and
  `leadkit_monday_forget_columns()` moves it on. That happens when
  monday.com refuses a column id (`InvalidColumnIdException`, in the error
  list or at the top level as the older API sends it), when the linker's
  read leaves out a cached column (monday.com silently drops ids that no
  longer exist), and when LeadKit's settings change. A renamed or deleted
  column is found again by title on the next run instead of up to a day
  later. The return-visit, People-roll-up and summary column caches use the
  same keys.
- The fake monday.com in `scripts/wp-stubs.php` now behaves like the real
  one: a read leaves out column ids the board does not have, a write to one
  is refused with `InvalidColumnIdException`, and items keep their group.
  `scripts/check-visitors.php` (section 12b–c) and
  `scripts/check-browsers.php` (schedule, settings) cover the above.

## 1.7.1

"Check again" finds a new LeadKit at once, and the help text says visits stay
in D1 until a goal.

- **Check again** under Dashboard → Updates (the network's too) asks GitHub
  afresh instead of the twelve-hour cache, so a new release shows at once,
  also when WordPress checked less than a minute before. Only for users who
  can update plugins. Until now the cache was cleared only after an update,
  so a new release could take up to twelve hours to appear.
- Visits stay in Cloudflare D1 until a browser reaches a goal: the help text
  and readme now describe the Visits Worker's `WARM_RULES` setting (by default
  donate, form and known; returning and campaign can be added) instead of
  listing returning visitors and campaign arrivals as warm. A browser
  WordPress has written stays warm, so its new visits keep coming.
- `scripts/check-updater.php` checks the update offer and Check again against
  a fake GitHub.

## 1.7.0

Every visit kept in Cloudflare D1; only warm or known browsers come to
monday.com.

- **Visits Worker URL** (Settings → LeadKit, empty by default). With it set,
  the tracker posts the browser's story straight to that Cloudflare Worker
  each time a page is hidden or left, for every browser, not only after a
  Donate click or a form. WordPress and its database never see that
  traffic. Sent as text/plain, so there is no CORS preflight: one request
  per post. A URL that is not https://, or has a ? or #, is refused with a
  notice and the one saved before is kept, so a typo cannot turn it off.
- **The Worker** (deployed per client in the client's Cloudflare account) keeps one row per browser
  in D1 and decides which are warm: came back (2 visits by default),
  arrived on a campaign link, clicked Donate, sent a form, or marked known.
  It forgets cold browsers after 13 months unseen. Those rules are its
  settings, not WordPress's.
- **Warm browsers come to monday.com.** Every 15 minutes
  (`leadkit_send_warm`, on `leadkit_quarter_hour`) LeadKit fetches up to 25
  warm browsers whose story changed (`GET /warm`) and writes each with the
  same writer a Donate click uses, so the Visitors row and every Visits row
  arrive at once, earlier anonymous visits included. Then it reports them
  sent by hash (`POST /sent`), so a story that changed meanwhile stays
  pending. One monday.com refused is not reported and is tried again next
  run. The batch size is the `leadkit/browsers_per_run` filter.
- **A new Visitors row is marked known** in the Worker (`POST /known`, fire
  and forget), so that browser stays warm from then on.
- **The secret** for the Worker's private routes: `LEADKIT_VISITS_SECRET` in
  wp-config.php, or the write-only **Visits Worker secret** setting. The
  monday.com token never leaves WordPress.
- The settings screen shows the Worker's counts: browsers kept, warm, sent
  and waiting (cached five minutes). It waits three seconds at most for
  them and remembers a failure for a minute, so a Worker that is down does
  not slow the screen down; saving the settings asks again.
- `scripts/check-browsers.php` checks the WordPress side against a fake
  Worker.
- **Generic out of the box.** No client's name or address in the plugin's
  own text: help-text examples are example.org, and the notes here name no
  site. Every difference between sites is in Settings → LeadKit. The first
  activation no longer copies settings from one client's old theme; a new
  site starts from the defaults. By Paul Bryan, PrettyGrand.com (the plugin
  header and the update details).

Needs a real cron on cached hosts, as the gift linker does (SiteGround:
wp-cron.php every 15 minutes).

## 1.6.0

The connections monday.com cannot make by itself, made by LeadKit.

- **Gift linker** (Settings → LeadKit → monday.com Donations board, with a
  People board). Every 15 minutes LeadKit reads the newest 100 gifts and, for
  up to 25 it has not finished:
  1. no Person 10 minutes after the gift arrived: finds the donor on People
     by Donor email (any case), or creates them, and sets the gift's Person.
     The wait lets the Zap's own find-or-create and the email match go first;
     LeadKit creating the donor while the Zap does would make two people;
  2. fills the gift's **Open person** link;
  3. a Visitor whose row has no Person: gives that browser the gift's
     person, with Open person on its row and its visit rows, and LeadKit's own
     record of the browser learns the person. A browser that already belongs
     to someone (a shared computer) is left alone; the gift is on the right
     person by email anyway.
  A gift is finished when that is done, or after a week for one with no
  email or no visitor (a QR-code gift). A failed lookup creates nobody; the
  next run tries again. Scheduled on `leadkit_quarter_hour`, cleared when the
  setting is emptied or the plugin is deactivated.
- **Open person** links (a link column, created if missing): one click opens
  the person's own card, which monday.com's connect columns cannot do (their
  pop-up links only to the board). Written on form submissions, on the
  Visitors row and every visit row when a person is connected, and on gifts.
- **Return visits keep the Visitors board current.** The tracker's sync now
  sends the whole recent story (as a Donate click does) plus the lead fields;
  the server updates the browser's Visitors row and visit rows, at most once
  a minute per browser, after answering. Only for browsers that already have
  a row, and only from a 1.6 tracker (an older one sends just the pages since
  the form, which would read as the whole history).
- **The page a form is sent from** is in the journey now: the tracker records
  the page before packing the session into the form.
- Visit rows are written after the browser's person is connected, so they
  can carry the person's link.
- On a sandbox: Donations' Person column was a one-way connection, so
  People's Total given never counted a gift whose Person was set by the
  automation or Zapier (only gifts linked from both sides by hand). It is now
  two-way; People's new "Donations" column fills itself and Total given sums
  it. The old one-way column is renamed "Donations (old – one-way)".

How the work splits with a donations Zap (Give Lively → Code → Create Item on
Donations → Find Items on People → Filter "not found" → Create Item on People
→ Update Item on Donations): the Zap creates a new donor and fills that
gift's Person and Open person at once. For a returning donor the Filter stops
the Zap, the email match fills Person, and LeadKit fills Open person. LeadKit
alone gives the gift's browser its person, and is the backstop when a Zap
step fails.

Tried and rejected:

- Zapier creating the person in one step: its monday.com search ("Find
  Items", formerly "Get Item by Column Value") has no create-if-not-found, so
  the Zap needs Find Items + a Filter + Create Item (Paths are not needed).
- monday.com's match automation creating the person: the template's only
  options are overwrite or add a match; it never creates.
- monday.com automations giving the gift's browser the gift's person: that
  changes a different item from the one that triggered the automation.
- A monday.com formula building the person's address: formulas cannot be
  written or filtered through the API, and a link column needs a value.

Note: WordPress's scheduler only runs on requests that reach PHP. On a site
SiteGround caches, set a real cron (Site Tools → Devs → Cron Jobs, wp-cron.php
every 15 minutes) so the gift linker runs on time.

## 1.5.0

Where each visit came from, one row per visit.

- **The referring site, on every landing.** The tracker records the domain of
  the outside site that sent each landing (`referrers`, the last 30, domain
  only: `l.facebook.com` and `m.facebook.com` are `facebook.com`), and the
  very first visit's (`first_referrer`, empty when it was typed in). This
  site and its subdomains, the donation form coming back, outside forms
  redirecting back and apps are not referrers. A second landing from the same
  site within 30 minutes is the same visit. `referrer` stays the first ever,
  as forms have always had it.
- **The source when there is no campaign, the way Google Analytics fills
  it:** `medium.organic__source.google` (the engine's name) for a search
  engine, `medium.referral__source.<domain>` for any other site, from the
  latest site that sent them — and nothing for someone who only ever typed
  the address in (the donation link then carries `visitorid.<id>` alone).
  Search hosts only: Gmail on the web (`mail.google.com`) and Google News are
  referrals. 1.4.0 wrote `source.google-com` and `medium.direct__source.website`.
- **A campaign keeps the credit** over a later visit from a search engine or
  another site (inside the campaign-credit window, if one is set). The plan
  asks which email or post brought each donor; both referrers are recorded
  either way.
- **Visitors board: First referrer and Latest referrer** columns.
- **monday.com Visits board** (Settings → LeadKit). One row per visit — a run
  of pages with no gap over 30 minutes — connected to its row on the Visitors
  board (Visitor): visitid, visitorid, Started (UTC, shown in each viewer's
  zone), Referrer, Source (the campaign link the visit arrived on, else the
  site that sent it, else blank), Landing page, Pages, Seconds on site,
  Donate clicks, Forms and the Journey. Named "Oct 14, 2026 9:58am · Email ·
  spring-appeal · email-1". Written with the Visitors row, earlier visits
  from the browser's story included: up to 40 a write, 20 to a request,
  newest first, the rest on the next post. A visit this site has no row for
  is looked up by visitid first, so lost local state cannot double a row; a
  row deleted on monday.com is made again only when that visit changes. The
  Visitors row stays the one gifts, forms and people connect to: one row per
  visit could not take Donations' exact visitorid match.
- The Visitors board's Journey log says where each visit came from for
  referral visits too ("Referral · facebook.com"), and form summaries no
  longer call Gmail an organic search.
- `leadkit_monday()` can return what succeeded in a request where part
  failed (`$partial`), for batches of aliased mutations.
- On a sandbox: a Visits board was created with these
  columns; its connection made a "link to …" column on Visitors, renamed
  "Visit rows" — not "Visits", which is already the count column there, and
  columns are found by title.

## 1.4.0

Every Give Lively gift can now be joined to the visits and forms before it.
Built for a nonprofit's giving plan: a gift arrives on a monday.com
Donations board through Zapier, and its packed source carries the same visitor
id the forms send.

- **Labeled packed source on donation links.** `utm_source` is now key.value
  pairs with the visitor id, in a fixed order, empty pairs left out:
  `medium.email__source.mailchimp__campaign.spring-appeal__content.email-1__visitorid.<id>`.
  Values fold to lowercase letters, numbers and single dashes (`google.com`
  becomes `google-com`), so neither separator can appear inside one. Every
  stamp carries the visitor id, including direct visitors
  (`medium.direct__source.website__visitorid.…`). With no campaign of their
  own, a link's own campaign is kept and gets the id — a hand-built packed
  link, plain UTMs, or a 1.3 positional `utm_source`. A site page reached
  with a packed `utm_source` reads it as its fields and ignores its visitorid.
  `campaign_history`, `first_touch` and `_leadkit_utm_packed` stay positional,
  as form reports split them. Key names are fixed: monday.com formulas and the
  Zapier step look for them.
- **If Give Lively strips underscores** (the first $1 test answers this):
  `add_filter( 'leadkit/packed_pair_separator', fn() => '-' );` switches the
  pair separator to `-`. Both separators are read everywhere.
- **Give Lively's widgets get the source too.** The Branded widget reads
  `data-widget-src` once and adds the page's own `utm_source` to its frame;
  LeadKit stamps the attribute and, if the frame still ends up with two
  `utm_source` values, leaves only its own (one frame reload, only on a
  landing page with UTMs in its address). The Simple widget builds its URL at
  the click, inside a shadow root, and sets it on a pop-up frame; LeadKit
  watches each open shadow root a click passes through and stamps that frame.
- **monday.com Visitors board** (Settings → LeadKit). One row per visitor id:
  visitorid, First and Last seen, Visits (30 minutes away starts a new one),
  First and Latest source (packed, no visitorid), Donate clicks, and the
  Journey log — each visit newest first, with the campaign it came from, its
  pages, and the form sent, the Donate click or the return from Give Lively
  where it happened, kept under monday.com's 2,000-character limit. Columns
  are found by title and created if missing. Written on every Donate click
  (new route `leadkit/v1/visitor`) and every form submission (after the
  visitor is answered), and connected to the submission (Form submissions,
  merged with links made by hand) and to the person (Person, with a People
  board; the latest email wins on a shared computer). Each connection is its
  own request, so one monday.com refuses cannot take the row down with it.
  The Donations column is left to the automation on the Donations board.
- **Retry what fails.** A Donate click is written to a browser outbox before
  it is posted (`fetch` with `keepalive`, the beacon if that throws), and only
  removed when the server answers. Unconfirmed posts are resent on the next
  page load, built fresh from the visitor's story; 5xx and 429 are retried up
  to eight times over 30 days, a 400 is dropped. The server merges by id, so a
  resend changes nothing — and writes nothing to monday.com when the row
  already says the same.
- **Untracked pages** (Settings → LeadKit). Path prefixes, e.g. `/help/`,
  where the tracker is not loaded at all — nothing recorded, nothing posted.
  Anything a browser recorded there before is purged from its storage and
  dropped by the server. `leadkit/track_page` filters it per request. Until
  it is set, the tracker loads on every page, help pages included.
- The endpoint is public, as it must be: it only writes the row of the id it
  is given, refuses bodies over 64 KB, and brakes per IP (30 posts per ten
  minutes, five new rows an hour). The monday.com token never leaves
  WordPress.
- `scripts/check-tracker.js`, `check-packing.php` and `check-visitors.php`:
  the tracker in a jsdom page, the PHP packing against the same fixtures, and
  the Visitors writes against a fake monday.com. `scripts/zapier-code-step.js`
  is the Code by Zapier step, tested against those fixtures.

Tried and rejected:

- Handing the Simple widget its source by putting `utm_source` into the
  address bar (`history.replaceState`) around the click. Both widgets read
  `location.search`, so it would work for both — but GA4's enhanced
  measurement counts history changes as page views, and the address bar would
  carry a visitor id anyone copying the link would pass on.
- `add_option()` as the per-visitor lock. It upserts (`ON DUPLICATE KEY
  UPDATE`), so two requests can both "win" and both create a row. The lock is
  an `INSERT IGNORE` on the options table instead.
- Counting every donation frame `src` change as a Donate click. Lazy-loading
  (SiteGround's included) swaps a frame's `src` in on scroll; only the
  Simple widget's own pop-up frame, or one inside a shadow root a click
  passed through, counts.

## 1.3.1

- Times follow the visitor's own time zone (sent by the tracker as
  `timezone`): submission names, journeys, return-visit logs and dates in
  monday.com. Falls back to the site's zone when a browser doesn't say.
- With a People board, submissions are named by date and form
  ("Oct 12, 2026 9:58 pm · Volunteer Inquiry"), so a person's "Form
  submissions" list reads as a timeline; the name goes in a Name column.
- **Every page as a subitem.** Each `[leadkit_monday_form]` submission gets a
  subitem per stop of the journey, in order ("3 · Volunteer Inquiry"), with
  Step, Page, Arrived (date and time), Seconds on page, Time on page, Read %,
  Loads and a Screenshot. Columns are created on the subitems board the first
  time. All the subitems go in one request per 20 stops, and the work runs
  after the visitor has been answered, so the form never waits on it. Numbers
  that were text before can now be charted: time on each page across everyone.
  Off with `add_filter( 'leadkit/monday_stops', '__return_false' )`.
- **Screenshot of each page.** Captured once per page (WordPress.com's public
  mShots service by default; `leadkit/screenshot_source` swaps it), cached a
  week in `uploads/leadkit-shots`, and shown with a gauge beside it filled to
  how far this visitor read, plus their time on the page. Pages that return an
  error are skipped rather than captured as the theme's 404 screen. At most 20
  per submission and 90 seconds of capturing; the next submission fills in
  pages still being captured. Off with `leadkit/monday_stop_screenshots`.
- **People and their submissions.** Set a **monday.com People board** and each
  person gets one row there, matched by email, the way Salesforce has a
  Contact with records related to it. Every `[leadkit_monday_form]` submission
  finds or creates its person, is connected both ways ("Person" on the
  submission, "Form submissions" on the person), and rolls the person up:
  phone, Visitor IDs, Submissions, Engagement (their best), First and Latest
  source, First and Last seen. Return visits go to the person — log, count,
  last seen and a daily update — instead of each submission. Columns are
  created the first time, so the board can start empty. Without a People
  board, rows are linked to each other by email as before.
- **Same email, same person.** When `[leadkit_monday_form]` creates an item,
  earlier items on the board with the same email address are found. Each
  submission stays its own row; all of that person's rows get a "Visitor IDs"
  column (every browser id seen for them) and a "Submissions" count, created
  the first time, plus updates linking them ("Same person as…" / "Submitted
  again…"). Return visits from any of those browsers are added to every one
  of their rows. LeadKit's own leads with the same email share visitor ids the
  same way. Emails are stored lowercase so they match however they are typed.
- **After submitting.** Pages viewed in the 30 minutes after someone submits
  are kept and shown as "After submitting" in the return-visits log on the
  monday item and the LeadKit lead — someone who volunteers and then goes
  straight to Donate is worth knowing about. They never trigger a "came back"
  update or email; only later visits do.
- Missing a required checkbox group is caught in the browser, which points at
  the group, instead of a round trip that sent the page back empty.
- The phone number is saved on the LeadKit lead for monday forms.
- A link from the site to itself that carries UTMs (an internal redirect
  tagged for a social post) no longer counts as a new campaign touch.
- **Readable in monday.com.** Each item from `[leadkit_monday_form]` gets an
  update, "How they got here": the campaign (and first campaign), an
  Engagement level, and every page in order, grouped by day, with the time
  they arrived, how long they stayed and how far they read. Plus columns that
  sort and filter — Engagement (Hot / Warm / Browsing, LeadKit's lead
  temperature), Source, Minutes on site, Pages — and Journey, the same list one
  page per line. Columns are created the first time. A page reloaded straight
  after itself counts once.
- New tracking value `page_log`: `page_detail` with each page's arrival time
  (UTC). Sent in full to LeadKit-rendered forms, capped in embedded-form URLs.
- The tracker script is versioned by its file's modification time, so a
  changed tracker.js always reaches browsers and caching plugins. Through the
  1.3.0 work the URL stayed `?ver=1.3.0`, browsers and SiteGround Optimizer
  kept the first copy, and monday.com forms were submitted without their
  tracking values.

## 1.3.0

Campaign tracking that survives the trip to a donation form. Built for a
nonprofit's year-end appeal, where the question is which email or post a
gift came from, and the gift happens on Give Lively — which keeps one tracking
field, `utm_source`, and drops the rest.

- **All five UTM fields.** `utm_content` and `utm_term` were never read, and
  `utm_content` is the one that says WHICH email or post. Google Ads click IDs
  (`gclid`, `gbraid`, `wbraid`) count as a paid-search touch when a click
  arrives with no UTMs, which Ad Grant auto-tagging often does.
- **Latest campaign gets the credit.** The tracker kept the first source
  forever, so a December gift was credited to whatever brought the visitor in
  last spring. Now `utm` is the latest campaign, `utm_first` the first, and
  `utm_touches` every distinct touch, with no cap. The latest campaign keeps
  the credit until a newer one arrives or the browser clears its storage; a
  site can set a window in days instead (Settings → LeadKit → Campaign
  credit). If storage ever fills, the oldest middle touches are trimmed first.
  Sessions stored by 1.2.x are migrated: their one source becomes the first touch.
- **Donation links carry the campaign.** Every link to a donation host
  (`givelively.org` by default; Settings → LeadKit → Donation hosts) gets the
  campaign packed into `utm_source` as `medium__source__campaign__content`,
  e.g. `email__mailchimp__spring-appeal__email-1`. Rewritten on load, on
  links added later, and again at the moment of the click. Give Lively widgets
  (`data-widget-src`) and donation iframes are covered too. With no campaign
  the source still says something: `organic__google.com`, `referral__<host>`
  or `direct__website`. Opt a link out with `data-leadkit-keep`.
- **Outside forms get the whole history.** Links and embeds to form hosts
  (`forms.monday.com` and `wkf.ms` by default; Settings → LeadKit → Form
  hosts) get the five UTM fields of the campaign with the credit, plus
  `campaign_history`: every campaign touch, oldest first, each packed as above,
  joined with ` > `. On a monday.com WorkForm, a form tag with each parameter's
  name captures it as a column. Past 1,500 characters the oldest middle
  touches give way to `…`; the first and most recent stay. LeadKit's own leads
  store the same string, uncapped, as `_leadkit_utm_history`.
  Those forms also get the rest of what the tracker knows, each as its own
  parameter for a form tag to capture: `first_touch`, `landing_page`,
  `referrer`, `click_id`, `form_page`, `pages_viewed`, `page_path`, `page_detail` (each page with its active
  seconds and scroll depth, e.g. `/protect/ 45s 80%`),
  `time_on_site` (active seconds), `photos_viewed`, `device`, `first_visit`
  and `visitor_id` (LeadKit's id, to join with lead records).
- **Return visits work again — and reach monday.com.** The tracker has
  always called `/api/sync-lead` and `/api/track-interaction`; those were
  Cloudflare functions that never came across to WordPress, so on every
  WordPress site return visits and first phone taps went to a 404. Both are
  routes in the plugin now (`leadkit/v1/sync`, `leadkit/v1/track`), and the old
  paths are treated as unset.
  - A visitor becomes a known lead when they submit LeadKit's form (they did
    not before — only a phone or email tap counted), tap the phone or email,
    or land on any page with `?leadkit_lead=<form name>`, which is where a
    monday.com form should redirect after submitting.
  - Pages from 30 minutes after that are return visits. Each page is recorded
    once per load (updated in place, also on `pagehide`, which mobile Safari
    fires when `beforeunload` does not) and sent when the page is hidden.
  - LeadKit's own leads get a "Came back" log and a "Last back" column, and
    with **Email me when a lead comes back** on, one email a day.
  - On each board under **monday.com boards** (with an API token), the item
    whose `visitor_id` column matches gets "Return visits log", "Last seen"
    and "Return visits" columns, created the first time, and one update a day
    on the item, which notifies its subscribers.
  - A first phone or email tap is stored as a lead and emailed.
- **monday.com forms as plain HTML.** `[leadkit_monday_form form="<token>"
  board="<id>" name="volunteer"]` renders a monday.com form's questions as
  ordinary markup — no CSS of its own, an `lk-form__` class on every element
  (`class_prefix` to change it), all on one page — and submits to
  `leadkit/v1/monday`, which creates the item through the API. Questions,
  labels, required flags and choices come from the monday form (cached ten
  minutes). Answers go to their columns, file uploads to the file column, and
  every tracking value to the column of the same title, with no URL length
  limit. Saved as a LeadKit lead first; a failed monday call shows as NOT SENT
  on the lead instead of losing it. Only boards listed in settings are
  accepted, so the site's token cannot be pointed at any other board. Return
  visits find the new item immediately.
- **Donation returns are logged.** A page reached from the donation host (the
  thank-you redirect) records a `donation_return` event with the credited
  campaign and fires `leadkit:donation-return` on `document`.
- **Leads show their campaign.** A Campaign column on the Leads list, campaign
  and first-touch rows on the lead, `_leadkit_utm_*` / `_leadkit_utm_first_*` /
  `_leadkit_utm_packed` meta for export, and the notification email now shows
  the campaign with the credit and, when different, where they first came from.
- `window.LeadKitAnalytics.attribution()` and `.packedSource()` for other
  scripts on the page.

## 1.2.1

The repository is public now, so updates need no authentication and the token
setting has no reason to exist. A credential field nobody needs is somewhere a
credential ends up for no reason.

- The **GitHub token** setting is gone. `LEADKIT_GITHUB_TOKEN` in wp-config.php
  still works, for anyone whose fork is private.
- A token saved by 1.2.0 is **deleted from the database on upgrade**. Removing
  the field alone would have left it sitting in `wp_options`, travelling in
  every export and backup, for a value nothing reads. Revoke it on GitHub as
  well — that part cannot be automated from here.

## 1.2.0

Two ways of installing it that do not involve a file manager.

- **A block.** "Lead form", under Widgets. Heading, project types, message
  label and button text are block settings; the editor preview renders the
  actual PHP through ServerSideRender rather than reimplementing the form in
  JavaScript, because a second implementation drifts from the first the moment
  a field changes. The shortcode and the theme template tag still work — three
  entry points, one renderer.

- **Self-updating.** WordPress only offers updates for things on wordpress.org,
  so a self-hosted plugin is invisible to it. This hooks the update machinery
  directly: a newer tag on GitHub appears under Dashboard → Updates and installs
  with the normal button.

  Two details are the whole reason this is more than a version check. A private
  repository's zipball needs an Authorization header, and `download_url()` sends
  none — so the download is performed here instead. And GitHub names the folder
  inside its zip `owner-repo-a1b2c3d`; installed under that name WordPress
  treats it as a different plugin, leaving the old copy active and the new one
  dormant beside it. The folder is renamed before install.

  A token is needed only while the repository is private.

## 1.1.0

The server side, which 1.0.0 did not have.

LeadKit shipped as a form renderer and a tracker, and left submissions to an
endpoint you configured elsewhere. That held until a site moved hosts — at which
point the endpoint 404ed, every visitor who filled the form landed on "page not
found", and no email, no record and no error appeared anywhere. The only symptom
was an inbox going quiet.

- **`POST leadkit/v1/submit`** — validates, honeypots, rate-limits, verifies
  Turnstile, stores, notifies. One route, two answers: JSON for `fetch`, a 303
  back to the page for a plain form submit, because the form must work without
  JavaScript.
- **Leads are a post type.** Visible in wp-admin immediately, searchable, and
  they outlive the plugin. Stored *before* the email is attempted, with the
  notification result recorded on the lead — a mail failure shows as a red flag
  beside an enquiry you still have.
- **The journey, rendered.** The tracker always collected which pages were read,
  for how long, how far down, which photographs were opened. It went in as JSON
  nobody read. It is now drawn, in the email and on the lead, from one renderer
  so the two cannot drift.
- **HTTPS mail API** as an option, for hosts whose transport is unauthenticated
  or whose SMTP ports are blocked. Secrets read from `wp-config.php` first.
- **Turnstile verified properly** — `success`, `action` and `hostname`, not just
  the first. Fails closed when the verifier is unreachable.
- Several recipients; honeypot; per-IP rate limit.

Fixes:

- On a page with two forms, only the first mounted a Turnstile widget — the
  other could never produce a token, and would have been refused on every
  submission once a secret was configured.
- Photographs viewed by advancing through a lightbox were not counted, because
  the handler only saw images inside the content.

## 1.0.0

First release as a standalone repository. The plugin itself is unchanged; what
is new is that it now lives in one place instead of being copied between sites.

- `Plugin URI` and `Update URI` headers, so Git Updater can serve updates to
  each site from the WordPress admin.
- README for GitHub; `readme.txt` remains the WordPress-facing text and holds
  the endpoint payload contract.
