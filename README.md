# LeadKit — Lead Form & Visitor Tracking

The lead-capture form and first-party visitor tracker, packaged to travel
between projects. `readme.txt` is the WordPress-facing version of this and
carries the full payload contract; this is the short version.

## Two pieces, one plugin

**The form.** Name / email / phone / project description, a hidden
`projectType`, and optional Cloudflare Turnstile that is **lazy-mounted on first
interaction** with its box height reserved — so the widget costs nothing on
pages nobody submits from, and zero layout shift when it does appear. Render it
with `[leadkit_form]` or, from a theme, `leadkit_form( array( 'class_prefix' =>
'your-prefix' ) )`; every class is yours to name, so it inherits the site's
design rather than importing its own.

**The tracker.** First-party, no third-party script, attaching behavioural
context to each lead.

## The server side is not in here

Submissions POST to endpoints you configure under **Settings → LeadKit**:
`/api/submit`, `/api/sync-lead`, `/api/track-interaction`. The reference
implementation is a set of Cloudflare Pages Functions. `readme.txt` documents
the exact payload contract so a new project can stand up compatible endpoints.

Nothing secret lives in this repository: the Turnstile **site** key is a public
value and is stored per-site in options, and the secret key belongs to the
endpoint, not to WordPress.

## Install

```bash
git clone https://github.com/paulbryanvisual/leadkit.git \
  wp-content/plugins/leadkit
```

Then set the endpoints and site key in **Settings → LeadKit**. The plugin
declares an `Update URI`, so with [Git Updater](https://git-updater.com) each
site updates it from the WordPress admin like any other plugin.

**Keeping it on several sites:** this repository is the only source of truth.
Never edit the copy inside a site's `wp-content` — that is exactly how the
sibling block-labeler plugin drifted into two incompatible forks.

## Settings migration

On first activation the plugin adopts the theme-era options it replaced
(`roger_form_endpoint`, `roger_turnstile_sitekey`, and the
`ROGER_TURNSTILE_SITEKEY` constant) so moving the form out of a theme does not
mean re-typing its settings. Harmless anywhere those never existed.

## Requirements

WordPress 6.4+, PHP 7.4+. No build step.
