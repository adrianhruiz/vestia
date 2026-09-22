#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/app/Images.php';

$email = $argv[1] ?? 'demo@vestia.es';
$pdo = db();
$user = $pdo->prepare('SELECT id FROM users WHERE email=?');
$user->execute([$email]);
$userId = (int)($user->fetchColumn() ?: 0);
if (!$userId) throw new RuntimeException("No existe la cuenta {$email}");

$models = [
    ['Camiseta baby tee de canalé','Partes de arriba','/assets/garments/camiseta-grafica.webp','Primavera / verano',15.99],
    ['Top halter de punto','Partes de arriba','/assets/garments/camiseta-grafica.webp','Primavera / verano',17.99],
    ['Camisa oversize de rayas','Partes de arriba','/assets/garments/camiseta-grafica.webp','Todo el año',27.99],
    ['Blusa satinada cruzada','Partes de arriba','/assets/garments/camiseta-grafica.webp','Todo el año',25.99],
    ['Sudadera cropped con capucha','Partes de arriba','/assets/garments/camiseta-grafica.webp','Otoño / invierno',29.99],
    ['Jersey de cuello barco','Partes de arriba','/assets/garments/camiseta-grafica.webp','Otoño / invierno',24.99],
    ['Body asimétrico drapeado','Partes de arriba','/assets/garments/camiseta-grafica.webp','Primavera / verano',19.99],
    ['Chaleco sastre entallado','Partes de arriba','/assets/garments/blazer-lino.webp','Todo el año',29.99],
    ['Polo de punto fino','Partes de arriba','/assets/garments/camiseta-grafica.webp','Todo el año',22.99],
    ['Camiseta gráfica oversize','Partes de arriba','/assets/garments/camiseta-grafica.webp','Todo el año',18.99],
    ['Vaquero wide leg tiro alto','Partes de abajo','/assets/garments/jeans-straight.webp','Todo el año',35.99],
    ['Jeans mom fit cropped','Partes de abajo','/assets/garments/jeans-marine.webp','Todo el año',32.99],
    ['Pantalón cargo parachute','Partes de abajo','/assets/garments/pantalon-lino.webp','Todo el año',32.99],
    ['Pantalón sastre de pinzas','Partes de abajo','/assets/garments/pantalon-lino.webp','Todo el año',37.99],
    ['Pantalón flare elástico','Partes de abajo','/assets/garments/jeans-marine.webp','Todo el año',27.99],
    ['Falda midi satinada','Partes de abajo','/assets/garments/pantalon-lino.webp','Todo el año',25.99],
    ['Minifalda cargo con bolsillos','Partes de abajo','/assets/garments/jeans-marine.webp','Primavera / verano',24.99],
    ['Falda vaquera larga','Partes de abajo','/assets/garments/jeans-straight.webp','Todo el año',29.99],
    ['Short bermuda de lino','Partes de abajo','/assets/garments/pantalon-lino.webp','Primavera / verano',23.99],
    ['Legging acampanado soft touch','Partes de abajo','/assets/garments/jeans-marine.webp','Todo el año',19.99],
    ['Vestido midi cut-out','Vestidos','/assets/garments/blazer-lino.webp','Primavera / verano',39.99],
    ['Vestido camisero con cinturón','Vestidos','/assets/garments/blazer-lino.webp','Todo el año',42.99],
    ['Vestido mini drapeado','Vestidos','/assets/garments/blazer-negra.webp','Primavera / verano',35.99],
    ['Vestido de punto acanalado','Vestidos','/assets/garments/blazer-negra.webp','Otoño / invierno',39.99],
    ['Vestido lencero satinado','Vestidos','/assets/garments/blazer-lino.webp','Primavera / verano',34.99],
    ['Vestido denim con cremallera','Vestidos','/assets/garments/jeans-straight.webp','Todo el año',45.99],
    ['Vestido maxi estampado floral','Vestidos','/assets/garments/blazer-lino.webp','Primavera / verano',44.99],
    ['Vestido blazer entallado','Vestidos','/assets/garments/blazer-negra.webp','Todo el año',49.99],
    ['Vestido skater de manga larga','Vestidos','/assets/garments/blazer-negra.webp','Otoño / invierno',37.99],
    ['Vestido bandeau con abertura','Vestidos','/assets/garments/blazer-lino.webp','Primavera / verano',32.99],
    ['Blazer oversize estructurada','Abrigos','/assets/garments/blazer-lino.webp','Todo el año',49.99],
    ['Cazadora biker efecto piel','Abrigos','/assets/garments/blazer-negra.webp','Otoño / invierno',55.99],
    ['Trench fluido con cinturón','Abrigos','/assets/garments/blazer-lino.webp','Primavera / verano',59.99],
    ['Sobrecamisa de pana','Abrigos','/assets/garments/blazer-negra.webp','Otoño / invierno',39.99],
    ['Chaqueta acolchada cropped','Abrigos','/assets/garments/blazer-negra.webp','Otoño / invierno',45.99],
    ['Cárdigan suave con botones','Abrigos','/assets/garments/blazer-lino.webp','Otoño / invierno',29.99],
    ['Gabardina corta repelente al agua','Abrigos','/assets/garments/blazer-lino.webp','Primavera / verano',47.99],
    ['Bomber satinada volumen','Abrigos','/assets/garments/blazer-negra.webp','Todo el año',42.99],
    ['Chaqueta denim boxy','Abrigos','/assets/garments/jeans-straight.webp','Todo el año',39.99],
    ['Abrigo largo de paño','Abrigos','/assets/garments/blazer-negra.webp','Otoño / invierno',69.99],
    ['Zapatilla retro urbana','Calzado','/assets/garments/zapatillas-running.webp','Todo el año',39.99],
    ['Sneaker chunky multicolor','Calzado','/assets/garments/zapatillas-running.webp','Todo el año',45.99],
    ['Botín cowboy bordado','Calzado','/assets/garments/zueco-serraje.webp','Otoño / invierno',49.99],
    ['Botín chelsea con plataforma','Calzado','/assets/garments/zueco-serraje.webp','Otoño / invierno',52.99],
    ['Sandalia de tiras metalizadas','Calzado','/assets/garments/zueco-serraje.webp','Primavera / verano',27.99],
    ['Bailarina con hebilla','Calzado','/assets/garments/zueco-serraje.webp','Todo el año',29.99],
    ['Mocasín track con antifaz','Calzado','/assets/garments/zueco-serraje.webp','Todo el año',39.99],
    ['Zueco de serraje con hebilla','Calzado','/assets/garments/zueco-serraje.webp','Todo el año',34.99],
    ['Alpargata de plataforma','Calzado','/assets/garments/zueco-serraje.webp','Primavera / verano',25.99],
    ['Salón destalonado kitten heel','Calzado','/assets/garments/zueco-serraje.webp','Todo el año',37.99],
    ['Bolso shopper texturizado','Accesorios','/assets/garments/zueco-serraje.webp','Todo el año',25.99],
    ['Bolso bandolera compacto','Accesorios','/assets/garments/zueco-serraje.webp','Todo el año',22.99],
    ['Bolso baguette metalizado','Accesorios','/assets/garments/zueco-serraje.webp','Todo el año',27.99],
    ['Mochila mini efecto piel','Accesorios','/assets/garments/zueco-serraje.webp','Todo el año',29.99],
    ['Pañuelo floral satinado','Accesorios','/assets/garments/camiseta-grafica.webp','Todo el año',12.99],
    ['Gorra de algodón lavado','Accesorios','/assets/garments/camiseta-grafica.webp','Todo el año',15.99],
    ['Cinturón ancho con hebilla','Accesorios','/assets/garments/zueco-serraje.webp','Todo el año',17.99],
    ['Gafas de sol cat eye','Accesorios','/assets/garments/blazer-negra.webp','Todo el año',19.99],
    ['Collar de eslabones combinados','Accesorios','/assets/garments/camiseta-grafica.webp','Todo el año',14.99],
    ['Sombrero bucket reversible','Accesorios','/assets/garments/camiseta-grafica.webp','Primavera / verano',18.99],
];
$colors = ['Coral','Verde lima','Azul cobalto','Rosa chicle','Lavanda','Amarillo mantequilla','Rojo cereza','Verde botella','Turquesa','Naranja teja','Beige arena','Marrón chocolate','Azul cielo','Granate','Crudo','Negro','Denim azul','Gris perla'];
$sizes = ['XS','S','M','L'];
$brandUrls = ['Bershka'=>'https://www.bershka.com/es/','Lefties'=>'https://www.lefties.com/es/'];

