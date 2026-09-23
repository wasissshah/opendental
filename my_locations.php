<?php
// my_locations.php — staff landing page: only the locations assigned to them.
require __DIR__ . '/lib/bootstrap.php';
$u = require_login();
if ($u['role'] === 'admin') redirect('locations.php');

$rows = q_all('SELECT l.* FROM user_locations ul JOIN locations l ON l.id = ul.location_id
               WHERE ul.user_id = ? AND l.is_active = 1 ORDER BY l.name', [$u['id']]);

render_header('My locations', 'mine');
?>
<h1>My locations</h1>
<?php if (!$rows): ?>
  <div class="card empty">
    <h2>No locations assigned</h2>
    <p>Contact your admin to get access to a location.</p>
  </div>
<?php else: ?>
  <div class="tiles">
    <?php foreach ($rows as $l): ?>
      <a class="tile" href="viewer.php?loc=<?= (int)$l['id'] ?>">
        <h2><?= e($l['name']) ?></h2>
        <small>Open appointments, open slots and patients →</small>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php render_footer();
