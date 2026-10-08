# Drift: Surface

The site engine behind **Surface** band and artist websites, by Drift Creative Systems. Content is edited in a [Drift: Surface Hub](https://github.com/drift-creative-systems/drift-hub), the artist or label presses Publish, and the WordPress site updates.

The engine is generic: Surface is a map file (`maps/surface.php`) plus the Surface theme (`surface-theme`).

- **Requires:** WordPress 6.2+, PHP 8.0+, OpenSSL, and a Drift: Surface Hub (drift-hub 1.4.0+). ACF Pro is needed for the Setup Wizard's modules.
- **Repo:** https://github.com/drift-creative-systems/drift-surface
- **Self-updates** from GitHub releases (bundled Plugin Update Checker, checked every 6 hours).
- **Pairs with its product theme.** A map can name its theme (`'theme'` key). For Surface that's [Drift: Surface Theme](https://github.com/drift-creative-systems/surface-theme) (`surface-theme`). Until it's active, Drift: Surface shows an **Install & activate** notice and the Setup Wizard is off. The theme in turn shows a holding page until this plugin is active. `surface-bundle.zip` on each theme release contains both.

## How the four Drift: Surface repos fit together

Drift: Surface is four repos, released separately. This section is the same in all four READMEs; update it in all four.

| Repo | Runs on | Job |
|---|---|---|
| [`drift-hub`](https://github.com/drift-creative-systems/drift-hub) (plugin) | the hub site | Where content is edited. `schemas/surface.php` defines every table and field. Serves the website API (`/wp-json/drift-hub/v0/`) and sends Publish webhooks. |
| [`drift-hub-theme`](https://github.com/drift-creative-systems/drift-hub-theme) (theme) | the hub site | Blank. Redirects the front end to the hub; 503 page if the plugin is off. |
| [`drift-surface`](https://github.com/drift-creative-systems/drift-surface) (plugin) | each artist site | Syncs from the hub. `maps/surface.php` says which hub table/field lands in which post type, meta key or setting. Receives Publish at `/wp-json/drift-surface/v1/publish`. |
| [`surface-theme`](https://github.com/drift-creative-systems/surface-theme) (theme) | each artist site | Renders what `drift-surface` wrote: `surface_*` post types, post meta, settings. Module names are a contract with the map's `pages[].rows`. |

```
drift-hub schemas/surface.php ──API──▶ drift-surface maps/surface.php ──WP posts/meta/settings──▶ surface theme templates
        ▲                                        │
        └──────── Publish webhook (hub → site) ──┘
drift-hub-theme: only cares about the hub's URL (Drift_Hub_App::url())
```

### When something changes in the hub

**Adding or changing a field or table** in `drift-hub/schemas/surface.php`:
1. **drift-hub:** add it to the schema. If it's `'hub_only' => true` (e.g. Hub Avatar), stop here: websites never see it.
2. **drift-surface:** add the same table/field name, type and select options to `maps/surface.php`, with its `to` key (meta key or setting). Names must match exactly; **Check connection** on the Connection tab compares them.
3. **Surface theme:** show it in the module or single template that needs it (read via `get_post_meta()` or the setting helpers). Add ACF JSON if a module gets a new option.
4. **drift-hub-theme:** usually nothing. It only changes if the hub's URL, root mode or `Drift_Hub_App::url()` changes.

**Release order:**
- **New fields:** release the **hub first**, then drift-surface + theme. The website asks for the fields in its map (`fields[]`), and the hub answers **422** to any field name it doesn't know, so a map that runs ahead of the hub breaks that table's sync.
- **Renames and removals:** the **website first** (stop asking for the old name), then the hub. In the hub, use `'was'` for renames; there are no migrations.
- **Webhook or API contract changes** (path, header, response shape): release both together and say so in both CHANGELOGs (e.g. hub 1.4.0 ↔ Drift: Surface 3.0).

## How it works

1. Each client's content lives in a Drift: Surface Hub, one artist per hub record.
2. Drift: Surface → Connection holds the hub address, the Base ID, an encrypted token and the product. All three connection values are on the artist's page in the hub (Website connection).
3. The artist or label presses **Publish** in the hub. The hub stamps "Last Published" on the artist's Site Settings and POSTs to `/wp-json/drift-surface/v1/publish` with an `X-Drift-Surface-Secret` header.
4. The site queues a background sync. Every table in the map is read (about 1 hub request each) and written into normal WordPress posts, meta, terms, media and one settings option. Templates read WordPress data and never call the hub.
5. A daily safety check reads one field and re-syncs only if a publish was missed.

## Connecting a site to the hub

1. In the hub, open the artist (wp-admin → Artists). The **Website connection** box shows the Data source, Base ID and a **Generate token** button. The token is shown once.
2. On the website, go to **Drift: Surface → Connection**, paste the Data source into **Hub address**, along with the Base ID and token, and save.
3. Copy the **Website address** and **Publish secret** from the same tab into the artist's Website connection box in the hub.
4. Press **Check connection**, then **Sync now** on the Sync tab.

If the check says HTTP 401 with the right token, the hub's server is probably stripping the `Authorization` header. The hub README has the one-line `.htaccess` fix.

## Admin

**Drift: Surface** (agency users only, once one exists) has these tabs:

- **Connection:** hub address, Base ID and token, product, the website address and publish secret for the hub, and a schema check against the map.
- **Sync:** last run, last publish received, next daily check, Sync now / Full resync, and the activity log.
- **Content:** which table goes where, and the current site settings.
- **White Label:** admin bar, footer, login screen, and which menus clients see.
- **Setup Wizard:** creates the product's pages with modules pre-filled and builds the Main Menu.

The admin bar also has **Sync from hub** for editors.

## Theme API

```php
drift_surface_setting( 'name' );                       // synced site setting
drift_surface_setting_image( 'logo', 'medium' );       // <img> for an image setting
drift_surface_linked_posts( get_the_ID(), 'tracks' );  // linked records, in the hub's order
drift_surface_form_hidden_fields( 'enquiry' );         // inside a form posting to admin-ajax.php
drift_surface_last_synced();
```

Synced post meta is plain WordPress meta (`get_post_meta( $id, 'gig_date', true )`), so ACF's `get_field()` works on it too.

## wp-config.php options

```php
define( 'DRIFT_SURFACE_HUB_URL', 'https://hub.example/wp-json/drift-hub/v0/' ); // overrides the screen
define( 'DRIFT_SURFACE_HUB_BASE', 'appXXXXXXXXXXXXXX' );        // overrides the screen
define( 'DRIFT_SURFACE_HUB_TOKEN', 'hub_…' );                   // overrides the screen
define( 'DRIFT_SURFACE_KEY', 'long-random-string' );            // encryption key (defaults to WP salts)
define( 'DRIFT_SURFACE_GITHUB_TOKEN', 'github_pat_…' );         // only if the repo is private
```

## Releasing

1. Bump `Version:` and `DRIFT_SURFACE_VERSION` in `drift-surface.php`, and add a `CHANGELOG.md` entry.
2. Commit and push to `main`.
3. Build the zip: `python tools/build-zip.py` writes `dist/drift-surface.zip` with a `drift-surface/` top folder, `lib/` included and `.git`, `tests/`, `tools/`, `dist/`, `.gitignore` and `CLAUDE.md` left out.
4. Create a GitHub release tagged `vX.Y.Z` with that zip attached. Sites see it in Dashboard → Updates within 6 hours, or straight away with "Check again" on the Plugins screen. The zip must be attached: release-assets mode is on, so plain source archives are ignored.

## Docs

| File | What |
|---|---|
| `CLAUDE.md` | Architecture rules and conventions (read before changing anything) |
| `docs/MAP-REFERENCE.md` | Every map key and field type |
| `drift-hub/schemas/surface.php` (hub repo) | The hub's Surface schema, which the map must match |

## Licence

Split licence, © Drift Creative Systems:

- **PHP files:** GPL-2.0-or-later (`GPL-2.0.txt`), because they run inside WordPress.
- **Everything else** (CSS, JavaScript, media, docs): proprietary, all rights reserved. They can't be copied, modified, redistributed or used in a competing product without written permission.
- **`lib/plugin-update-checker/`:** MIT, by its author.
- **Names:** "Drift: Surface" and "Surface" are reserved. Modified versions can't be distributed under them.

The full terms are in `LICENSE`.
