<?php
// lib/auth.php — passwordless email-code login, 7-day sessions, role checks, CSRF.

const SESSION_COOKIE   = 'portal_session';
const SESSION_DAYS     = 7;
const CODE_MINUTES     = 10;
const CODE_MAX_TRIES   = 5;
const CODE_RESEND_SECS = 60;
const CODE_PER_HOUR    = 5;

// ---------------- Login codes ----------------

// Always returns silently: the page shows the same message whether or not the email exists.
function request_login_code($email) {
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return;

    $user = q_one('SELECT * FROM users WHERE email = ? AND is_active = 1', [$email]);
    if (!$user) return;

    // Max 5 codes per email per hour
    $recent = q_one('SELECT COUNT(*) AS n FROM login_codes WHERE user_id = ? AND created_at > ?',
                    [$user['id'], now_utc(-3600)])['n'];
    if ($recent >= CODE_PER_HOUR) return;

    // Resend only after 60 seconds
    $last = q_one('SELECT created_at FROM login_codes WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$user['id']]);
    if ($last && strtotime($last['created_at'] . ' UTC') > time() - CODE_RESEND_SECS) return;

    // A new code invalidates any older one
    q('UPDATE login_codes SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [now_utc(), $user['id']]);

    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    q('INSERT INTO login_codes (user_id, code_hash, expires_at, created_at) VALUES (?, ?, ?, ?)',
      [$user['id'], token_hash($code), now_utc(CODE_MINUTES * 60), now_utc()]);

    $subject = APP_NAME . ' login code: ' . $code;
    $text = "Hi {$user['name']},\n\nYour login code is: $code\n\nIt expires in " . CODE_MINUTES .
            " minutes. If you didn't ask for it, you can ignore this email.\n";
    $html = '<div style="font-family:Arial,sans-serif;font-size:15px;color:#222">'
          . '<p>Hi ' . e($user['name']) . ',</p><p>Your login code is:</p>'
          . '<p style="font-size:30px;font-weight:bold;letter-spacing:6px;margin:12px 0">' . $code . '</p>'
          . '<p style="color:#666">It expires in ' . CODE_MINUTES . " minutes. If you didn't ask for it, you can ignore this email.</p></div>";
    try {
        send_mail($user['email'], $user['name'], $subject, $html, $text);
    } catch (Exception $e) {
        error_log('Login code email failed: ' . $e->getMessage());
    }
}

// Returns the user on success, or null.
function verify_login_code($email, $code) {
    $email = strtolower(trim($email));
    $code  = preg_replace('/\D/', '', (string)$code);
    $user  = q_one('SELECT * FROM users WHERE email = ? AND is_active = 1', [$email]);
    if (!$user || strlen($code) !== 6) return null;

    $row = q_one('SELECT * FROM login_codes WHERE user_id = ? AND used_at IS NULL ORDER BY id DESC LIMIT 1', [$user['id']]);
    if (!$row) return null;
    if (strtotime($row['expires_at'] . ' UTC') < time() || $row['attempts'] >= CODE_MAX_TRIES) {
        q('UPDATE login_codes SET used_at = ? WHERE id = ?', [now_utc(), $row['id']]);
        return null;
    }
    if (!hash_equals($row['code_hash'], token_hash($code))) {
        $tries = $row['attempts'] + 1;
        q('UPDATE login_codes SET attempts = ?, used_at = ? WHERE id = ?',
          [$tries, $tries >= CODE_MAX_TRIES ? now_utc() : null, $row['id']]);
        return null;
    }

    q('UPDATE login_codes SET used_at = ? WHERE id = ?', [now_utc(), $row['id']]);
    q('UPDATE users SET last_login_at = ? WHERE id = ?', [now_utc(), $user['id']]);
    start_session($user['id']);
    return $user;
}

// ---------------- Sessions ----------------

function start_session($userId) {
    $token = bin2hex(random_bytes(32));
    q('INSERT INTO sessions (user_id, token_hash, expires_at, created_at, last_seen_at) VALUES (?, ?, ?, ?, ?)',
      [$userId, token_hash($token), now_utc(SESSION_DAYS * 86400), now_utc(), now_utc()]);
    setcookie(SESSION_COOKIE, $token, [
        'expires'  => time() + SESSION_DAYS * 86400,
        'path'     => '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE[SESSION_COOKIE] = $token;
    // Occasional clean-up of old rows
    if (random_int(1, 20) === 1) {
        q('DELETE FROM sessions WHERE expires_at < ?', [now_utc()]);
        q('DELETE FROM login_codes WHERE created_at < ?', [now_utc(-7 * 86400)]);
    }
}

function end_session() {
    $token = $_COOKIE[SESSION_COOKIE] ?? '';
    if ($token !== '') q('DELETE FROM sessions WHERE token_hash = ?', [token_hash($token)]);
    setcookie(SESSION_COOKIE, '', ['expires' => time() - 3600, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
    unset($_COOKIE[SESSION_COOKIE]);
}

// Checked on every request, so deactivating a user or removing a location works immediately.
function current_user() {
    static $user = false;
    if ($user !== false) return $user;
    $user = null;
    $token = $_COOKIE[SESSION_COOKIE] ?? '';
    if ($token === '') return null;
    $row = q_one('SELECT u.*, s.id AS session_id FROM sessions s JOIN users u ON u.id = s.user_id
                   WHERE s.token_hash = ? AND s.expires_at > ? AND u.is_active = 1',
                 [token_hash($token), now_utc()]);
    if (!$row) return null;
    q('UPDATE sessions SET last_seen_at = ? WHERE id = ?', [now_utc(), $row['session_id']]);
    $user = $row;
    return $user;
}

function is_admin() {
    $u = current_user();
    return $u && $u['role'] === 'admin';
}

function require_login($api = false) {
    $u = current_user();
    if (!$u) {
        if ($api) json_out(['error' => 'Your session has ended. Please log in again.'], 401);
        redirect('login.php');
    }
    return $u;
}

function require_admin($api = false) {
    $u = require_login($api);
    if ($u['role'] !== 'admin') {
        if ($api) json_out(['error' => 'Admins only.'], 403);
        http_response_code(403);
        render_header('Not allowed');
        echo '<div class="card"><h2>Admins only</h2><p>You don\'t have access to this page.</p><p><a href="index.php">Back</a></p></div>';
        render_footer();
        exit;
    }
    return $u;
}

// Locations this user may open (admins: all active; staff: active + assigned)
function user_location_ids($user) {
    if ($user['role'] === 'admin') {
        return array_map('intval', array_column(q_all('SELECT id FROM locations'), 'id'));
    }
    return array_map('intval', array_column(q_all(
        'SELECT l.id FROM user_locations ul JOIN locations l ON l.id = ul.location_id
         WHERE ul.user_id = ? AND l.is_active = 1', [$user['id']]), 'id'));
}

function can_access_location($user, $locationId) {
    return in_array((int)$locationId, user_location_ids($user), true);
}

// Loads the location from ?loc= and checks access. Ends the request if not allowed.
function require_location_access($api = false) {
    $u = require_login($api);
    $id = (int)($_GET['loc'] ?? $_POST['loc'] ?? 0);
    if (!$id || !can_access_location($u, $id)) {
        if ($api) json_out(['error' => 'You don\'t have access to this location.'], 403);
        http_response_code(403);
        render_header('Not allowed');
        echo '<div class="card"><h2>No access</h2><p>You don\'t have access to this location.</p><p><a href="index.php">Back</a></p></div>';
        render_footer();
        exit;
    }
    return [$u, $id];
}

// ---------------- CSRF ----------------
// Tied to the login session, so it stays valid for the whole 7 days.

function csrf_token() {
    $t = $_COOKIE[SESSION_COOKIE] ?? '';
    if ($t === '') {
        // Before login (login pages): use the short PHP session
        if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
        return $_SESSION['csrf'];
    }
    return hash_hmac('sha256', 'csrf|' . $t, APP_KEY);
}

function csrf_field() {
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function check_csrf($api = false) {
    $sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? '');
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        if ($api) json_out(['error' => 'Security check failed. Refresh the page and try again.'], 419);
        http_response_code(419);
        exit('Security check failed. Go back, refresh the page and try again.');
    }
}
