-- Registration failed under STRICT_TRANS_TABLES because the INSERT omitted style_profile
-- and the column had no default. The INSERT sends '{}' now; this is the safety net for any
-- other insert path. The parenthesised expression default is required by MySQL 8 for TEXT
-- columns and accepted by MariaDB, which stores it as a plain literal default.
ALTER TABLE users MODIFY style_profile LONGTEXT NOT NULL DEFAULT ('{}');
