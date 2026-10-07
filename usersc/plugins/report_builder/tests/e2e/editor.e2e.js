// End-to-end test of the Report Builder editor against the php -S harness.
// Run via run_e2e.sh. Needs Playwright (npm i -g playwright) + Chromium.
const path = require('path');
let chromium;
try { ({ chromium } = require('playwright')); } catch (e) { ({ chromium } = require(path.join(require('child_process').execSync('npm root -g').toString().trim(), 'playwright'))); }
const fs = require('fs');
const BASE = 'http://127.0.0.1:' + (process.env.RB_E2E_PORT || 8765);
const WORK = process.env.RB_E2E_DIR || path.join(require('os').tmpdir(), 'rb_e2e');
const SHOTS = WORK;

let failures = 0;
const ok = (cond, msg) => { console.log((cond ? '  ok   ' : '  FAIL ') + msg); if (!cond) failures++; };

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1500, height: 950 } });
  const errors = [];
  page.on('pageerror', e => errors.push('pageerror: ' + e.message));
  page.on('console', m => { if (m.type() === 'error') errors.push('console: ' + m.text()); });
  let dialogs = 0;
  page.on('dialog', d => { dialogs++; d.accept(); });

  const frame = () => page.frameLocator('iframe.rb-frame');
  const waitPreview = async () => {
    await page.waitForTimeout(900);                 // debounce
    await page.waitForFunction(() => !document.querySelector('.rb-preview-busy'));
  };
  const blockTypes = () => page.$$eval('.rb-block', els => els.map(e => e.dataset.type));

  // ── list → create from preset ───────────────────────────────────────────
  await page.goto(BASE + '/editor.php');
  await page.getByText('No reports yet').waitFor();
  ok(true, 'list view loads (empty)');
  await page.getByRole('button', { name: 'Create from preset' }).click();
  await page.waitForURL(/id=1/);
  await page.locator('iframe.rb-frame').waitFor();
  await waitPreview();
  ok(await frame().getByText('Container Tracking').isVisible(), 'preset preview renders in iframe');
  ok((await page.textContent('[data-rb=subject]')).includes('1 Container Awaiting Review'), 'subject line shown');
  ok((await blockTypes()).length === 10, 'preset has 10 blocks');
  await page.screenshot({ path: SHOTS + '/1_digest_preset.png' });

  // ── add a table block, reorder ──────────────────────────────────────────
  await page.getByRole('button', { name: '+ Table' }).click();
  await waitPreview();
  let types = await blockTypes();
  ok(types.length === 11 && types[10] === 'table', 'table block added at the end, opened');
  // Drag the new block to the top with SortableJS.
  const handles = page.locator('.rb-block .rb-handle');
  await handles.nth(10).scrollIntoViewIfNeeded();
  const from = await handles.nth(10).boundingBox();
  await page.mouse.move(from.x + from.width / 2, from.y + from.height / 2);
  await page.mouse.down();
  // Move up in small steps; Sortable reorders as the pointer passes each block.
  for (let i = 9; i >= 0; i--) {
    const box = await page.locator('.rb-block').nth(i).boundingBox();
    await page.mouse.move(box.x + 30, box.y + 4, { steps: 6 });
    await page.waitForTimeout(60);
  }
  await page.mouse.up();
  await waitPreview();
  types = await blockTypes();
  ok(types[0] === 'table', 'drag-and-drop moved the table to the top (' + types.slice(0, 3).join(',') + ')');
  // ▲▼ buttons as the keyboard/no-JS-library fallback.
  await page.locator('.rb-block').nth(1).getByTitle('Move up').click();
  types = await blockTypes();
  ok(types[0] === 'header' && types[1] === 'table', 'Move up button reorders');

  // ── edit the table: columns, filter, label ──────────────────────────────
  const tbl = page.locator('.rb-block[data-type=table]').first();
  if (!(await tbl.locator('.rb-block-body').count())) await tbl.locator('.rb-block-title').click();
  await tbl.locator('.rb-row').filter({ hasText: 'Heading' }).locator('input').fill('Pending by carrier');
  await tbl.getByLabel('Add column').selectOption('piece_count');
  await tbl.getByRole('button', { name: '+ Filter' }).click();
  const f = tbl.locator('.rb-filter').first();
  await f.getByLabel('Filter field').selectOption('status');
  await f.getByLabel('Condition').selectOption('eq');
  await f.getByLabel('Value').selectOption('pending');
  await tbl.getByLabel('Column heading for Carrier').fill('Trucking Co');
  await waitPreview();
  const fr = frame();
  ok(await fr.getByText('Pending by carrier').isVisible(), 'block heading updates live');
  ok(await fr.getByText('Trucking Co').first().isVisible(), 'custom column heading in preview');
  ok(await fr.getByText('TGHU2000003').first().isVisible() && !(await fr.locator('table').first().getByText('MSCU1000002').count()), 'status filter applied');

  // ── totals mode ─────────────────────────────────────────────────────────
  await tbl.locator('.rb-row').filter({ hasText: 'Show' }).locator('select').selectOption('totals');
  await tbl.getByRole('button', { name: '+ Group by' }).click();
  await tbl.locator('.rb-section').filter({ hasText: 'Group by' }).locator('select').first().selectOption('customer');
  await tbl.getByRole('button', { name: '+ Value' }).click();
  await tbl.locator('.rb-section').filter({ hasText: 'Values' }).locator('.rb-subrow').nth(1).locator('select').first().selectOption('sum');
  await waitPreview();
  const totalsText = await fr.locator('body').innerText();
  ok(/Client\s+Count\s+Total Piece Count/i.test(totalsText), 'totals table headings (Client / Count / Total Piece Count)');
  ok(/Acme Imports\s+1\s+20/.test(totalsText) && /Beta & Sons <Foods>\s+1\s+30/.test(totalsText), 'grouped totals correct for pending rows');

  // ── metrics tab shows live values ───────────────────────────────────────
  await page.getByRole('tab', { name: 'Metrics & filters' }).click();
  const awaitingVal = await page.locator('[data-metric=awaiting] .rb-metric-value').innerText();
  ok(awaitingVal.trim() === '= 1', 'metric value from preview shown (' + awaitingVal + ')');
  // Report filter (client multi-select) narrows every block.
  await page.getByRole('button', { name: '+ Report filter' }).click();
  const rf = page.locator('.rb-filter').last();
  ok(await rf.getByLabel('Filter field').inputValue() === 'customer_id', 'report filter defaults to client pick-list');
  await rf.getByLabel('Values').selectOption(['2']);
  await waitPreview();
  ok((await page.locator('[data-metric=total_open] .rb-metric-value').innerText()).trim() === '= 3' ||
     (await page.locator('[data-metric=total_open] .rb-metric-value').innerText()).trim() === '= 2', 'report filter changes metrics');
  const tot = (await page.locator('[data-metric=total_open] .rb-metric-value').innerText()).trim();
  ok(tot === '= 2', 'Beta-only total open = 2 (' + tot + ')');
  await rf.getByTitle('Remove filter').click();
  await waitPreview();

  // ── delivery: recipient + note, save, reload ────────────────────────────
  await page.getByRole('tab', { name: 'Delivery' }).click();
  await page.getByRole('button', { name: '+ Recipient' }).click();
  const rec = page.locator('.rb-recipient').last();
  await rec.getByLabel('Email').fill('client@acme.example');
  await rec.getByLabel('Note').fill('Acme ops manager');
  await page.locator('input.rb-name').fill('Digest + carrier totals');
  ok((await page.textContent('.rb-status')) === 'Unsaved changes', 'dirty indicator');
  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await page.getByText('Saved', { exact: true }).first().waitFor();
  ok((await page.textContent('.rb-status')) === 'Saved', 'saved indicator');
  await page.reload();
  await page.locator('iframe.rb-frame').waitFor();
  ok(await page.locator('input.rb-name').inputValue() === 'Digest + carrier totals', 'name persisted');
  ok((await blockTypes())[1] === 'table', 'block order persisted');
  await page.getByRole('tab', { name: 'Delivery' }).click();
  const recEmails = await page.$$eval('.rb-recipient input[type=email]', els => els.map(e => e.value));
  ok(recEmails.includes('client@acme.example'), 'email recipient persisted');
  const recNotes = await page.$$eval('.rb-recipient input[aria-label=Note]', els => els.map(e => e.value));
  ok(recNotes.includes('Acme ops manager'), 'recipient note persisted');
  await page.screenshot({ path: SHOTS + '/2_delivery.png' });

  // ── test send ───────────────────────────────────────────────────────────
  await page.getByRole('button', { name: 'Test to me' }).click();
  await page.getByText('Test sent to dan@example.com.').waitFor();
  const sent = fs.readFileSync(WORK + '/sent.log', 'utf8').trim().split('\n').map(JSON.parse);
  ok(sent.length === 1 && sent[0].to[0] === 'dan@example.com' && sent[0].subject.startsWith('[TEST]'), 'test email sent only to me');

  // ── validation error surfaces, nothing saved ────────────────────────────
  await page.getByRole('button', { name: '+ Recipient' }).click();
  await page.locator('.rb-recipient').last().getByLabel('Email').fill('not-an-email');
  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await page.getByText("'not-an-email' isn't a valid email address.").waitFor();
  ok(true, 'invalid recipient blocked with a readable message');
  await page.locator('.rb-recipient').last().getByTitle('Remove recipient').click();

  // ── injection attempts stay inert ───────────────────────────────────────
  await page.getByRole('tab', { name: 'Blocks' }).click();
  await page.getByRole('button', { name: '+ Text' }).click();
  await page.locator('.rb-block[data-type=text]').last().locator('textarea').fill('<img src=x onerror=alert(1)><script>alert(2)</script>{brand}');
  await page.locator('input.rb-name').fill('<b onmouseover=alert(3)>x</b>');
  await waitPreview();
  ok(await frame().getByText('<img src=x onerror=alert(1)><script>alert(2)</script>Container Flow').isVisible(), 'markup shown as text in preview');
  ok(dialogs === 0, 'no script ran (no alert dialogs)');
  ok(!(await frame().locator('img').count()), 'no <img> element created');

  // Advanced: JSON round trip.
  await page.getByRole('tab', { name: 'Advanced' }).click();
  const json = await page.getByLabel('Layout JSON').inputValue();
  ok(JSON.parse(json).blocks.length === 12 && !json.includes('_uid'), 'layout JSON excludes editor-only keys');
  await page.screenshot({ path: SHOTS + '/3_advanced.png' });

  // ── charts ──────────────────────────────────────────────────────────────
  await page.getByRole('tab', { name: 'Blocks' }).click();
  await page.getByRole('button', { name: '+ Chart' }).click();
  await waitPreview();
  const chartImg = () => frame().locator('img[alt^="Column chart"], img[alt^="Line chart"]');
  ok(await chartImg().count() === 1, 'column chart image in preview');
  ok((await chartImg().getAttribute('src')).startsWith('data:image/png;base64,'), 'preview embeds the PNG');
  const cb = page.locator('.rb-block[data-type=chart]').last();
  await cb.getByLabel('Split by').selectOption('type');
  await cb.locator('.rb-row').filter({ hasText: 'Chart' }).first().locator('select').selectOption('line');
  await waitPreview();
  ok(await frame().locator('img[alt^="Line chart"]').count() === 1, 'switched to a line chart');
  ok(/Inbound\s+Outbound/.test(await frame().locator('body').innerText()), 'legend for 2 series');
  await page.locator('.rb-split').screenshot({ path: SHOTS + '/6_chart.png' });
  await cb.locator('.rb-row').filter({ hasText: 'Chart' }).first().locator('select').selectOption('bar');
  await waitPreview();
  ok(await chartImg().count() === 0 && await frame().locator('td[style*="background:#2a78d6"]').count() > 0, 'bar chart drawn as HTML bars');

  // ── back to the list ────────────────────────────────────────────────────
  await page.locator('input.rb-name').fill('Digest + carrier totals');
  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await page.getByText('Saved', { exact: true }).first().waitFor();
  await page.goto(BASE + '/editor.php');
  await page.locator('.rb-table').waitFor();
  ok(await page.getByRole('link', { name: 'Digest + carrier totals' }).isVisible(), 'report listed');
  await page.getByRole('button', { name: 'Paused' }).click();
  await page.getByRole('button', { name: 'Active' }).waitFor();
  ok(true, 'activate from list');
  await page.screenshot({ path: SHOTS + '/4_list.png' });

  // ── narrow screen still usable ──────────────────────────────────────────
  await page.setViewportSize({ width: 420, height: 900 });
  await page.goto(BASE + '/editor.php?id=1');
  await page.locator('iframe.rb-frame').waitFor();
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
  ok(!overflow, 'no horizontal page scroll at phone width');

  ok(errors.length === 0, 'no browser console errors' + (errors.length ? ': ' + errors.join(' | ') : ''));
  await page.setViewportSize({ width: 1500, height: 950 });
  await page.goto(BASE + '/editor.php?id=1');
  await page.locator('iframe.rb-frame').waitFor();
  await page.locator('.rb-block[data-type=table]').first().locator('.rb-block-title').click();
  await waitPreview();
  await page.screenshot({ path: SHOTS + '/5_editor.png' });

  await browser.close();
  console.log(failures ? `\n${failures} FAILED` : '\nall e2e checks passed');
  process.exit(failures ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
