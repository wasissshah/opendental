<?php
// lib/layout.php — shared page header / footer.

function render_header($title, $active = '', $bare = false) {
    $u = PHP_SAPI !== 'cli' ? current_user() : null;
    ?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title) ?> · <?= e(APP_NAME) ?></title>
  <link rel="stylesheet" href="assets/app.css?v=3">
</head>
<body class="<?= $bare ? 'bare' : '' ?>">
<?php if (!$bare && $u): ?>
  <header class="topbar">
    <a class="brand" href="index.php"><?= e(APP_NAME) ?></a>
    <nav>
      <?php if ($u['role'] === 'admin'): ?>
        <a href="locations.php" class="<?= $active === 'locations' ? 'on' : '' ?>">Locations</a>
        <a href="staff.php" class="<?= $active === 'staff' ? 'on' : '' ?>">Staff</a>
      <?php else: ?>
        <a href="my_locations.php" class="<?= $active === 'mine' ? 'on' : '' ?>">My locations</a>
      <?php endif; ?>
    </nav>
    <div class="who">
      <span><?= e($u['name']) ?> <small><?= e(ucfirst($u['role'])) ?></small></span>
      <a href="logout.php">Log out</a>
    </div>
  </header>
<?php endif; ?>
  <main class="<?= $bare ? 'narrow' : 'wrap' ?>">
<?php
    $f = flash();
    if ($f) echo '<div class="flash ' . e($f['type']) . '">' . e($f['msg']) . '</div>';
}

function render_footer() {
    ?>
  </main>
</body>
</html><?php
}
