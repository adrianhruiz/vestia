<?php
declare(strict_types=1);

/**
 * Sizes stored for every garment photo. The wardrobe grid paints a 210x260 card, so 480px
 * covers it on a 2x screen; 1200px is the larger copy kept for the outfit collage and for
 * anything that later wants the detail. What the phone uploaded is never published: an 8 MB
 * camera file to paint a 210px card is the whole reason the wardrobe weighed 14 MB.
 */
const IMAGE_MAX_SIDE = 1200;
const IMAGE_THUMB_SIDE = 480;
const IMAGE_QUALITY = 82;

/**
 * Writes the WebP derivatives of an uploaded photo into $directory and returns their file
 * names, largest first. Throws if the file cannot be decoded or written.
 */
function store_garment_image(string $source, string $mime, string $directory, string $basename): array {
    $original = @imagecreatefromstring((string) file_get_contents($source));
    if ($original === false) throw new RuntimeException('No se pudo decodificar la imagen.');
    if ($mime === 'image/jpeg') $original = apply_exif_orientation($original, $source);
    imagepalettetotruecolor($original);
    imagealphablending($original, false);
    imagesavealpha($original, true);

    $written = [];
    try {
        foreach (['' => IMAGE_MAX_SIDE, '-thumb' => IMAGE_THUMB_SIDE] as $suffix => $maxSide) {
            $resized = scale_within($original, $maxSide);
            $name = $basename . $suffix . '.webp';
            $saved = imagewebp($resized, $directory . '/' . $name, IMAGE_QUALITY);
            if ($resized !== $original) imagedestroy($resized);
            if (!$saved) throw new RuntimeException('No se pudo guardar la imagen.');
            $written[] = $name;
        }
    } catch (Throwable $e) {
        // Never leave half a pair behind: the caller would store a path to a missing file.
        foreach ($written as $name) @unlink($directory . '/' . $name);
        throw $e;
    } finally {
        imagedestroy($original);
    }
    return $written;
}

/**
 * Size a photo of a person is reduced to before the stylist reads it. Above 1024 px the
 * model sees nothing it did not already see -- it works from a downscaled copy of anything
 * larger anyway -- and this file travels to the API as base64 inside the request body, where
 * every pixel is paid for twice: in bandwidth and in input tokens.
 */
const STYLE_PHOTO_MAX_SIDE = 1024;
const STYLE_PHOTO_QUALITY = 82;

/**
 * Writes the copy of an uploaded photo that the stylist will read, as $directory/$name.
 * Throws if it cannot be decoded or written.
 *
 * JPEG rather than the WebP the wardrobe stores, because nothing ever displays this one: its
 * only reader is the API, and JPEG is what every vision endpoint has accepted since the
 * first of them. Re-encoding also drops the EXIF block whole, which on a photo somebody took
 * of themselves is where the coordinates of their house are.
 */
function store_style_photo(string $source, string $mime, string $directory, string $name): void {
    $original = @imagecreatefromstring((string) file_get_contents($source));
    if ($original === false) throw new RuntimeException('No se pudo decodificar la imagen.');
    if ($mime === 'image/jpeg') $original = apply_exif_orientation($original, $source);
    imagepalettetotruecolor($original);

    $resized = scale_within($original, STYLE_PHOTO_MAX_SIDE);
    // JPEG has no alpha, and GD fills what it cannot keep with black: a cut-out PNG saved
    // straight from here would reach the stylist as a silhouette. Flattening onto white
    // gives it the background such an image was drawn against.
    $flat = imagecreatetruecolor(imagesx($resized), imagesy($resized));
    imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
    imagecopy($flat, $resized, 0, 0, 0, 0, imagesx($resized), imagesy($resized));

    try {
        if (!imagejpeg($flat, $directory . '/' . $name, STYLE_PHOTO_QUALITY)) {
            throw new RuntimeException('No se pudo guardar la imagen.');
        }
    } finally {
        imagedestroy($flat);
        if ($resized !== $original) imagedestroy($resized);
        imagedestroy($original);
    }
}

/**
 * Public path of the grid-sized copy of $path, or null when there is no such file. Seed
 * assets ship their -thumb.webp next to the full-size one, so the seeds can fill thumb_path
 * without knowing how each image was produced.
 */
function thumb_path_for(?string $path): ?string {
    if ($path === null || $path === '') return null;
    $thumb = preg_replace('/\.(webp|png|jpe?g)$/i', '-thumb.webp', $path);
    return $thumb !== null && $thumb !== $path && is_file(APP_ROOT . '/public' . $thumb) ? $thumb : null;
}

/** Returns a copy scaled so neither side exceeds $maxSide, or the original if it already fits. */
function scale_within(GdImage $image, int $maxSide): GdImage {
    $width = imagesx($image);
    $height = imagesy($image);
    $longest = max($width, $height);
    if ($longest <= $maxSide) return $image;
    $scaled = imagescale($image, (int) round($width * $maxSide / $longest), (int) round($height * $maxSide / $longest), IMG_BICUBIC);
    if ($scaled === false) return $image;
    imagealphablending($scaled, false);
    imagesavealpha($scaled, true);
    return $scaled;
}

/**
 * Phone cameras record rotation in EXIF rather than in the pixels, and GD ignores it, so a
 * photo taken sideways would land sideways in the grid.
 */
function apply_exif_orientation(GdImage $image, string $file): GdImage {
    if (!function_exists('exif_read_data')) return $image;
    $exif = @exif_read_data($file);
    $degrees = [3 => 180, 6 => -90, 8 => 90][(int) ($exif['Orientation'] ?? 1)] ?? 0;
    if ($degrees === 0) return $image;
    $rotated = imagerotate($image, $degrees, 0);
    if ($rotated === false) return $image;
    imagedestroy($image);
    return $rotated;
}
