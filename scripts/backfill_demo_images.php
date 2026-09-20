#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

$images = [
    'Blazer lino 100% ZW Collection' => '/assets/garments/blazer-lino.png',
    'Jeans Marine' => '/assets/garments/jeans-marine.png',
    'Jeans Straight Fit' => '/assets/garments/jeans-straight.png',
    'Pantalón traje con lino melange' => '/assets/garments/pantalon-lino.png',
    'Zapatillas running OY/ER 835 V2' => '/assets/garments/zapatillas-running.png',
    'Blazer entallada hombreras' => '/assets/garments/blazer-negra.png',
    'Camiseta gráfica STWD' => '/assets/garments/camiseta-grafica.png',
    'Zueco de serraje' => '/assets/garments/zueco-serraje.png',
];

$stmt = db()->prepare("UPDATE garments SET image_path=? WHERE name=? AND (image_path IS NULL OR image_path='')");
$updated = 0;
foreach ($images as $name => $path) {
    $stmt->execute([$path, $name]);
    $updated += $stmt->rowCount();
}
echo "Imágenes demo asociadas: {$updated}\n";
