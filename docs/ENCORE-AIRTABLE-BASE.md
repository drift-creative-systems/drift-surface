# Encore — Airtable template base

**Quickest route:** `docs/airtable-setup/` builds all of this through the API in about a minute. This page is the reference for what it builds.

The base every Encore client gets: build it once as the golden template, then duplicate it into each client's own free workspace. Table and field names must match `maps/encore.php` **exactly**, including capitals and spaces. Encore Website → Connection → **Check connection** lists anything that's missing.

Free plan limits apply per base or workspace: 1,000 records, 1 GB attachments, 100 automation runs and 1,000 API calls a month. A band site uses roughly 150–400 records. Each publish costs 1 automation run plus about 11 API calls, so a client can publish around 60 times a month with room to spare.

Fields marked *optional* can be left out of the template. Every field is still requested by the sync, though, so the connection check will flag it. It's simplest to include everything.

## Site Settings (one row only)

| Field | Type | Notes |
|---|---|---|
| **Artist Name** | Single line text | Primary field |
| Tagline | Single line text | |
| Genre | Single line text | |
| Hometown | Single line text | |
| Short Bio | Long text | ~50 words, used in cards and meta |
| Full Bio | Long text, **rich text on** | Bold, italic, links, lists and headings come through |
| Logo | Attachment | PNG/SVG, transparent |
| Logo (Light) | Attachment | For dark backgrounds |
| Hero Image | Attachment | 2400px wide |
| Hero Video URL | URL | Optional background video |
| Primary Colour | Single line text | Hex, e.g. `#ee4367` |
| Secondary Colour | Single line text | Hex |
| Booking Email | Email | Enquiry form notifications go here |
| Management Email | Email | |
| Press Email | Email | |
| Mailing List URL | URL | External signup page, if they use one |
| Press Kit PDF | Attachment | |
| Instagram / Facebook / TikTok / YouTube / X | URL | One field each |
| Spotify / Apple Music / Bandcamp / SoundCloud | URL | Artist profile links |
| SEO Description | Long text | ~155 characters |
| Live Embed | Long text, rich text **off** | *Optional.* Iframe embed code for a tour-dates widget. When set, it replaces the gig list on every gigs module. Scripts are stripped; only `<iframe>` with an `https` source gets through |
| Merch Embed | Long text, rich text **off** | *Optional.* Iframe embed code for a store widget (Bandcamp, Shopify and so on). When set, it replaces the merch grid. Same rules as Live Embed |
| **Publish** | Checkbox | Ticked by the client to publish; the automation unticks it |
| **Last Published** | Date, include time (GMT) | Written by the automation; don't edit |

## Gigs

| Field | Type | Notes |
|---|---|---|
| **Gig** | Formula: `{Venue} & ", " & {City}` | Primary field, becomes the post title |
| Date | Date | Sorted on |
| Doors | Single line text | e.g. `19:30` |
| Venue | Single line text | |
| City | Single line text | |
| Country | Single line text | |
| Ticket URL | URL | |
| Status | Single select | On Sale, Few Left, Sold Out, Free, Cancelled, Announced |
| Support | Single line text | |
| Festival | Checkbox | |
| Notes | Long text (rich) | |
| Show on Site | Checkbox | Unticked rows are removed from the site |

## Releases

| Field | Type | Notes |
|---|---|---|
| **Title** | Single line text | Primary |
| Type | Single select | Single, EP, Album, Live, Remix (becomes a taxonomy) |
| Release Date | Date | Sorted newest first |
| Artwork | Attachment | Square, 3000px |
| Label | Single line text | |
| Description | Long text (rich) | |
| Spotify URL / Apple Music URL / Bandcamp URL / YouTube URL | URL | |
| Pre-save URL | URL | For upcoming releases |
| Featured | Checkbox | For the theme's "Out now" module |
| Tracks | Link to **Tracks** | Order here is the tracklist order |

## Tracks

| Field | Type | Notes |
|---|---|---|
| **Title** | Single line text | Primary |
| Release | Link to **Releases** | The other side of Releases → Tracks |
| Track Number | Number (integer) | |
| Duration | Single line text | `3:41` |
| Writers | Single line text | |
| Preview URL | URL | |
| Lyrics | Long text (rich) | |

