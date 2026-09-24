-- Grant catalog administration to existing designated accounts only.
CREATE TABLE IF NOT EXISTS catalog_administrators (
 user_id BIGINT UNSIGNED PRIMARY KEY,
 granted_at VARCHAR(40) NOT NULL,
 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO catalog_administrators(user_id,granted_at)
 SELECT id,DATE_FORMAT(NOW(),'%Y-%m-%dT%H:%i:%s') FROM users
 WHERE LOWER(email) IN ('raul@vestia.com','adri@vestia.com');
