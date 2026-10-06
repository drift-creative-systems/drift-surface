# Setting up Airtable for Encore

Building the template takes about 30 minutes once. After that, each new band takes about 10 minutes.

You need Node 18 or newer on your machine (Claude Code already requires it). Check with `node -v`.

- **Part A** builds the golden template base (once).
- **Part B** sets up each new band from that template.
- **Part C** brings existing bands' bases up to date when the template changes, using the script's migrate mode.
- **Part D** is how the band uses the live and merch embeds. Copy it into your handover notes.

## Part A: the template base (once)

### 1. Workspace and base

- In Airtable, create a workspace called **Drift — Encore templates** (Free plan).
- Inside it: **Create → Start from scratch**. Name the base **Encore — Template**.
- Copy the base ID from the address bar: `airtable.com/`**`appXXXXXXXXXXXXXX`**`/…`

### 2. Setup token (temporary)

At **airtable.com/create/tokens → Create token**:

- **Name:** `Encore setup — delete after`
- **Scopes:** `schema.bases:read`, `schema.bases:write`, `data.records:read`, `data.records:write`
- **Access:** only the *Encore — Template* base

Copy the token. It's shown once.

### 3. Run the builder

Open a terminal in this folder (`drift-website/docs/airtable-setup`) and run:

```bash
# macOS / Linux / Git Bash
export AIRTABLE_TOKEN="<your setup token>"
node setup-encore-base.mjs --base appXXXXXXXXXXXXXX
```

```powershell
# Windows PowerShell
$env:AIRTABLE_TOKEN = "<your setup token>"
node setup-encore-base.mjs --base appXXXXXXXXXXXXXX
```

The token is read from `AIRTABLE_TOKEN` so it never lands in shell history or in docs. (`--token` still works, but avoid it.)

Add `--demo velvet` (indie four-piece) or `--demo hollin` (solo folk) to fill it with a demo band, including placeholder images. Add `--dry-run` to see what it would do without changing anything.

It creates all 12 tables with the exact field names and types the website expects. That includes the Releases ↔ Tracks link, select options, £ currency, UK date formats and Europe/London time. It takes about 35 API calls and is safe to run again.

It also creates the two optional embed fields in **Site Settings**: **Live Embed** and **Merch Embed** (Long text, rich text off). The demos leave them empty, so the demo site shows the normal gig list and merch grid.

### 4. Finish by hand

The API can't do these. They take about 2 minutes, and the script prints this list at the end.

1. **Gigs → Gig** field → Edit field → **Formula**: `{Venue} & ", " & {City}`
2. **Gallery → Title** field → Edit field → **Formula**: `IF({Caption}, {Caption}, "Photo")`
3. **Enquiries:** add a **Received** field (Created time). **Subscribers:** add a **Joined** field (Created time).
4. Delete the empty **Table 1**.
5. **Site Settings:** add Logo and Logo (Light) images if you have them. Leaving them blank makes the site show the name in type, which works fine.
6. **Videos:** add 2–3 rows with real YouTube or Vimeo links, tick *Show on Site*, and tick *Featured* on one.

Then delete the setup token at airtable.com/create/tokens.

### 5. Publish button

The Free plan can't run scripts in automations, so publishing uses a **button field** that opens the site's publish link. Opening the link updates the site immediately and shows the band a "Your website is up to date" page. It uses no automation runs and about 1 API call per table.

In **Site Settings**:

1. Click **+** to add a field. Choose type **Button**.
2. **Field name:** `Publish website`. **Label:** `Publish website`. **Style:** a strong colour.
3. **Action:** *Open URL*. **URL formula:** `"REPLACE_PER_SITE"` (keep the quotes). Each band's real link goes in later.
4. Optionally, hide the old **Publish** checkbox and **Last Published** fields. They're only used by the paid-plan automation.

On a paid plan you can use the automation instead. Trigger: *When record matches conditions* on Site Settings, with Publish checked. Action: *Run a script* using `docs/airtable-publish-automation.js`, with input variables `recordId`, `webhookUrl` and `secret`.

### 6. Interface (what the band sees)

Go to **Interfaces → Start building** and add these pages:

| Page | Layout | Shows |
|---|---|---|
| Publish | Record detail | The Site Settings record: the **Publish website** button, with the note "Click when you're ready — your website updates straight away", then the **Live Embed** and **Merch Embed** fields underneath (see below) |
| Gigs | List + record detail | Gigs sorted by Date, plus an "Add gig" form button |
| Music | List + record detail | Releases, with the linked Tracks shown on each release |
| Media | Grid | Gallery, plus Videos |
| About | List | Members, plus Press |
| Shop | Grid | Merch |
| Inbox | List | Enquiries filtered to Status = New |

