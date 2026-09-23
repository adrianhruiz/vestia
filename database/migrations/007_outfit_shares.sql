-- Sharing a look publishes it at /o/{token}, readable by anyone holding the link and by
-- nobody else. The row is a frozen copy on purpose: the wardrobe moves on, analyses are
-- re-run and garments get deleted, and a link already circulating in a chat has to keep
-- showing what was shared. Only the one outfit travels here -- the analysis summary, the
-- palette, the gaps it found and the other looks never leave the account.
--
-- token is the whole authorisation: 32 hex characters of random, unguessable, and the only
-- thing standing between the link and the page.
--
-- revoked_at rather than a DELETE: "dejar de compartir" has to be able to answer 410 and
-- say so, and a deleted row can only answer 404, which reads as a broken link.
CREATE TABLE IF NOT EXISTS outfit_shares (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL, job_id BIGINT UNSIGNED NOT NULL, outfit_index TINYINT UNSIGNED NOT NULL,
 token CHAR(32) NOT NULL, snapshot_json LONGTEXT NOT NULL,
 views INT UNSIGNED NOT NULL DEFAULT 0, created_at VARCHAR(40) NOT NULL, revoked_at VARCHAR(40) NULL,
 UNIQUE KEY outfit_shares_token(token),
 -- One link per look: pressing Compartir twice returns the link that already exists instead
 -- of minting a second one nobody can keep track of.
 UNIQUE KEY outfit_shares_outfit(job_id, outfit_index),
 INDEX outfit_shares_user_idx(user_id),
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
 FOREIGN KEY(job_id) REFERENCES jobs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which shared look brought this account in, or NULL for everyone who arrived by the front
-- door. It is the only way to answer whether sharing actually brings anybody, and it is
-- written once at registration from the session, never from anything the client sends.
--
-- ADD COLUMN IF NOT EXISTS is MariaDB syntax, which is what every VPS environment runs.
-- MySQL 8 rejects it: drop the IF NOT EXISTS there and run the file once.
ALTER TABLE users ADD COLUMN IF NOT EXISTS signup_share_token CHAR(32) NULL AFTER style_profile;
