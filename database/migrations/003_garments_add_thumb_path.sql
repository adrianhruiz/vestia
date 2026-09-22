-- The wardrobe grid painted the full-size photo in a 210x260 card: 14 MB of PNG for the
-- eight demo garments alone. Uploads now produce a second, grid-sized WebP derivative and
-- this column points at it. Rows created before this migration keep thumb_path NULL and the
-- frontend falls back to image_path, so nothing breaks until scripts/backfill_thumbnails.php
-- has run.
--
-- ADD COLUMN IF NOT EXISTS is MariaDB syntax, which is what every VPS environment runs.
-- MySQL 8 rejects it: drop the IF NOT EXISTS there and run the file once.
ALTER TABLE garments ADD COLUMN IF NOT EXISTS thumb_path VARCHAR(500) NULL AFTER image_path;
