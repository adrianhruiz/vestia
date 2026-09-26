<?php
declare(strict_types=1);
require_once APP_ROOT . '/app/Wardrobe.php';
require_once APP_ROOT . '/app/ProductLink.php';

/**
 * A product link is a string somebody pasted, so half of this file is about what must not get
 * through: a link to anywhere but Inditex, a scheme that is not https, credentials smuggled
 * into the host. The other half is the derivations, which are worth pinning because they are
 * what makes pasting a link better than typing nine boxes -- and because the URL shapes they
 * read come from the brands and not from us, so a change there has to fail loudly here.
 *
 * There is nothing here about scanning a tag. The hangtag carries an EAN barcode, the printed
 * reference and the size as text, and how we will read any of that is not decided; the file
 * under test says so at the top and assumes none of it.
 *
 * Nothing in here touches the network or the catalogue: product_from_catalog() needs the
 * harvester's SQLite, which only exists where the harvester runs.
 */

describe('product_brand() · de qué tienda es este enlace');

$brands = [
    'www.bershka.com' => 'Bershka',
    'bershka.com' => 'Bershka',
    'WWW.ZARA.COM' => 'Zara',
    'www.massimodutti.com' => 'Massimo Dutti',
    'www.pullandbear.com' => 'Pull&Bear',
    'www.lefties.com' => 'Lefties',
];
foreach ($brands as $host => $brand) {
    test("{$host} es {$brand}", fn() => same($brand, product_brand($host)));
}

$extraños = [
    'bershka.com.phishing.example' => 'un dominio que solo empieza igual',
    'notbershka.com' => 'un dominio que solo acaba igual',
    'shein.com' => 'otra marca',
    'localhost' => 'un host interno',
    '' => 'nada',
];
foreach ($extraños as $host => $why) {
    test('no reconoce ' . $why, fn() => same(null, product_brand((string) $host)));
}

test('las marcas que lee son las que el armario acepta', function () {
    // Si un enlace rellenase una marca que el <select> no tiene, el formulario llegaría
    // roto al guardar.
    foreach (PRODUCT_LINK_BRANDS as $brand) truthy(in_array($brand, GARMENT_BRANDS, true), "{$brand} está en el armario");
});

describe('product_reference() · la referencia dentro de la propia URL');

test('la saca del slug y del colorId', function () {
    // l0 + 3239 + 143, con colorId 430, es el 3239/143/430 impreso en la etiqueta.
    same('3239/143/430', product_reference('/es/camiseta-manga-corta-l03239143-c0p228299466.html', '430'));
});

test('un colorId con ceros delante se conserva', function () {
    same('5922/329/087', product_reference('/es/pantalon-culotte-l05922329-c0p1.html', '087'));
});

test('sin colorId no hay referencia que dar', function () {
    same(null, product_reference('/es/camiseta-l03239143-c0p228299466.html', null));
});

test('una URL que no la lleva devuelve null en vez de inventarla', function () {
    // Lefties y Zara construyen la suya con ids internos: ahí responde el catálogo.
    same(null, product_reference('/es/mujer/ropa/camisetas/camiseta-basica-c1030267505p747884547.html', '250'));
});

test('un colorId que no son tres dígitos no se acepta', function () {
    foreach (['43', '4300', 'abc', ''] as $malo) same(null, product_reference('/es/x-l03239143-c0p1.html', $malo));
});

describe('product_category() · en qué cajón va lo que trae el enlace');

$categorias = [
    'Camiseta manga corta oversize' => GARMENT_TOP,
    'Pantalón wide leg' => GARMENT_BOTTOM,
    'Vestido midi fruncido' => GARMENT_DRESS,
    'Botín campero' => GARMENT_SHOES,
    'Bota alta tacón' => GARMENT_SHOES,
    'Cazadora encerada cuello pana' => 'Abrigos',
    'Bufanda de cuadros' => 'Accesorios',
];
foreach ($categorias as $name => $category) {
    test("«{$name}» es {$category}", fn() => same($category, product_category($name)));
}

test('un vestido camisero es un vestido, no una camisa', function () {
    // El orden de la tabla es lo que lo decide, así que merece su caso.
    same(GARMENT_DRESS, product_category('Vestido camisero midi'));
});

test('los acentos no cambian la respuesta', function () {
    same(GARMENT_BOTTOM, product_category('PANTALÓN CULOTTE BÁSICO'));
});

test('lo que no reconoce lo deja sin decidir', function () {
    same(null, product_category('Artículo 4321'));
});

test('solo responde con categorías que existen', function () {
    foreach (['Camiseta', 'Pantalón', 'Vestido', 'Bota', 'Abrigo', 'Bolso'] as $name) {
        truthy(in_array(product_category($name), GARMENT_CATEGORIES, true), "{$name} cae en una categoría real");
    }
});

describe('product_from_url() · una URL que ha pegado cualquiera');

test('una ficha de Bershka se lee entera', function () {
    $link = product_from_url('https://www.bershka.com/es/camiseta-manga-corta-l03239143-c0p228299466.html?colorId=430');
    same('Bershka', $link['brand']);
    same('3239/143/430', $link['reference']);
    same('430', $link['color_id']);
});

test('la talla nunca sale del enlace', function () {
    // La etiqueta la imprime como texto y ninguna URL la lleva: la caja se queda vacía y el
    // formulario ofrece las tallas reales del producto. Inventar una sería peor que no dar
    // ninguna, porque analysis_garments() se la manda al estilista como un hecho.
    $link = product_from_url('https://www.bershka.com/es/camiseta-l03239143-c0p1.html?colorId=430&sizeId=03');
    falsy(isset($link['size']));
});

test('lo que no lleva la URL no aparece como clave vacía', function () {
    $link = product_from_url('https://www.lefties.com/es/mujer/ropa/camisetas/camiseta-basica-c1030267505p747884547.html');
    same(['brand', 'source_url'], array_keys($link), 'ni referencia ni color inventados');
});

$rechazos = [
    'http://www.bershka.com/es/x.html' => 'http sin cifrar',
    'https://otra-tienda.example/producto' => 'otra tienda',
    'https://usuario:clave@www.zara.com/es/x.html' => 'credenciales metidas en el enlace',
    'javascript:alert(1)' => 'un esquema que no es una web',
    '/es/camiseta-l03239143-c0p1.html' => 'una ruta sin host',
    '' => 'una cadena vacía',
    'no soy una url' => 'texto cualquiera',
];
foreach ($rechazos as $url => $why) {
    test('rechaza ' . $why, fn() => same(null, product_from_url((string) $url)));
}

test('rechaza una URL más larga que la columna que la guardaría', function () {
    same(null, product_from_url('https://www.bershka.com/es/' . str_repeat('a', PRODUCT_LINK_MAX_LENGTH) . '.html'));
});

test('el enlace se devuelve tal cual, para que «ver producto real» funcione', function () {
    $url = 'https://www.bershka.com/es/camiseta-l03239143-c0p1.html?colorId=430&parentId=99';
    same($url, product_from_url(' ' . $url . ' ')['source_url'], 'recortado por los extremos y nada más');
});

describe('product_price()');

test('escribe el precio como ya lo escribe el armario', function () {
    same('15,99 €', product_price(15.99, 'EUR'));
    same('1.299,00 €', product_price(1299.0, 'EUR'));
});

test('sin precio no escribe nada', function () {
    same('', product_price(0.0, 'EUR'));
    same('', product_price(-1.0, 'EUR'));
});

test('otra moneda se dice con su nombre', function () {
    same('15,99 GBP', product_price(15.99, 'GBP'));
});
