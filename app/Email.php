<?php
declare(strict_types=1);

/**
 * The canonical form of an address: the string that answers "is this the same mailbox as
 * one we already have an account for?".
 *
 * A UNIQUE index on the address as typed only catches an exact repeat, and a provider does
 * not work that way. Everything to the left of the @ is interpreted by the receiving server
 * and by nobody else (RFC 5321), so adri@gmail.com, adri+vestia@gmail.com and a.d.r.i@
 * gmail.com are three different strings that Gmail delivers to one inbox. Without this,
 * every per-account limit in the app -- the two analyses an hour, the fifty live shares --
 * costs an abuser one plus sign to reset.
 *
 * Kept next to no other logic on purpose: registration decides identity with it, and a
 * second implementation that disagreed would hand out the duplicate accounts this prevents.
 */

/**
 * Strips the sub-address label from every domain rather than from a list of the providers
 * known to support one.
 *
 * The narrow alternative -- only strip it for Gmail, Outlook and friends -- leaves every
 * provider off the list as an open door, and in Spain that list has to carry hotmail.es,
 * outlook.es and the rest of the country variants or it misses the common case. The cost of
 * going wide is a legitimate mailbox whose name really contains a plus, at a provider that
 * does not do sub-addressing, whose stripped form belongs to somebody else. A local part
 * with a literal plus in it is close to nonexistent; that three-way coincidence is not
 * something to maintain a domain list for.
 */
function email_canonical(string $email): string {
    $email = strtolower(trim($email));
    // The last @ is the separator: a quoted local part may legally contain one.
    $at = strrpos($email, '@');
    if ($at === false || $at === 0 || $at === strlen($email) - 1) return $email;

    $local = substr($email, 0, $at);
    $domain = substr($email, $at + 1);

    // A quoted local part ("a+b"@host) is legal and can hold characters that mean nothing
    // outside the quotes. Reducing one would turn two distinct mailboxes into the same key
    // and lock the second person out, so it is passed through whole.
    if (str_starts_with($local, '"')) return $email;

    // Google's two domains are one namespace: the same account answers at both.
    if ($domain === 'googlemail.com') $domain = 'gmail.com';

    $plus = strpos($local, '+');
    if ($plus !== false) $local = substr($local, 0, $plus);

    // Gmail alone treats dots in the local part as insignificant. It is a decision of
    // Google's, not a standard, and applying it anywhere else would merge the accounts of
    // two unrelated people.
    if ($domain === 'gmail.com') $local = str_replace('.', '', $local);

    // A label-only local part ("+tag@") would canonicalise to an empty mailbox that every
    // other label-only address at that domain also maps to. Leave those as they came in and
    // let the ordinary UNIQUE on the address do the work.
    if ($local === '') return $email;

    return $local . '@' . $domain;
}
