#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/app/Images.php';

$images = [
    'Blazer lino 100% ZW Collection' => '/assets/garments/blazer-lino.webp',
    'Jeans Marine' => '/assets/garments/jeans-marine.webp',
    'Jeans Straight Fit' => '/assets/garments/jeans-straight.webp',
    'Pantalón traje con lino melange' => '/assets/garments/pantalon-lino.webp',
    'Zapatillas running OY/ER 835 V2' => '/assets/garments/zapatillas-running.webp',
    'Blazer entallada hombreras' => '/assets/garments/blazer-negra.webp',
    'Camiseta gráfica STWD' => '/assets/garments/camiseta-grafica.webp',
    'Zueco de serraje' => '/assets/garments/zueco-serraje.webp',
];

$stmt = db()->prepare("UPDATE garments SET image_path=?, thumb_path=? WHERE name=? AND (image_path IS NULL OR image_path='')");
$updated = 0;
foreach ($images as $name => $path) {
    $stmt->execute([$path, thumb_path_for($path), $name]);
    $updated += $stmt->rowCount();
}
echo "Imágenes demo asociadas: {$updated}\n";
