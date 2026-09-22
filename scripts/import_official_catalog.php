#!/usr/bin/env php
<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/app/Images.php';

$email = $argv[1] ?? 'demo@vestia.es';
$catalogFile = dirname(__DIR__) . '/storage/official_catalog.json';
$catalog = json_decode((string)file_get_contents($catalogFile), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($catalog) || count($catalog) !== 60) {
    throw new RuntimeException('El catálogo oficial debe contener exactamente 60 artículos.');
}

$pdo = db();
$user = $pdo->prepare('SELECT id FROM users WHERE email=?');
$user->execute([$email]);
$userId = (int)($user->fetchColumn() ?: 0);
if (!$userId) throw new RuntimeException("No existe la cuenta {$email}");

$oldJobs = $pdo->prepare('SELECT id FROM jobs WHERE user_id=?');
$oldJobs->execute([$userId]);
$jobIds = array_map('intval', array_column($oldJobs->fetchAll(), 'id'));

$pdo->beginTransaction();
try {
    $pdo->prepare('DELETE FROM jobs WHERE user_id=?')->execute([$userId]);
    $pdo->prepare('DELETE FROM garments WHERE user_id=?')->execute([$userId]);
    $insert = $pdo->prepare('INSERT INTO garments(user_id,name,brand,reference,category,color,size,season,notes,image_path,thumb_path,favorite,wear_count,created_at,source_url,price_note) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach ($catalog as $index => $item) {
        // The catalog JSON still names the source JPGs; the app serves the WebP pair.
        $image = preg_replace('/\.jpe?g$/i', '.webp', (string) $item['image_path']);
        $insert->execute([$userId,$item['name'],$item['brand'],$item['reference'],$item['category'],$item['color'],$item['size'],$item['season'],$item['notes'],$image,thumb_path_for($image),$index < 8 ? 1 : 0,$index % 9,date(DATE_ATOM),$item['source_url'],$item['price_note']]);
    }
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $error;
}

foreach ($jobIds as $jobId) {
    foreach (glob(APP_ROOT . '/storage/outfits/job-' . $jobId . '-outfit-*.png') ?: [] as $file) unlink($file);
}
echo "Armario de {$email} sustituido por 60 artículos del catálogo oficial.\n";
