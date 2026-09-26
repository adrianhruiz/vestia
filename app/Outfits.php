<?php
declare(strict_types=1);

/**
 * The looks somebody assembles themselves, and what registering now asks for.
 *
 * Registration used to end on a screen that asked for photographs of the person. It worked
 * -- a face in a living room is a lot of signal -- and it was the wrong thing to ask for:
 * the photos had to be deleted the moment the reading ended, nothing else in the app was
 * allowed to touch them, and the first thing that happened to a new account was handing over
 * pictures of itself.
 *
 * Two outfits say the same thing without any of that. What somebody wears with what *is*
 * their style, it is information this app is already built to hold, and it survives the
 * reading instead of being destroyed by it: the looks stay in the wardrobe afterwards.
 *
 * This is also not a wall between registering and the product. Nobody is held on a separate
 * page -- the panel lives inside the wardrobe, next to the grid, and everything else works
 * while it is unfinished.
 */

/** How many complete looks the welcome asks for before the stylist reads them. */
const ONBOARDING_OUTFITS = 2;

/** The names those two get. Somebody who has just registered has not named anything yet. */
const ONBOARDING_OUTFIT_NAMES = ['Outfit 1', 'Outfit 2'];

/**
 * The categories a look is still missing, in the order the panel should ask for them.
 *
 * Empty means complete. Abrigos and Accesorios are never in here: a look is not incomplete
 * for having no scarf, and asking for one is how a welcome turns into a form.
 */
function outfit_missing(array $categories): array {
    $has = array_flip($categories);
    $missing = [];
    // A dress is the top and the bottom at once, so it settles both questions.
    if (!isset($has[GARMENT_DRESS])) {
        if (!isset($has[GARMENT_TOP])) $missing[] = GARMENT_TOP;
        if (!isset($has[GARMENT_BOTTOM])) $missing[] = GARMENT_BOTTOM;
    }
    if (!isset($has[GARMENT_SHOES])) $missing[] = GARMENT_SHOES;
    return $missing;
}

/** The categories present in one look, as outfits_of_user() shapes it. */
function outfit_categories(array $outfit): array {
    return array_map(static fn(array $garment): string => (string) $garment['category'], $outfit['garments'] ?? []);
}

/** Whether one look is wearable as it stands. */
function outfit_complete(array $outfit): bool {
    return outfit_missing(outfit_categories($outfit)) === [];
}

/**
 * Whether the welcome has what it asked for: two looks, both complete, and *different*.
 *
 * Different means different sets of garments, not disjoint ones. The same jeans in both
 * looks is how people actually dress and refusing it would be a lie about clothes; two looks
 * made of exactly the same three garments is one look entered twice, and reading it as two
 * would tell the stylist something that is not true.
 */
function outfits_ready(array $outfits): bool {
    $wanted = array_slice($outfits, 0, ONBOARDING_OUTFITS);
    if (count($wanted) < ONBOARDING_OUTFITS) return false;

    $sets = [];
    foreach ($wanted as $outfit) {
        if (!outfit_complete($outfit)) return false;
        $ids = array_map(static fn(array $garment): int => (int) $garment['id'], $outfit['garments']);
        sort($ids);
        $sets[] = implode(',', $ids);
    }
    return count(array_unique($sets)) === count($sets);
}

/**
 * Why the welcome is not satisfied yet, in one sentence, or null when the slots say it
 * themselves.
 *
 * There is exactly one way to be stuck with both looks finished -- having entered the same
 * look twice -- and it is the one case a row of empty slots cannot explain, so it is the one
 * case worth a sentence.
 */
function outfits_hint(array $outfits): ?string {
    $wanted = array_slice($outfits, 0, ONBOARDING_OUTFITS);
    if (count($wanted) < ONBOARDING_OUTFITS) return null;
    foreach ($wanted as $outfit) if (!outfit_complete($outfit)) return null;
    return outfits_ready($outfits) ? null : 'Los dos outfits llevan exactamente la misma ropa. Cambia al menos una prenda en uno de ellos.';
}