Keep it simple. The goal is that a band never needs the raw grid.

#### Adding the embed fields to the Publish page

The Publish page is the only page built on Site Settings, so the embed fields go there. The Gigs and Shop pages are built on other tables and can't easily show them.

1. In the interface editor, select the **Publish** page in the left-hand page list.
2. Click the Site Settings record area, so the properties panel opens on the right.
3. In the panel's **Fields** section, turn on **Live Embed** and **Merch Embed**.
4. Click each field on the page and set it to **Editable**. Fields added to an interface can start as read-only, and then the band can't paste into them.
5. Drag both fields below the **Publish website** button.
6. Add a **Text** element above them:
   > **Tour dates or shop widget (optional).** Paste embed code here and it replaces your gig list or merch on the website. Leave it empty to show them as normal. Then click Publish website.

If you'd rather keep the Publish page as just the button, add a separate page instead: **+ Add page → Record detail**, with Site Settings as the source. Show only the two embed fields and the text above, and call it **Website extras**.

#### Publish the interface

When you've finished, click **Publish** at the top right of the interface editor. The band sees nothing you change until you do this. It's Airtable's own button and has nothing to do with the **Publish website** field.

Airtable renames things in the Interface editor now and then. If a label above doesn't match, look for the nearest equivalent in the right-hand properties panel.

## Part B: each new band

### 1. Their own base

- Create a workspace named after the band (Free plan). Each workspace gets its own 1,000 API calls a month.
- On the template base: **⋯ → Duplicate base** → choose the band's workspace. Untick *Duplicate records* for a real client; leave it ticked for a demo.
- In the new base, delete any demo rows, then add one row in **Site Settings** with at least the Artist Name.
- Duplicating copies the Interface too. Open it, check the Publish page still shows the embed fields, and click **Publish** in the interface editor.

### 2. Website token

At **airtable.com/create/tokens**:

- **Name:** `Website — Band Name`
- **Scopes:** `data.records:read`, `data.records:write`, `schema.bases:read`. Don't add schema write.
- **Access:** only that band's base

### 3. Connect WordPress

On the band's site, with the Drift Website plugin and Encore theme active:

1. Go to **Drift → Connection**. Paste the base ID and token, choose product *Encore*, and click **Save**.
2. Click **Check connection**. It should say every table and field is present.
3. Go to **Drift → Sync → Sync now**. Content appears on the site. Images import in batches; the rest continue automatically a minute later.
4. Run **Drift → Setup Wizard → Select all → Create**, if the pages don't exist yet.

### 4. Set up the Publish button

1. On Drift → Connection, copy the **Publish link (Free plan)**.
2. In the band's base, go to **Site Settings → Publish website** field → **Edit field**. Replace `"REPLACE_PER_SITE"` with the link, inside quotes: `"https://bandsite.co.uk/?drift_publish=…"`
3. Test it: click the button. A new tab says "Your website is up to date" and lists what changed.

The link contains the site's secret, so only share it inside the band's own base. If it ever leaks, use "Generate a new secret" on the Connection tab and update the button.

On Free, you can also untick **Daily safety check** on the Connection tab. With no Last Published stamp it does a full sync each night (about 330 calls a month), which is only worth keeping as a nightly refresh.

### 5. Invite the band

Share the base with the band as **Editor**, not Creator, so they can edit content but can't change tables or fields. Point them at the Interface, and send them Part D if they want to use a tour-dates or shop widget.

## Part C: updating existing bases to the latest template

When the master template changes (new fields, new tables), bring every band's base up to date with the script's **migrate** mode. It finds every Encore base your token can see, across all workspaces, and:

- adds missing tables, fields and links
- **never** deletes, renames or changes the type of anything
- stamps the template version at the end of the **Site Settings** table description, e.g. `[Encore template v2]`
- lists anything the API can't do, per base: formulas, missing select options, wrong field types, Created time and button fields, the Interface

### 1. Maintenance token (once)

At **airtable.com/create/tokens → Create token**:

- **Name:** `Drift maintenance`
- **Scopes:** `schema.bases:read`, `schema.bases:write`. No data scopes are needed.
- **Access:** every client workspace, or "All current and future bases in all current and future workspaces"

