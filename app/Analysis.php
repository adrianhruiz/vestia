<?php
declare(strict_types=1);

/**
 * How much of a free-text note the stylist needs. It is the only unbounded thing a person
 * writes per garment, and up to forty of them ride in every prompt.
 */
const ANALYSIS_NOTES_MAX_LENGTH = 160;

/**
 * The wardrobe exactly as the stylist sees it: same rows, same order, same fields. The API
 * and the worker both call this, so the two can never disagree about what was sent.
 *
 * The ordering carries an id tiebreaker on purpose. created_at only has second resolution,
 * and a seed inserts dozens of rows within the same second, so without it the same wardrobe
 * comes back in a different order from one call to the next.
 */
function analysis_garments(PDO $pdo, int $userId): array {
    $limit = max(1, (int) env('ANALYSIS_MAX_ITEMS', '40'));
    $stmt = $pdo->prepare('SELECT id,name,brand,reference,category,color,size,season,notes FROM garments WHERE user_id=? ORDER BY favorite DESC, created_at DESC, id DESC LIMIT ?');
    $stmt->bindValue(1, $userId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();

    $garments = [];
    foreach ($stmt->fetchAll() as $row) {
        $row['notes'] = mb_substr(trim((string) $row['notes']), 0, ANALYSIS_NOTES_MAX_LENGTH);
        // An empty colour or season is a key and a null the model has to read for nothing.
        $garments[] = array_filter($row, static fn($value): bool => $value !== null && $value !== '');
    }
    return $garments;
}

/**
 * Bump when the instructions or the schema change enough that an old answer is no longer
 * the answer we would give today, so fingerprints from before stop matching.
 */
const ANALYSIS_PROMPT_VERSION = 1;

/**
 * Everything that decides what the stylist replies, in one hash. The garments are the rows
 * as they are actually sent, so editing a note or a colour changes the fingerprint on its
 * own — there is no separate "has the wardrobe changed" flag to keep in sync.
 */
function analysis_fingerprint(array $garments, array $input): string {
    return hash('sha256', (string) json_encode([
        'garments' => $garments,
        'input' => $input,
        'model' => env('OPENAI_MODEL', ''),
        'prompt' => ANALYSIS_PROMPT_VERSION,
    ], JSON_UNESCAPED_UNICODE));
}
