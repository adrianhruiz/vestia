<?php
declare(strict_types=1);

const LABEL_BRANDS = ['zara'=>'Zara', 'pullandbear'=>'Pull&Bear', 'massimodutti'=>'Massimo Dutti', 'bershka'=>'Bershka', 'stradivarius'=>'Stradivarius', 'oysho'=>'Oysho', 'lefties'=>'Lefties'];

/** Printed references only: an EAN or an internal SKU is not a garment reference. */
function label_reference(string $input): ?string {
    $input = trim($input);
    if (strlen($input) > 40 || !preg_match('/^\d{4}[\s\/.-]*\d{3}(?:[\s\/.-]*\d{3})?$/D', $input)) return null;
    $digits = preg_replace('/\D/', '', $input);
    return substr($digits, 0, 4).'/'.substr($digits, 4, 3).(strlen($digits) === 10 ? '/'.substr($digits, 7, 3) : '');
}

/** Do not repair ambiguous OCR digits silently or interpret a barcode as a reference. */
function label_parse_text(string $text): array {
    $text = mb_substr($text, 0, 16000);
    $brand = '';
    foreach (LABEL_BRANDS as $id=>$name) {
        $pattern = match ($id) { 'pullandbear'=>'pull\s*(?:&|and|y)?\s*bear', 'massimodutti'=>'massimo\s*dutti', default=>preg_quote($name, '/') };
        if (preg_match('/\b'.$pattern.'\b/iu', $text)) { $brand = $name; break; }
    }
    // Zara Home is outside the wardrobe's clothing brands.
    if (preg_match('/zara\s*home/iu', $text)) $brand = '';
    preg_match_all('/(?<![\d\pL])(\d{4}[ \/.\-]+\d{3}(?:[ \/.\-]+\d{3})?)(?![\d\pL])/u', $text, $matches);
    $refs = array_values(array_unique(array_filter(array_map('label_reference', $matches[1]))));
    if (!$refs && preg_match_all('/\b(?:ART(?:[ÍI]CULO)?|REF(?:ERENCIA)?)\s*[.:#]?\s*(\d{10}|\d{7})\b/iu', $text, $matches)) {
        $refs = array_values(array_unique(array_filter(array_map('label_reference', $matches[1]))));
    }
    $size = '';
    if (preg_match('/\b(?:TALLA|SIZE|EUR)\s*[:.]?\s*(XXXL|XXL|XXS|XL|XS|S|M|L|\d{2})(?![\pL\d.,])/iu', $text, $m)) $size = strtoupper($m[1]);
    elseif (preg_match('/^\s*(XXXL|XXL|XXS|XL|XS|S|M|L)\s*$/m', $text, $m)) $size = $m[1];
    return ['brand'=>$brand, 'reference'=>count($refs) === 1 ? $refs[0] : '', 'references'=>array_slice($refs, 0, 8), 'size'=>$size];
}

