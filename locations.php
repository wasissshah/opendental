<?php
require __DIR__ . '/lib/bootstrap.php';
require_admin();

// Add location
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $name = trim($_POST['name'] ?? '');
    $tz   = trim($_POST['timezone'] ?? 'America/Denver');
    if ($name === '') {
        flash('Enter a location name.', 'bad');
        redirect('locations.php');
    }
    if (!in_array($tz, timezone_identifiers_list(), true)) $tz = 'America/Denver';
    q('INSERT INTO locations (name, timezone, webhook_secret, is_active, created_at, updated_at) VALUES (?, ?, ?, 1, ?, ?)',
      [$name, $tz, bin2hex(random_bytes(20)), now_utc(), now_utc()]);
    $id = db()->lastInsertId();
    flash('Location created. Now add its GHL and Open Dental settings.');
    redirect('location.php?loc=' . $id);
}

$search = trim($_GET['q'] ?? '');
$sql = 'SELECT l.*,
          (SELECT status FROM location_integrations WHERE location_id = l.id AND provider = "ghl") AS ghl_status,
          (SELECT status FROM location_integrations WHERE location_id = l.id AND provider = "open_dental") AS od_status,
          (SELECT COUNT(*) FROM user_locations ul JOIN users u ON u.id = ul.user_id WHERE ul.location_id = l.id) AS staff_count
        FROM locations l';
$params = [];
if ($search !== '') { $sql .= ' WHERE l.name LIKE ?'; $params[] = '%' . $search . '%'; }
$sql .= ' ORDER BY l.is_active DESC, l.name';
$rows = q_all($sql, $params);

render_header('Locations', 'locations');
?>
<div class="head">
  <h1>Locations</h1>
  <div class="actions">
    <form class="search" method="get">
      <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search by name">
      <button class="btn ghost">Search</button>
    </form>
    <button class="btn" onclick="document.getElementById('addbox').hidden = !document.getElementById('addbox').hidden">+ Add Location</button>
  </div>
</div>

<div class="card" id="addbox" hidden>
  <h2>Add Location</h2>
  <form method="post" class="grid2" style="align-items:end">
    <?= csrf_field() ?>
    <label>Location name <span class="req">*</span>
      <input type="text" name="name" required placeholder="e.g. Arvada Implants and Cosmetic Dentistry">
    </label>
    <label>Time zone (for appointment times)
      <select name="timezone">
        <?php foreach (['America/New_York','America/Chicago','America/Denver','America/Phoenix','America/Los_Angeles','America/Anchorage','Pacific/Honolulu'] as $tz): ?>
          <option value="<?= e($tz) ?>" <?= $tz === 'America/Denver' ? 'selected' : '' ?>><?= e($tz) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <div><button class="btn">Create location</button></div>
  </form>
</div>

<div class="tablewrap">
  <table class="list">
    <thead>
      <tr><th>Location name</th><th>GHL</th><th>Open Dental</th><th>Assigned staff</th><th>Created</th></tr>
    </thead>
    <tbody>
    <?php if (!$rows): ?>
      <tr><td colspan="5" class="empty"><?= $search ? 'No locations match your search.' : 'No locations yet. Click "Add Location" to create the first one.' ?></td></tr>
    <?php endif; ?>
    <?php foreach ($rows as $r): ?>
      <tr class="click <?= $r['is_active'] ? '' : 'inactive' ?>" onclick="location.href='location.php?loc=<?= (int)$r['id'] ?>'">
        <td><b><?= e($r['name']) ?></b><?= $r['is_active'] ? '' : ' <span class="badge muted">Inactive</span>' ?></td>
        <td><?= status_badge($r['ghl_status'] ?: 'not_set') ?></td>
        <td><?= status_badge($r['od_status'] ?: 'not_set') ?></td>
        <td><?= (int)$r['staff_count'] ?></td>
        <td><?= e(fmt_dt($r['created_at'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php render_footer();
