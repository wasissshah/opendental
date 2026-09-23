<?php
// staff_edit.php — add / edit a staff member and assign locations (admin only).
require __DIR__ . '/lib/bootstrap.php';
$me = require_admin();

$id   = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$user = $id ? q_one('SELECT * FROM users WHERE id = ?', [$id]) : null;
if ($id && !$user) { flash('Staff member not found.', 'bad'); redirect('staff.php'); }

function active_admin_count() {
    return (int)q_one('SELECT COUNT(*) AS n FROM users WHERE role = "admin" AND is_active = 1')['n'];
}

// ---------- One-click assign / remove (AJAX) ----------
if (($_POST['action'] ?? '') === 'assign' && $user) {
    check_csrf(true);
    $locId = (int)($_POST['location_id'] ?? 0);
    if (!location_get($locId)) json_out(['error' => 'Location not found'], 404);
    if (($_POST['on'] ?? '') === '1') {
        q('INSERT IGNORE INTO user_locations (user_id, location_id, assigned_at) VALUES (?, ?, ?)', [$id, $locId, now_utc()]);
    } else {
        q('DELETE FROM user_locations WHERE user_id = ? AND location_id = ?', [$id, $locId]);
    }
    json_out(['ok' => true]);
}

// ---------- Save profile ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? 'save';
    try {
        if ($action === 'toggle' && $user) {
            if ($user['id'] == $me['id']) throw new Exception("You can't deactivate your own account.");
            if ($user['is_active'] && $user['role'] === 'admin' && active_admin_count() <= 1) throw new Exception('At least one active admin is needed.');
            $new = $user['is_active'] ? 0 : 1;
            q('UPDATE users SET is_active = ? WHERE id = ?', [$new, $id]);
            if (!$new) {
                q('DELETE FROM sessions WHERE user_id = ?', [$id]);      // ends active sessions now
                q('UPDATE login_codes SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [now_utc(), $id]);
            }
            flash($new ? 'Staff member activated.' : 'Staff member deactivated and logged out everywhere.');
            redirect('staff_edit.php?id=' . $id);
        }

        $name  = trim($_POST['name'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $role  = ($_POST['role'] ?? 'staff') === 'admin' ? 'admin' : 'staff';
        if ($name === '') throw new Exception('Name is required.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Enter a valid email.');
        $dupe = q_one('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, $id]);
        if ($dupe) throw new Exception('Another staff member already uses this email.');

        if ($user) {
            if ($user['id'] == $me['id'] && $role !== 'admin') throw new Exception("You can't remove your own admin role.");
            if ($user['role'] === 'admin' && $role !== 'admin' && $user['is_active'] && active_admin_count() <= 1) {
                throw new Exception('At least one active admin is needed.');
            }
            q('UPDATE users SET name = ?, email = ?, role = ? WHERE id = ?', [$name, $email, $role, $id]);
            if ($email !== $user['email']) q('DELETE FROM sessions WHERE user_id = ?', [$id]);
            flash('Profile saved.');
        } else {
            q('INSERT INTO users (name, email, role, is_active, created_at) VALUES (?, ?, ?, 1, ?)', [$name, $email, $role, now_utc()]);
            $id = (int)db()->lastInsertId();
            foreach ((array)($_POST['locations'] ?? []) as $locId) {
                if (location_get((int)$locId)) {
                    q('INSERT IGNORE INTO user_locations (user_id, location_id, assigned_at) VALUES (?, ?, ?)', [$id, (int)$locId, now_utc()]);
                }
            }
            flash('Staff member added. They can log in with ' . $email . '.');
        }
        redirect('staff_edit.php?id=' . $id);
    } catch (Exception $e) {
        flash($e->getMessage(), 'bad');
        redirect('staff_edit.php' . ($id ? '?id=' . $id : ''));
    }
}

$locations = q_all('SELECT id, name, is_active FROM locations ORDER BY is_active DESC, name');
$mine = $user ? array_map('intval', array_column(q_all('SELECT location_id FROM user_locations WHERE user_id = ?', [$id]), 'location_id')) : [];

render_header($user ? $user['name'] : 'Add Staff', 'staff');
?>
<div class="head">
  <div>
    <a href="staff.php">← Staff</a>
    <h1 style="margin-top:6px"><?= $user ? e($user['name']) : 'Add Staff' ?>
      <?php if ($user && !$user['is_active']): ?><span class="badge muted">Inactive</span><?php endif; ?></h1>
  </div>
</div>

<div class="grid2">
  <div class="card">
    <h2>Profile</h2>
    <form method="post" id="profile">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int)$id ?>">
      <label>Name <span class="req">*</span>
        <input type="text" name="name" value="<?= e($user['name'] ?? '') ?>" required>
      </label>
      <label>Email <span class="req">*</span> <span class="hint">(used to log in)</span>
        <input type="email" name="email" value="<?= e($user['email'] ?? '') ?>" required>
      </label>
      <label>Role <span class="req">*</span>
        <select name="role">
          <option value="staff" <?= ($user['role'] ?? 'staff') === 'staff' ? 'selected' : '' ?>>Staff: only assigned locations</option>
          <option value="admin" <?= ($user['role'] ?? '') === 'admin' ? 'selected' : '' ?>>Admin: all locations, configuration and staff</option>
        </select>
      </label>
      <?php if (!$user): ?>
        <label>Assign locations</label>
        <div class="checklist" style="margin-bottom:14px">
          <?php if (!$locations): ?><small>No locations yet.</small><?php endif; ?>
          <?php foreach ($locations as $l): ?>
            <label><input type="checkbox" name="locations[]" value="<?= (int)$l['id'] ?>"> <?= e($l['name']) ?><?= $l['is_active'] ? '' : ' <small>(inactive)</small>' ?></label>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <button class="btn"><?= $user ? 'Save profile' : 'Add staff member' ?></button>
    </form>

    <?php if ($user && $user['id'] != $me['id']): ?>
      <form method="post" style="margin-top:22px;border-top:1px solid var(--line);padding-top:16px">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$id ?>">
        <input type="hidden" name="action" value="toggle">
        <p style="margin-top:0"><?= $user['is_active'] ? 'Deactivating blocks login right away and logs them out everywhere.' : 'This person cannot log in.' ?></p>
        <button class="btn <?= $user['is_active'] ? 'danger' : 'ghost' ?>"><?= $user['is_active'] ? 'Deactivate' : 'Activate' ?></button>
      </form>
    <?php endif; ?>
  </div>

  <?php if ($user): ?>
  <div class="card">
    <h2>Assigned locations</h2>
    <?php if ($user['role'] === 'admin'): ?>
      <p style="margin-top:0">Admins can open every location. Assignments below only matter if the role changes to Staff.</p>
    <?php else: ?>
      <p style="margin-top:0">Tick or untick to add or remove. Changes save immediately.</p>
    <?php endif; ?>
    <div class="checklist">
      <?php if (!$locations): ?><small>No locations yet. <a href="locations.php">Create one</a>.</small><?php endif; ?>
      <?php foreach ($locations as $l): ?>
        <label>
          <input type="checkbox" <?= in_array((int)$l['id'], $mine, true) ? 'checked' : '' ?> onchange="assign(<?= (int)$l['id'] ?>, this)">
          <?= e($l['name']) ?><?= $l['is_active'] ? '' : ' <small>(inactive)</small>' ?>
        </label>
      <?php endforeach; ?>
    </div>
    <div class="status-line" id="assignMsg"></div>
    <p style="font-size:12.5px">Last login: <?= e($user['last_login_at'] ? date('M j, Y H:i', strtotime($user['last_login_at'] . ' UTC')) . ' UTC' : 'never') ?></p>
  </div>
  <?php endif; ?>
</div>

<script>
async function assign(locId, box) {
  const msg = document.getElementById('assignMsg');
  box.disabled = true;
  try {
    const body = new URLSearchParams({ action: 'assign', id: '<?= (int)$id ?>', location_id: locId, on: box.checked ? '1' : '0', csrf: '<?= e(csrf_token()) ?>' });
    const res = await fetch('staff_edit.php?id=<?= (int)$id ?>', { method: 'POST', body });
    const r = await res.json();
    if (r.error) throw new Error(r.error);
    msg.className = 'status-line ok';
    msg.textContent = box.checked ? 'Location added.' : 'Location removed.';
  } catch (e) {
    box.checked = !box.checked;
    msg.className = 'status-line bad';
    msg.textContent = e.message;
  } finally {
    box.disabled = false;
  }
}
</script>
<?php render_footer();
