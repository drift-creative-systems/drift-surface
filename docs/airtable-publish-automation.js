/**
 * Drift: Surface — Airtable "Publish" automation script.
 *
 * Automation setup (in the client's base):
 *   Trigger:  When a record matches conditions
 *             Table: Site Settings   Condition: "Publish" is checked
 *   Action:   Run a script (this file)
 *             Input variables:
 *               recordId   → Airtable record ID (from the trigger)
 *               webhookUrl → https://CLIENT-SITE/wp-json/drift-surface/v1/publish   (Drift: Surface → Connection)
 *               secret     → the site's publish secret                       (Drift: Surface → Connection)
 *
 * What it does:
 *   1. Stamps "Last Published" with the current time and unticks "Publish"
 *      (so the trigger re-arms for next time). Stamping FIRST matters: the
 *      site's sync reads this value, and the daily safety check compares it.
 *   2. POSTs to the site, which queues a sync and answers 202 immediately.
 *
 * Cost: 1 automation run per publish. The site then uses ~1 API call per table.
 */

const { recordId, webhookUrl, secret } = input.config();

if (!recordId || !webhookUrl || !secret) {
    throw new Error('Missing input variables: recordId, webhookUrl and secret are all required.');
}

const table = base.getTable('Site Settings');
const stamp = new Date().toISOString();

await table.updateRecordAsync(recordId, {
    'Last Published': stamp,
    'Publish': false,
});

const response = await fetch(webhookUrl, {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-Drift-Surface-Secret': secret,
    },
    body: JSON.stringify({ last_published: stamp, by: 'airtable-automation' }),
});

if (response.status !== 202) {
    const text = await response.text();
    // Failing the run shows red in the automation history, so it gets noticed.
    // The site's daily check will still pick the change up within 24 hours.
    throw new Error(`Site answered HTTP ${response.status}: ${text.slice(0, 300)}`);
}

output.set('published', stamp);
