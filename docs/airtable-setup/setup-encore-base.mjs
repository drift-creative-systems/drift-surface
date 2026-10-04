#!/usr/bin/env node
/**
 * Drift: Encore — Airtable base builder.
 *
 * Builds every table and field the Encore map expects (maps/encore.php,
 * docs/ENCORE-AIRTABLE-BASE.md) into an EMPTY base you've already created,
 * and optionally fills it with a demo band.
 *
 *   AIRTABLE_TOKEN=<setup token> node setup-encore-base.mjs --base appXXXXXXXXXXXXXX [--demo velvet|hollin] [--dry-run]
 *
 * (--token also works, but the environment variable keeps the token out of
 * shell history.)
 *
 * Token: create at airtable.com/create/tokens with access to that ONE base and
 * scopes schema.bases:read, schema.bases:write, data.records:read,
 * data.records:write. It's a setup token — delete it afterwards and give the
 * website its own token without schema:write.
 *
 * Safe to re-run: existing tables are kept, only missing fields are added,
 * and demo records are skipped for any table that already has rows.
 *
 * Needs Node 18+ (built-in fetch). No npm install.
 * Uses roughly 30–45 API calls of the base's 1,000 a month.
 *
 * Things the API can't create — finish by hand (2 minutes, listed at the end):
 * formula fields, created-time fields, deleting the default "Table 1".
 */

const API = process.env.AIRTABLE_API || 'https://api.airtable.com/v0';

/* ── Args ─────────────────────────────────────────────────────────────── */
const args = Object.fromEntries(
	process.argv.slice(2).reduce((acc, cur, i, all) => {
		if (cur.startsWith('--')) acc.push([cur.slice(2), all[i + 1] && !all[i + 1].startsWith('--') ? all[i + 1] : true]);
		return acc;
	}, [])
);
const BASE = args.base;
const TOKEN = args.token || process.env.AIRTABLE_TOKEN;
const DEMO = args.demo || '';
const DRY = !!args['dry-run'];

if (!BASE || !/^app[A-Za-z0-9]{14}$/.test(BASE) || !TOKEN) {
	console.error('Usage: AIRTABLE_TOKEN=<setup token> node setup-encore-base.mjs --base appXXXXXXXXXXXXXX [--demo velvet|hollin] [--dry-run]');
	process.exit(1);
}
if (DEMO && !['velvet', 'hollin'].includes(DEMO)) {
	console.error('--demo must be "velvet" or "hollin".');
	process.exit(1);
}

/* ── Field type helpers ───────────────────────────────────────────────── */
const text = (name, description) => ({ name, type: 'singleLineText', ...(description ? { description } : {}) });
const long = (name) => ({ name, type: 'multilineText' });
const rich = (name) => ({ name, type: 'richText' });
const url = (name) => ({ name, type: 'url' });
const email = (name) => ({ name, type: 'email' });
const phone = (name) => ({ name, type: 'phoneNumber' });
const files = (name) => ({ name, type: 'multipleAttachments', options: { isReversed: false } });
const check = (name, description) => ({ name, type: 'checkbox', options: { icon: 'check', color: 'greenBright' }, ...(description ? { description } : {}) });
const date = (name) => ({ name, type: 'date', options: { dateFormat: { name: 'european' } } });
const datetime = (name, description) => ({
	name,
	type: 'dateTime',
	options: { dateFormat: { name: 'european' }, timeFormat: { name: '24hour' }, timeZone: 'Europe/London' },
	...(description ? { description } : {}),
});
const int = (name) => ({ name, type: 'number', options: { precision: 0 } });
const money = (name) => ({ name, type: 'currency', options: { precision: 2, symbol: '£' } });
const rating = (name) => ({ name, type: 'rating', options: { icon: 'star', max: 5, color: 'yellowBright' } });
const select = (name, choices) => ({ name, type: 'singleSelect', options: { choices: choices.map((c) => ({ name: c })) } });
const multi = (name, choices) => ({ name, type: 'multipleSelects', options: { choices: choices.map((c) => ({ name: c })) } });

