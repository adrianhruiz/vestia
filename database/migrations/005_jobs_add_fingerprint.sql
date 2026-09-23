-- Identifies an analysis by everything that shapes its answer: the wardrobe as the stylist
-- sees it, the style profile, whether shopping was asked for, the model and the prompt
-- version. Asking twice for the same thing should not be paid for twice, so /api/analyze
-- looks here before queueing and offers the previous result instead.
--
-- ADD COLUMN IF NOT EXISTS is MariaDB syntax, which is what every VPS environment runs.
-- MySQL 8 rejects it: drop the IF NOT EXISTS there and run the file once.
ALTER TABLE jobs ADD COLUMN IF NOT EXISTS fingerprint CHAR(64) NULL AFTER type;
CREATE INDEX IF NOT EXISTS jobs_user_fingerprint_idx ON jobs (user_id, fingerprint);
