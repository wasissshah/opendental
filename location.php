<?php
// location.php — Configuration Page for one location (admin only).
require __DIR__ . '/lib/bootstrap.php';
require_admin();

$id  = (int)($_GET['loc'] ?? $_POST['loc'] ?? 0);
$loc = location_get($id);
if (!$loc) { flash('Location not found.', 'bad'); redirect('locations.php'); }

// ---------- Test Connection (AJAX) ----------
if (($_POST['action'] ?? '') === 'test') {
    check_csrf(true);
    $provider = $_POST['provider'] ?? '';
    if (!isset(INTEGRATION_FIELDS[$provider])) json_out(['error' => 'Unknown provider'], 400);
    if (!integration_get($id, $provider)['exists']) json_out(['status' => 'failed', 'message' => 'Save the settings first.']);
    $r = integration_test($id, $provider);
    json_out($r + ['badge' => status_badge($r['status']), 'tested' => date('M j, Y H:i') . ' UTC']);
}

// ---------- Saves ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    try {
        switch ($action) {
            case 'general':
                $name = trim($_POST['name'] ?? '');
                $tz   = trim($_POST['timezone'] ?? '');
                if ($name === '') throw new Exception('Location name is required.');
                if (!in_array($tz, timezone_identifiers_list(), true)) throw new Exception('Pick a valid time zone.');
                q('UPDATE locations SET name = ?, timezone = ?, updated_at = ? WHERE id = ?', [$name, $tz, now_utc(), $id]);
                flash('General settings saved.');
                break;
            case 'ghl':
            case 'open_dental':
                integration_save($id, $action, $_POST);
                flash(($action === 'ghl' ? 'GHL' : 'Open Dental') . ' settings saved. Click "Test Connection" to check them.');
                break;
            case 'toggle':
                $newState = $loc['is_active'] ? 0 : 1;
                q('UPDATE locations SET is_active = ?, updated_at = ? WHERE id = ?', [$newState, now_utc(), $id]);
                flash($newState ? 'Location activated. Assigned staff can see it again.' : 'Location deactivated. Staff can no longer see it; its data is kept.');
                break;
            case 'delete':
                if (trim($_POST['confirm_name'] ?? '') !== $loc['name']) throw new Exception('To delete, type the location name exactly.');
                q('DELETE FROM locations WHERE id = ?', [$id]);
                flash('Location "' . $loc['name'] . '" deleted.');
                redirect('locations.php');
        }
    } catch (Exception $e) {
        flash($e->getMessage(), 'bad');
    }
    redirect('location.php?loc=' . $id . '#' . $action);
}

