<?php
// lib/bootstrap.php — loaded by every page.

require_once dirname(__DIR__) . '/config.php';

if (defined('APP_DEBUG') && APP_DEBUG) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
}

date_default_timezone_set('UTC'); // all DB times are UTC

define('APP_ROOT', dirname(__DIR__));
define('DATA_DIR', APP_ROOT . '/data');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/locations.php';
require_once __DIR__ . '/layout.php';

function e($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function redirect($path) {
    header('Location: ' . $path);
    exit;
}

function flash($msg = null, $type = 'ok') {
    if ($msg !== null) {
        $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function json_out($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function fmt_dt($utc) {
    if (!$utc) return '—';
    return date('M j, Y', strtotime($utc . ' UTC'));
}

// Small PHP session only for flash messages and the login email between screens.
if (session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli') {
    session_name('portal_tmp');
    session_set_cookie_params(['httponly' => true, 'secure' => is_https(), 'samesite' => 'Lax', 'path' => '/']);
    session_start();
}

function is_https() {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}
