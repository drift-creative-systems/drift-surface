# Drift Website

The Airtable-powered site engine behind the **Drift App Suite** by Drift Creative Systems. Clients edit their content in Airtable and press Publish; their WordPress site updates.

The first product is **Encore** (band and artist sites). Each product is a map file plus a theme. The engine is shared.

- **Requires:** WordPress 6.2+, PHP 8.0+, OpenSSL. ACF Pro is needed for the Setup Wizard's modules.
- **Repo:** https://github.com/drift-creative-systems/drift-website
- **Self-updates** from GitHub releases (bundled Plugin Update Checker, checked every 6 hours).

## How it works

1. Each client has their own Airtable base, duplicated from the product's template base (`docs/ENCORE-AIRTABLE-BASE.md`).
2. Drift → Connection holds the base ID, an encrypted token and the product.
3. The client ticks **Publish** in Airtable. The automation (`docs/airtable-publish-automation.js`) stamps the time and POSTs to `/wp-json/drift/v1/publish`.
4. The site queues a background sync. Every table in the map is read (about 1 API call each) and written into normal WordPress posts, meta, terms, media and one settings option. Templates read WordPress data and never call Airtable.
5. A daily safety check reads one field and re-syncs only if a publish was missed.

## Admin

**Drift** (agency users only, once one exists) has these tabs:

- **Connection:** credentials, product, webhook URL and secret, and a schema check against the map.
- **Sync:** last run, API usage this month against the budget, Sync now / Full resync, and the activity log.
- **Content:** which table goes where, and the current site settings.
- **White Label:** admin bar, footer, login screen, and which menus clients see.
- **Setup Wizard:** creates the product's pages with modules pre-filled and builds the Main Menu.

The admin bar also has **Sync from Airtable** for editors.

## Theme API

```php
drift_setting( 'name' );                       // synced site setting
drift_setting_image( 'logo', 'medium' );       // <img> for an image setting
drift_linked_posts( get_the_ID(), 'tracks' );  // linked records, Airtable order
drift_form_hidden_fields( 'enquiry' );         // inside a form posting to admin-ajax.php
drift_last_synced();
```

Synced post meta is plain WordPress meta (`get_post_meta( $id, 'gig_date', true )`), so ACF's `get_field()` works on it too.

## wp-config.php options

```php
define( 'DRIFT_WEBSITE_AIRTABLE_BASE', 'appXXXXXXXXXXXXXX' );  // overrides the screen
define( 'DRIFT_WEBSITE_AIRTABLE_TOKEN', 'pat…' );               // overrides the screen
define( 'DRIFT_WEBSITE_KEY', 'long-random-string' );            // encryption key (defaults to WP salts)
define( 'DRIFT_WEBSITE_GITHUB_TOKEN', 'github_pat_…' );         // only if the repo is private
```

## Releasing

1. Bump `Version:` and `DRIFT_WEBSITE_VERSION` in `drift-website.php`, and add a `CHANGELOG.md` entry.
2. Commit and push to `main`.
3. Build the zip: `python tools/build-zip.py` writes `dist/drift-website.zip` with a `drift-website/` top folder, `lib/` included and `.git`, `tests/`, `tools/` and `dist/` left out.
4. Create a GitHub release tagged `vX.Y.Z` with that zip attached. Sites see it in Dashboard → Updates within 6 hours, or straight away with "Check again" on the Plugins screen. The zip must be attached: release-assets mode is on, so plain source archives are ignored.

## Docs

| File | What |
|---|---|
| `CLAUDE.md` | Architecture rules and conventions (read before changing anything) |
| `docs/MAP-REFERENCE.md` | Every map key and field type |
| `docs/ENCORE-AIRTABLE-BASE.md` | The Encore template base, table by table |
| `docs/airtable-publish-automation.js` | The Publish automation script |
| `tests/fake-airtable-mu-plugin.php` | Local test fixture faking Airtable (never deploy) |
