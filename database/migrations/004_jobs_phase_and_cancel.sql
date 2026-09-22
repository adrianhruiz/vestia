-- The analysis runs for one to three minutes behind a bar that jumped 10 -> 70 -> 95 under
-- a fixed "Preparando recomendaciones…". phase carries what the worker is actually doing,
-- and cancel_requested lets the user stop a run instead of paying for every outfit image of
-- an analysis they launched by mistake. Jobs stopped that way end as status='cancelled'.
--
-- ADD COLUMN IF NOT EXISTS is MariaDB syntax, which is what every VPS environment runs.
-- MySQL 8 rejects it: drop the IF NOT EXISTS there and run the file once.
ALTER TABLE jobs ADD COLUMN IF NOT EXISTS phase VARCHAR(120) NULL AFTER progress;
ALTER TABLE jobs ADD COLUMN IF NOT EXISTS cancel_requested TINYINT(1) NOT NULL DEFAULT 0 AFTER phase;
