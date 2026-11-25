# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

Library Hours — a single-file WordPress plugin (`mcw-library-hours.php`) that lets library
staff edit hours in wp-admin and displays them on the front end via shortcodes. No build step, no
package manager — it's plain PHP + vanilla JS, deployed by zipping the plugin and
uploading it through wp-admin (see README.md).

## Tests

A dependency-free stub-harness suite covers the PHP data layer (time/date validation, the
`mcw_hours_sanitize*()` family, `mcw_hours_get()`/`default()`, `mcw_hours_resolve()` precedence),
the REST callbacks (called directly with stub request objects) and the shortcode output. The shared
JS resolver (`mcwResolve()`/`mcwSchedule()`) has Node tests that run the plugin's real JS. The
editor and the widget/calendar rendering are not covered; verify those in a browser or a real
WordPress install.

- `tests/run.php` defines `ABSPATH`, stubs the WordPress functions the plugin uses, requires the
  plugin, then requires `tests/cases.php` (the cases and a tiny `ok()` helper).
- Run: `C:\Users\thoma\bin\php\php.exe tests/run.php` (exits non-zero on any failure).
- Lint: `C:\Users\thoma\bin\php\php.exe -l mcw-library-hours.php` (and on both test files).
- If the plugin starts calling a new WordPress function at load time or in a tested function, add a
  stub to `tests/run.php`.
- `tests/` must stay out of the release zip (the zip holds only the plugin file, README and LICENSE).
- Cases marked `CURRENT BEHAVIOUR` pin down quirks as they are today (for example a block that
  closes before it opens is accepted and stored); update them deliberately if the behaviour changes.
- JS tests: `node --test "tests/js/*.test.mjs"` (Node's built-in runner, no npm packages; the quoted
  glob is needed because Node 22 does not accept a bare directory). `tests/js/resolver.test.mjs`
  runs `php tests/js-bridge.php` (so `php` must be on PATH, or set `PHP` to its full path), which
  prints the resolver source from `mcw_hours_resolve_js()` plus the PHP resolver's answer for every
  fixture in `tests/fixtures.php`; the test evaluates that source with `node:vm`. The same fixtures
  are asserted in `tests/cases.php`, so PHP and JS are checked against each other. When you change
  resolution, add the case to `tests/fixtures.php`.

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
     exception for that date > last matching range (the most recently added wins when ranges overlap) > weekly default.
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
