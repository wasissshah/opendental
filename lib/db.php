<?php
// lib/db.php — PDO connection and small query helpers.

function db() {
    static $pdo = null;
    if ($pdo) return $pdo;
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}

function q($sql, $params = []) {
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function q_one($sql, $params = []) {
    $r = q($sql, $params)->fetch();
    return $r ?: null;
}

function q_all($sql, $params = []) {
    return q($sql, $params)->fetchAll();
}

function now_utc($plusSeconds = 0) {
    return gmdate('Y-m-d H:i:s', time() + $plusSeconds);
}
