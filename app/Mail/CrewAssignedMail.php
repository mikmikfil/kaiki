<?php

declare(strict_types=1);

namespace App\Mail;

use App\Domain\Branding\Actions\GetBrandPayload;
use App\Filament\App\Resources\DepartureResource;
use App\Mail\Support\OperatorSender;
use App\Models\Departure;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * «Είστε στο πλήρωμα» — to a person just put on a departure (Mike,
 * 2026-09-24: an email when somebody is added to a crew).
 *
 * Only to people newly added: saving a departure again sends nothing to those
 * already on it. In their own language, from the operator, like the staff
 * invitation it is modelled on; both bodies, always (NTF-6).
 */
class CrewAssignedMail extends Mailable
{
    public function __construct(
        public readonly User $member,
        public readonly Departure $departure,
        public readonly bool $asCaptain,
    ) {
        $this->locale($member->locale ?? config('app.locale'));
    }

    public function envelope(): Envelope
    {
        $tenant = $this->tenant();

        return new Envelope(
            from: OperatorSender::from($tenant),
            replyTo: OperatorSender::replyTo($tenant),
            subject: __($this->asCaptain ? 'availability.departure.crew.mail.subject_captain' : 'availability.departure.crew.mail.subject', $this->facts()),
        );
    }

    public function content(): Content
    {
        $tenant = $this->tenant();
        $brand = $tenant instanceof Tenant
            ? app(GetBrandPayload::class)($tenant, $this->member->locale ?? (string) config('app.locale'))
            : [];

        return new Content(
            view: 'mail.staff.crew-assigned-html',
            text: 'mail.staff.crew-assigned-text',
            with: [
                'facts' => $this->facts(),
                'asCaptain' => $this->asCaptain,
                'url' => DepartureResource::getUrl('edit', ['record' => $this->departure], panel: 'app'),
                'accent' => $brand['colors']['primary'] ?? '#123A5E',
            ],
        );
    }

    /** @return array<string, string> */
    private function facts(): array
    {
        $tenant = $this->tenant();

        return [
            'name' => (string) $this->member->name,
            'operator' => $tenant instanceof Tenant ? (string) $tenant->name : (string) config('app.name'),
            'trip' => (string) ($this->departure->product->title),
            'boat' => (string) ($this->departure->vessel->name),
            'date' => $this->departure->local_date->format('d/m/Y'),
            'time' => substr((string) $this->departure->local_time, 0, 5),
            'captain' => (string) ($this->departure->captainName() ?? '—'),
        ];
    }

    private function tenant(): ?Tenant
    {
        return Tenant::query()->find($this->departure->tenant_id);
    }
}