/** OCR runs locally, with bounded image memory, CPU time and no retained label photos. */
function label_read_image(string $file): array {
    if (!is_executable('/usr/bin/tesseract') || !is_executable('/usr/bin/timeout')) throw new RuntimeException('La lectura de etiquetas no está disponible. Escribe la referencia para buscar.');
    $info = @getimagesize($file);
    if (!$info || !in_array($info['mime'], ['image/jpeg','image/png','image/webp'], true) || $info[0]*$info[1] > 24000000) throw new InvalidArgumentException('Usa una foto JPG, PNG o WebP de hasta 24 megapíxeles.');
    $image = @imagecreatefromstring((string)file_get_contents($file));
    if (!$image) throw new InvalidArgumentException('No se ha podido leer la foto.');
    if ($info['mime'] === 'image/jpeg') $image = apply_exif_orientation($image, $file);
    $scaled = scale_within($image, 2400);
    $temporary = tempnam(sys_get_temp_dir(), 'vestia-label-');
    if ($temporary === false) throw new RuntimeException('No se ha podido preparar la lectura.');
    try {
        // Flatten transparent photos against white before recognition.
        $canvas = imagecreatetruecolor(imagesx($scaled), imagesy($scaled));
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $scaled, 0, 0, 0, 0, imagesx($scaled), imagesy($scaled));
        imagepng($canvas, $temporary); imagedestroy($canvas);
        $result = ['brand'=>'', 'reference'=>'', 'references'=>[], 'size'=>''];
        foreach ([6, 11] as $mode) {
            $pipes = [];
            $process = proc_open(['/usr/bin/timeout', '--kill-after=1', '10', '/usr/bin/tesseract', $temporary, 'stdout', '-l', 'eng+spa', '--psm', (string)$mode], [0=>['file','/dev/null','r'], 1=>['pipe','w'], 2=>['file','/dev/null','w']], $pipes, null, ['OMP_THREAD_LIMIT'=>'1']);
            if (!is_resource($process)) throw new RuntimeException('No se ha podido iniciar la lectura.');
            $text = stream_get_contents($pipes[1]); fclose($pipes[1]);
            $status = proc_close($process);
            if ($status !== 0) throw new RuntimeException('No se ha podido leer la etiqueta a tiempo. Acerca la cámara o escribe la referencia.');
            $parsed = label_parse_text((string)$text);
            foreach (['brand','size'] as $key) if (!$result[$key]) $result[$key] = $parsed[$key];
            $result['references'] = array_values(array_unique(array_merge($result['references'], $parsed['references'])));
            if ($result['references']) break;
        }
        $result['reference'] = count($result['references']) === 1 ? $result['references'][0] : '';
        return $result;
    } finally {
        @unlink($temporary);
        if ($scaled !== $image) imagedestroy($scaled);
        imagedestroy($image);
    }
}

function label_product_objects(array $product): array {
    $all = [$product];
    foreach ($product['bundleProductSummaries'] ?? [] as $child) if (is_array($child)) $all = array_merge($all, label_product_objects($child));
    return $all;
}

/** Resolve only colors belonging to this model, including bundled product responses. */
function label_product_colors(array $raw, string $base): array {
    $colors = [];
    foreach (label_product_objects($raw) as $object) {
        foreach ($object['detail']['colors'] ?? [] as $color) {
            $ref = $color['reference'] ?? $object['detail']['reference'] ?? '';
            if ($ref && preg_match('/^C?\d(\d{4})(\d{3})/', $ref, $m) && $m[1].'/'.$m[2] !== $base) continue;
            $id = (string)($color['id'] ?? '');
            if (!preg_match('/^\d{3}$/D', $id)) continue;
            $colors[$id] = $color;
        }
    }
    return $colors;
}

/** The gateway puts swatches last. Select a photo whose file identifies this color. */
function label_image_position(array $raw, string $base, string $color): ?int {
    $media = $raw['_normalized']['media'] ?? [];
    usort($media, static fn($a,$b)=>(int)in_array(strtolower((string)($a['name']??'')), ['r','r1','r2','swatch'], true) <=> (int)in_array(strtolower((string)($b['name']??'')), ['r','r1','r2','swatch'], true));
    $needle = str_replace('/', '', $base).$color;
    foreach ($media as $i=>$item) {
        if (str_contains((string)parse_url($item['url'] ?? '', PHP_URL_PATH), $needle) && !in_array(strtolower((string)($item['name']??'')), ['r','r1','r2','swatch'], true)) return $i;
    }
    return null;
}

