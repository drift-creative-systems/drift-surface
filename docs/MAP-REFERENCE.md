# Product map reference

A product map is a PHP file returning an array, in `maps/{slug}.php` or registered with the `drift_surface_maps` filter. It is the only product-specific code in the plugin. Everything else (sync, post types, forms, wizard) is generic and reads from it. `maps/surface.php` is the worked example.

## Top level

| Key | Type | Purpose |
|---|---|---|
| `label` | string | Shown in the admin header and the product picker |
| `description` | string | |
| `page_builder_field` | string | ACF flexible content field name (or key) the wizard fills. Default `page_builder` |
| `publish` | `[ 'field' => 'Last Published' ]` | Settings-table field stamped by the hub's Publish button, used by the daily check |
| `settings` | array | One-row table → one option (below) |
| `entities` | array | Tables → posts (below) |
| `taxonomies` | array | Taxonomies to register |
| `forms` | array | Website → hub forms |
| `pages` | array | Setup Wizard page definitions |
| `theme` | `[ 'slug', 'name', 'zip' ]` | Optional. The theme this product renders with. Until it (or a child of it) is active, Drift: Surface shows an Install & activate notice (from `zip`) and the Setup Wizard is off |

## `settings`

```php
'settings' => [
    'table'  => 'Site Settings',
    'option' => 'surface_site_settings',         // read with drift_surface_setting( 'key' )
    'fields' => [ 'Artist Name' => [ 'to' => 'name', 'type' => 'text' ], … ],
],
```

Only the first row is read (`maxRecords=1`, so 1 hub request). Image fields store an attachment ID; a pending image keeps the previous one until it lands.

A settings field can also mirror into a core WordPress option with `'wp_option'`:

```php
'Artist Name' => [ 'to' => 'name', 'type' => 'text', 'wp_option' => 'blogname' ],        // Site Title
'Tagline'     => [ 'to' => 'tagline', 'type' => 'text', 'wp_option' => 'blogdescription' ], // Tagline
```

Only `blogname` and `blogdescription` are allowed (`Drift_Surface_Sync_Engine::WP_OPTIONS`); anything else is logged and skipped. A blank or missing hub value leaves the WordPress value as it is.

## `entities`

```php
'gigs' => [
    'table'        => 'Gigs',                 // hub table name
    'post_type'    => 'surface_gig',          // max 20 chars; 'post' for native posts
    'register'     => [ … ] | false,          // register the post type (see below), false if it exists already
    'title'        => 'Gig',                  // field → post_title. Rows with a blank title are skipped.
    'slug'         => '',                     // optional field → post_name
    'status_field' => 'Show on Site',         // optional checkbox; unticked = treated as removed
    'sort'         => [ [ 'field' => 'Date', 'direction' => 'asc' ] ],
    'order'        => 'row',                  // menu_order: 'row' = position after sorting, or a number field name
    'on_remove'    => 'trash',                // trash | draft | delete
    'fields'       => [ 'Venue' => [ 'to' => 'meta:venue', 'type' => 'text' ], … ],
],
```

`register` keys are `label`, `singular`, `public` (default true), `rewrite`, `has_archive`, `menu_icon`, `menu_position` and `supports`. Adjust anything further with the `drift_surface_post_type_args` filter.

## Field specs: `'Hub field' => [ 'to' => …, 'type' => … ]`

The `'Field' => 'content'` shorthand also works; the type is then inferred.

**`to` (posts):**

| `to` | Writes to |
|---|---|
| `content` | post_content (`html` type converts Markdown) |
| `excerpt` | post_excerpt |
| `post_date` | post date; a future date makes the post scheduled |
| `thumbnail` | featured image |
| `meta:{key}` | post meta `{key}` |
| `tax:{taxonomy}` | terms, created if new |

**`type`:**

| `type` | Stored as |
|---|---|
| `text` | plain string |
| `html` | safe HTML from hub rich text (Markdown) |
| `date` | `Y-m-d` |
| `datetime` | `Y-m-d H:i:s`, site timezone |
| `number` | int/float, or `''` |
| `bool` | `'1'` or `''` |
| `url` | validated URL |
| `email` | validated email |
| `list` | array of strings (multi-select, lookups) |
| `json` | raw value |
| `embed` | `<iframe>` tags only, `https` sources only; everything else (scripts, `srcdoc`, surrounding text) is stripped. Use with a Long text field, rich text **off** |
| `image` | attachment ID (first attachment) |
| `gallery` | array of attachment IDs |
| `link` | array of WordPress post IDs for linked records, in the hub's order. Read with `drift_surface_linked_posts( $post_id, 'key' )` |

## `forms`

```php
'enquiry' => [
    'table'    => 'Enquiries',
    'fields'   => [ 'name' => 'Name', 'email' => 'Email', 'message' => 'Message' ], // input name => hub field
    'required' => [ 'name', 'email', 'message' ],
    'email'    => [ 'email' ],
    'notify'   => 'booking_email',   // settings key holding the address; false = email only if the hub fails
    'subject'  => 'New enquiry from the website',
],
```

In the theme's form markup:

```php
<form class="ds-form" method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">
    <?php drift_surface_form_hidden_fields( 'enquiry' ); ?>
    <input name="name"> <input name="email" type="email"> <textarea name="message"></textarea>
    <button>Send</button>
</form>
```

Submit via AJAX. The JSON response is `{ success, data: { message, fields[] } }`, where `fields` lists invalid inputs.

## `pages`

```php
[
    'id' => 'live', 'title' => 'Live', 'slug' => 'live',   // slug '' = front page
    'description' => '…', 'tags' => [ 'Gigs' ], 'required' => true, 'in_menu' => true,
    'parent' => null, 'template' => null, 'companions' => [],
    'rows' => [ [ 'acf_fc_layout' => 'gigs_module', 'section_title' => 'Upcoming' ] ],
],
```

Use `Drift_Surface_Page_Creator::IMAGE_PLACEHOLDER` for any image sub-field. The layouts named in `rows` must exist in the active theme's `page_builder` field.

## Hooks

| Hook | Type | Use |
|---|---|---|
| `drift_surface_maps` | filter | Register extra map files |
| `drift_surface_map` | filter | Adjust the loaded map |
| `drift_surface_post_type_args` / `drift_surface_taxonomy_args` | filter | Registration args |
| `drift_surface_admin_tabs` | filter | Add Drift: Surface admin tabs |
| `drift_surface_form_values` | filter | Alter a form's hub fields before saving |
| `drift_surface_loaded` | action | Plugin booted |
| `drift_surface_synced` | action | After every sync run (status array) |
| `drift_surface_form_submitted` | action | After a form submission |
