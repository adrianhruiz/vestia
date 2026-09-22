-- Baseline schema. Every environment created before migrations existed already has these
-- tables, so this file is a no-op there and creates them from scratch anywhere else.
CREATE TABLE IF NOT EXISTS users (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(160) NOT NULL, email VARCHAR(255) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL, style_profile LONGTEXT NOT NULL, created_at VARCHAR(40) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS garments (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, name VARCHAR(255) NOT NULL,
 brand VARCHAR(80) NOT NULL, reference VARCHAR(100), category VARCHAR(100) NOT NULL, color VARCHAR(100), size VARCHAR(50),
 season VARCHAR(100), notes TEXT, image_path VARCHAR(500), favorite TINYINT(1) NOT NULL DEFAULT 0,
 wear_count INT UNSIGNED NOT NULL DEFAULT 0, created_at VARCHAR(40) NOT NULL, source_url VARCHAR(1000), price_note VARCHAR(100),
 INDEX garments_user_idx(user_id), FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS jobs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, type VARCHAR(80) NOT NULL,
 status VARCHAR(30) NOT NULL DEFAULT 'queued', progress TINYINT UNSIGNED NOT NULL DEFAULT 0,
 input_json LONGTEXT NOT NULL, result_json LONGTEXT, error TEXT,
 created_at VARCHAR(40) NOT NULL, started_at VARCHAR(40), finished_at VARCHAR(40),
 INDEX jobs_status_idx(status, created_at), FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
