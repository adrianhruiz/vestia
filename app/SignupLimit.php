<?php
declare(strict_types=1);

/**
 * How many accounts one origin may open, and how fast.
 *
 * The per-account limits elsewhere -- two analyses an hour, fifty live shares -- all assume
 * accounts are scarce. They were not: the form took three fields and gave back a session,
 * with nothing counting how often. This is what makes an account cost something to get.
 *
 * Only successful registrations are recorded. Counting rejected attempts would mean someone
 * who mistyped their password twice arrives at the limit without ever having created
 * anything, and a rejected attempt costs us a row we never wrote.
 */

/** Accounts one origin may open. The day window is what a slow drip runs into. */
const SIGNUPS_PER_IP_PER_HOUR = 3;
const SIGNUPS_PER_IP_PER_DAY = 8;

/**
 * Accounts one email domain may produce in an hour, for domains nobody shares.
 *
 * Aimed at a catch-all domain: its owner invents as many real, deliverable, canonically
 * distinct addresses as they like, and neither the disposable list nor the MX check sees
 * anything wrong. It is deliberately low, which is only safe because the domains where a
 * crowd of unrelated people genuinely share an address space are exempt below.
 */
const SIGNUPS_PER_DOMAIN_PER_HOUR = 5;

/**
 * Consumer providers the domain limit must not touch. Ten strangers signing up from
 * gmail.com in an hour is a good day, not an attack, and throttling that would be a
 * self-inflicted wound the moment the app gets any traction.
 *
 * Microsoft and Yahoo are matched by prefix because their country variants are endless and
 * hotmail.es and outlook.es are exactly the ones Spain uses.
 */
const SIGNUP_MAJOR_DOMAINS = [
    'gmail.com', 'googlemail.com', 'icloud.com', 'me.com', 'mac.com',
    'proton.me', 'protonmail.com', 'pm.me', 'gmx.es', 'gmx.com', 'gmx.net',
    'aol.com', 'zoho.com', 'fastmail.com', 'telefonica.net', 'terra.es', 'ono.com',
];
const SIGNUP_MAJOR_DOMAIN_PREFIXES = ['outlook.', 'hotmail.', 'live.', 'msn.', 'yahoo.'];

/** How long these rows are worth keeping: one day past the longest window. */
const SIGNUP_ATTEMPT_RETENTION_DAYS = 7;

/**
 * The address this request came from. REMOTE_ADDR only: X-Forwarded-For is written by
 * whoever is speaking to us, so trusting it would let a header reset the limit.
 */
function signup_client_ip(): string {
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
}

/**
 * The stored form of an address. Keyed with APP_KEY rather than plainly hashed: the space of
 * IPv4 addresses is small enough to walk through in minutes, so an unkeyed digest would be
 * the addresses themselves with extra steps.
 */
function signup_ip_hash(string $ip): string {
    return hash_hmac('sha256', $ip, (string) env('APP_KEY', ''));
}

/** Whether this domain is one many unrelated people legitimately register from. */
function signup_major_domain(string $domain): bool {
    if (in_array($domain, SIGNUP_MAJOR_DOMAINS, true)) return true;
    foreach (SIGNUP_MAJOR_DOMAIN_PREFIXES as $prefix) if (str_starts_with($domain, $prefix)) return true;
    return false;
}

/**
 * The signups this key has produced, newest first.
 *
 * Counted in PHP rather than in SQL, for the reason recent_analyses() gives: created_at is
 * an ISO-8601 string carrying a local offset, and comparing those as strings silently
 * reverses order across the two days a year the clocks change.
 */
function recent_signups(PDO $pdo, string $field, string $value): array {
    $column = ['ip' => 'ip_hash', 'domain' => 'email_domain'][$field] ?? null;
    if ($column === null) throw new InvalidArgumentException('Unknown signup limit field: ' . $field);
    // Comfortably more than the widest window allows, so the window itself decides the count.
    $stmt = $pdo->prepare("SELECT created_at FROM signup_attempts WHERE {$column}=? ORDER BY id DESC LIMIT 50");
    $stmt->execute([$value]);

    $times = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $stamp) {
        try { $times[] = new DateTimeImmutable((string) $stamp); } catch (Exception) { continue; }
    }
    return $times;
}

/**
 * Those of $times inside the last $seconds, newest first.
 *
 * Sorted here rather than trusted from the query. Rows arrive in id order, which is the same
 * as time order for anything the app itself wrote, but the countdown below reads the oldest
 * entry off the end and would be wrong by hours if the two ever disagreed -- a row seeded by
 * hand, or a clock that moved.
 */
function signups_within(array $times, int $seconds): array {
    $since = new DateTimeImmutable('-' . $seconds . ' seconds');
    $recent = array_values(array_filter($times, static fn(DateTimeImmutable $at): bool => $at >= $since));
    usort($recent, static fn(DateTimeImmutable $a, DateTimeImmutable $b): int => $b <=> $a);
    return $recent;
}

/**
 * Seconds until this origin may register again, or null when it may right now.
 *
 * Returns the wait of whichever window is full. The oldest signup inside a window is the one
 * whose slot frees up first, so that is what the countdown is measured from.
 */
function signup_retry_after(PDO $pdo, string $ipHash, string $domain): ?int {
    $windows = [];
    $byIp = recent_signups($pdo, 'ip', $ipHash);
    $windows[] = [signups_within($byIp, 3600), SIGNUPS_PER_IP_PER_HOUR, 3600];
    $windows[] = [signups_within($byIp, 86400), SIGNUPS_PER_IP_PER_DAY, 86400];
    if (!signup_major_domain($domain)) {
        $windows[] = [signups_within(recent_signups($pdo, 'domain', $domain), 3600), SIGNUPS_PER_DOMAIN_PER_HOUR, 3600];
    }

    $wait = null;
    foreach ($windows as [$recent, $allowed, $seconds]) {
        if (count($recent) < $allowed) continue;
        $oldest = end($recent);
        $wait = max($wait ?? 0, max(1, $oldest->getTimestamp() + $seconds - time()));
    }
    return $wait;
}

/**
 * Records a signup and drops the rows too old for any window to read. Pruning here rather
 * than on a timer keeps the table self-maintaining; a string comparison is good enough for
 * a seven-day cut, where an hour either way changes nothing.
 */
function record_signup(PDO $pdo, string $ipHash, string $domain): void {
    $pdo->prepare('INSERT INTO signup_attempts(ip_hash,email_domain,created_at) VALUES(?,?,?)')
        ->execute([$ipHash, $domain, date(DATE_ATOM)]);
    $pdo->prepare('DELETE FROM signup_attempts WHERE created_at < ?')
        ->execute([date(DATE_ATOM, time() - SIGNUP_ATTEMPT_RETENTION_DAYS * 86400)]);
}
