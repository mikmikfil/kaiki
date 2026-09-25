<?php

declare(strict_types=1);

namespace App\Mail\Support;

use App\Models\Tenant;
use Illuminate\Mail\Mailables\Address;

/**
 * Who a message is from, and where the answer goes (decided 2026-09-23).
 *
 * **The platform sends; the operator is the sender a guest sees.** Every
 * message leaves from the one address the platform's mail provider has
 * verified — `MAIL_FROM_ADDRESS` — under the operator's own name, with
 * `Reply-To` set to the operator's email. The guest reads «Aegean Blue» in
 * their inbox, and pressing reply writes to Aegean Blue.
 *
 * The alternative, each operator's own SMTP, was weighed and set aside. A
 * booking confirmation is the one message that must never quietly stop, and an
 * operator's mailbox stops the day its password changes — nobody finds out
 * until a guest rings. A small operator's host also rate-limits in August and
 * lands in spam more often. Sending from their own *domain* stays open without
 * any of that: two DNS records on their side let the platform's provider sign
 * for it, with no password of theirs stored anywhere.
 *
 * Why the name and not the address: a provider refuses, or a receiver
 * quarantines, mail whose `From` domain it cannot verify. `aegean-blue.gr` in
 * the From line of a message our provider sends is exactly that, and it would
 * fail at the worst possible moment — the first real booking.
 */
final class OperatorSender
{
    /** The platform's verified address, carrying the operator's name. */
    public static function from(?Tenant $tenant): Address
    {
        $name = $tenant instanceof Tenant && trim($tenant->name) !== ''
            ? $tenant->name
            : (string) config('mail.from.name');

        return new Address((string) config('mail.from.address'), self::headerSafe($name));
    }

    /**
     * Where a reply goes: the operator, when they have an address that will
     * take it.
     *
     * An empty list rather than the platform's address when they do not — a
     * guest's question sent to a mailbox nobody reads is worse than a reply
     * button that falls back to the From line, which a provider can at least
     * bounce.
     *
     * @return list<Address>
     */
    public static function replyTo(?Tenant $tenant): array
    {
        $email = $tenant instanceof Tenant ? trim((string) $tenant->email) : '';

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return [];
        }

        return [new Address($email, self::headerSafe($tenant->name))];
    }

    /**
     * A display name with nothing in it that could start a new header line.
     *
     * The name is typed by an operator; a line break in it would otherwise be a
     * header of their choosing in every message the platform sends for them.
     */
    private static function headerSafe(string $name): string
    {
        return trim((string) preg_replace('/[\r\n]+/', ' ', $name));
    }
}