/* ── Schema (must match maps/encore.php) ──────────────────────────────── */
// First field = primary field. Links are added in a second pass (they need table IDs).
const SCHEMA = [
	{
		name: 'Site Settings',
		description: 'ONE row only. Everything site-wide: name, bio, logos, colours, links. Tick Publish to update the website.',
		fields: [
			text('Artist Name'), text('Tagline'), text('Genre'), text('Hometown'),
			long('Short Bio'), rich('Full Bio'),
			files('Logo'), files('Logo (Light)'), files('Hero Image'), url('Hero Video URL'),
			text('Primary Colour', 'Hex, e.g. #ee4367'), text('Secondary Colour', 'Hex'),
			email('Booking Email'), email('Management Email'), email('Press Email'),
			url('Mailing List URL'), files('Press Kit PDF'),
			url('Instagram'), url('Facebook'), url('TikTok'), url('YouTube'), url('X'),
			url('Spotify'), url('Apple Music'), url('Bandcamp'), url('SoundCloud'),
			long('SEO Description'),
			check('Publish', 'Tick to publish the website. The automation unticks it.'),
			datetime('Last Published', 'Set by the Publish automation — do not edit.'),
		],
	},
	{
		name: 'Gigs',
		description: 'Tour dates. Untick Show on Site to hide one.',
		fields: [
			text('Gig', 'Convert to formula: {Venue} & ", " & {City}'),
			date('Date'), text('Doors'), text('Venue'), text('City'), text('Country'), url('Ticket URL'),
			select('Status', ['On Sale', 'Few Left', 'Sold Out', 'Free', 'Cancelled', 'Announced']),
			text('Support'), check('Festival'), rich('Notes'), check('Show on Site'),
		],
	},
	{
		name: 'Releases',
		description: 'Albums, EPs and singles. Link the tracks in tracklist order.',
		fields: [
			text('Title'), select('Type', ['Album', 'EP', 'Single', 'Live', 'Remix']), date('Release Date'),
			files('Artwork'), text('Label'), rich('Description'),
			url('Spotify URL'), url('Apple Music URL'), url('Bandcamp URL'), url('YouTube URL'), url('Pre-save URL'),
			check('Featured'),
		],
	},
	{
		name: 'Tracks',
		fields: [text('Title'), int('Track Number'), text('Duration', 'm:ss, e.g. 3:41'), text('Writers'), url('Preview URL'), rich('Lyrics')],
		links: [{ name: 'Release', to: 'Releases', inverse: 'Tracks' }],
	},
	{
		name: 'Members',
		fields: [text('Name'), text('Role'), rich('Bio'), files('Photo'), url('Instagram'), int('Order'), check('Show on Site')],
	},
	{
		name: 'News',
		fields: [text('Headline'), datetime('Date'), long('Summary'), rich('Body'), files('Image'), check('Published')],
	},
	{
		name: 'Gallery',
		fields: [
			text('Title', 'Convert to formula: IF({Caption}, {Caption}, "Photo")'),
			files('Photo'), text('Caption'), multi('Album', ['Live', 'Press', 'Studio']), text('Credit'), int('Order'), check('Show on Site'),
		],
	},
	{
		name: 'Videos',
		fields: [text('Title'), url('Video URL'), files('Thumbnail'), date('Date'), check('Featured'), check('Show on Site')],
	},
	{
		name: 'Press',
		fields: [text('Publication'), long('Quote'), text('Author'), url('Link'), files('Logo'), rating('Rating'), date('Date'), int('Order'), check('Show on Site')],
	},
	{
		name: 'Merch',
		fields: [text('Item'), files('Image'), money('Price'), url('Store URL'), select('Badge', ['New', 'Limited', 'Sold Out']), int('Order'), check('Show on Site')],
	},
	{
		name: 'Enquiries',
		description: 'Written by the website contact form.',
		fields: [
			text('Name'), email('Email'), phone('Phone'),
			select('Enquiry Type', ['Booking', 'Festival', 'Wedding / private event', 'Press', 'Other']),
			text('Event Date'), text('Location'), long('Message'), url('Page'),
			select('Status', ['New', 'Replied', 'Booked', 'Declined']),
		],
	},
	{
		name: 'Subscribers',
		description: 'Written by the website mailing list form.',
		fields: [email('Email'), text('Name')],
	},
];

