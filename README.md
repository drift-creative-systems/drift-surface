# Encore Website

The Airtable-powered site engine behind **Encore** band and artist websites, by Drift Creative Systems. Clients edit their content in Airtable and press Publish; their WordPress site updates.

The engine is generic: Encore is a map file (`maps/encore.php`) plus the Encore theme.

> **Formerly Drift Website.** Renamed in 2.0.0. Sites updating from 1.x keep working with no manual steps: they keep the `drift-website/` folder, settings and synced content move to the new names automatically, and every 1.x name (functions, constants, the `drift/v1` webhook, `?drift_publish=` links) still works until 3.0. See "Upgrading from Drift Website 1.x" below.

- **Requires:** WordPress 6.2+, PHP 8.0+, OpenSSL. ACF Pro is needed for the Setup Wizard's modules.
- **Repo:** https://github.com/drift-creative-systems/encore-website
- **Self-updates** from GitHub releases (bundled Plugin Update Checker, checked every 6 hours).
- **Pairs with its product theme.** A map can name its theme (`'theme'` key). For Encore that's the [Encore theme](https://github.com/drift-creative-systems/encore-theme): until it's active, Encore Website shows an **Install & activate** notice and the Setup Wizard is off. The theme in turn shows a holding page until this plugin is active. `encore-bundle.zip` on each theme release contains both.

## How it works

1. Each client has their own Airtable base, duplicated from the product's template base (`docs/ENCORE-AIRTABLE-BASE.md`).
2. Encore Website → Connection holds the base ID, an encrypted token and the product.
3. The client ticks **Publish** in Airtable. The automation (`docs/airtable-publish-automation.js`) stamps the time and POSTs to `/wp-json/encore/v1/publish`.
4. The site queues a background sync. Every table in the map is read (about 1 API call each) and written into normal WordPress posts, meta, terms, media and one settings option. Templates read WordPress data and never call Airtable.
5. A daily safety check reads one field and re-syncs only if a publish was missed.

## Admin

**Encore Website** (agency users only, once one exists) has these tabs:

- **Connection:** credentials, product, webhook URL and secret, and a schema check against the map.
- **Sync:** last run, API usage this month against the budget, Sync now / Full resync, and the activity log.
- **Content:** which table goes where, and the current site settings.
- **White Label:** admin bar, footer, login screen, and which menus clients see.
- **Setup Wizard:** creates the product's pages with modules pre-filled and builds the Main Menu.

The admin bar also has **Sync from Airtable** for editors.

## Theme API

```php
encore_website_setting( 'name' );                       // synced site setting
encore_website_setting_image( 'logo', 'medium' );       // <img> for an image setting
encore_website_linked_posts( get_the_ID(), 'tracks' );  // linked records, Airtable order
encore_website_form_hidden_fields( 'enquiry' );         // inside a form posting to admin-ajax.php
encore_website_last_synced();
```

Synced post meta is plain WordPress meta (`get_post_meta( $id, 'gig_date', true )`), so ACF's `get_field()` works on it too.

## wp-config.php options

```php
define( 'ENCORE_WEBSITE_AIRTABLE_BASE', 'appXXXXXXXXXXXXXX' );  // overrides the screen
define( 'ENCORE_WEBSITE_AIRTABLE_TOKEN', 'pat…' );               // overrides the screen
define( 'ENCORE_WEBSITE_KEY', 'long-random-string' );            // encryption key (defaults to WP salts)
define( 'ENCORE_WEBSITE_GITHUB_TOKEN', 'github_pat_…' );         // only if the repo is private
```

## Releasing

1. Bump `Version:` and `ENCORE_WEBSITE_VERSION` in `encore-website.php`, and add a `CHANGELOG.md` entry.
2. Commit and push to `main`.
3. Build the zip: `python tools/build-zip.py` writes `dist/encore-website.zip` with an `encore-website/` top folder, `lib/` included and `.git`, `tests/`, `tools/`, `dist/`, `.gitignore` and `CLAUDE.md` left out.
4. Create a GitHub release tagged `vX.Y.Z` with that zip attached. Sites see it in Dashboard → Updates within 6 hours, or straight away with "Check again" on the Plugins screen. The zip must be attached: release-assets mode is on, so plain source archives are ignored.

## Upgrading from Drift Website 1.x

Automatic on the first page load after the update:

- `drift-website/drift-website.php` (a header-less loader) switches the active plugin entry to `encore-website.php` in the same folder.
- `Encore_Website_Migrate` renames the stored options, post meta (`_drift_airtable_id` and friends) and user meta, re-encrypts the saved token and publish secret, and swaps the cron hooks. It runs once and never overwrites data already under a new name.

Worth doing per client, when convenient:

- Change the Airtable Publish automation's webhook to `/wp-json/encore/v1/publish` and its header to `X-Encore-Secret` (the old URL and header keep working).
- Rename `DRIFT_WEBSITE_*` constants in `wp-config.php` to `ENCORE_WEBSITE_*` (the old ones keep working).
- Update the Encore theme to 1.3.0+, which calls the new `encore_website_*` functions.

## Docs

| File | What |
|---|---|
| `CLAUDE.md` | Architecture rules and conventions (read before changing anything) |
| `docs/MAP-REFERENCE.md` | Every map key and field type |
| `docs/ENCORE-AIRTABLE-BASE.md` | The Encore template base, table by table |
| `docs/airtable-publish-automation.js` | The Publish automation script |
| `tests/fake-airtable-mu-plugin.php` | Local test fixture faking Airtable (never deploy) |

## Licence

Split licence, © Drift Creative Systems:

- **PHP files:** GPL-2.0-or-later (`GPL-2.0.txt`), because they run inside WordPress.
- **Everything else** (CSS, JavaScript, media, docs, the Airtable template and its scripts): proprietary, all rights reserved. They can't be copied, modified, redistributed or used in a competing product without written permission.
- **`lib/plugin-update-checker/`:** MIT, by its author.
- **Names:** "Encore Website" and "Encore" are reserved. Modified versions can't be distributed under them.

The full terms are in `LICENSE`.
