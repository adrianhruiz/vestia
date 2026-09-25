-- Every account this installation has handed out, by where it came from.
--
-- Registration is free, instant and grants a session, and nothing anywhere counted how many
-- of them one origin had asked for. A loop of curl could open accounts until the disk filled;
-- with an analysis behind each one it could open them until the OpenAI key ran dry.
--
-- ip_hash, not the address: this only ever has to answer "is this the same origin as before",
-- which a keyed hash answers just as well without the table becoming a log of who registered
-- from where. app/SignupLimit.php keys it with APP_KEY, so the rows are useless on their own.
--
-- email_domain is the second axis. Blocking throwaway providers does nothing against someone
-- pointing a catch-all domain of their own at the form, where every address is real,
-- deliverable and different.
--
-- Rows are pruned after a week by the same code that writes them: the windows are an hour
-- and a day, so nothing older than that is ever read.
CREATE TABLE IF NOT EXISTS signup_attempts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 ip_hash CHAR(64) NOT NULL,
 email_domain VARCHAR(255) NOT NULL,
 created_at VARCHAR(40) NOT NULL,
 INDEX signup_attempts_ip_idx(ip_hash, id),
 INDEX signup_attempts_domain_idx(email_domain, id),
 INDEX signup_attempts_created_idx(created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
