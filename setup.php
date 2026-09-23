<?php
// setup.php — one-time setup: checks config and creates the first admin.
// Locks itself as soon as any user exists.

require_once __DIR__ . '/config.php';
define('APP_ROOT', __DIR__);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$checks = [];
$keyOk = (bool)preg_match('/^[0-9a-fA-F]{64}$/', APP_KEY);
$checks[] = ['APP_KEY in config.php', $keyOk, $keyOk ? 'Set' : 'Not set. Paste this into config.php: ' . bin2hex(random_bytes(32))];
$checks[] = ['PHP version 7.4+', version_compare(PHP_VERSION, '7.4', '>='), PHP_VERSION];
$checks[] = ['cURL extension', function_exists('curl_init'), function_exists('curl_init') ? 'OK' : 'Missing'];
$checks[] = ['OpenSSL extension', function_exists('openssl_encrypt'), function_exists('openssl_encrypt') ? 'OK' : 'Missing'];
$checks[] = ['APP_URL', strpos(APP_URL, 'http') === 0, APP_URL];

$dataOk = is_dir(__DIR__ . '/data') && is_writable(__DIR__ . '/data');
$checks[] = ['data folder writable', $dataOk, $dataOk ? 'OK' : 'Create the "data" folder and make it writable (755)'];

$dbOk = false; $dbMsg = ''; $hasUsers = false; $tablesOk = false;
try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS,
                   [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $dbOk = true;
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $need = ['users', 'locations', 'location_integrations', 'user_locations', 'login_codes', 'sessions'];
    $missing = array_diff($need, $tables);
    $tablesOk = !$missing;
    $dbMsg = $tablesOk ? 'Connected, all tables found' : 'Connected, but missing tables: ' . implode(', ', $missing) . '. Import database.sql in phpMyAdmin.';
    if ($tablesOk) $hasUsers = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
} catch (Exception $e) {
    $dbMsg = 'Cannot connect: ' . $e->getMessage();
}
$checks[] = ['Database', $dbOk && $tablesOk, $dbMsg];

$allOk = !in_array(false, array_column($checks, 1), true);
$error = '';
$done = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $allOk && !$hasUsers) {
    $name  = trim($_POST['name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter your name and a valid email.';
    } else {
        $pdo->prepare('INSERT INTO users (name, email, role, is_active, created_at) VALUES (?, ?, "admin", 1, UTC_TIMESTAMP())')
            ->execute([$name, $email]);
        $done = true;
        $hasUsers = true;
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Setup · <?= h(APP_NAME) ?></title>
  <link rel="stylesheet" href="assets/app.css?v=3">
</head>
<body>
<main class="wrap" style="max-width:720px">
  <h1>Portal setup</h1>

  <div class="card">
    <h2>1. Checks</h2>
    <table class="list">
      <?php foreach ($checks as [$label, $ok, $msg]): ?>
        <tr>
          <td style="width:200px"><?= h($label) ?></td>
          <td><span class="badge <?= $ok ? 'ok' : 'bad' ?>"><?= $ok ? 'OK' : 'Fix' ?></span></td>
          <td style="word-break:break-all"><?= h($msg) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>

  <div class="card">
    <h2>2. First admin</h2>
    <?php if ($done): ?>
      <div class="flash ok">Admin created. You can now <a href="login.php">log in</a> with that email. This setup page is now locked.</div>
    <?php elseif ($hasUsers): ?>
      <p>Setup is already finished. <a href="login.php">Go to login</a>.</p>
    <?php elseif (!$allOk): ?>
      <p>Fix the checks above first, then refresh this page.</p>
    <?php else: ?>
      <?php if ($error): ?><div class="flash bad"><?= h($error) ?></div><?php endif; ?>
      <form method="post">
        <label>Your name <input type="text" name="name" required></label>
        <label>Your email (this is how you log in) <input type="email" name="email" required></label>
        <button class="btn">Create admin</button>
      </form>
    <?php endif; ?>
  </div>
</main>
</body>
</html>
