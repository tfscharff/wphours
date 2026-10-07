# wphours — Library Hours

No-code WordPress plugin for library hours. Staff edit hours under **Library Hours** in
wp-admin and click Save; display them anywhere with the `[library_hours]` shortcode.
Replaces LibCal Hours.

- Homepage widget + full `/hours` calendar view
- Handles special/holiday hours and a secondary "guest hours" schedule
- No database tables — stored in a single WordPress option

## Install

Download the latest release zip, then in wp-admin: **Plugins → Add New → Upload Plugin**.

## Changelog

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
