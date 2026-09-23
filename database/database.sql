-- Admin Portal (v1) — database for Hostinger MySQL / MariaDB
-- Import in hPanel > Databases > phpMyAdmin > (your database) > Import.
-- Creates the 5 tables from the spec plus a sessions table for 7-day logins.
-- The first admin is created on the /setup.php page after upload.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `sessions`;
DROP TABLE IF EXISTS `login_codes`;
DROP TABLE IF EXISTS `user_locations`;
DROP TABLE IF EXISTS `location_integrations`;
DROP TABLE IF EXISTS `locations`;
DROP TABLE IF EXISTS `users`;

SET FOREIGN_KEY_CHECKS = 1;

-- One table for admins and staff. Email is the login identity.
CREATE TABLE `users` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`          VARCHAR(150) NOT NULL,
  `email`         VARCHAR(190) NOT NULL,
  `role`          ENUM('admin','staff') NOT NULL DEFAULT 'staff',
  `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_login_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per business / clinic.
CREATE TABLE `locations` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`           VARCHAR(150) NOT NULL,
  `timezone`       VARCHAR(64)  NOT NULL DEFAULT 'America/Denver',
  `webhook_secret` VARCHAR(64)  NOT NULL,
  `is_active`      TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_locations_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- GHL and Open Dental settings per location. config_json is AES-256-GCM encrypted.
CREATE TABLE `location_integrations` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `location_id`    INT UNSIGNED NOT NULL,
  `provider`       ENUM('ghl','open_dental') NOT NULL,
  `config_json`    TEXT NOT NULL,
  `status`         ENUM('not_tested','connected','failed') NOT NULL DEFAULT 'not_tested',
  `status_message` VARCHAR(500) NULL DEFAULT NULL,
  `last_tested_at` DATETIME NULL DEFAULT NULL,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_integration` (`location_id`, `provider`),
  CONSTRAINT `fk_integration_location` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Staff <-> locations (many-to-many).
CREATE TABLE `user_locations` (
  `user_id`     INT UNSIGNED NOT NULL,
  `location_id` INT UNSIGNED NOT NULL,
  `assigned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`, `location_id`),
  KEY `idx_ul_location` (`location_id`),
  CONSTRAINT `fk_ul_user`     FOREIGN KEY (`user_id`)     REFERENCES `users` (`id`)     ON DELETE CASCADE,
  CONSTRAINT `fk_ul_location` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One-time email login codes. Only a hash of the code is stored.
CREATE TABLE `login_codes` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `code_hash`  CHAR(64) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `attempts`   TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `used_at`    DATETIME NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_codes_user` (`user_id`, `created_at`),
  CONSTRAINT `fk_codes_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Login sessions (7 days). Only a hash of the cookie token is stored.
CREATE TABLE `sessions` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      INT UNSIGNED NOT NULL,
  `token_hash`   CHAR(64) NOT NULL,
  `expires_at`   DATETIME NOT NULL,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sessions_token` (`token_hash`),
  KEY `idx_sessions_user` (`user_id`),
  CONSTRAINT `fk_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
