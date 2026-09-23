<?php
// lib/crypto.php — AES-256-GCM encryption for API keys stored in the database.

function app_key_bytes() {
    if (!preg_match('/^[0-9a-fA-F]{64}$/', APP_KEY)) {
        throw new Exception('APP_KEY in config.php must be 64 hex characters. Open setup.php to generate one.');
    }
    return hex2bin(APP_KEY);
}

function encrypt_str($plain) {
    $iv  = random_bytes(12);
    $tag = '';
    $ct  = openssl_encrypt($plain, 'aes-256-gcm', app_key_bytes(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($ct === false) throw new Exception('Encryption failed');
    return 'v1:' . base64_encode($iv . $tag . $ct);
}

function decrypt_str($stored) {
    if (strpos((string)$stored, 'v1:') !== 0) return null;
    $raw = base64_decode(substr($stored, 3), true);
    if ($raw === false || strlen($raw) < 28) return null;
    $iv  = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ct  = substr($raw, 28);
    $pt  = openssl_decrypt($ct, 'aes-256-gcm', app_key_bytes(), OPENSSL_RAW_DATA, $iv, $tag);
    return $pt === false ? null : $pt;
}

// ••••1234
function mask_secret($s) {
    $s = (string)$s;
    if ($s === '') return '';
    return '••••' . substr($s, -4);
}

// Keyed hash for login codes and session tokens (never stored in plain text)
function token_hash($value) {
    return hash_hmac('sha256', (string)$value, APP_KEY);
}
