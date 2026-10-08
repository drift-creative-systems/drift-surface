# Changelog

All notable changes to this project are documented here. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Versioning: [SemVer](https://semver.org/).

## [3.0.0] - 2026-10-08

Renamed from **Encore Website** to **Drift: Surface**, to sit alongside the Drift: Surface Hub. There are no client installs of 2.x, so this is a new plugin folder rather than an in-place update. See "Upgrading from Encore Website 2.x" in README.md.

### Changed
- Plugin name **Drift: Surface**. Slug, folder, main file (`drift-surface.php`), text domain, release zip (`drift-surface.zip`) and GitHub repo (`drift-creative-systems/drift-surface`) are now `drift-surface`. Added an `Update URI` header.
- Code prefixes: `Drift_Surface_*` classes, `DRIFT_SURFACE_*` constants, `drift_surface_*` functions, hooks, options and cron events, `_drift_surface_*` post meta, `ds-` admin CSS classes, `DriftSurface` JS object.
- Product map renamed `maps/encore.php` → `maps/surface.php`, slug `surface`, label "Surface". Its post types, taxonomies and `encore_site_settings` option keep their names, because the Encore theme reads them.
- Publish webhook is `POST /wp-json/drift-surface/v1/publish` (and `/status`) with an `X-Drift-Surface-Secret` header. The publish link is `?drift_surface_publish=`, and the form action is `drift_surface_form`. drift-hub 1.4.0 posts to the new endpoint.
- Admin screen, login screen and White Label defaults restyled to match the Drift: Surface Hub. They use the Drift Brand System: self-hosted Poppins and Inter (no Google requests), a black header with the Drift mark, black pill buttons and the `#FF4FA3` accent. The header shows the data source (hub or Airtable). Default login button is now black (hover `#2A2B2E`). Sites that saved their own colours keep them.
- The publish result page's button is a black pill. White text on a client's accent colour could fail contrast.
- Docs: `docs/ENCORE-AIRTABLE-BASE.md` → `docs/SURFACE-AIRTABLE-BASE.md`, `setup-encore-base.mjs` → `setup-surface-base.mjs`. The base migrator stamps `[Surface template vN]` and still recognises `[Encore template vN]`.

### Added
- `Drift_Surface_Migrate` moves 2.x data once on activation: options, post meta, user meta and cron hooks are renamed, the token and publish secret are re-encrypted, and the product changes from `encore` to `surface`. Synced posts keep their ownership, so the next sync updates them rather than duplicating them.
- `includes/compat.php` keeps the 2.x names working until 4.0: `encore_website_*` functions and hooks, `Encore_Website_*` classes and `ENCORE_WEBSITE_*` constants. The current Encore theme runs unchanged.

### Removed
- 1.x (Drift Website) compatibility: the `drift-website.php` loader, `drift_*` functions, `Drift_Website_*` aliases, `drift_website_*` hooks, `DRIFT_WEBSITE_*` constants, the `drift/v1` namespace, the `X-Drift-Secret` header, `?drift_publish=` links and the `drift_form` action.
- 2.x wire names: `encore/v1`, `X-Encore-Secret`, `?encore_publish=` and `encore_form`.

## [2.1.0] - 2026-10-08

### Added
- **Data source** setting (Connection tab, or `ENCORE_WEBSITE_API_BASE` in wp-config.php). Blank keeps syncing from Airtable; a Drift Hub's API address makes the site sync from the hub instead. The hub speaks the same API, so the map, sync engine, forms and publishing are unchanged. A Drift Hub has no monthly call limit, so the API budget no longer pauses the daily check on hub-connected sites.

### Changed
- White Label default admin footer is "Website by Drift Creative Systems", linking to https://driftcreativesystems.co.uk/. Plugin Author URI points there too. Sites that saved their own footer text keep it.

## [2.0.0] - 2026-10-06

Renamed from **Drift Website** to **Encore Website**. 1.x sites update in place with no manual steps.

### Changed
- Plugin name, slug, main file (`encore-website.php`), text domain and GitHub repo are now `encore-website`.
- Code prefixes: `Encore_Website_*` classes, `ENCORE_WEBSITE_*` constants, `encore_website_*` hooks, options and cron events, `_encore_*` post meta, `encore_site_settings` for the synced settings, `ew-` admin CSS classes, `EncoreWebsite` JS object.
- Theme-facing API is `encore_website_settings()`, `encore_website_setting()`, `encore_website_setting_image()`, `encore_website_linked_posts()`, `encore_website_is_synced()`, `encore_website_last_synced()` and `encore_website_form_hidden_fields()`. (`encore_*` alone belongs to the Encore theme.)
- Publish webhook is `/wp-json/encore/v1/publish` with an `X-Encore-Secret` header; the Free-plan link is `?encore_publish=`. Form AJAX action is `encore_form`, honeypot `encore_hp`.
- Secrets are sealed under a new key; 1.x values still unseal and are re-sealed by the migration.
- `tools/build-zip.py` no longer ships `CLAUDE.md` or `.gitignore`. 1.3.0's zip included `CLAUDE.md`.
- Encore map: the enquiry form's page-URL field is `source_page` (Airtable column still "Page"). `page` is a WordPress admin query var, and logged-in AJAX requests run `admin_init`. Encore theme 1.3.0 posts `source_page`; older themes' page URL is dropped.

### Added
- `includes/class-migrate.php`: runs once per site on `plugins_loaded` and on activation. It renames the 1.x options, post meta (including `_drift_links_*`) and user meta in place, re-seals the token and publish secret, deletes 1.x transients and swaps the cron hooks. It never overwrites a new name that already holds data. A failed run logs and retries on the next request.
- `drift-website.php`: header-less legacy loader. 1.x sites keep `drift-website/drift-website.php` in `active_plugins` after an update; the loader repoints it to `encore-website.php` (multisite too) so WordPress doesn't deactivate the plugin.
- `includes/compat.php`, kept until 3.0: `drift_*` theme functions, `Drift_Website_*` class aliases, and `drift_website_*` filters and actions passed through with deprecation notices. The `drift/v1` namespace, `X-Drift-Secret` header, `?drift_publish=` link, `drift_form` action, `drift_hp` honeypot and `DRIFT_WEBSITE_*` constants are all still accepted.

## [1.3.0] - 2026-10-06

### Added
- Settings fields can mirror into core options with `'wp_option'` (allowed: `blogname`, `blogdescription`). Blank or missing values leave the WordPress value alone. No extra API calls.
- Encore map: **Artist Name** now updates the WordPress Site Title and **Tagline** the WordPress Tagline on every sync.

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
