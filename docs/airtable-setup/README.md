# Setting up Airtable for Encore

Building the template takes about 30 minutes once. After that, each new band takes about 10 minutes.

You need Node 18 or newer on your machine (Claude Code already requires it). Check with `node -v`.

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
| Publish | Record detail | The Site Settings record, showing only the **Publish website** button, with the note "Click when you're ready — your website updates straight away" |
| Gigs | List + record detail | Gigs sorted by Date, plus an "Add gig" form button |
| Music | List + record detail | Releases, with the linked Tracks shown on each release |
| Media | Grid | Gallery, plus Videos |
| About | List | Members, plus Press |
| Shop | Grid | Merch |
| Inbox | List | Enquiries filtered to Status = New |

Keep it simple. The goal is that a band never needs the raw grid.

## Part B: each new band

### 1. Their own base

- Create a workspace named after the band (Free plan). Each workspace gets its own 1,000 API calls a month.
- On the template base: **⋯ → Duplicate base** → choose the band's workspace. Untick *Duplicate records* for a real client; leave it ticked for a demo.
- In the new base, delete any demo rows, then add one row in **Site Settings** with at least the Artist Name.

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

Share the base with the band as **Editor**, not Creator, so they can edit content but can't change tables or fields. Point them at the Interface.

## Costs to keep in mind (Free plan, per band workspace)

- Each publish uses about 11 API calls (one per table), plus 1 automation run on the paid-plan automation route.
- The daily safety check uses 1 API call a day.
- Each website form submission uses 1 API call.
- That leaves room for about 60 publishes a month, well inside the 1,000-call and 100-run limits.
- A band site uses roughly 150–400 records, against a limit of 1,000.
