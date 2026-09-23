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

/** How many analyses an ordinary account may start per hour. */
const ANALYSES_PER_HOUR = 2;

/**
 * The analyses this account has spent in the last hour, newest first.
 *
 * Counted in PHP rather than in SQL on purpose. created_at is an ISO-8601 string with a
 * local offset, so a string comparison silently reverses order across the two days a year
 * the clocks change; DateTimeImmutable reads the offset and gets it right. Reading the last
 * twenty rows costs nothing, and the limit itself keeps the window far below that.
 *
 * Failed runs are not counted: the person got nothing out of them. The global daily ceiling
 * is what stops a failure loop from draining the key.
 */
function recent_analyses(PDO $pdo, int $userId): array {
    $stmt = $pdo->prepare("SELECT created_at FROM jobs WHERE user_id=? AND type='wardrobe_analysis' AND status<>'failed' ORDER BY id DESC LIMIT 20");
    $stmt->execute([$userId]);
    $since = new DateTimeImmutable('-1 hour');
    $recent = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $value) {
        try { $at = new DateTimeImmutable((string) $value); } catch (Exception) { continue; }
        if ($at >= $since) $recent[] = $at;
    }
    return $recent;
}

/** Test accounts run analyses back to back by design, so the limit does not apply to them. */
function analysis_limit_exempt(array $user): bool {
    if ((int) ($user['is_test'] ?? 0) === 1) return true;
    $exempt = array_filter(array_map('trim', explode(',', (string) env('RATE_LIMIT_EXEMPT_EMAILS', ''))));
    return in_array(strtolower((string) ($user['email'] ?? '')), array_map('strtolower', $exempt), true);
}

/** Seconds until this account may analyse again, or null when it may right now. */
function analysis_retry_after(PDO $pdo, array $user): ?int {
    if (analysis_limit_exempt($user)) return null;
    $recent = recent_analyses($pdo, (int) $user['id']);
    if (count($recent) < ANALYSES_PER_HOUR) return null;
    // The oldest one in the window is the one whose slot frees up first.
    return max(1, end($recent)->getTimestamp() + 3600 - time());
}
