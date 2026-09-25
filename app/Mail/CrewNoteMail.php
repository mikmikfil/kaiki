<?php

declare(strict_types=1);

namespace App\Mail;

use App\Domain\Branding\Actions\GetBrandPayload;
use App\Mail\Support\OperatorSender;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A note to one of the operator's people about where they sail (2026-09-24):
 * put on a schedule, put on one departure, or the 24-hours-before reminder.
 *
 * The staff invitation's construction — from the operator, in the person's own
 * language, both bodies always (NTF-6) — with the words left to each note.
 */
abstract class CrewNoteMail extends Mailable
{
    public function __construct(public readonly User $member)
    {
        $this->locale($member->locale ?? config('app.locale'));
    }

    abstract protected function tenantId(): int;

    abstract protected function subjectLine(): string;

    abstract protected function heading(): string;

    abstract protected function body(): string;

    abstract protected function action(): string;

    abstract protected function url(): string;

    public function envelope(): Envelope
    {
        $tenant = $this->tenant();

        return new Envelope(
            from: OperatorSender::from($tenant),
            replyTo: OperatorSender::replyTo($tenant),
            subject: $this->subjectLine(),
        );
    }

    public function content(): Content
    {
        $tenant = $this->tenant();
        $brand = $tenant instanceof Tenant
            ? app(GetBrandPayload::class)($tenant, $this->member->locale ?? (string) config('app.locale'))
            : [];

        return new Content(
            view: 'mail.staff.crew-note-html',
            text: 'mail.staff.crew-note-text',
            with: [
                'heading' => $this->heading(),
                'body' => $this->body(),
                'action' => $this->action(),
                'url' => $this->url(),
                'operator' => $this->operatorName(),
                'accent' => $brand['colors']['primary'] ?? '#123A5E',
            ],
        );
    }

    protected function operatorName(): string
    {
        $tenant = $this->tenant();

        return $tenant instanceof Tenant ? (string) $tenant->name : (string) config('app.name');
    }

    protected function tenant(): ?Tenant
    {
        return Tenant::query()->find($this->tenantId());
    }
}
