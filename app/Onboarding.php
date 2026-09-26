<?php
declare(strict_types=1);

/**
 * The welcome: what is still owed to an account that has just been created.
 *
 * It used to be a page of its own at /bienvenida, which asked for photographs and stood
 * between registering and the product. Both are gone. Registering now ends in the wardrobe,
 * and the welcome is a panel inside it that asks for two complete outfits (app/Outfits.php)
 * and, once they exist, reads them.
 *
 * All that is left here is the flag: whether this account has been through that yet. It is
 * read to decide whether the panel is painted, and written once, when the person accepts the
 * profile the reading wrote for them.
 */

/** Whether the welcome is still owed to this account. */
function onboarding_pending(?array $user): bool {
    return $user !== null && ($user['onboarding_done_at'] ?? null) === null;
}

/**
 * Writes down that the welcome is behind them.
 *
 * Only ever the first time: the date is when they stopped being new, and somebody redoing
 * their reading a year later has not just registered.
 */
function onboarding_done(PDO $pdo, int $userId): void {
    $pdo->prepare('UPDATE users SET onboarding_done_at=? WHERE id=? AND onboarding_done_at IS NULL')
        ->execute([date(DATE_ATOM), $userId]);
}

/**
 * Everything the wardrobe panel needs to paint the welcome, in one answer.
 *
 * The looks come first because they are what is being asked for, and the reading comes with
 * them because the panel has to be able to resume: somebody who reloads the page while the
 * stylist is working, or who comes back to a finished reading they never accepted, has to find
 * the same screen they left. Nothing about that state lives in the browser.
 */
function onboarding_state(PDO $pdo, int $userId): array {
    $outfits = onboarding_outfits($pdo, $userId);
    // What each look is still missing is decided here and not in the browser, so the rule for
    // a wearable outfit lives in outfit_missing() and nowhere else. The panel paints what it
    // is told.
    foreach ($outfits as $index => $outfit) {
        $outfits[$index]['missing'] = outfit_missing(outfit_categories($outfit));
        $outfits[$index]['complete'] = $outfits[$index]['missing'] === [];
    }
    // Every garment that could fill a slot, by the three fields a slot shows. The page already
    // ships the wardrobe, but it ships whatever the grid is currently filtered to, and a
    // <select> that is missing the trousers because somebody typed in the search box would be
    // a bug nobody could see happening.
    $stmt = $pdo->prepare('SELECT id,name,category FROM garments WHERE user_id=? ORDER BY created_at DESC, id DESC');
    $stmt->execute([$userId]);
    $wardrobe = array_map(static fn(array $row): array => ['id' => (int) $row['id']] + $row, $stmt->fetchAll());

    return [
        'outfits' => $outfits,
        'wardrobe' => $wardrobe,
        'ready' => outfits_ready($outfits),
        'hint' => outfits_hint($outfits),
        'reading' => latest_style_reading($pdo, $userId),
    ];
}

/**
 * The same state, after a change to the wardrobe that may have been the one that finished it.
 *
 * Two complete outfits is the whole ask, so completing the second one is what starts the
 * reading: nobody should have to go and find a button for the thing they were just asked to
 * do. Which means this is the one place in the app where a model call is started by something
 * other than a press, so it is deliberately narrow -- once per account, only while the welcome
 * is still owed, only when no reading exists yet, and never past the daily limit.
 *
 * A reading that failed is not retried here. It would be billed again for the same account
 * without anybody asking, and the panel has a button for exactly that case.
 */
function onboarding_advance(PDO $pdo, array $user): array {
    $state = onboarding_state($pdo, (int) ($user['id'] ?? 0));
    if (!$state['ready'] || $state['reading'] !== null || !onboarding_pending($user)) return $state;
    if (style_reading_retry_after($pdo, $user) !== null) return $state;

    style_reading_queue($pdo, (int) $user['id'], $state['outfits']);
    $state['reading'] = latest_style_reading($pdo, (int) $user['id']);
    return $state;
}
