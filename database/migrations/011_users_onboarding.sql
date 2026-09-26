-- The welcome screen asks for photos once, right after registering. Without somewhere to
-- write down that it happened there is nothing to read: an empty style_profile is also what
-- somebody who typed nothing has, and a style reading may never have been asked for at all.
--
-- onboarding_done_at is set when the profile is saved from that screen and when the person
-- chooses to do it later, and it is the only thing deciding whether the app keeps offering
-- it. The screen itself stays reachable afterwards -- a style changes, and redoing the
-- reading has to be possible.
--
-- The backfill is for the accounts that already exist: somebody who has written their
-- profile by hand is not owed a welcome, and would only be nagged by one.
--
-- ADD COLUMN IF NOT EXISTS is MariaDB syntax, which is what every VPS environment runs.
-- MySQL 8 rejects it: drop the IF NOT EXISTS there and run the file once.
ALTER TABLE users ADD COLUMN IF NOT EXISTS onboarding_done_at VARCHAR(40) NULL AFTER style_profile;

UPDATE users SET onboarding_done_at = created_at
 WHERE onboarding_done_at IS NULL AND style_profile NOT IN ('', '{}');
