<?php
declare(strict_types=1);
require __DIR__.'/Capture.php';
$db=VestiaLogCapture::db();
$db->prepare('DELETE FROM calls WHERE started_at < ?')->execute([gmdate('c',time()-30*86400)]);
$db->prepare('DELETE FROM login_attempts WHERE attempted_at < ?')->execute([time()-900]);
$db->exec('PRAGMA wal_checkpoint(TRUNCATE)');
