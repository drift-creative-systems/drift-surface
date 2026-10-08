# Changelog

All notable changes to this project are documented here. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Versioning: [SemVer](https://semver.org/).

## [3.0.1] - 2026-10-08

### Changed
- LICENSE: titled **Drift: Surface**, with the reserved names updated to the current product names. Dropped the clause for docs/ files this package no longer ships.

## [3.0.0] - 2026-10-08

First release of **Drift: Surface**, the site engine for Surface band and artist websites.

### Added
- One-way sync from a Drift: Surface Hub (drift-hub 1.4.0+) into WordPress, driven by a product map (`maps/surface.php`): posts, post meta, taxonomies, linked records, media and one site settings option (`surface_site_settings`). Unchanged rows are skipped by content hash; removed rows are trashed and restored if they return. Images import in batches, and later batches continue from cached records with no extra hub requests.
- Surface map: `surface_gig`, `surface_release`, `surface_track`, `surface_member`, `surface_photo`, `surface_video`, `surface_press` and `surface_merch` post types, `surface_release_type` and `surface_album` taxonomies, booking enquiry and newsletter forms, and the Setup Wizard pages. Requires Drift: Surface Theme (`surface-theme`), with an Install & activate notice until it's active.
- Publish webhook `POST /wp-json/drift-surface/v1/publish` with an `X-Drift-Surface-Secret` header, queued as a background sync; `GET /wp-json/drift-surface/v1/status` for monitoring. A daily check re-syncs only if a publish was missed, and the admin bar has **Sync from hub**.
- Website forms (`drift_surface_form` AJAX action) that save to the hub, with an email copy and a fallback email when the hub can't be reached.
- **Drift: Surface** admin screen: Connection (hub address, Base ID and encrypted token, product, website address and publish secret for the hub, Check connection against the map), Sync, Content, White Label and Setup Wizard tabs, styled with the Drift Brand System (self-hosted Poppins and Inter).
- Agency user access control and client menu hiding, and White Label for the admin bar, footer and login screen.
- Theme API: `drift_surface_setting()`, `drift_surface_setting_image()`, `drift_surface_linked_posts()`, `drift_surface_form_hidden_fields()`, `drift_surface_is_synced()` and `drift_surface_last_synced()`.
- wp-config.php overrides: `DRIFT_SURFACE_HUB_URL`, `DRIFT_SURFACE_HUB_BASE`, `DRIFT_SURFACE_HUB_TOKEN`, `DRIFT_SURFACE_KEY` and `DRIFT_SURFACE_GITHUB_TOKEN`.
- Self-updates from GitHub releases via the bundled Plugin Update Checker.
