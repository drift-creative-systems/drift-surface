# Changelog

All notable changes to this project are documented here. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Versioning: [SemVer](https://semver.org/).

## [1.2.0] - 2026-10-06

### Added
- `embed` map field type: keeps only `<iframe>` tags with an `https` source and strips scripts, `srcdoc` and surrounding text (`Drift_Website_Media::embed()`).
- Encore map: Site Settings → **Live Embed** (`live_embed`) and **Merch Embed** (`merch_embed`), both `embed`. The base doc and setup script add the two Long text fields.
- Sync tolerates fields missing from the base. When Airtable answers 422 `UNKNOWN_FIELD_NAME`, `Drift_Website_Airtable::list_records_lenient()` drops that field and retries (one extra API call per missing field, only while it's missing). The sync keeps the field's current WordPress values and logs which fields were skipped. Before this, one missing field failed the whole table. Fields a table can't sync without (title, slug, status field, order and sort fields, from `Drift_Website_Map::structural_fields()`) are never dropped, so the table still fails safely rather than binning rows. Airtable errors now carry `type` and `message` in their error data.
- `setup-encore-base.mjs` migrate mode: `--migrate --all` (or `--bases`, `--base`) finds every Encore base the token can see and adds missing tables, fields and links. It never deletes, renames or retypes anything. It stamps `[Encore template vN]` on the Site Settings table description and lists hand jobs per base: formulas, select options, wrong types, Created time and button fields, the Interface. Dry run by default; `--apply` makes the changes. Bases newer than the script, and bases without Creator access, are skipped. `TEMPLATE_VERSION` is 2.
- `docs/airtable-setup/README.md`: click-by-click steps for adding the embed fields to the Interface's Publish page; Part C, updating existing bases with migrate mode and a maintenance token; Part D, band-facing instructions for the embeds.

### Changed
- Licence: split. PHP stays GPL-2.0-or-later (`GPL-2.0.txt`); everything else (CSS, JS, media, docs, the Airtable template and its scripts) is proprietary to Drift Creative Systems. See `LICENSE`. The Drift and Encore names are reserved.
- Code comments and changelog no longer name the private projects the plugin was originally built from.
- Airtable setup docs now pass the setup token through the `AIRTABLE_TOKEN` environment variable instead of a command-line option. This keeps the token out of shell history and stops secret scanners flagging the placeholder.

## [1.1.0] - 2026-10-04

### Added
- Product maps can name their theme (`'theme' => [ 'slug', 'name', 'zip' ]`). `maps/encore.php` names the Encore theme.
- When the map's theme (or a child of it) isn't active, a persistent admin notice offers **Install & activate** (downloads the latest release from GitHub) or **Activate** if it's already installed. Needs `install_themes` / `switch_themes`, nonce-checked, and falls back to a "upload it manually" message on hosts without direct filesystem access.
- The Setup Wizard tab and its page-creation AJAX are blocked until the theme is active, because the wizard's pages are built from that theme's modules.

## [1.0.0] - 2026-10-03

First stable release.

### Changed
- Self-updates now come from https://github.com/drift-creative-systems/drift-website (public repo, no token needed). Sites check every 6 hours, or immediately with "Check again" on the Plugins screen.
- Plugin author is now Drift Creative Systems.
- Drift admin menu now uses the `dashicons-layout` icon and is pinned to the top of the sidebar, above Dashboard.

## [0.1.2] - 2026-10-03

### Added
- Publish link for Airtable's Free plan, where automations can't run scripts. An Airtable button field opens `https://site/?drift_publish=SECRET`, the site syncs immediately and shows the band a confirmation page listing what changed. Throttled to one run a minute, `noindex`, never cached; an invalid secret returns 403. The link is shown (masked, with copy) on Drift → Connection.

## [0.1.1] - 2026-10-03

### Added
- `docs/airtable-setup/`: `setup-encore-base.mjs` builds the whole Encore base (12 tables, exact field names and types, Releases ↔ Tracks link) through the Airtable API in about 35 calls, with optional demo bands (`--demo velvet|hollin`). Re-runnable. Includes a step-by-step README for the template base, the Publish automation, the Interface and per-band setup.

### Fixed
- `url` fields: validated by shape instead of `wp_http_validate_url()`, which did a DNS lookup per URL during sync and rejected valid links on hosts with flaky DNS.
- Test fixture: video uses a valid 11-character YouTube ID.

## [0.1.0] - 2026-10-03

### Added
- First release. Map-driven, one-way Airtable → WordPress sync engine. Rules: whole-table-or-nothing fetches, Airtable owns only the posts it created, hash change detection, removed rows binned and restored on return, failed images retried.
- Linked records resolved to WordPress post IDs (`link` field type, `drift_linked_posts()`).
- Per-run image import cap with automatic continuation from cached records (no extra API calls).
- Publish webhook (`POST /wp-json/drift/v1/publish`) and status endpoint (`GET /wp-json/drift/v1/status`), secret-protected.
- Daily safety check: one API call, full sync only if a publish was missed.
- Monthly API usage counter and budget, admin-bar "Sync from Airtable", activity log, run lock and page-cache purge.
- Encrypted credentials (AES-256-GCM) with wp-config overrides.
- Website → Airtable forms with honeypot, rate limit and email fallback.
- Encore product map: Site Settings, Gigs, Releases, Tracks, Members, News, Gallery, Videos, Press, Merch, Enquiries and Subscribers, plus 10 Setup Wizard pages.
- Drift admin shell with tab filter, White Label (Bonsai palette defaults), agency-user menu hiding, and the Setup Wizard (now map-driven, and builds the Main Menu).
- Self-updates from GitHub releases at Drift-Apps/drift-website.
