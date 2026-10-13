# Changelog

## 1.3.0

Campaign tracking that survives the trip to a donation form. Built for the
3Strands "Light the Path" appeal, where the question is which email or post a
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
  e.g. `email__mailchimp__light-the-path__email-1`. Rewritten on load, on
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
