#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

$email = $argv[1] ?? 'demo@vestia.es';
$pdo = db();
$user = $pdo->prepare('SELECT id FROM users WHERE email=?');
$user->execute([$email]);
$userId = (int)($user->fetchColumn() ?: 0);
if (!$userId) throw new RuntimeException("No existe la cuenta {$email}");

$models = [
    ['Top cropped de canalé','Partes de arriba','/assets/garments/camiseta-grafica.png','Primavera / verano',17.99],
    ['Camisa fluida satinada','Partes de arriba','/assets/garments/camiseta-grafica.png','Todo el año',25.99],
    ['Camiseta oversize de algodón','Partes de arriba','/assets/garments/camiseta-grafica.png','Todo el año',12.99],
    ['Jersey fino de cuello barco','Partes de arriba','/assets/garments/camiseta-grafica.png','Otoño / invierno',22.99],
    ['Body asimétrico','Partes de arriba','/assets/garments/camiseta-grafica.png','Primavera / verano',19.99],
    ['Blusa romántica de volantes','Partes de arriba','/assets/garments/camiseta-grafica.png','Primavera / verano',27.99],
    ['Vaquero wide leg','Partes de abajo','/assets/garments/jeans-straight.png','Todo el año',29.99],
    ['Pantalón cargo parachute','Partes de abajo','/assets/garments/pantalon-lino.png','Todo el año',32.99],
    ['Pantalón sastre de pinzas','Partes de abajo','/assets/garments/pantalon-lino.png','Todo el año',35.99],
    ['Falda midi satinada','Partes de abajo','/assets/garments/pantalon-lino.png','Todo el año',25.99],
    ['Minifalda plisada','Partes de abajo','/assets/garments/jeans-marine.png','Primavera / verano',22.99],
    ['Short vaquero relaxed','Partes de abajo','/assets/garments/jeans-straight.png','Primavera / verano',23.99],
    ['Vestido midi cut-out','Vestidos','/assets/garments/blazer-lino.png','Primavera / verano',39.99],
    ['Vestido camisero con cinturón','Vestidos','/assets/garments/blazer-lino.png','Todo el año',42.99],
    ['Vestido mini drapeado','Vestidos','/assets/garments/blazer-negra.png','Primavera / verano',35.99],
    ['Vestido de punto acanalado','Vestidos','/assets/garments/blazer-negra.png','Otoño / invierno',39.99],
    ['Blazer oversize','Abrigos','/assets/garments/blazer-lino.png','Todo el año',49.99],
    ['Cazadora biker efecto piel','Abrigos','/assets/garments/blazer-negra.png','Otoño / invierno',55.99],
    ['Trench ligero','Abrigos','/assets/garments/blazer-lino.png','Primavera / verano',59.99],
    ['Sobrecamisa de pana','Abrigos','/assets/garments/blazer-negra.png','Otoño / invierno',39.99],
    ['Chaqueta acolchada corta','Abrigos','/assets/garments/blazer-negra.png','Otoño / invierno',45.99],
    ['Cárdigan suave con botones','Abrigos','/assets/garments/blazer-lino.png','Otoño / invierno',29.99],
    ['Zapatilla retro urbana','Calzado','/assets/garments/zapatillas-running.png','Todo el año',39.99],
    ['Botín cowboy bajo','Calzado','/assets/garments/zueco-serraje.png','Otoño / invierno',49.99],
    ['Sandalia de tiras','Calzado','/assets/garments/zueco-serraje.png','Primavera / verano',27.99],
    ['Bailarina con hebilla','Calzado','/assets/garments/zueco-serraje.png','Todo el año',29.99],
    ['Bolso shopper texturizado','Accesorios','/assets/garments/zueco-serraje.png','Todo el año',25.99],
    ['Bolso bandolera compacto','Accesorios','/assets/garments/zueco-serraje.png','Todo el año',22.99],
    ['Pañuelo estampado','Accesorios','/assets/garments/camiseta-grafica.png','Todo el año',12.99],
    ['Gorra de algodón lavado','Accesorios','/assets/garments/camiseta-grafica.png','Todo el año',15.99],
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
$insert = $pdo->prepare('INSERT INTO garments(user_id,name,brand,reference,category,color,size,season,notes,image_path,favorite,wear_count,created_at,source_url,price_note) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
$number = 0;
foreach ($models as $modelIndex => [$name,$category,$image,$season,$basePrice]) {
    foreach (['Bershka','Lefties'] as $variant => $brand) {
        $number++;
        $color = $colors[(($modelIndex * 2) + $variant) % count($colors)];
        $size = in_array($category, ['Calzado','Accesorios'], true) ? ($category === 'Calzado' ? '39' : 'Única') : $sizes[($modelIndex + $variant) % count($sizes)];
        $price = max(9.99, $basePrice + ($brand === 'Bershka' ? 2 : -2));
        $insert->execute([$userId,$name,$brand,sprintf('%04d/%03d/%03d',7000+$modelIndex,100+$variant,10+$number),$category,$color,$size,$season,'Prenda ficticia de demostración; colección femenina de estilo urbano y versátil.',$image,$number <= 8 ? 1 : 0,$number % 9,date(DATE_ATOM),$brandUrls[$brand],number_format($price,2,',','.') . ' €']);
    }
}
$pdo->commit();

foreach ($jobIds as $jobId) {
    foreach (glob(APP_ROOT . '/storage/outfits/job-' . $jobId . '-outfit-*.png') ?: [] as $file) unlink($file);
}
echo "Armario de {$email} sustituido por {$number} prendas femeninas ficticias.\n";
