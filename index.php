<?php
require __DIR__ . '/lib/bootstrap.php';

// Not set up yet? Go to setup.
try {
    $hasUsers = (int)q_one('SELECT COUNT(*) AS n FROM users')['n'] > 0;
} catch (Exception $e) {
    $hasUsers = false;
}
if (!$hasUsers) redirect('setup.php');

$u = current_user();
if (!$u) redirect('login.php');
redirect($u['role'] === 'admin' ? 'locations.php' : 'my_locations.php');