/* ── API ──────────────────────────────────────────────────────────────── */
let calls = 0;
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function api(method, path, body) {
	if (DRY && method !== 'GET') {
		console.log(`  [dry-run] ${method} ${path}`);
		return { id: `dry_${Math.random().toString(36).slice(2, 10)}`, fields: [], records: [] };
	}
	for (let attempt = 0; attempt < 4; attempt++) {
		calls++;
		const res = await fetch(`${API}/${path}`, {
			method,
			headers: { Authorization: `Bearer ${TOKEN}`, 'Content-Type': 'application/json' },
			body: body ? JSON.stringify(body) : undefined,
		});
		if (res.status === 429) {
			console.log('  Rate limited — waiting 31s…');
			await sleep(31000);
			continue;
		}
		const json = await res.json().catch(() => ({}));
		if (!res.ok) {
			const msg = json?.error?.message || json?.error?.type || JSON.stringify(json);
			throw new Error(`${method} ${path} → HTTP ${res.status}: ${msg}`);
		}
		await sleep(250); // Stay under 5 requests/second.
		return json;
	}
	throw new Error(`${method} ${path} kept hitting the rate limit.`);
}

const getSchema = async () => (await api('GET', `meta/bases/${BASE}/tables`)).tables || [];

/* ── Build ────────────────────────────────────────────────────────────── */
async function buildSchema() {
	console.log('\nReading the base…');
	let tables = await getSchema();
	const byName = () => Object.fromEntries(tables.map((t) => [t.name, t]));

	for (const spec of SCHEMA) {
		const existing = byName()[spec.name];
		if (!existing) {
			console.log(`+ Table "${spec.name}" (${spec.fields.length} fields)`);
			const created = await api('POST', `meta/bases/${BASE}/tables`, {
				name: spec.name,
				...(spec.description ? { description: spec.description } : {}),
				fields: spec.fields,
			});
			tables.push(created.id ? { ...created, name: spec.name, fields: created.fields || [] } : created);
			continue;
		}
		const have = new Set((existing.fields || []).map((f) => f.name));
		for (const field of spec.fields) {
			if (!have.has(field.name)) {
				console.log(`+ Field "${spec.name}" → "${field.name}"`);
				await api('POST', `meta/bases/${BASE}/tables/${existing.id}/fields`, field);
			}
		}
		console.log(`= Table "${spec.name}" exists`);
	}

	if (!DRY) tables = await getSchema();

	// Link fields, then name the automatic inverse field as the map expects.
	for (const spec of SCHEMA.filter((s) => s.links)) {
		const table = byName()[spec.name];
		for (const link of spec.links) {
			const target = byName()[link.to];
			if (!table || !target) continue;
			if ((table.fields || []).some((f) => f.name === link.name)) {
				console.log(`= Link "${spec.name}" → "${link.name}" exists`);
				continue;
			}
			console.log(`+ Link "${spec.name}.${link.name}" ↔ "${link.to}.${link.inverse}"`);
			const field = await api('POST', `meta/bases/${BASE}/tables/${table.id}/fields`, {
				name: link.name,
				type: 'multipleRecordLinks',
				options: { linkedTableId: target.id },
			});
			if (DRY) continue;
			tables = await getSchema();
			const inverse = (byName()[link.to].fields || []).find(
				(f) => f.type === 'multipleRecordLinks' && f.options?.inverseLinkFieldId === field.id
			);
			if (inverse && inverse.name !== link.inverse) {
				await api('PATCH', `meta/bases/${BASE}/tables/${target.id}/fields/${inverse.id}`, { name: link.inverse });
			}
		}
	}

	return DRY ? tables : await getSchema();
}

