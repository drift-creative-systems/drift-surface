# Drift: Surface

The site engine behind **Surface** band and artist websites, by Drift Creative Systems. Content is edited in a [Drift: Surface Hub](https://github.com/drift-creative-systems/drift-hub) (or an Airtable base), the artist or label presses Publish, and the WordPress site updates.

The engine is generic: Surface is a map file (`maps/surface.php`) plus the Encore theme.

> **Formerly Encore Website (2.x) and Drift Website (1.x).** Renamed in 3.0.0. See "Upgrading from Encore Website 2.x" below.

- **Requires:** WordPress 6.2+, PHP 8.0+, OpenSSL. ACF Pro is needed for the Setup Wizard's modules.
- **Repo:** https://github.com/drift-creative-systems/drift-surface
- **Self-updates** from GitHub releases (bundled Plugin Update Checker, checked every 6 hours).
- **Pairs with its product theme.** A map can name its theme (`'theme'` key). For Surface that's the [Encore theme](https://github.com/drift-creative-systems/encore-theme): until it's active, Drift: Surface shows an **Install & activate** notice and the Setup Wizard is off. The theme in turn shows a holding page until this plugin is active. `encore-bundle.zip` on each theme release contains both.

## How it works

1. Each client's content lives in a Drift: Surface Hub (one artist per hub record), or in their own Airtable base duplicated from the template base (`docs/SURFACE-AIRTABLE-BASE.md`). The hub speaks the same API as Airtable, so the sync doesn't care which.
2. Drift: Surface → Connection holds the data source (blank = Airtable, or the hub's API address), the base ID, an encrypted token and the product.
3. The client presses **Publish** in the hub, or ticks it in Airtable (automation: `docs/airtable-publish-automation.js`). Either POSTs to `/wp-json/drift-surface/v1/publish` with an `X-Drift-Surface-Secret` header.
4. The site queues a background sync. Every table in the map is read (about 1 API call each) and written into normal WordPress posts, meta, terms, media and one settings option. Templates read WordPress data and never call Airtable.
5. A daily safety check reads one field and re-syncs only if a publish was missed.

## Admin

**Drift: Surface** (agency users only, once one exists) has these tabs:

- **Connection:** credentials, product, webhook URL and secret, and a schema check against the map.
- **Sync:** last run, API usage this month against the budget, Sync now / Full resync, and the activity log.
- **Content:** which table goes where, and the current site settings.
- **White Label:** admin bar, footer, login screen, and which menus clients see.
- **Setup Wizard:** creates the product's pages with modules pre-filled and builds the Main Menu.

The admin bar also has **Sync from Airtable** for editors.

## Theme API

```php
drift_surface_setting( 'name' );                       // synced site setting
drift_surface_setting_image( 'logo', 'medium' );       // <img> for an image setting
drift_surface_linked_posts( get_the_ID(), 'tracks' );  // linked records, Airtable order
drift_surface_form_hidden_fields( 'enquiry' );         // inside a form posting to admin-ajax.php
drift_surface_last_synced();
```

Synced post meta is plain WordPress meta (`get_post_meta( $id, 'gig_date', true )`), so ACF's `get_field()` works on it too.

## wp-config.php options

```php
define( 'DRIFT_SURFACE_API_BASE', 'https://hub.example/wp-json/drift-hub/v0/' ); // data source; undefined = the screen (blank = Airtable)
define( 'DRIFT_SURFACE_AIRTABLE_BASE', 'appXXXXXXXXXXXXXX' );  // overrides the screen
define( 'DRIFT_SURFACE_AIRTABLE_TOKEN', 'pat…' );               // overrides the screen
define( 'DRIFT_SURFACE_KEY', 'long-random-string' );            // encryption key (defaults to WP salts)
define( 'DRIFT_SURFACE_GITHUB_TOKEN', 'github_pat_…' );         // only if the repo is private
```

The 2.x `ENCORE_WEBSITE_*` names are still read if the new ones aren't defined.

## Releasing

1. Bump `Version:` and `DRIFT_SURFACE_VERSION` in `drift-surface.php`, and add a `CHANGELOG.md` entry.
2. Commit and push to `main`.
3. Build the zip: `python tools/build-zip.py` writes `dist/drift-surface.zip` with a `drift-surface/` top folder, `lib/` included and `.git`, `tests/`, `tools/`, `dist/`, `.gitignore` and `CLAUDE.md` left out.
4. Create a GitHub release tagged `vX.Y.Z` with that zip attached. Sites see it in Dashboard → Updates within 6 hours, or straight away with "Check again" on the Plugins screen. The zip must be attached: release-assets mode is on, so plain source archives are ignored.

## Upgrading from Encore Website 2.x

3.0.0 renames the plugin to **Drift: Surface**: folder and slug `drift-surface`, main file `drift-surface.php`, repo `drift-creative-systems/drift-surface`. There are no client installs of 2.x, so there's no in-place update path. On a 2.x site:

1. Deactivate Encore Website, then delete it or rename its folder.
2. Install `drift-surface.zip` and activate it.
3. On activation, `Drift_Surface_Migrate` runs once and automatically: options, post meta (`_encore_airtable_id` and friends), user meta and cron hooks move to `drift_surface_*` names, the token and publish secret are re-encrypted, and the product switches from `encore` to `surface`. Synced posts stay owned by the sync, so nothing is duplicated.
4. Re-point anything that calls the site: the hub (drift-hub 1.4.0+ posts to the new endpoint) or the Airtable automation (`/wp-json/drift-surface/v1/publish`, `X-Drift-Surface-Secret`). The old `encore/v1` endpoint, `X-Encore-Secret` header, `?encore_publish=` link and `encore_form` action are gone.

Still working until 4.0 (`includes/compat.php`): `encore_website_*` functions and hooks, `Encore_Website_*` classes and `ENCORE_WEBSITE_*` constants, so the current Encore theme runs unchanged. The 1.x Drift Website names were removed.

## Docs

| File | What |
|---|---|
| `CLAUDE.md` | Architecture rules and conventions (read before changing anything) |
| `docs/MAP-REFERENCE.md` | Every map key and field type |
| `docs/SURFACE-AIRTABLE-BASE.md` | The Surface template base, table by table |
| `docs/airtable-publish-automation.js` | The Publish automation script |
| `tests/fake-airtable-mu-plugin.php` | Local test fixture faking Airtable (never deploy) |

## Licence

Split licence, © Drift Creative Systems:

- **PHP files:** GPL-2.0-or-later (`GPL-2.0.txt`), because they run inside WordPress.
- **Everything else** (CSS, JavaScript, media, docs, the Airtable template and its scripts): proprietary, all rights reserved. They can't be copied, modified, redistributed or used in a competing product without written permission.
- **`lib/plugin-update-checker/`:** MIT, by its author.
- **Names:** "Drift: Surface" and "Surface" are reserved. Modified versions can't be distributed under them.

The full terms are in `LICENSE`.
