<?php
// config.php — portal settings. Keep this file private.
// Location credentials (GHL + Open Dental) are NOT here: they are entered in the portal
// per location and stored encrypted in the database.

// ---------------- Database (hPanel > Databases > MySQL Databases) ----------------
define('DB_HOST', 'localhost');
define('DB_NAME', 'u937090168_speedadsai');   // your database name
define('DB_USER', 'u937090168_speedadsai');   // your database user
define('DB_PASS', 'u937090168_Speedadsai');

// ---------------- Security ----------------
// Encryption key for API keys stored in the database. 64 hex characters.
// Generate once and NEVER change it later (saved keys could not be decrypted).
// Leave as-is and open setup.php: it shows a fresh random key you can paste here.
define('APP_KEY', '9fa24014eb3db3c37e4f9341508d0f5f352452e7c3858646671d0e1cbb659662');

// Full address of the portal, no trailing slash. Used in emails and webhook links.
define('APP_URL', 'https://opendental.speedadsai.com');

define('APP_NAME', 'Admin Portal');

// ---------------- Email for login codes ----------------
// 'smtp' = send through a Hostinger mailbox (recommended; create one in hPanel > Emails)
// 'mail' = PHP mail() (works on Hostinger, but more likely to land in spam)
define('MAIL_DRIVER', 'smtp');
define('SMTP_HOST', 'smtp.hostinger.com');
define('SMTP_PORT', 465);                        // 465 = SSL
define('SMTP_USER', 'info@speedadsai.com');  // the mailbox
define('SMTP_PASS', '#123456789#Hostinger');
define('MAIL_FROM', 'info@speedadsai.com');
define('MAIL_FROM_NAME', 'Admin Portal');

// ---------------- Sync defaults (all locations) ----------------
define('CONTACT_TAG', 'opendental');       // tag on every contact created or updated
define('CONTACT_SOURCE', 'opendental');    // Contact Source on every contact
define('SYNC_ALL_PATIENT_CHANGES', true);  // automatic sync: send every edited patient to GHL

// Show PHP errors on screen (turn on only while debugging)
define('APP_DEBUG', false);
