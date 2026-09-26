-- The two outfits somebody puts together right after registering, and anything they group
-- afterwards. This is the person's own wardrobe knowledge -- what they actually wear with
-- what -- and it is the whole input of the style reading that used to ask for photos of
-- their face.
--
-- Not to be confused with the outfits the stylist proposes: those live inside
-- jobs.result_json and are addressed by (job_id, outfit_index), which is what outfit_shares
-- points at. Nothing here is generated; every row was assembled by hand.
--
-- outfit_garments has no surrogate key on purpose: an outfit either contains a garment or it
-- does not, so the pair is the identity, and the primary key is also what stops the same
-- garment being added to the same look twice. Both sides cascade -- deleting a garment must
-- not leave a look pointing at nothing, and deleting a look must not leave orphan rows.
CREATE TABLE IF NOT EXISTS outfits (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL,
 name VARCHAR(160) NOT NULL, created_at VARCHAR(40) NOT NULL,
 INDEX outfits_user_idx(user_id), FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS outfit_garments (
 outfit_id BIGINT UNSIGNED NOT NULL, garment_id BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY(outfit_id, garment_id), INDEX outfit_garments_garment_idx(garment_id),
 FOREIGN KEY(outfit_id) REFERENCES outfits(id) ON DELETE CASCADE,
 FOREIGN KEY(garment_id) REFERENCES garments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
