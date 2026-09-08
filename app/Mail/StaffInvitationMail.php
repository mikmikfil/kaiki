<?php

declare(strict_types=1);

namespace App\Mail;

use App\Domain\Branding\Actions\GetBrandPayload;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "Somebody has given you access — choose a password."
 *
 * ## Why a colleague is not a guest
 *
 * {@see GuestMail} carries every message a customer receives and is built
 * around a booking: its subject, its body and its one button all take a
 * `Booking`. An invitation has no booking, goes to a colleague rather than a
 * customer, and its single action is a **credential-setting link**, which is the
 * one link in this product that must not be reused, forwarded or logged. It
 * gets its own class rather than a nullable booking on that one.
 *
 * ## The link is a password-reset token, deliberately
 *
 * Not a bespoke invitation token. Laravel's reset tokens are already hashed at
 * rest, already single-use, already expire on their own schedule, and are
 * already invalidated the moment they are spent. A second token type would be a
 * second chance to get all four wrong, and it would be the one guarding access
 * to an operator's whole business.
 *
 * The practical consequence is that the panel needs its reset routes registered,
 * which is why `passwordReset()` now sits on both panels — an invitation whose
 * link 404s is worse than no invitation, because the owner believes they sent
 * one.
 *
 * ## No password is ever chosen by the person doing the inviting
 *
 * The alternative — an owner typing a password into a form and reading it down
 * the telephone — puts a working credential into a chat window, a notebook and
 * the owner's memory, and it is a credential they can go on using afterwards.
 * The invited person sets their own, and nobody else ever knows it.
 */
class StaffInvitationMail extends Mailable
{
    public function __construct(
        public readonly User $invitee,
        public readonly string $resetUrl,
        public readonly User $invitedBy,
    ) {
        // The colleague's own language, not the inviter's and not whatever
        // locale the worker happens to be holding. A crew member who reads
        // English should not have their first contact with the product be in
        // Greek because the owner's panel was.
        $this->locale($invitee->locale ?? config('app.locale'));
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('staff.invitation.subject', ['operator' => $this->operatorName()]),
        );
    }

    public function content(): Content
    {
        $tenant = Tenancy::current();

        // The invitee's locale, not the request's: the brand payload carries
        // translated strings, and this mail is being rendered inside whatever
        // locale the inviter's panel happened to be in.
        $brand = $tenant instanceof Tenant
            ? app(GetBrandPayload::class)($tenant, $this->invitee->locale ?? (string) config('app.locale'))
            : [];

        $data = [
            'invitee' => $this->invitee,
            'invitedBy' => $this->invitedBy,
            'resetUrl' => $this->resetUrl,
            'operator' => $this->operatorName(),
            'accent' => $brand['colors']['primary'] ?? '#0B4F4A',
        ];

        // Both bodies, always — NTF-6. Set here rather than left to a template
        // so no invitation can ship without a plain-text part.
        return new Content(
            view: 'mail.staff.invitation-html',
            text: 'mail.staff.invitation-text',
            with: $data,
        );
    }

    private function operatorName(): string
    {
        $tenant = Tenancy::current();

        return $tenant instanceof Tenant ? $tenant->name : (string) config('app.name');
    }
}
