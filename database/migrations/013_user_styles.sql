-- The styles somebody names themselves, next to the presets that ship in app/Styles.php.
--
-- "Café", "Fiesta" and "Deporte" are code: they are the same for everybody, they come and go
-- with a deploy, and a row per account per preset would be a copy of a constant. What belongs
-- here is the other half -- "Biblioteca", "Terraceo", whatever this person actually does --
-- which is a list that grows, is ordered by what they used last, and has to be deletable one
-- chip at a time. That is a table and not another key in users.style_profile, which is four
-- capped fields describing who somebody is; an occasion they saved is not that.
--
-- canonical is the label folded to lowercase without accents, and it carries the uniqueness:
-- "Biblioteca" and "biblioteca" are one style, not two chips that look the same. It is stored
-- rather than computed in the query because utf8mb4_unicode_ci would also fold "cafe" into
-- "café" for the index, and the fold we want is the one style_canonical() does in PHP, so the
-- rule that decides what is a duplicate is the same one the error message is written against.
--
-- last_used_at is what orders the chips. Somebody who picks "Biblioteca" three weeks running
-- should not find it last in the row the fourth time. NULL means saved but never used yet,
-- which sorts after everything used, so created_at breaks that tie.
--
-- Nothing points at these rows. An analysis stores the label as text in jobs.input_json, so
-- deleting a style does not rewrite the history of what was asked for -- a look created for
-- "Biblioteca" still says so after the chip is gone.
CREATE TABLE IF NOT EXISTS user_styles (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL,
 label VARCHAR(40) NOT NULL, canonical VARCHAR(40) NOT NULL,
 created_at VARCHAR(40) NOT NULL, last_used_at VARCHAR(40) NULL,
 UNIQUE KEY user_styles_unique(user_id, canonical),
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
