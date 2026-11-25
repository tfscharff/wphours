# wphours — Library Hours

No-code WordPress plugin for library hours. Staff edit hours under **Library Hours** in
wp-admin and click Save; display them anywhere with the `[library_hours]` shortcode.
Replaces LibCal Hours.

- Homepage widget + full `/hours` calendar view
- Handles special/holiday hours and a secondary "guest hours" schedule
- No database tables — stored in a single WordPress option

## Install

Download the latest release zip, then in wp-admin: **Plugins → Add New → Upload Plugin**.

## Development

Tests need only the PHP CLI, no Composer or WordPress: `php tests/run.php` (stubs WordPress, runs
`tests/cases.php`, exits non-zero on failure). The shared JS resolver is tested with Node's built-in
runner: `node --test "tests/js/*.test.mjs"` (it calls the PHP CLI to get the plugin's real JS).
`tests/` is for development and must not be included in the release zip.

## Changelog

- **1.1.16** (2026-11-25) — Stricter validation when hours are saved: times after 24:00 (such as
  24:30) and dates that do not exist (such as 2026-02-30) are now rejected. A damaged saved
  schedule now shows the day as closed instead of open with no hours.
- **1.1.15** (2026-10-07) — Full hours calendar shading: open days white, closed days light gray,
  today pale blue, and days outside the month dark gray.
- **1.1.14** (2026-10-07) — Closed days on the full hours calendar (and their legend key) use a
  solid light gray instead of diagonal stripes, so accessibility checkers can measure the text
  contrast instead of flagging every closed day for manual review. Contrast is unchanged.
- **1.1.13** (2026-09-09) — Fixed: overlapping date ranges now resolve to the most recently
  added range instead of the oldest one, so a new range no longer requires deleting old ones to
  take effect. Fixed: the "Set hours" grid no longer silently resets to blank after saving a date
  range, which could cause a second range saved right after to end up with no hours at all
  (looking like holidays/exceptions weren't being applied). Removed "MCW" branding from
  user-facing text (plugin name).
- **1.1.12** (2026-09-02) — Initial commit to this repo.

## License

GPL-2.0+
