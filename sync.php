<?php
// sync.php?loc=ID — sync tools for one location (admin only).
require __DIR__ . '/lib/bootstrap.php';
require_admin();
[$user, $locId] = require_location_access();
$loc = location_get($locId);
$ghl = integration_get($locId, 'ghl');
$od  = integration_get($locId, 'open_dental');

render_header($loc['name'] . ' · Sync', 'locations');
?>
<style>
  .row { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; margin-bottom: 12px; }
  .row label { margin: 0; }
  label.inline { display: flex; flex-direction: row; align-items: center; gap: 6px; color: var(--text); }
  label.inline input { width: auto; margin: 0; }
  button[data-action], #useActive { padding: 8px 14px; border-radius: 6px; border: 1px solid var(--brand); background: var(--brand); color: #fff; cursor: pointer; font-size: 13px; }
  button.secondary { background: #fff !important; color: var(--brand) !important; }
  button:disabled { opacity: .5; cursor: wait; }
  #progress { font-size: 13px; margin: 16px 0 8px; color: #444; min-height: 18px; }
  #progress.error { color: var(--bad); }
  .bar { height: 6px; background: #e3e8ec; border-radius: 3px; overflow: hidden; margin-bottom: 12px; }
  .bar > div { height: 100%; width: 0; background: var(--brand); transition: width .3s; }
  .logtable { width: 100%; border-collapse: collapse; font-size: 12.5px; background: #fff; }
  .logtable th, .logtable td { text-align: left; padding: 7px 10px; border-bottom: 1px solid #eee; vertical-align: top; }
  .logtable th { background: #f0f3f5; font-weight: 600; position: sticky; top: 0; }
  .logwrap { max-height: 520px; overflow: auto; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
  .r-created, .r-updated, .r-matched, .r-active { color: var(--ok); font-weight: 600; }
  .r-error { color: var(--bad); font-weight: 600; }
  .r-skipped, .r-unchanged { color: #888; }
  .r-would { color: var(--warn); font-weight: 600; }
  .summary { font-size: 13px; margin-bottom: 8px; }
  code { background: #eef2f4; padding: 1px 5px; border-radius: 3px; font-size: 12px; }
</style>

<div class="head">
  <div>
    <a href="location.php?loc=<?= $locId ?>">← <?= e($loc['name']) ?></a>
    <h1 style="margin-top:6px">Sync to GHL</h1>
  </div>
  <div class="actions">
    <span>GHL <?= status_badge($ghl['status']) ?></span>
    <span>Open Dental <?= status_badge($od['status']) ?></span>
    <a class="btn ghost" href="viewer.php?loc=<?= $locId ?>">Open data viewer</a>
  </div>
</div>

<?php if (!$ghl['exists'] || !$od['exists']): ?>
  <div class="flash bad">Add both GHL and Open Dental settings on the <a href="location.php?loc=<?= $locId ?>">Configuration Page</a> before syncing.</div>
<?php endif; ?>

<div class="card">
  <div class="row">
    <label class="inline"><input type="checkbox" id="dryRun" checked> Dry run (show what would happen, change nothing)</label>
  </div>
  <div class="row" style="margin-bottom:0">
    <button class="secondary" data-action="test">Test connections</button>
    <button class="secondary" data-action="calendars">List GHL calendars</button>
    <button class="secondary" data-action="checkcal">Check calendar</button>
  </div>
</div>

<div class="grid2">
  <div class="card">
    <h2>1. Patients → Contacts</h2>
    <p>Creates or updates a GHL contact for each patient: name, email, phone, birthday and address. Every contact gets the tag <code><?= e(CONTACT_TAG) ?></code> and Contact Source <code><?= e(CONTACT_SOURCE) ?></code>.</p>
    <div class="row">
      <label>Only patients changed since <input type="date" id="since"></label>
      <label>Status
        <select id="patStatus">
          <option value="Patient">Active patients</option>
          <option value="">All</option>
          <option value="Inactive">Inactive</option>
          <option value="Prospective">Prospective</option>
        </select>
      </label>
      <label>Limit (0 = all) <input type="number" id="patLimit" value="10" min="0" style="width:110px"></label>
    </div>
    <button data-action="patients">Sync patients</button>
  </div>

  <div class="card">
    <h2>2. Appointments → Calendar</h2>
    <p>Scheduled → <b>confirmed</b>, Complete → <b>showed</b>, Broken/Unscheduled → <b>cancelled</b> (contact tagged <code>od-cancelled</code>). Patients are added as contacts automatically. GHL notifications are off.</p>
    <div class="row">
      <label>From <input type="date" id="dateStart"></label>
      <label>To <input type="date" id="dateEnd"></label>
      <label>Limit (0 = all) <input type="number" id="aptLimit" value="10" min="0" style="width:110px"></label>
    </div>
    <button data-action="appointments">Sync appointments</button>
  </div>
</div>

<div class="card">
  <h2>3. Automatic sync</h2>
  <p>Open Dental sends every change to this portal within about a minute: new, moved, completed, broken or deleted appointments, and new or edited patients. It runs 24/7 on the server; this page doesn't need to be open. <b>Workstations</b> are practice computers that run Open Dental; it works while any of them is on.</p>
  <div class="row">
    <label>Workstation(s), separated by commas
      <input type="text" id="workstation" placeholder="e.g. ADMIN, CHECKOUT" style="min-width:380px">
    </label>
    <button class="secondary" data-action="computers">Find practice computers</button>
    <button class="secondary" id="useActive" type="button">Use all active computers</button>
    <label>Check every (seconds)
      <input type="number" id="seconds" value="60" min="15" style="width:110px">
    </label>
  </div>
  <div class="row" style="margin-bottom:0">
    <button data-action="subscribe">Switch on</button>
    <button class="secondary" data-action="subscriptions">Show status</button>
    <button class="secondary" data-action="log">Activity log</button>
    <button class="secondary" data-action="unsubscribe">Switch off</button>
  </div>
</div>

<div id="progress"></div>
<div class="bar"><div id="barFill"></div></div>
<div class="summary" id="summary"></div>
<div class="logwrap">
  <table class="logtable">
    <thead><tr><th style="width:120px">Result</th><th>Open Dental</th><th>GHL</th></tr></thead>
    <tbody id="log"></tbody>
  </table>
</div>

  <script>
    const $ = s => document.querySelector(s);
    const LOC_ID = <?= (int)$locId ?>;
    const CSRF = <?= json_encode(csrf_token()) ?>;

    // Default dates: today
    const d = n => {
      const x = new Date(); x.setDate(x.getDate() + n);
      return x.getFullYear() + '-' + String(x.getMonth() + 1).padStart(2, '0') + '-' + String(x.getDate()).padStart(2, '0');
    };
    $('#dateStart').value = d(0);
    $('#dateEnd').value = d(0);

    let busy = false;
    let counts = {};

    function setProgress(text, isError) {
      $('#progress').textContent = text;
      $('#progress').className = isError ? 'error' : '';
    }

    function addLog(rows) {
      const tbody = $('#log');
      rows.forEach(r => {
        const tr = document.createElement('tr');
        const key = (r.result || '').split(' ')[0];
        counts[r.result] = (counts[r.result] || 0) + 1;
        [r.result, r.od, r.ghl || ''].forEach((v, i) => {
          const td = document.createElement('td');
          td.textContent = v;
          if (i === 0) td.className = 'r-' + key;
          tr.appendChild(td);
        });
        tbody.appendChild(tr);
      });
      $('#summary').textContent = Object.entries(counts).map(([k, v]) => `${k}: ${v}`).join(' · ');
    }

    async function call(params) {
      const body = new URLSearchParams({ ...params, loc: LOC_ID, csrf: CSRF });
      const res = await fetch('sync_api.php?loc=' + LOC_ID, { method: 'POST', body });
      let data;
      try { data = await res.json(); } catch (e) { throw new Error('Server returned an invalid response (HTTP ' + res.status + ')'); }
      if (!res.ok || data.error) throw new Error(data.error || ('HTTP ' + res.status));
      return data;
    }

    async function run(action) {
      if (busy) return;

      busy = true;
      document.querySelectorAll('button').forEach(b => b.disabled = true);
      $('#log').innerHTML = '';
      $('#summary').textContent = '';
      $('#barFill').style.width = '0';
      counts = {};

      const dryRun = $('#dryRun').checked ? 1 : 0;
      const params = { action, dryRun };
      if (action === 'patients') {
        params.since = $('#since').value;
        params.PatStatus = $('#patStatus').value;
        params.limit = $('#patLimit').value;
      }
      if (action === 'appointments') {
        params.dateStart = $('#dateStart').value;
        params.dateEnd = $('#dateEnd').value;
        params.limit = $('#aptLimit').value;
      }

      try {
        if (action === 'test') {
          setProgress('Testing…');
          const r = await call(params);
          setProgress(r.message);
        } else if (action === 'computers') {
          setProgress('Loading computers from Open Dental…');
          const r = await call(params);
          lastComputers = r.computers;
          addLog(r.computers.map(c => ({ result: 'computer', od: c.name, ghl: 'last active: ' + c.lastSeen })));
          if (r.computers.length) {
            $('#workstation').value = r.computers[0].name;
            setProgress('Filled in the most recently active computer (' + r.computers[0].name + '). Pick another from the list if the practice prefers. Click names to add or remove them.');
          } else {
            setProgress('No computers returned. Ask the practice for the computer name.', true);
          }
          document.querySelectorAll('#log tr').forEach(tr => {
            tr.style.cursor = 'pointer';
            tr.addEventListener('click', () => {
              const name = tr.children[1].textContent;
              const list = $('#workstation').value.split(',').map(x => x.trim()).filter(Boolean);
              const i = list.indexOf(name);
              if (i >= 0) list.splice(i, 1); else list.push(name);   // click again to remove
              $('#workstation').value = list.join(', ');
            });
          });
        } else if (action === 'subscribe') {
          params.workstation = $('#workstation').value;
          params.seconds = $('#seconds').value;
          setProgress('Switching on…');
          const r = await call(params);
          setProgress(r.message);
        } else if (action === 'unsubscribe') {
          setProgress('Switching off…');
          const r = await call(params);
          setProgress(r.message);
        } else if (action === 'subscriptions') {
          setProgress('Loading…');
          const r = await call(params);
          addLog(r.subscriptions.map(x => ({
            result: x.ours ? 'active' : 'other app',
            od: '#' + x.num + ' · ' + x.what + ' · every ' + x.every + 's · on ' + x.pc,
            ghl: x.url
          })));
          const ours = r.subscriptions.filter(x => x.ours).length;
          setProgress(ours ? 'Automatic sync is ON (' + ours + ' subscriptions).' : 'Automatic sync is OFF.');
        } else if (action === 'log') {
          setProgress('Loading…');
          const r = await call(params);
          addLog(r.lines.map(l => ({
            result: l.includes('ERROR') ? 'error' : 'event',
            od: l.slice(0, 19),
            ghl: l.slice(21)
          })));
          setProgress(r.lines.length ? 'Last ' + r.lines.length + ' automatic events, newest first.' : 'No automatic events received yet.');
        } else if (action === 'checkcal') {
          setProgress('Checking calendar…');
          const r = await call(params);
          addLog(r.info.map(i => ({ result: 'info', od: i.k, ghl: i.v })));
          setProgress('Calendar settings as GHL reports them.');
        } else if (action === 'calendars') {
          setProgress('Loading calendars…');
          const r = await call(params);
          addLog(r.calendars.map(c => ({ result: c.active === false ? 'inactive' : 'calendar', od: c.name, ghl: c.id })));
          setProgress(r.calendars.length + ' calendars found. Copy the right ID into Calendar ID on the location\'s Configuration Page.');
        } else {
          // Process in batches until done
          let start = 0;
          while (start !== null) {
            setProgress((dryRun ? 'Dry run: ' : 'Syncing ') + action + '… ' + start + ' done');
            const r = await call({ ...params, start });
            addLog(r.log);
            const done = r.next === null ? r.total : r.next;
            $('#barFill').style.width = (r.total ? (done / r.total * 100) : 100) + '%';
            start = r.next;
            if (start === null) {
              setProgress((dryRun ? 'Dry run finished: ' : 'Finished: ') + r.total + ' ' + action + ' checked.' +
                           (dryRun ? ' Untick "Dry run" to actually sync.' : ''));
            }
          }
        }
      } catch (e) {
        setProgress(e.message, true);
      } finally {
        busy = false;
        document.querySelectorAll('button').forEach(b => b.disabled = false);
      }
    }

    // Computers seen in the last 24 hours (compared with the most recent one)
    let lastComputers = [];
    $('#useActive').addEventListener('click', async () => {
      if (!lastComputers.length) { await run('computers'); }
      const withBeat = lastComputers.filter(c => c.heartbeat);
      if (!withBeat.length) { setProgress('No active computers found.', true); return; }
      const newest = new Date(withBeat[0].heartbeat.replace(' ', 'T')).getTime();
      const active = withBeat.filter(c => newest - new Date(c.heartbeat.replace(' ', 'T')).getTime() < 24 * 3600 * 1000);
      $('#workstation').value = active.map(c => c.name).join(', ');
      setProgress(active.length + ' computers active in the last 24 hours filled in. Now click "Switch on".');
    });

    document.querySelectorAll('button[data-action]').forEach(b =>
      b.addEventListener('click', () => run(b.dataset.action)));
  </script>
<?php render_footer();
