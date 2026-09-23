#!/usr/bin/env php
<?php
declare(strict_types=1);

// Read credentials from stdin, never from command arguments or a committed file.
require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/app/Images.php';

if (PHP_SAPI !== 'cli') exit(1);
$credentials = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
foreach (['adri', 'raul'] as $name) {
    if (!is_string($credentials[$name] ?? null) || strlen($credentials[$name]) < 16) {
        throw new RuntimeException('Provide passwords of at least 16 characters for adri and raul via stdin JSON.');
    }
}
$catalog = json_decode((string)file_get_contents(APP_ROOT . '/storage/official_catalog.json'), true, 512, JSON_THROW_ON_ERROR);
if (count($catalog) !== 60) throw new RuntimeException('Expected 60 catalog garments.');
foreach ($catalog as &$item) {
    $item['image_path'] = preg_replace('/\.jpe?g$/i', '.webp', $item['image_path']);
    $item['thumb_path'] = thumb_path_for($item['image_path']);
    foreach ([$item['image_path'], $item['thumb_path']] as $image) {
        if (!$image || !is_file(APP_ROOT . '/public' . $image) || !getimagesize(APP_ROOT . '/public' . $image)) {
            throw new RuntimeException('Missing or invalid catalog image: ' . $item['image_path']);
        }
    }
}
unset($item);

$pdo = db();
$pdo->beginTransaction();
try {
    foreach (['adri', 'raul'] as $name) {
        $email = $name . '@vestia.com';
        $lookup = $pdo->prepare('SELECT id FROM users WHERE email=?');
        $lookup->execute([$email]);
        $userId = (int)$lookup->fetchColumn();
        if ($userId) {
            // A repeat run must never reset a password or replace an existing wardrobe, but
            // an account created before is_test existed still has to be flagged as a test one.
            $pdo->prepare('UPDATE users SET is_test=1 WHERE id=?')->execute([$userId]);
            echo "Skipped existing account (marked as test): {$email}\n";
            continue;
        }
        $profile = json_encode([
            'style' => $name === 'adri' ? 'Casual urbano, prendas cómodas y deportivos' : 'Minimalista, casual elegante y tonos neutros',
            'colors' => $name === 'adri' ? 'Azul, negro, blanco y verde' : 'Beige, blanco, azul marino y marrón',
            'avoid' => 'Combinaciones demasiado recargadas',
            'sizes' => 'M, pantalón 38, calzado 39; catálogo de prueba con tallas variadas',
            'budget' => 'Hasta 150 € por outfit',
            'occasions' => 'Trabajo, fin de semana, cenas y deporte',
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $pdo->prepare('INSERT INTO users(name,email,password_hash,style_profile,is_test,created_at) VALUES(?,?,?,?,1,?)')
            ->execute([$name, $email, password_hash($credentials[$name], PASSWORD_DEFAULT), $profile, date(DATE_ATOM)]);
        $userId = (int)$pdo->lastInsertId();
        $insert = $pdo->prepare('INSERT INTO garments(user_id,name,brand,reference,category,color,size,season,notes,image_path,thumb_path,favorite,wear_count,created_at,source_url,price_note) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach ($catalog as $index => $item) {
            $insert->execute([$userId,$item['name'],$item['brand'],$item['reference'],$item['category'],$item['color'],$item['size'],$item['season'],$item['notes'],$item['image_path'],$item['thumb_path'],($index + ($name === 'raul' ? 5 : 0)) % 8 === 0 ? 1 : 0,$index % 9,date(DATE_ATOM),$item['source_url'],$item['price_note']]);
        }
        echo "Created {$email}: 60 garments with catalog photos.\n";
    }
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $error;
}