## Members

| Field | Type | Notes |
|---|---|---|
| **Name** | Single line text | Primary |
| Role | Single line text | |
| Bio | Long text (rich) | |
| Photo | Attachment | Portrait, 1200px |
| Instagram | URL | |
| Order | Number | Sorted on |
| Show on Site | Checkbox | |

## News

| Field | Type | Notes |
|---|---|---|
| **Headline** | Single line text | Primary |
| Date | Date, include time | Becomes the post date; a future date schedules the post |
| Summary | Long text | Excerpt |
| Body | Long text (rich) | |
| Image | Attachment | |
| Published | Checkbox | |

## Gallery

| Field | Type | Notes |
|---|---|---|
| **Title** | Formula: `IF({Caption}, {Caption}, "Photo")` | Primary |
| Photo | Attachment | One per row |
| Caption | Single line text | |
| Album | Multiple select | e.g. Live, Press, Studio. The EPK page shows "Press". |
| Credit | Single line text | Photographer |
| Order | Number | |
| Show on Site | Checkbox | |

## Videos

| Field | Type | Notes |
|---|---|---|
| **Title** | Single line text | Primary |
| Video URL | URL | YouTube or Vimeo |
| Thumbnail | Attachment | Optional; the theme falls back to the provider thumbnail |
| Date | Date | Sorted newest first |
| Featured | Checkbox | |
| Show on Site | Checkbox | |

## Press

| Field | Type | Notes |
|---|---|---|
| **Publication** | Single line text | Primary |
| Quote | Long text | |
| Author | Single line text | |
| Link | URL | |
| Logo | Attachment | |
| Rating | Rating (5) | |
| Date | Date | |
| Order | Number | |
| Show on Site | Checkbox | |

## Merch

| Field | Type | Notes |
|---|---|---|
| **Item** | Single line text | Primary |
| Image | Attachment | |
| Price | Currency (£) | |
| Store URL | URL | Bandcamp, Shopify, Big Cartel and so on |
| Badge | Single select | New, Limited, Sold Out |
| Order | Number | |
| Show on Site | Checkbox | |

## Enquiries (written by the website)

| Field | Type | Notes |
|---|---|---|
| **Name** | Single line text | Primary |
| Email | Email | |
| Phone | Phone | |
| Enquiry Type | Single select | Booking, Wedding, Festival, Press, Other (typecast adds new options) |
| Event Date | Single line text | Free text from the form |
| Location | Single line text | |
| Message | Long text | |
| Page | URL | Page the form was sent from |
| Received | Created time | Not written by the site |
| Status | Single select | New, Replied, Booked, Declined (for the band's own workflow) |

## Subscribers (written by the website)

| Field | Type | Notes |
|---|---|---|
| **Email** | Email | Primary |
| Name | Single line text | |
| Joined | Created time | |

## Automation

Add one automation, **Publish website**:

- **Trigger:** When a record matches conditions. Table: Site Settings. Condition: Publish is checked.
- **Action:** Run a script, using `docs/airtable-publish-automation.js`.
- **Input variables:**
  - `recordId`: Airtable record ID from the trigger.
  - `webhookUrl`: from Encore Website → Connection.
  - `secret`: from Encore Website → Connection.

Before relying on this for Free-plan clients, check that **Run a script** is available as an automation action on the Free plan. If it isn't, the fallback is the site's daily safety check plus the admin-bar **Sync from Airtable** button. A Make.com route is not a good fallback, because Make's Airtable watcher polls and spends the API allowance.

## Interface (the client's "space")

Build one Interface so the band never sees raw grids. It needs these pages:

- **Publish:** the Site Settings record, with the Publish checkbox front and centre.
- **Gigs:** a list sorted by Date with a quick-add form.
- **Music:** Releases with Tracks.
- **Media:** Gallery and Videos.
- **About:** Members and Press.
- **Shop:** Merch.
- **Inbox:** Enquiries, filtered to Status = New.

## Token

Create the token at airtable.com/create/tokens, scoped to **this base only**, with these scopes:

- `data.records:read` for the sync.
- `data.records:write` for the website's forms.
- `schema.bases:read` for Check connection.

Paste it into Encore Website → Connection, where it's stored encrypted.
