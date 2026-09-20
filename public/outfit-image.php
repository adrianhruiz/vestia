<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
$uid = user_id(); if (!$uid) { http_response_code(401); exit; }
$jobId = (int)($_GET['job'] ?? 0); $file = basename((string)($_GET['file'] ?? ''));
$stmt = db()->prepare("SELECT id FROM jobs WHERE id=? AND user_id=? AND status='completed'"); $stmt->execute([$jobId, $uid]);
$path = APP_ROOT . '/storage/outfits/' . $file;
if (!$stmt->fetch() || !preg_match('/^job-' . $jobId . '-outfit-\d+\.png$/', $file) || !is_file($path)) { http_response_code(404); exit; }
header('Content-Type: image/png'); header('Cache-Control: private, max-age=86400'); readfile($path);