$ghl   = integration_public($id, 'ghl');
$od    = integration_public($id, 'open_dental');
$staff = q_all('SELECT u.id, u.name, u.email, u.role, u.is_active FROM user_locations ul JOIN users u ON u.id = ul.user_id
                WHERE ul.location_id = ? ORDER BY u.name', [$id]);
$tzList = ['America/New_York','America/Chicago','America/Denver','America/Phoenix','America/Los_Angeles','America/Anchorage','Pacific/Honolulu'];
if (!in_array($loc['timezone'], $tzList, true)) $tzList[] = $loc['timezone'];

function integration_form($provider, $data, $title, $help) {
    ?>
    <div class="card" id="<?= $provider ?>">
      <div class="head" style="margin-bottom:8px">
        <h2 style="margin:0"><?= e($title) ?></h2>
        <span id="badge_<?= $provider ?>"><?= status_badge($data['status']) ?></span>
      </div>
      <p style="margin-top:0"><?= $help ?></p>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="<?= $provider ?>">
        <?php foreach (INTEGRATION_FIELDS[$provider] as $key => $f): ?>
          <label><?= e($f['label']) ?> <span class="req">*</span>
            <?php if ($f['secret']): ?>
              <input type="password" name="<?= $key ?>" autocomplete="new-password"
                     placeholder="<?= $data['config'][$key] ? e($data['config'][$key]) . '  (leave blank to keep)' : 'Paste key' ?>">
            <?php else: ?>
              <input type="text" name="<?= $key ?>" value="<?= e($data['config'][$key]) ?>">
            <?php endif; ?>
          </label>
        <?php endforeach; ?>
        <div class="actions">
          <button class="btn">Save</button>
          <button type="button" class="btn ghost" onclick="testConn('<?= $provider ?>', this)">Test Connection</button>
        </div>
      </form>
      <div class="status-line <?= $data['status'] === 'connected' ? 'ok' : ($data['status'] === 'failed' ? 'bad' : '') ?>" id="msg_<?= $provider ?>">
        <?php if ($data['tested']): ?>
          <?= e($data['message']) ?> <small>· tested <?= e(date('M j, Y H:i', strtotime($data['tested'] . ' UTC'))) ?> UTC</small>
        <?php endif; ?>
      </div>
    </div>
    <?php
}

render_header($loc['name'], 'locations');
?>
<div class="head">
  <div>
    <a href="locations.php">← Locations</a>
    <h1 style="margin-top:6px"><?= e($loc['name']) ?> <?= $loc['is_active'] ? '' : '<span class="badge muted">Inactive</span>' ?></h1>
  </div>
  <div class="actions">
    <a class="btn ghost" href="viewer.php?loc=<?= $id ?>">Open data viewer</a>
    <a class="btn" href="sync.php?loc=<?= $id ?>">Sync to GHL</a>
  </div>
</div>

<div class="grid2">
  <div>
    <div class="card" id="general">
      <h2>General</h2>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="general">
        <label>Location name <span class="req">*</span>
          <input type="text" name="name" value="<?= e($loc['name']) ?>" required>
        </label>
        <label>Time zone <span class="hint">(Open Dental appointment times are in this zone)</span>
          <select name="timezone">
            <?php foreach ($tzList as $tz): ?>
              <option <?= $tz === $loc['timezone'] ? 'selected' : '' ?>><?= e($tz) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <button class="btn">Save</button>
      </form>
    </div>

    <?php integration_form('ghl', $ghl, 'GoHighLevel (GHL)',
        'API key: GHL <b>Settings → Private Integrations</b> (starts with <code>pit-</code>). Location ID: the part after <code>/location/</code> in the GHL address bar. Calendar ID: <b>Settings → Calendars</b>, copy icon next to the calendar.'); ?>
  </div>

  <div>
    <?php integration_form('open_dental', $od, 'Open Dental',
        'Customer API key: Open Dental Developer Portal → <b>Customer Keys</b>. Developer API key: Developer Portal → <b>Account</b>.'); ?>

    <div class="card">
      <h2>Assigned staff</h2>
      <?php if (!$staff): ?>
        <p>No staff assigned. Assign staff from the <a href="staff.php">Staff</a> page.</p>
      <?php else: ?>
        <table class="list">
          <?php foreach ($staff as $s): ?>
            <tr class="<?= $s['is_active'] ? '' : 'inactive' ?>">
              <td><a href="staff_edit.php?id=<?= (int)$s['id'] ?>"><?= e($s['name']) ?></a><br><small><?= e($s['email']) ?></small></td>
              <td><?= e(ucfirst($s['role'])) ?></td>
              <td><?= $s['is_active'] ? '<span class="badge ok">Active</span>' : '<span class="badge muted">Inactive</span>' ?></td>
            </tr>
          <?php endforeach; ?>
        </table>
      <?php endif; ?>
    </div>

    <div class="card">
      <h2>Status</h2>
      <p style="margin-top:0"><?= $loc['is_active'] ? 'Deactivating hides this location from staff but keeps all its settings and data.' : 'This location is hidden from staff. Activate it to make it visible again.' ?></p>
      <form method="post" style="margin-bottom:18px">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="toggle">
        <button class="btn ghost"><?= $loc['is_active'] ? 'Deactivate location' : 'Activate location' ?></button>
      </form>
      <details>
        <summary style="cursor:pointer;color:var(--bad)">Delete permanently…</summary>
        <form method="post" style="margin-top:10px" onsubmit="return confirm('Delete this location and all its settings permanently?')">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete">
          <label>Type <b><?= e($loc['name']) ?></b> to confirm
            <input type="text" name="confirm_name" autocomplete="off">
          </label>
          <button class="btn danger">Delete location</button>
        </form>
      </details>
    </div>
  </div>
</div>

<script>
async function testConn(provider, btn) {
  const msg = document.getElementById('msg_' + provider);
  btn.disabled = true;
  msg.className = 'status-line';
  msg.textContent = 'Testing…';
  try {
    const body = new URLSearchParams({ action: 'test', provider, loc: '<?= $id ?>', csrf: '<?= e(csrf_token()) ?>' });
    const res = await fetch('location.php?loc=<?= $id ?>', { method: 'POST', body });
    const r = await res.json();
    if (r.error) throw new Error(r.error);
    msg.className = 'status-line ' + (r.status === 'connected' ? 'ok' : 'bad');
    msg.textContent = r.message + (r.tested ? '  · tested ' + r.tested : '');
    if (r.badge) document.getElementById('badge_' + provider).innerHTML = r.badge;
  } catch (e) {
    msg.className = 'status-line bad';
    msg.textContent = e.message;
  } finally {
    btn.disabled = false;
  }
}
</script>
<?php render_footer();
