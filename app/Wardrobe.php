<?php
declare(strict_types=1);

/**
 * What the wardrobe already knows about the person wearing it.
 *
 * The style profile used to carry a "tallas habituales" box, which was the wrong place for
 * it twice over. It is one line for somebody who is a 40 in trousers, an M in shirts and a
 * 43 in shoes, so it can only ever be approximately true. And it was asked before there was
 * a single garment to hang it on -- the one moment when nobody has thought about it.
 *
 * Meanwhile every garment already carries its own size, and analysis_garments() has always
 * sent it to the stylist. The profile field was a worse copy of a better answer.
 *
 * So nobody is asked up front. The size offered for a new garment is the one the last
 * garment in that category had, which is right nearly every time and, when it is not, is
 * wrong inside the box where it can be corrected.
 */

/**
 * The size to offer per category: the most recent one this person actually used there.
 *
 * Read in ascending id order so the newest row is the one left standing. Categories with
 * nothing to go on are simply absent -- an empty suggestion is not a suggestion, and the
 * form has something better to say in that case.
 */
function remembered_sizes(PDO $pdo, int $userId): array {
    $stmt = $pdo->prepare("SELECT category, size FROM garments WHERE user_id=? AND size IS NOT NULL AND size<>'' ORDER BY id");
    $stmt->execute([$userId]);

    $sizes = [];
    foreach ($stmt->fetchAll() as $row) $sizes[(string) $row['category']] = (string) $row['size'];
    return $sizes;
}