/* ── Demo content ─────────────────────────────────────────────────────── */
const day = (offset) => {
	const d = new Date();
	d.setUTCDate(d.getUTCDate() + offset);
	return d.toISOString().slice(0, 10);
};
const img = (seed, w = 1600, h = 1067) => [{ url: `https://picsum.photos/seed/${seed}/${w}/${h}`, filename: `${seed}.jpg` }];

const DEMOS = {
	velvet: {
		settings: {
			'Artist Name': 'The Velvet Tides',
			Tagline: 'Coastal indie from the Exe estuary',
			Genre: 'Indie rock',
			Hometown: 'Exeter, Devon',
			'Short Bio': 'Four-piece indie band from Exeter making big, salt-stung guitar songs about leaving and coming home.',
			'Full Bio':
				'**The Velvet Tides** met at a Topsham open mic in 2021 and never quite left the pub.\n\nTheir debut album *Low Tide* was recorded live in a converted boathouse on the Exe and picked up BBC Introducing support across the South West.\n\n- Four piece: two guitars, bass, drums\n- Two records out, a third on the way\n- Headline UK tour this winter',
			'Hero Image': img('velvet-hero', 2400, 1600),
			'Primary Colour': '#ee4367',
			'Secondary Colour': '#e2ecf3',
			'Booking Email': 'bookings@velvettides.example',
			'Management Email': 'mgmt@velvettides.example',
			'Press Email': 'press@velvettides.example',
			Instagram: 'https://www.instagram.com/',
			Spotify: 'https://open.spotify.com/',
			Bandcamp: 'https://bandcamp.com/',
			YouTube: 'https://www.youtube.com/',
			'SEO Description': 'The Velvet Tides — indie four-piece from Exeter. Tour dates, music, videos and booking.',
		},
		gigs: [
			[day(-40), 'The Cavern', 'Exeter', 'Sold Out'],
			[day(-12), 'Mama Stone\'s', 'Exeter', 'Sold Out'],
			[day(18), 'Phoenix', 'Exeter', 'On Sale'],
			[day(25), 'The Fleece', 'Bristol', 'Few Left'],
			[day(32), 'The Joiners', 'Southampton', 'On Sale'],
			[day(39), 'The Lexington', 'London', 'On Sale', 'Support: Saltmarsh'],
			[day(60), 'Beautiful Days', 'Escot Park', 'Announced', 'Festival'],
		],
		releases: [
			{ title: 'Harbour Lights', type: 'Single', date: day(21), featured: true, tracks: [['Harbour Lights', '3:28']] },
			{ title: 'Low Tide', type: 'Album', date: day(-200), tracks: [['Undertow', '3:41'], ['Salt', '4:02'], ['Topsham Ferry', '3:15'], ['Estuary Lights', '5:10'], ['Weather Front', '3:56'], ['Leaving Exmouth', '4:24']] },
			{ title: 'Breakwater EP', type: 'EP', date: day(-600), tracks: [['Breakwater', '3:33'], ['Shingle', '2:58'], ['Second Tide', '4:11']] },
		],
		members: [['Ella Marsh', 'Vocals, guitar'], ['Tom Hale', 'Lead guitar'], ['Priya Lane', 'Bass'], ['Josh Reed', 'Drums']],
		press: [
			['Harbourside Sound', 'Big, generous guitar songs with the sea air still in them.', 4],
			['The Westcountry Wire', 'The best new band to come out of Devon in years.', 5],
			['Low Light Zine', 'Low Tide rewards every listen — Estuary Lights is a stunner.', 4],
		],
		merch: [['Low Tide vinyl', 22, 'New'], ['Tour tee 2026', 20, ''], ['Tote bag', 12, ''], ['Breakwater EP CD', 8, 'Limited']],
		news: [
			['Harbour Lights — new single out soon', 'Our first new music since Low Tide lands next month. Pre-save it now.', -2],
			['Winter tour announced', 'Exeter, Bristol, Southampton and our first London headline. Tickets on sale now.', -20],
			['Beautiful Days!', 'We\'re playing Beautiful Days next summer. Pinch us.', -45],
		],
	},
	hollin: {
		settings: {
			'Artist Name': 'Hollin Wren',
			Tagline: 'Songs from the edge of the moor',
			Genre: 'Folk',
			Hometown: 'Chagford, Dartmoor',
			'Short Bio': 'Dartmoor songwriter writing quiet, weather-worn folk songs for fiddle, guitar and one very old harmonium.',
			'Full Bio':
				'**Hollin Wren** writes songs in a stone cottage at the edge of Dartmoor, mostly in the early morning.\n\nHer second record *Clapper Bridge* was recorded over four days in a village hall, with the doors open.\n\n- Solo, and sometimes with a string trio\n- Regular at folk clubs across the South West',
			'Hero Image': img('hollin-hero', 2400, 1600),
			'Primary Colour': '#3d6b52',
			'Booking Email': 'hello@hollinwren.example',
			Instagram: 'https://www.instagram.com/',
			Bandcamp: 'https://bandcamp.com/',
			Spotify: 'https://open.spotify.com/',
			'SEO Description': 'Hollin Wren — folk songwriter from Dartmoor. Gigs, records and bookings.',
		},
		gigs: [
			[day(-30), 'Chagford Jubilee Hall', 'Chagford', 'Sold Out'],
			[day(14), 'Exeter Phoenix Voodoo Lounge', 'Exeter', 'On Sale'],
			[day(29), 'The Barrel House', 'Totnes', 'Few Left'],
			[day(45), 'Dartington Great Hall', 'Dartington', 'On Sale', 'With string trio'],
		],
		releases: [
			{ title: 'Clapper Bridge', type: 'Album', date: day(-90), featured: true, tracks: [['Clapper Bridge', '4:12'], ['Granite and Gorse', '3:38'], ['The Harmonium', '2:51'], ['Hound Tor', '5:02'], ['Teign', '3:44']] },
			{ title: 'Peat Smoke', type: 'EP', date: day(-500), tracks: [['Peat Smoke', '3:20'], ['Wren Song', '2:46'], ['Moorgate', '4:05']] },
		],
		members: [['Hollin Wren', 'Voice, guitar, harmonium']],
		press: [
			['Moorland Folk Review', 'Songs that sound like they were found, not written.', 5],
			['South West Roots', 'Clapper Bridge is a small, perfect record.', 4],
		],
		merch: [['Clapper Bridge LP', 20, 'New'], ['Peat Smoke EP CD', 8, ''], ['Hand-printed poster', 15, 'Limited']],
		news: [
			['Clapper Bridge is out', 'The new record is out everywhere today. Thank you for waiting.', -90],
			['Autumn dates', 'A handful of small shows around Devon this autumn.', -25],
		],
	},
};

