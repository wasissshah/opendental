<?php
require __DIR__ . '/lib/bootstrap.php';
require_admin();

$search = trim($_GET['q'] ?? '');
$sql = 'SELECT * FROM users';
$params = [];
if ($search !== '') { $sql .= ' WHERE name LIKE ? OR email LIKE ?'; $params = ['%' . $search . '%', '%' . $search . '%']; }
$sql .= ' ORDER BY is_active DESC, name';
$users = q_all($sql, $params);

// Assigned locations for everyone, in one query
$assigned = [];
foreach (q_all('SELECT ul.user_id, l.name, l.is_active FROM user_locations ul JOIN locations l ON l.id = ul.location_id ORDER BY l.name') as $r) {
    $assigned[$r['user_id']][] = $r;
}

render_header('Staff', 'staff');
?>
<div class="head">
  <h1>Staff Members</h1>
  <div class="actions">
    <form class="search" method="get">
      <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search by name or email">
      <button class="btn ghost">Search</button>
    </form>
    <a class="btn" href="staff_edit.php">+ Add Staff</a>
  </div>
</div>

<div class="tablewrap">
  <table class="list">
    <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Assigned locations</th><th>Status</th></tr></thead>
    <tbody>
    <?php if (!$users): ?>
      <tr><td colspan="5" class="empty">No staff match your search.</td></tr>
    <?php endif; ?>
    <?php foreach ($users as $u): ?>
      <tr class="click <?= $u['is_active'] ? '' : 'inactive' ?>" onclick="location.href='staff_edit.php?id=<?= (int)$u['id'] ?>'">
        <td><b><?= e($u['name']) ?></b></td>
        <td><?= e($u['email']) ?></td>
        <td><?= e(ucfirst($u['role'])) ?></td>
        <td>
          <?php if ($u['role'] === 'admin'): ?>
            <small>All locations</small>
          <?php elseif (empty($assigned[$u['id']])): ?>
            <small>None</small>
          <?php else: foreach ($assigned[$u['id']] as $a): ?>
            <span class="chip"><?= e($a['name']) ?><?= $a['is_active'] ? '' : ' (inactive)' ?></span>
          <?php endforeach; endif; ?>
        </td>
        <td><?= $u['is_active'] ? '<span class="badge ok">Active</span>' : '<span class="badge muted">Inactive</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php render_footer();
