<?php
declare(strict_types=1);
require_once APP_ROOT . '/app/Share.php';

/**
 * The shared page is read by strangers, so what the stylist wrote for the app has to come out
 * of it as plain, honest text: no raw links mid-sentence, no budget passed off as a price, no
 * catalogue name shouting in capitals.
 */

describe('share_plain_text()');

test('una cita entre paréntesis desaparece entera', function () {
    same('Camiseta fluida en blanco.', share_plain_text('Camiseta fluida en blanco. ([massimodutti.com](https://www.massimodutti.com/es/camiseta-l06220757?pelement=62327615))'));
});

test('un enlace dentro de una frase conserva sus palabras', function () {
    same('Mira el pantalón de Bershka hoy', share_plain_text('Mira el pantalón de [Bershka](https://www.bershka.com/es/x.html) hoy'));
});

test('el texto sin enlaces no cambia', function () {
    same('Azul, negro y la cazadora (acolchada).', share_plain_text('Azul, negro y la cazadora (acolchada).'));
});

describe('share_price()');

test('saca el precio de la nota y salta el presupuesto', function () {
    same("29,99\u{00A0}€", share_price('Verificado en web oficial de Bershka: 29,99 €, color negro. Queda dentro del presupuesto de 100 €.'));
    same("45,95\u{00A0}€", share_price('Dentro del presupuesto de 100 €. Precio: 45,95€'));
});

test('una nota sin precio no inventa uno', function () {
    same('', share_price('Página de búsqueda oficial: no se pudo verificar el precio.'));
    same('', share_price('Queda dentro del presupuesto de 100 €.'));
});

describe('share_display_name()');

test('un nombre en mayúsculas pasa a frase', function () {
    same('Deportivo tecnico', share_display_name('DEPORTIVO TECNICO'));
});

test('las palabras en mayúsculas dentro de un nombre bajan, las siglas cortas no', function () {
    same('Cazadora acolchada Thermal Resistance & Water Repellent', share_display_name('Cazadora acolchada THERMAL RESISTANCE & WATER REPELLENT'));
    same('Camiseta 100% algodón TRF', share_display_name('Camiseta 100% algodón TRF'));
});

describe('share_flatlay()');

test('el abrigo abre el collage, el calzado lo cierra y lo que no tiene foto no entra', function () {
    $piece = static fn(string $name, string $category, string $thumb = '/assets/x.webp'): array => ['name' => $name, 'category' => $category, 'thumb_path' => $thumb];
    $flatlay = share_flatlay([$piece('camiseta', 'Partes de arriba'), $piece('zapatillas', 'Calzado'), $piece('cazadora', 'Abrigos'),
        $piece('gorra', 'Accesorios'), $piece('sin foto', 'Accesorios', '')]);
    same(['cazadora', 'camiseta', 'gorra', 'zapatillas'], array_column($flatlay, 'name'));
});

test('sin fotos no hay collage', function () {
    same([], share_flatlay([['name' => 'x', 'category' => 'Calzado', 'thumb_path' => '']]));
});

describe('share_missing_rows()');

test('cada cosa que falta va con su enlace cuando las listas cuadran', function () {
    $rows = share_missing_rows(['Pantalón cargo negro'], [['brand' => 'Bershka', 'name' => 'Cargo parachute', 'url' => 'https://www.bershka.com/x', 'price_note' => '']]);
    same('Pantalón cargo negro', $rows[0]['need']);
    same('Cargo parachute', $rows[0]['shop']['name']);
});

test('si no cuadran, cada enlace nombra su producto y los enlaces que no son https no salen', function () {
    $rows = share_missing_rows(['Camiseta blanca'], [
        ['brand' => 'Massimo Dutti', 'name' => 'Camiseta fluida', 'url' => 'https://www.massimodutti.com/a'],
        ['brand' => 'Zara', 'name' => 'Camisa popelín', 'url' => 'https://www.zara.com/b'],
        ['brand' => 'Zara', 'name' => 'Trampa', 'url' => 'javascript:alert(1)'],
    ]);
    same(['Camiseta blanca', 'Camiseta fluida', 'Camisa popelín'], array_column($rows, 'need'));
    same(null, $rows[0]['shop']);
});

describe('share_photo_backdrop()');

test('el fondo de una foto de producto se lee de sus esquinas', function () {
    $path = '/assets/test-backdrop-' . bin2hex(random_bytes(4)) . '.png';
    $image = imagecreatetruecolor(40, 50);
    imagefill($image, 0, 0, imagecolorallocate($image, 0xf4, 0xf3, 0xf6));
    imagefilledrectangle($image, 10, 10, 30, 40, imagecolorallocate($image, 20, 20, 60));
    imagepng($image, APP_ROOT . '/public' . $path);
    imagedestroy($image);
    try {
        same('#f4f3f6', share_photo_backdrop($path));
    } finally {
        @unlink(APP_ROOT . '/public' . $path);
    }
});

test('sin foto legible no hay color que copiar', function () {
    same('', share_photo_backdrop('/assets/no-existe.webp'));
    same('', share_photo_backdrop('https://example.com/zapato.jpg'));
});

describe('share_owner_name()');

test('el nombre de quien comparte empieza en mayúscula', function () {
    same('Adri', share_owner_name('adri'));
    same('Álvaro de la Torre', share_owner_name(' álvaro de la Torre'));
    same('', share_owner_name(''));
});
