<?php

declare(strict_types=1);

namespace App\Mail;

use App\Filament\App\Resources\DepartureResource;
use App\Models\Departure;
use App\Models\User;

/**
 * «Αύριο 15:00, Γαλήνη» — 24 hours before a departure, to its captain and crew
 * (Mike, 2026-09-24: «24 hours before»). The same words will be the phone
 * notification once push exists.
 */
class CrewReminderMail extends CrewNoteMail
{
    public function __construct(
        User $member,
        public readonly Departure $departure,
        public readonly bool $asCaptain,
    ) {
        parent::__construct($member);
    }

    protected function tenantId(): int
    {
        return (int) $this->departure->tenant_id;
    }

    protected function subjectLine(): string
    {
        return __('availability.departure.crew.reminder.subject', $this->facts());
    }

    protected function heading(): string
    {
        return __('availability.departure.crew.reminder.subject', $this->facts());
    }

    protected function body(): string
    {
        return __($this->asCaptain ? 'availability.departure.crew.reminder.body_captain' : 'availability.departure.crew.reminder.body', $this->facts());
    }

    protected function action(): string
    {
        return __('availability.departure.crew.mail.action');
    }

    protected function url(): string
    {
        return DepartureResource::getUrl('edit', ['record' => $this->departure], panel: 'app');
    }

    /** @return array<string, string> */
    private function facts(): array
    {
        return [
            'trip' => (string) $this->departure->product->title,
            'boat' => (string) $this->departure->vessel->name,
            'date' => $this->departure->local_date->format('d/m/Y'),
            'time' => substr((string) $this->departure->local_time, 0, 5),
            'port' => (string) ($this->departure->product->meetingPoint->name ?? ''),
            'captain' => (string) ($this->departure->captainName() ?? '—'),
            'pax' => (string) $this->departure->seats_sold,
        ];
    }
}
