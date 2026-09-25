<?php
declare(strict_types=1);

/**
 * Whether an address is one we are willing to open an account for.
 *
 * This is a different question from the one app/Email.php answers. That one asks which
 * mailbox an address belongs to; this one asks whether the mailbox can plausibly exist and
 * whether it is the kind that is thrown away ten minutes from now. Registration is free and
 * grants a session immediately, so the address is the only thing standing between a script
 * and an unbounded supply of accounts.
 *
 * None of this proves a human is holding the mailbox -- only a verification mail can do
 * that, and that needs a domain and a sender we do not have yet. What it does is make the
 * cheap attempts fail: a typo, an invented domain, a throwaway provider.
 */

/** A domain that has to resolve. If even this one does not, the resolver is the problem. */
const EMAIL_DNS_SENTINEL = 'gmail.com';

const EMAIL_DISPOSABLE_LIST = APP_ROOT . '/database/disposable_domains.txt';

/**
 * Why this address cannot be used, or null when it can. Returns a code rather than a
 * sentence: the wording belongs with the other copy in the endpoint, not in here.
 *
 * Ordered cheapest first. The DNS lookup is the only part that can block on the network, so
 * nothing reaches it that a string comparison could have rejected.
 */
function email_rejection(string $email): ?string {
    // The endpoint has already run this; repeated so the function is safe to call on its
    // own. PHP's filter is stricter than it looks -- it enforces the 254-octet address, the
    // 64-octet local part, a dot in the domain and no trailing dot, so none of those needs
    // checking again here.
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return 'syntax';
    $domain = email_domain_of($email);
    if ($domain === null) return 'syntax';
    if (email_domain_disposable($domain)) return 'disposable';
    if (!email_domain_resolvable($domain)) return 'domain';
    return null;
}

/** The domain half, lowercased, or null when there is not exactly one sane one. */
function email_domain_of(string $email): ?string {
    $at = strrpos($email, '@');
    if ($at === false) return null;
    $domain = strtolower(trim(substr($email, $at + 1), '.'));
    return $domain === '' ? null : $domain;
}

/**
 * Whether the domain belongs to a throwaway-mailbox provider.
 *
 * Parent domains are checked too: several of these hand out an unlimited supply of
 * subdomains, so blocking only the exact name blocks nothing.
 *
 * The list is a starting point, not a complete one -- it is an arms race nobody wins, and
 * this is deliberately the cheap 90%. Refresh it from a public list now and then.
 */
function email_domain_disposable(string $domain): bool {
    static $blocked;
    if ($blocked === null) {
        $blocked = [];
        foreach (@file(EMAIL_DISPOSABLE_LIST, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = strtolower(trim($line));
            if ($line === '' || str_starts_with($line, '#')) continue;
            $blocked[$line] = true;
        }
    }
    $labels = explode('.', $domain);
    // foo.bar.mailinator.com -> checks foo.bar.mailinator.com, bar.mailinator.com,
    // mailinator.com. Stops at two labels: a single label is never a registrable domain.
    for ($i = 0; $i <= count($labels) - 2; $i++) {
        if (isset($blocked[implode('.', array_slice($labels, $i))])) return true;
    }
    return false;
}

/**
 * Whether anything on the internet accepts mail for this domain.
 *
 * Fails open. checkdnsrr() answers false both for "this domain has no such record" and for
 * "the resolver did not reply", and turning every registration away because the VPS lost
 * its DNS for a minute is a far worse outcome than letting a made-up domain through. So
 * before rejecting, ask for a domain that certainly resolves: if that one fails too, the
 * problem is at this end and the address gets the benefit of the doubt.
 */
function email_domain_resolvable(string $domain): bool {
    if (email_domain_has_records($domain)) return true;
    return !email_domain_has_records(EMAIL_DNS_SENTINEL);
}

function email_domain_has_records(string $domain): bool {
    static $cache = [];
    // RFC 5321 s5.1: with no MX, the address record is the implicit mail exchanger, and
    // plenty of small domains are set up exactly that way.
    return $cache[$domain] ??= checkdnsrr($domain, 'MX') || checkdnsrr($domain, 'A') || checkdnsrr($domain, 'AAAA');
}
