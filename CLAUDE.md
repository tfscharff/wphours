# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

MCW Library Hours — a single-file WordPress plugin (`mcw-library-hours.php`) that lets library
staff edit hours in wp-admin and displays them on the front end via shortcodes. No build step, no
package manager, no test suite — it's plain PHP + vanilla JS, deployed by zipping the plugin and
uploading it through wp-admin (see README.md).

There is no local dev/test tooling in this repo. Verify changes by reading the code carefully and,
where possible, loading the plugin in a real or local WordPress install (e.g. `wp-env` or a local
WP install) — this repo does not include one.

## Architecture

Everything lives in `mcw-library-hours.php`, organized top-to-bottom into five sections (see the
`/* --- */` banner comments):

1. **Data layer** — `mcw_hours_default()`, `mcw_hours_get()`, and the `mcw_hours_sanitize_*()`
   family. Hours are stored as **one WordPress option** (`MCW_HOURS_OPTION` =
   `mcw_library_hours`), no custom DB tables. The stored shape is:
   - `weekly`: map of `"0"`–`"6"` (Sun–Sat) → array of `{open, close}` blocks (`"HH:MM"`, `24:00`
     = end of day) — the everyday schedule.
   - `exceptions`: map of `"YYYY-MM-DD"` → either `{closed:true, note}` or `[{open,close}]` —
     single-date overrides (holidays/special hours).
   - `ranges`: array of `{start, end, label, weekly}` — a full weekly schedule that applies only
     between two dates (e.g. term/seasonal hours).
   - `guest` (optional): a second, independent `{weekly, exceptions, ranges}` schedule — only
     persisted once staff actually set it.
   - **Resolution precedence** (`mcw_hours_resolve()` in PHP, mirrored by `mcwResolve()` in JS):
     exception for that date > first matching range > weekly default.
   - All writes go through `mcw_hours_sanitize()` (and the schedule/weekly/exceptions/ranges
     sanitizers it calls) — this is the only path that persists data, so any new field must be
     added there or it will be silently dropped.

2. **REST API** (`mcw-hours/v1/hours`) — GET is public (`__return_true`) and returns the raw
   option; POST requires `current_user_can('edit_pages')` and calls
   `mcw_hours_sanitize()` before `update_option()`. This is the only way data is read or written;
   the admin editor and both front-end shortcodes all talk to it via `fetch()`.

3. **Admin editor** (`admin_menu` → `mcw_hours_admin_page()`) — a single wp-admin page under
   "Library Hours" (`edit_pages` capability). The HTML/CSS/JS is emitted from PHP **heredocs**:
   `mcw_hours_editor_markup()` uses a **nowdoc** (`<<<'HTML'`) so `$` in the embedded JS is never
   interpolated by PHP. The editor is a small hand-rolled SPA (no framework) that:
   - lets staff pick which calendar to edit (`library` vs `guest`, the latter created lazily as a
     clone of the library weekly hours on first switch),
   - edits weekly hours / date ranges / exceptions client-side in JS state (`sched`, `work`),
   - POSTs the full config back to the REST endpoint on every save (`doSave()`).
   Config for this JS is passed via an inline `window.MCW_ADMIN = {root, nonce}` script tag built
   with `wp_json_encode()` — keep injecting config this way rather than interpolating PHP directly
   into JS.

4. **Front-end widget** (`[library_hours]` shortcode) — compact "open now" card + 7-day list,
   rendered client-side after fetching the REST endpoint (cache-busted with an hourly timestamp
   query param). Supports a Library/Guest tab toggle when a `guest` schedule exists.

5. **Full calendar page** (`[library_hours_full]` shortcode) — month-grid calendar view with
   prev/next navigation, same data source and resolution logic.

Sections 3–5 each duplicate a small shared JS resolver via `mcw_hours_resolve_js()` (returned as a
nowdoc and interpolated into each script with PHP heredoc string interpolation) — `mcwResolve()`
and `mcwSchedule()`. If you change the exception/range/weekly precedence, update
`mcw_hours_resolve()` (PHP) and this shared JS snippet together; they must stay in sync since PHP
resolves server-side rendering paths (none currently) while JS resolves entirely client-side.

## Release workflow

Every code change to this plugin ships as a versioned release, not just a commit — same process
used for the wprooms (MCW Reserve a Room) sister repo:

1. Bump `Version:` in the `mcw-library-hours.php` plugin header using SemVer (patch for bug fixes,
   minor for backward-compatible features, major for breaking changes).
2. Add a dated entry to the `## Changelog` section in `README.md`.
3. Commit and push to `main`.
4. Cut a GitHub release: `gh release create vX.Y.Z` with a zip of the plugin as the asset. The
   zip's top-level folder must be `mcw-library-hours/` containing `mcw-library-hours.php` (plus
   README/LICENSE) — WordPress's Plugins → Add New → Upload Plugin requires that exact layout, not
   a bare `.php` file or files at the zip root. Do this automatically for future changes; only
   check in if the version-bump category is ambiguous.

## Conventions to preserve

- **Nowdoc vs heredoc**: markup/CSS/JS with no PHP variables uses `<<<'HTML'` (nowdoc, no
  interpolation). Scripts that need a PHP value injected (e.g. `mcw_hours_widget_script()`
  interpolating `$resolve`) use `<<<HTML` (heredoc) — be deliberate about which one a block needs
  when editing, since accidentally using a heredoc where a nowdoc is expected will make PHP try to
  interpolate `$` inside the embedded JS.
- Time values are always `"HH:MM"` 24-hour strings, with `"24:00"` as a valid "end of day" sentinel
  (see `mcw_hours_valid_time()`); don't use `Date`/strtotime parsing that would reject `24:00`.
- All user-facing config passed into inline `<script>` blocks goes through `wp_json_encode()`
  (never raw string concatenation) to avoid injection.
- Everything is vanilla PHP/JS with no dependencies, transpilation, or minification — keep new code
  consistent with that (ES5-style function syntax is used throughout the front-end JS, no
  arrow functions/`const`/`let`).