Your Airtable account needs **Creator** or **Owner** access in each workspace. Bases where it only has read or edit access are reported and skipped. Keep this token in a password manager, and never give it to a website.

### 2. Dry run

From this folder:

```bash
# macOS / Linux / Git Bash
export AIRTABLE_TOKEN="<maintenance token>"
node setup-encore-base.mjs --migrate --all
```

```powershell
# Windows PowerShell
$env:AIRTABLE_TOKEN = "<maintenance token>"
node setup-encore-base.mjs --migrate --all
```

Nothing changes. For each base it prints what it would add, the version it would move from and to, and a **To do by hand** list. Bases that aren't Encore bases (no Site Settings, Gigs and Releases tables) are ignored.

To check only some bases, use `--bases appXXXX,appYYYY` (or `--base appXXXX`) instead of `--all`.

### 3. Apply

```bash
node setup-encore-base.mjs --migrate --all --apply
```

Then work through each base's **To do by hand** list. Re-running is safe and costs 1 API call per base. It keeps listing hand jobs until they're done, but the Interface reminder only appears on the run that adds the fields, so note it down.

### 4. Then update the websites

Release the plugin and theme update, then publish each site (or **Drift → Sync → Sync now**) and check **Drift → Connection → Check connection**.

Since plugin 1.2.0 the order isn't critical: if a site updates before its base is migrated, it skips the missing fields, keeps their current values, and notes them in the sync log. The rest of the content still syncs.

### API cost

A dry run costs 1 call per base the token can see, plus 1 to list them. Applying adds 1 call per field or table created and 1 for the version stamp. These count against each base's own workspace allowance, so a typical update costs a few calls per band.

### When you change the template (for Bonsai developers)

1. Edit `SCHEMA` in `setup-encore-base.mjs`, and `maps/encore.php` and `docs/ENCORE-AIRTABLE-BASE.md` to match.
2. Bump `TEMPLATE_VERSION` and add a line to the version history above it.
3. If the API can't create the new thing (formula, button, Created time), add it to `FORMULAS` or `HAND_FIELDS` so the migrate checklist picks it up.
4. Run the dry run, then `--apply`, then release the plugin.

### Without the script

To add a field by hand, e.g. the v2 embed fields: in **Site Settings** click **+**, name it exactly `Live Embed`, set the type to **Long text** with **rich text formatting off**, and create it. Repeat for `Merch Embed`. Then add both to the Interface (Part A step 6). The base won't be version-stamped, so the next migrate run will stamp it.

## Part D: using the live and merch embeds (for the band)

You can show a tour-dates widget on your Live section, or a shop widget on your Merch page, instead of the lists the website builds from Airtable.

### Add an embed

1. On the provider's site, find **Share**, **Embed** or **Widget**, and copy the embed code. On Bandcamp, for example: **Share/Embed → Embed this album**, pick a style, then copy the HTML.
2. In your Airtable interface, paste it into **Live Embed** (tour dates) or **Merch Embed** (shop).
3. Click **Publish website**.

On the website, visitors see a **Show tour dates** or **Show the shop** button. The widget loads when they press it. Nothing from the provider loads, and no cookies are set, until then.

### What works

- **Works:** embed code that's an `<iframe>` starting with `https://`. Bandcamp players work this way, and so does any provider whose embed code starts with `<iframe`.
- **Doesn't work:** embed code containing `<script>`. Many tour-date and shop widgets (Bandsintown, Songkick, Seated, Shopify Buy Button) use scripts. The website removes them for security, so nothing shows. Ask us if you need one of these.
- **Doesn't work:** plain links. Paste the embed code, not the page address.

If you paste something that doesn't work, the website ignores it and keeps showing your normal gig list or merch.

### Things to know

- The Live Embed replaces the gig list **everywhere it appears**, including the "Live" section on the home page.
- Google's event listings come from the gigs in Airtable, not from the widget. Keep adding your gigs in Airtable if you want your shows to appear in Google's event results.

### Go back to the normal lists

Clear the field (select all the text and delete it), then click **Publish website**.

## Costs to keep in mind (Free plan, per band workspace)

- Each publish uses about 11 API calls (one per table), plus 1 automation run on the paid-plan automation route.
- The daily safety check uses 1 API call a day.
- Each website form submission uses 1 API call.
- That leaves room for about 60 publishes a month, well inside the 1,000-call and 100-run limits.
- A band site uses roughly 150–400 records, against a limit of 1,000.
- The embed fields cost nothing extra. They come with the Site Settings call every sync already makes.
