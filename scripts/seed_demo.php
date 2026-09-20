#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

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
    $stmt = $pdo->prepare('INSERT INTO users(name,email,password_hash,style_profile,created_at) VALUES(?,?,?,?,?)');
    $stmt->execute(['Lucía Demo', $email, password_hash($password, PASSWORD_DEFAULT), $profile, date(DATE_ATOM)]);
    $userId = (int)$pdo->lastInsertId();
} else {
    $pdo->prepare('UPDATE users SET name=?,password_hash=?,style_profile=? WHERE id=?')
        ->execute(['Lucía Demo', password_hash($password, PASSWORD_DEFAULT), $profile, $userId]);
    $pdo->prepare('DELETE FROM garments WHERE user_id=?')->execute([$userId]);
}

$items = [
 ['Blazer lino 100% ZW Collection','Zara','2909/681/250','Abrigos','Blanco','M','Primavera / verano','100% lino; producto oficial verificado','https://www.zara.com/es/es/blazer-lino-100-zw-collection-p02909681.html','79,95 €','/assets/garments/blazer-lino.png'],
 ['Jeans Marine','Zara','7227/703/800','Partes de abajo','Negro','38','Todo el año','98% algodón; pierna ancha y bajo desflecado','https://www.zara.com/es/es/jeans-marine-p07227703.html','19,95 €','/assets/garments/jeans-marine.png'],
 ['Jeans Straight Fit','Zara','5585/435/405','Partes de abajo','Índigo','38','Todo el año','Denim rígido 100% algodón; corte recto','https://www.zara.com/es/es/jeans-straight-fit-p05585435.html','Precio promocional','/assets/garments/jeans-straight.png'],
 ['Pantalón traje con lino melange','Massimo Dutti','5001/601','Partes de abajo','Azul marino','38','Primavera / verano','Tejido fluido con lino y pliegues delanteros','https://www.massimodutti.com/es/pantalon-traje-con-lino-melange-l05001601','Consultar precio','/assets/garments/pantalon-lino.png'],
 ['Zapatillas running OY/ER 835 V2','Oysho','1140/880/030','Calzado','Verde','39','Todo el año','Running easy run con Freefoam, amortiguación y estabilidad','https://www.oysho.com/es/zapatillas-running-oyer-835-v2-l11140880?categoryId=1010826070&colorId=030&pelement=225681239','69,99 €','/assets/garments/zapatillas-running.png'],
 ['Blazer entallada hombreras','Zara','','Abrigos','Negro','M','Todo el año','Modelo actual de la colección oficial de blazers negros','https://www.zara.com/es/es/mujer-blazers-negro-l2173.html','35,95 €','/assets/garments/blazer-negra.png'],
 ['Camiseta gráfica STWD','Pull&Bear','','Partes de arriba','Blanco','M','Primavera / verano','Camiseta de la colección gráfica STWD actual','https://www.pullandbear.com/es/es/hombre/ropa/camisetas/estampadas-n6325','Consultar precio','/assets/garments/camiseta-grafica.png'],
 ['Zueco de serraje','Oysho','','Calzado','Marrón','39','Todo el año','Modelo actual de la colección de zapatos planos','https://www.oysho.com/es/zapatos-planos-mujer-n4960','39,99 €','/assets/garments/zueco-serraje.png'],
];
$insert = $pdo->prepare('INSERT INTO garments(user_id,name,brand,reference,category,color,size,season,notes,image_path,favorite,wear_count,created_at,source_url,price_note) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
foreach ($items as $i => $item) {
    [$name,$brand,$reference,$category,$color,$size,$season,$notes,$url,$price,$imagePath] = $item;
    $insert->execute([$userId,$name,$brand,$reference,$category,$color,$size,$season,$notes,$imagePath,$i < 3 ? 1 : 0,$i % 4,date(DATE_ATOM),$url,$price]);
}
$pdo->commit();
echo "Demo creada: {$email} / {$password} (" . count($items) . " prendas)\n";
