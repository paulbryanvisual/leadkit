# Changelog

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
