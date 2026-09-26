<?php
declare(strict_types=1);

/**
 * A link to an Inditex product, and how much of a garment it fills in for us.
 *
 * Adding a garment by hand is nine boxes, and the shop's own page already answers eight of
 * them. So the fast way in is a URL -- copied from the app, from a browser, from a shared
 * link -- and the slow way in, which always works, is a photo and the boxes.
 *
 * ## Why this is not about scanning the tag
 *
 * It was going to be, and the tag turned out not to support it yet. What an Inditex hangtag
 * actually carries today is an EAN barcode, the printed reference in AAAA/BBB/CCC form, and
 * the size as plain text; item-level identification across the group is RFID, inside the
 * security tag, which no phone camera can read. QR codes are arriving on the label -- the
 * accessibility labelling rolled out across the eight brands from June 2026 sits next to one,
 * and the EU product passport makes one compulsory for textiles in 2027 -- but those point at
 * a traceability page, and nothing we have checked says they resolve to a product page or
 * carry a size.
 *
 * So nothing here assumes a scanner, and nothing here decodes anything. When the way to scan
 * an article is decided, this is the function it hands its answer to: whatever a reader ends
 * up producing -- a URL, a reference, a barcode -- the job after it is still "which garment is
 * this", which is what the rest of this file does.
 *
 * Nothing here goes out to the network either. Everything it answers comes from the URL itself
 * plus the Inditex catalogue this installation already harvests (app/Catalog.php), so adding a
 * garment is as fast as a form submit and cannot be turned into a request to somewhere of the
 * sender's choosing.
 *
 * Whatever comes back is a suggestion in a form the person confirms, never a saved row.
 */

/**
 * The brands whose links we read, by registrable domain.
 *
 * It is the same list the wardrobe accepts as a brand (GARMENT_BRANDS), and it is what makes
 * this safe to point at a URL somebody pasted: a link to anywhere else is refused here,
 * before anything is parsed out of it.
 */
const PRODUCT_LINK_BRANDS = [
    'zara.com' => 'Zara',
    'massimodutti.com' => 'Massimo Dutti',
    'bershka.com' => 'Bershka',
    'pullandbear.com' => 'Pull&Bear',
    'stradivarius.com' => 'Stradivarius',
    'oysho.com' => 'Oysho',
    'lefties.com' => 'Lefties',
];

/** A URL longer than the column that stores it is not a URL we can keep. */
const PRODUCT_LINK_MAX_LENGTH = 1000;

/** The brand behind a host, or null when the host is not one of ours. */
function product_brand(string $host): ?string {
    $host = strtolower(ltrim($host, '.'));
    foreach (PRODUCT_LINK_BRANDS as $domain => $brand) {
        if ($host === $domain || str_ends_with($host, '.' . $domain)) return $brand;
    }
    return null;
}

/**
 * The Inditex reference of a product, read out of its own URL, or null.
 *
 * The group's fast-fashion sites spell the product inside the slug as l0AAAABBB and the
 * colour as colorId=CCC, which is exactly the AAAA/BBB/CCC printed on the hangtag next to
 * "ART". Where the URL does not carry it -- Lefties and Zara build theirs from internal ids --
 * this answers null and the catalogue lookup is what supplies the reference instead.
 */
function product_reference(string $path, ?string $colorId): ?string {
    if ($colorId === null || !preg_match('/^\d{3}$/', $colorId)) return null;
    if (!preg_match('/-l0(\d{4})(\d{3})-/', $path, $m)) return null;
    return $m[1] . '/' . $m[2] . '/' . $colorId;
}

/**
 * Which of the wardrobe's categories a product name belongs to, or null when it is not clear.
 *
 * Read from the name rather than from the brand's own category, which is a navigation path
 * ("mujer/ropa/camisetas") and says as much about where the product sits in a shop as about
 * what it is. The order matters: a "vestido camisero" is a dress, not a shirt, so the longer
 * garment is asked about first.
 */
