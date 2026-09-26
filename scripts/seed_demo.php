#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/app/Images.php';

$email = 'demo@vestia.es';
$password = 'DemoVestia2026!';
$pdo = db();
$pdo->beginTransaction();

$stmt = $pdo->prepare('SELECT id FROM users WHERE email=?');
$stmt->execute([$email]);
$userId = (int)($stmt->fetchColumn() ?: 0);
$profile = json_encode([
    'style' => 'Minimalista mediterráneo, casual sofisticado y deportivo limpio',
    'colors' => 'Blanco, azul marino, negro, verde y tonos neutros',
    'avoid' => 'Logos grandes y combinaciones demasiado recargadas',
    'sizes' => 'M, pantalón 38, calzado 39',
    'budget' => 'Hasta 150 € para completar un outfit',
    'occasions' => 'Trabajo, fin de semana, cenas informales y running',
], JSON_UNESCAPED_UNICODE);

if (!$userId) {
    // onboarding_done_at is set for the reason migration 011 backfilled it: an account whose
    // profile is already written is not owed the welcome, and would only be nagged by a panel
    // asking it to assemble two outfits it has no reason to assemble.
    $stmt = $pdo->prepare('INSERT INTO users(name,email,password_hash,style_profile,onboarding_done_at,is_test,created_at) VALUES(?,?,?,?,?,1,?)');
    $stmt->execute(['Lucía Demo', $email, password_hash($password, PASSWORD_DEFAULT), $profile, date(DATE_ATOM), date(DATE_ATOM)]);
    $userId = (int)$pdo->lastInsertId();
} else {
    $pdo->prepare('UPDATE users SET name=?,password_hash=?,style_profile=?,onboarding_done_at=COALESCE(onboarding_done_at,?),is_test=1 WHERE id=?')
        ->execute(['Lucía Demo', password_hash($password, PASSWORD_DEFAULT), $profile, date(DATE_ATOM), $userId]);
    $pdo->prepare('DELETE FROM garments WHERE user_id=?')->execute([$userId]);
}

$items = [
 ['Blazer lino 100% ZW Collection','Zara','2909/681/250','Abrigos','Blanco','M','Primavera / verano','100% lino; producto oficial verificado','https://www.zara.com/es/es/blazer-lino-100-zw-collection-p02909681.html','79,95 €','/assets/garments/blazer-lino.webp'],
 ['Jeans Marine','Zara','7227/703/800','Partes de abajo','Negro','38','Todo el año','98% algodón; pierna ancha y bajo desflecado','https://www.zara.com/es/es/jeans-marine-p07227703.html','19,95 €','/assets/garments/jeans-marine.webp'],
 ['Jeans Straight Fit','Zara','5585/435/405','Partes de abajo','Índigo','38','Todo el año','Denim rígido 100% algodón; corte recto','https://www.zara.com/es/es/jeans-straight-fit-p05585435.html','Precio promocional','/assets/garments/jeans-straight.webp'],
 ['Pantalón traje con lino melange','Massimo Dutti','5001/601','Partes de abajo','Azul marino','38','Primavera / verano','Tejido fluido con lino y pliegues delanteros','https://www.massimodutti.com/es/pantalon-traje-con-lino-melange-l05001601','Consultar precio','/assets/garments/pantalon-lino.webp'],
 ['Zapatillas running OY/ER 835 V2','Oysho','1140/880/030','Calzado','Verde','39','Todo el año','Running easy run con Freefoam, amortiguación y estabilidad','https://www.oysho.com/es/zapatillas-running-oyer-835-v2-l11140880?categoryId=1010826070&colorId=030&pelement=225681239','69,99 €','/assets/garments/zapatillas-running.webp'],
 ['Blazer entallada hombreras','Zara','','Abrigos','Negro','M','Todo el año','Modelo actual de la colección oficial de blazers negros','https://www.zara.com/es/es/mujer-blazers-negro-l2173.html','35,95 €','/assets/garments/blazer-negra.webp'],
 ['Camiseta gráfica STWD','Pull&Bear','','Partes de arriba','Blanco','M','Primavera / verano','Camiseta de la colección gráfica STWD actual','https://www.pullandbear.com/es/es/hombre/ropa/camisetas/estampadas-n6325','Consultar precio','/assets/garments/camiseta-grafica.webp'],
 ['Zueco de serraje','Oysho','','Calzado','Marrón','39','Todo el año','Modelo actual de la colección de zapatos planos','https://www.oysho.com/es/zapatos-planos-mujer-n4960','39,99 €','/assets/garments/zueco-serraje.webp'],
];
$insert = $pdo->prepare('INSERT INTO garments(user_id,name,brand,reference,category,color,size,season,notes,image_path,thumb_path,favorite,wear_count,created_at,source_url,price_note) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
foreach ($items as $i => $item) {
    [$name,$brand,$reference,$category,$color,$size,$season,$notes,$url,$price,$imagePath] = $item;
    $insert->execute([$userId,$name,$brand,$reference,$category,$color,$size,$season,$notes,$imagePath,thumb_path_for($imagePath),$i < 3 ? 1 : 0,$i % 4,date(DATE_ATOM),$url,$price]);
}
$pdo->commit();
echo "Demo creada: {$email} / {$password} (" . count($items) . " prendas)\n";