/** Return explicit choices, never silently choose another brand or another color. */
function label_catalog_matches(PDO $db, string $reference, string $brand): array {
    $base = substr($reference, 0, 8); $wantedColor = strlen($reference) === 12 ? substr($reference, 9, 3) : null;
    $brandId = array_search($brand, LABEL_BRANDS, true);
    if ($brand !== '' && $brandId === false) throw new InvalidArgumentException('Elige una marca de la lista.');
    $sql = 'SELECT * FROM products WHERE reference=?'; $args = [$base];
    if ($brandId !== false) { $sql .= ' AND brand=?'; $args[] = $brandId; }
    $sql .= ' ORDER BY captured_at DESC,id DESC LIMIT 150';
    $statement = $db->prepare($sql); $statement->execute($args); $matches = [];
    foreach ($statement as $row) {
        if (!isset(LABEL_BRANDS[$row['brand']])) continue;
        $raw = json_decode($row['raw_json'] ?? '{}', true) ?: [];
        $colors = label_product_colors($raw, $base);
        if ($wantedColor !== null) $colors = isset($colors[$wantedColor]) ? [$wantedColor=>$colors[$wantedColor]] : [];
        if (!$colors && $wantedColor === null) $colors = [''=>['name'=>'']];
        foreach ($colors as $id=>$color) {
            $id = (string)$id;
            $ref = $base.($id !== '' ? '/'.$id : '');
            $key = $row['brand'].'|'.$ref.'|'.$row['name'];
            if (isset($matches[$key])) continue;
            $sizes = array_values(array_unique(array_filter(array_map(static fn($s)=>(string)($s['name']??''), $color['sizes']??[]))));
            $source = product_from_url($row['url'] ?? '');
            $url = $source['source_url'] ?? '';
            if ($url && $id !== '') {
                $query = []; parse_str((string)parse_url($url, PHP_URL_QUERY), $query); $query['colorId'] = $id;
                $url = explode('?', $url, 2)[0].'?'.http_build_query($query);
            }
            $prices = array_values(array_filter(array_column($color['sizes']??[], 'price'), 'is_numeric'));
            $price = is_numeric($color['price']??null) ? (float)$color['price']/100 : ($prices ? min($prices)/100 : (float)($row['price']??0));
            $matches[$key] = [
                'product_id'=>(int)$row['id'], 'image_position'=>$id !== '' ? label_image_position($raw, $base, $id) : null,
                'name'=>$row['name'], 'brand'=>LABEL_BRANDS[$row['brand']], 'reference'=>$ref,
                'category'=>product_category($row['name']) ?? '', 'color'=>(string)($color['name']??''), 'sizes'=>$sizes,
                'source_url'=>$url, 'price_note'=>product_price($price, $row['currency']??'EUR'),
                'notes'=>mb_substr(trim(html_entity_decode(strip_tags($row['description'] ?? ''), ENT_QUOTES|ENT_HTML5, 'UTF-8')),0,1500),
                'captured_at'=>$row['captured_at'],
            ];
        }
    }
    return array_values($matches);
}

/** Short-lived, session-owned choices keep catalog IDs and image parameters server-side. */
function label_offer_matches(array $matches): array {
    $_SESSION['label_choices'] = array_filter($_SESSION['label_choices'] ?? [], static fn($x)=>($x['expires']??0)>time());
    $_SESSION['label_choices'] = array_slice($_SESSION['label_choices'], -36, null, true);
    $offered = [];
    foreach (array_slice($matches,0,12) as $match) {
        $token = bin2hex(random_bytes(16));
        $_SESSION['label_choices'][$token] = ['expires'=>time()+1800, 'garment'=>$match];
        $image = $match['image_position'] !== null ? '/api/label-image?token='.$token : null;
        unset($match['product_id'], $match['image_position']);
        $offered[] = $match + ['token'=>$token, 'image_url'=>$image];
    }
    return $offered;
}

function label_choice(string $token): ?array {
    if (!preg_match('/^[a-f0-9]{32}$/D', $token)) return null;
    $choice = $_SESSION['label_choices'][$token] ?? [];
    return ($choice['expires']??0)>time() ? $choice['garment'] : null;
}

function label_photo(array $choice): array {
    if ($choice['image_position'] === null) return [404,'',''];
    return catalog_image_gateway('image', ['product'=>$choice['product_id'], 'position'=>$choice['image_position'], 'size'=>'full']);
}

function label_store_photo(array $choice, int $uid): array {
    [$status,$mime,$body] = label_photo($choice);
    $info = $status === 200 ? @getimagesizefromstring($body) : false;
    if (!$info || !in_array($mime,['image/jpeg','image/png','image/webp'],true) || strlen($body)>20*1024*1024 || $info[0]*$info[1]>24000000) throw new RuntimeException('No se ha podido recuperar la fotografía oficial. Desmarca «Usar foto oficial» o añade tu propia foto.');
    $temp = tempnam(sys_get_temp_dir(), 'vestia-product-');
    if ($temp === false) throw new RuntimeException('No se ha podido guardar la fotografía.');
    try {
        file_put_contents($temp, $body);
        [$image,$thumb] = store_garment_image($temp,$mime,APP_ROOT.'/public/uploads',$uid.'-'.bin2hex(random_bytes(12)));
        return ['/uploads/'.$image, '/uploads/'.$thumb];
    } finally { @unlink($temp); }
}