async function seed(tables) {
	const demo = DEMOS[DEMO];
	const slug = DEMO;
	console.log(`\nAdding demo content: ${demo.settings['Artist Name']}`);

	const hasRows = async (table) => {
		if (DRY) return false;
		const res = await api('GET', `${BASE}/${encodeURIComponent(table)}?maxRecords=1`);
		return (res.records || []).length > 0;
	};
	const add = async (table, records) => {
		if (await hasRows(table)) {
			console.log(`= ${table}: has rows already — skipped`);
			return [];
		}
		console.log(`+ ${table}: ${records.length} rows`);
		const created = [];
		for (let i = 0; i < records.length; i += 10) {
			const res = await api('POST', `${BASE}/${encodeURIComponent(table)}`, {
				records: records.slice(i, i + 10).map((fields) => ({ fields })),
				typecast: true,
			});
			created.push(...(res.records || []));
		}
		return created;
	};

	await add('Site Settings', [clean(demo.settings)]);

	await add('Gigs', demo.gigs.map(([date, venue, city, status, extra]) => ({
		Gig: `${venue}, ${city}`,
		Date: date,
		Doors: '19:30',
		Venue: venue,
		City: city,
		Country: 'UK',
		Status: status,
		'Ticket URL': status === 'Announced' ? undefined : 'https://www.seetickets.com/',
		Support: extra && extra.startsWith('Support: ') ? extra.slice(9) : extra && extra !== 'Festival' ? extra : undefined,
		Festival: extra === 'Festival',
		'Show on Site': true,
	})).map(clean));

	const releases = await add('Releases', demo.releases.map((r, i) => clean({
		Title: r.title,
		Type: r.type,
		'Release Date': r.date,
		Artwork: img(`${slug}-art-${i}`, 1200, 1200),
		Label: 'Self-released',
		Description: `${r.title} — ${r.type.toLowerCase()} by ${demo.settings['Artist Name']}.`,
		'Spotify URL': 'https://open.spotify.com/',
		'Bandcamp URL': 'https://bandcamp.com/',
		'Pre-save URL': r.date > day(0) ? 'https://open.spotify.com/' : undefined,
		Featured: !!r.featured,
	})));

	if (releases.length) {
		const tracks = [];
		demo.releases.forEach((r, ri) => {
			r.tracks.forEach(([title, duration], ti) => {
				tracks.push({ Title: title, 'Track Number': ti + 1, Duration: duration, Writers: demo.members.map((m) => m[0]).join(', '), Release: [releases[ri].id] });
			});
		});
		await add('Tracks', tracks);
	}

	await add('Members', demo.members.map(([name, role], i) => ({
		Name: name, Role: role, Photo: img(`${slug}-member-${i}`, 800, 1000), Order: i + 1, 'Show on Site': true,
	})));

	await add('News', demo.news.map(([headline, body, offset], i) => ({
		Headline: headline, Date: `${day(offset)}T09:00:00.000Z`, Summary: body, Body: `${body}\n\nMore soon.`, Image: img(`${slug}-news-${i}`), Published: true,
	})));

	const albums = ['Live', 'Live', 'Live', 'Press', 'Press', 'Studio', 'Studio', 'Live'];
	await add('Gallery', albums.map((album, i) => ({
		Title: `${album} photo ${i + 1}`, Photo: img(`${slug}-photo-${i}`, i % 3 ? 1200 : 1000, i % 3 ? 800 : 1300), Caption: `${album} photo ${i + 1}`, Album: [album], Credit: 'Demo photography', Order: i + 1, 'Show on Site': true,
	})));

	await add('Press', demo.press.map(([publication, quote, stars], i) => ({
		Publication: publication, Quote: quote, Rating: stars, Order: i + 1, 'Show on Site': true,
	})));

	await add('Merch', demo.merch.map(([item, price, badge], i) => clean({
		Item: item, Image: img(`${slug}-merch-${i}`, 1000, 1000), Price: price, 'Store URL': 'https://bandcamp.com/', Badge: badge || undefined, Order: i + 1, 'Show on Site': true,
	})));
}

