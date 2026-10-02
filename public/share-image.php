<?php
declare(strict_types=1);
// No session: this is the one image endpoint meant to be fetched by strangers and by the
// preview crawlers of WhatsApp, Telegram, Slack and X, none of which carry a cookie. The
// token is the authorisation, and nothing else in the app is served without a session.
define('VESTIA_NO_SESSION', true);
require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/app/Share.php';

$token = (string) ($_GET['token'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $token)) { http_response_code(404); exit; }
$stmt = db()->prepare('SELECT revoked_at FROM outfit_shares WHERE token=?');
$stmt->execute([$token]);
$share = $stmt->fetch();
if (!$share) { http_response_code(404); exit; }
// Retiring a link has to retire its preview too, or the picture keeps answering to every
// crawler that cached the URL.
if ($share['revoked_at'] !== null) { http_response_code(410); exit; }

// 'page' only ever answers for a link created back when Vestia generated a picture of each
// look; those files stay on disk and their share pages still paint them.
$variant = ($_GET['v'] ?? 'page') === 'og' ? 'og' : 'page';
$file = share_files($token)[$variant];
if (!is_file($file)) { http_response_code(404); exit; }
header('Content-Type: ' . ($variant === 'og' ? 'image/jpeg' : 'image/webp'));
// The token is unguessable and these two files never change once written, so any copy in
// any cache is the right one for as long as the share lives.
header('Cache-Control: public, max-age=31536000, immutable');
readfile($file);
