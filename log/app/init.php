<?php
declare(strict_types=1);
require __DIR__.'/Capture.php';
$db=VestiaLogCapture::db();
$db->exec('PRAGMA journal_mode=WAL');
$db->exec('CREATE TABLE IF NOT EXISTS calls (
 id TEXT PRIMARY KEY, environment TEXT NOT NULL, user_id INTEGER NOT NULL, job_id INTEGER NOT NULL,
 operation TEXT NOT NULL, started_at TEXT NOT NULL, finished_at TEXT, status TEXT NOT NULL, model TEXT,
 request_json TEXT, response_json TEXT, prompt_hash TEXT, http_status INTEGER, duration_ms INTEGER,
 error TEXT, request_id TEXT, input_tokens INTEGER, output_tokens INTEGER, total_tokens INTEGER
); CREATE INDEX IF NOT EXISTS calls_owner ON calls(environment,user_id,job_id); CREATE INDEX IF NOT EXISTS calls_time ON calls(started_at);
CREATE TABLE IF NOT EXISTS login_attempts (bucket TEXT NOT NULL, attempted_at INTEGER NOT NULL);
CREATE INDEX IF NOT EXISTS attempts_bucket ON login_attempts(bucket,attempted_at);');
echo "Registro inicializado\n";