function product_category(string $name): ?string {
    $name = strtolower(strtr($name, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']));
    $keywords = [
        GARMENT_DRESS => ['vestido', 'mono'],
        GARMENT_SHOES => ['zapato', 'zapatilla', 'bota', 'botin', 'sandalia', 'deportiv', 'mocasin', 'bailarina', 'chancla', 'alpargata', 'sneaker'],
        'Abrigos' => ['abrigo', 'cazadora', 'chaqueta', 'parka', 'trench', 'gabardina', 'plumifero', 'blazer', 'anorak'],
        GARMENT_BOTTOM => ['pantalon', 'jean', 'vaquero', 'falda', 'short', 'bermuda', 'legging', 'culotte'],
        GARMENT_TOP => ['camiseta', 'camisa', 'blusa', 'sudadera', 'jersey', 'polo', 'chaleco', 'body', 'top'],
        'Accesorios' => ['bolso', 'mochila', 'cinturon', 'gorro', 'gorra', 'bufanda', 'guante', 'manopla', 'panuelo', 'gafa', 'pendiente', 'collar', 'anillo', 'reloj', 'cartera'],
    ];
    foreach ($keywords as $category => $words) {
        foreach ($words as $word) if (str_contains($name, $word)) return $category;
    }
    return null;
}

/**
 * Everything the URL says on its own, or null when it is not an Inditex product link.
 *
 * https only, and the brand has to be one of ours. The string arrives from a box anybody can
 * paste into, and a link is the cheapest way there is to hand an application a URL it did not
 * choose.
 *
 * No size. The tag prints it as text and the URL does not carry it, so the box stays empty and
 * the form offers the sizes the product is actually sold in -- see product_from_catalog().
 */
function product_from_url(string $url): ?array {
    $url = trim($url);
    if ($url === '' || strlen($url) > PRODUCT_LINK_MAX_LENGTH) return null;
    $parts = parse_url($url);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') === '') return null;
    // Credentials in a product link are not something a shop publishes; they are something
    // somebody added.
    if (isset($parts['user']) || isset($parts['pass'])) return null;
    $brand = product_brand((string) $parts['host']);
    if ($brand === null) return null;

    $query = [];
    parse_str((string) ($parts['query'] ?? ''), $query);
    $colorId = is_string($query['colorId'] ?? null) ? $query['colorId'] : null;

    return array_filter([
        'brand' => $brand,
        'source_url' => $url,
        'reference' => product_reference((string) ($parts['path'] ?? ''), $colorId),
        'color_id' => $colorId,
    ], static fn(?string $value): bool => $value !== null);
}

/**
 * What the harvested Inditex catalogue knows about this product, or nothing.
 *
 * Best effort by design. The catalogue is a read-only SQLite file that only exists where the
 * harvester runs and only covers what it has already been through, so a local checkout and an
 * unvisited brand both land here and get an empty array -- which is a link that filled in the
 * brand and the reference and left the name to be typed, not a failure.
 *
 * Matched on the URL, which is the one thing a pasted link and a harvest row are certain to
 * agree on. Products differing only by colour share a path, so the row carrying the pasted
 * colorId wins over the freshest one.
 */
function product_from_catalog(string $url, ?string $colorId): array {
    try { $db = catalog_db(); } catch (Throwable) { return []; }
    $base = explode('?', $url, 2)[0];
    try {
        $stmt = $db->prepare("SELECT name,reference,category,color_text,price,currency,sizes_json,url FROM products
            WHERE url=? OR url LIKE ? ESCAPE '!' ORDER BY captured_at DESC LIMIT 20");
        $stmt->execute([$url, str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $base) . '%']);
        $rows = $stmt->fetchAll();
    } catch (Throwable) { return []; }
    if (!$rows) return [];

    $product = $rows[0];
    if ($colorId !== null) {
        foreach ($rows as $row) if (str_contains((string) $row['url'], 'colorId=' . $colorId)) { $product = $row; break; }
    }

    $name = trim((string) $product['name']);
    $sizes = json_decode((string) $product['sizes_json'], true);
    return array_filter([
        'name' => $name,
        'reference' => trim((string) $product['reference']),
        'color' => trim((string) $product['color_text']),
        'category' => product_category($name),
        'price_note' => product_price((float) $product['price'], (string) $product['currency']),
        // Every size this product is sold in, so the box offers them instead of asking.
        'sizes' => is_array($sizes) ? array_values(array_filter(array_map('strval', $sizes), static fn(string $s): bool => $s !== '')) : [],
    ], static fn(mixed $value): bool => $value !== '' && $value !== null && $value !== []);
}

/** The price the way the wardrobe already writes it, or '' when there is none to write. */
function product_price(float $price, string $currency): string {
    if ($price <= 0) return '';
    return number_format($price, 2, ',', '.') . ($currency === 'EUR' || $currency === '' ? ' €' : ' ' . $currency);
}

/**
 * The whole answer to one pasted link: what the URL says, with what the catalogue adds.
 *
 * The URL wins where both speak. Its reference and colour are what the shop is showing right
 * now; the catalogue's came off a page that may have been re-harvested since.
 */
function garment_from_link(string $url): ?array {
    $fromUrl = product_from_url($url);
    if ($fromUrl === null) return null;
    $found = product_from_catalog((string) $fromUrl['source_url'], $fromUrl['color_id'] ?? null);
    unset($fromUrl['color_id']);
    return $fromUrl + $found;
}
