#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
require APP_ROOT . '/app/Images.php';

/**
 * Fills thumb_path for garments created before migration 003, so existing wardrobes stop
 * painting full-size photos into a 210px card. Seed assets already ship their -thumb.webp
 * next to the original; uploads are re-derived from whatever is on disk and the superseded
 * file is removed. Safe to run more than once: rows that already have a thumbnail are
 * skipped, and an unreadable image leaves its row untouched.
 */
$pdo = db();
$rows = $pdo->query("SELECT id, image_path FROM garments WHERE image_path IS NOT NULL AND image_path <> '' AND (thumb_path IS NULL OR thumb_path = '')")->fetchAll();
$update = $pdo->prepare('UPDATE garments SET image_path=?, thumb_path=? WHERE id=?');
$linked = $generated = $skipped = 0;

foreach ($rows as $row) {
    $path = (string) $row['image_path'];

    if (!str_starts_with($path, '/uploads/')) {
        $thumb = thumb_path_for($path);
        if ($thumb === null) { $skipped++; continue; }
        $update->execute([$path, $thumb, $row['id']]);
        $linked++;
        continue;
    }

    $source = APP_ROOT . '/public' . $path;
    if (!is_file($source)) { $skipped++; continue; }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($source);
    try {
        [$image, $thumb] = store_garment_image($source, (string) $mime, APP_ROOT . '/public/uploads', pathinfo($path, PATHINFO_FILENAME));
    } catch (Throwable $e) {
        fwrite(STDERR, "  {$path}: {$e->getMessage()}\n");
        $skipped++;
        continue;
    }
    $update->execute(['/uploads/' . $image, '/uploads/' . $thumb, $row['id']]);
    // The derivative may have overwritten the source when it was already a .webp.
    if ('/uploads/' . $image !== $path) @unlink($source);
    $generated++;
}

echo "Miniaturas asociadas: {$linked} · generadas: {$generated} · sin imagen utilizable: {$skipped}\n";