function clean(obj) {
	return Object.fromEntries(Object.entries(obj).filter(([, v]) => v !== undefined && v !== ''));
}

/* ── Run ──────────────────────────────────────────────────────────────── */
try {
	console.log(`Drift: Encore base builder → ${BASE}${DRY ? ' (dry run)' : ''}`);
	const tables = await buildSchema();
	if (DEMO) await seed(tables);

	console.log(`\nDone — ${calls} API calls.`);
	console.log(`
Finish by hand in Airtable (the API can't do these):
  1. Gigs → "Gig" field → Edit field → Formula:      {Venue} & ", " & {City}
  2. Gallery → "Title" field → Edit field → Formula:   IF({Caption}, {Caption}, "Photo")
  3. Enquiries → add field "Received" (Created time).  Subscribers → add "Joined" (Created time).
  4. Delete the empty "Table 1" that came with the base.
  5. Site Settings: add your Logo / Logo (Light) images (demo leaves them blank so the site shows the name in type).
  6. Videos: add 2–3 rows with real YouTube or Vimeo links, tick Show on Site (and Featured on one).
Then: the Publish automation and the Interface — see docs/airtable-setup/README.md.
`);
} catch (err) {
	console.error(`\n✖ ${err.message}\n(${calls} API calls made before the error.)`);
	process.exit(1);
}
