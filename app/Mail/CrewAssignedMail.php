<?php

declare(strict_types=1);

namespace App\Mail;

use App\Filament\App\Resources\DepartureResource;
use App\Models\Departure;
use App\Models\User;

/**
 * «Είστε στο πλήρωμα» for one departure — somebody put on it by hand for that
 * day (2026-09-24). Only to people newly added.
 */
class CrewAssignedMail extends CrewNoteMail
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
        return __($this->asCaptain ? 'availability.departure.crew.mail.subject_captain' : 'availability.departure.crew.mail.subject', $this->facts());
    }

    protected function heading(): string
    {
        return __($this->asCaptain ? 'availability.departure.crew.mail.heading_captain' : 'availability.departure.crew.mail.heading', $this->facts());
    }

    protected function body(): string
    {
        return __($this->asCaptain ? 'availability.departure.crew.mail.body_captain' : 'availability.departure.crew.mail.body', $this->facts());
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
            'name' => (string) $this->member->name,
            'operator' => $this->operatorName(),
            'trip' => (string) $this->departure->product->title,
            'boat' => (string) $this->departure->vessel->name,
            'date' => $this->departure->local_date->format('d/m/Y'),
            'time' => substr((string) $this->departure->local_time, 0, 5),
            'captain' => (string) ($this->departure->captainName() ?? '—'),
        ];
    }
}