/**
 * Every look this person has, oldest first, each with its garments.
 *
 * Two queries rather than one per look, and rather than a join whose rows would have to be
 * folded back into shape: a look with no garments in it still has to come back, and it is
 * the ordinary case for a minute after registering.
 */
function outfits_of_user(PDO $pdo, int $userId): array {
    $stmt = $pdo->prepare('SELECT id,name FROM outfits WHERE user_id=? ORDER BY id');
    $stmt->execute([$userId]);
    $outfits = [];
    foreach ($stmt->fetchAll() as $row) $outfits[(int) $row['id']] = ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'garments' => []];
    if (!$outfits) return [];

    // Joined against garments on user_id as well, so a row that somehow pointed at somebody
    // else's clothes could not put them in this answer.
    $stmt = $pdo->prepare('SELECT og.outfit_id, g.id, g.name, g.brand, g.category, g.color, g.size, g.season, g.notes, g.image_path, g.thumb_path
        FROM outfit_garments og JOIN garments g ON g.id=og.garment_id AND g.user_id=?
        WHERE og.outfit_id IN (' . implode(',', array_fill(0, count($outfits), '?')) . ') ORDER BY g.id');
    $stmt->execute(array_merge([$userId], array_keys($outfits)));
    foreach ($stmt->fetchAll() as $row) {
        $outfitId = (int) $row['outfit_id'];
        unset($row['outfit_id']);
        $row['id'] = (int) $row['id'];
        $outfits[$outfitId]['garments'][] = $row;
    }
    return array_values($outfits);
}

/**
 * The looks the welcome works on, creating the ones that do not exist yet.
 *
 * Created here rather than at registration because registration is the one request that must
 * not grow: it already does a rate-limit read, a DNS lookup, a uniqueness check and an
 * insert, and two empty rows nobody may ever look at do not belong in front of a session.
 *
 * An account that already has looks keeps them -- somebody who grouped three outfits and
 * then came back to the welcome is asked for nothing new.
 */
function onboarding_outfits(PDO $pdo, int $userId): array {
    $outfits = outfits_of_user($pdo, $userId);
    if (count($outfits) >= ONBOARDING_OUTFITS) return $outfits;

    $insert = $pdo->prepare('INSERT INTO outfits(user_id,name,created_at) VALUES(?,?,?)');
    for ($i = count($outfits); $i < ONBOARDING_OUTFITS; $i++) {
        $insert->execute([$userId, ONBOARDING_OUTFIT_NAMES[$i] ?? 'Outfit ' . ($i + 1), date(DATE_ATOM)]);
    }
    return outfits_of_user($pdo, $userId);
}

/**
 * Puts a garment in a look, or answers false when either of them belongs to somebody else.
 *
 * Both ids arrive in a request body, so both are numbers somebody chose, and the check asks
 * the only question that matters about either: does this account own it. The join is what
 * makes it one question -- a look and a garment belonging to the same person.
 *
 * Adding what is already there is not an error. The primary key makes the write a no-op and
 * the caller wanted the garment in the look, which it is.
 */
function outfit_add_garment(PDO $pdo, int $userId, int $outfitId, int $garmentId): bool {
    $owned = $pdo->prepare('SELECT 1 FROM outfits o JOIN garments g ON g.user_id=o.user_id WHERE o.id=? AND g.id=? AND o.user_id=?');
    $owned->execute([$outfitId, $garmentId, $userId]);
    if (!$owned->fetchColumn()) return false;
    $pdo->prepare('INSERT IGNORE INTO outfit_garments(outfit_id,garment_id) VALUES(?,?)')->execute([$outfitId, $garmentId]);
    return true;
}

/** Takes a garment out of a look. False when there was nothing of theirs to take out. */
function outfit_remove_garment(PDO $pdo, int $userId, int $outfitId, int $garmentId): bool {
    $stmt = $pdo->prepare('DELETE og FROM outfit_garments og JOIN outfits o ON o.id=og.outfit_id
        WHERE og.outfit_id=? AND og.garment_id=? AND o.user_id=?');
    $stmt->execute([$outfitId, $garmentId, $userId]);
    return $stmt->rowCount() > 0;
}