$oldJobs = $pdo->prepare('SELECT id FROM jobs WHERE user_id=?');
$oldJobs->execute([$userId]);
$jobIds = array_map('intval', array_column($oldJobs->fetchAll(), 'id'));

$pdo->beginTransaction();
$pdo->prepare('DELETE FROM jobs WHERE user_id=?')->execute([$userId]);
$pdo->prepare('DELETE FROM garments WHERE user_id=?')->execute([$userId]);
$insert = $pdo->prepare('INSERT INTO garments(user_id,name,brand,reference,category,color,size,season,notes,image_path,thumb_path,favorite,wear_count,created_at,source_url,price_note) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
$number = 0;
foreach ($models as $modelIndex => [$name,$category,$image,$season,$basePrice]) {
    $number++;
    $brand = $modelIndex % 2 === 0 ? 'Bershka' : 'Lefties';
    $color = $colors[$modelIndex % count($colors)];
    $size = in_array($category, ['Calzado','Accesorios'], true) ? ($category === 'Calzado' ? (string)(37 + ($modelIndex % 4)) : 'Única') : $sizes[$modelIndex % count($sizes)];
    $price = max(9.99, $basePrice + ($brand === 'Bershka' ? 2 : -2));
    $insert->execute([$userId,$name,$brand,sprintf('%04d/%03d/%03d',7000+$modelIndex,100+($modelIndex%2),10+$number),$category,$color,$size,$season,'Prenda ficticia de demostración; colección femenina de estilo urbano y versátil.',$image,thumb_path_for($image),$number <= 8 ? 1 : 0,$number % 9,date(DATE_ATOM),$brandUrls[$brand],number_format($price,2,',','.') . ' €']);
}
$pdo->commit();

foreach ($jobIds as $jobId) {
    foreach (glob(APP_ROOT . '/storage/outfits/job-' . $jobId . '-outfit-*.png') ?: [] as $file) unlink($file);
}
echo "Armario de {$email} sustituido por {$number} prendas femeninas ficticias.\n";
