<?php

declare(strict_types=1);

namespace App\Mail;

use App\Filament\App\Pages\Calendar;
use App\Filament\App\Resources\ScheduleRuleResource;
use App\Models\ScheduleRule;
use App\Models\User;

/**
 * «Είστε στο πλήρωμα: Απογευματινό ψάρεμα, καθημερινά 15:00» — one email for a
 * whole schedule, not one per departure it makes (2026-09-24).
 */
class CrewScheduleMail extends CrewNoteMail
{
    public function __construct(
        User $member,
        public readonly ScheduleRule $rule,
        public readonly bool $asCaptain,
    ) {
        parent::__construct($member);
    }

    protected function tenantId(): int
    {
        return (int) $this->rule->tenant_id;
    }

    protected function subjectLine(): string
    {
        return __($this->asCaptain ? 'availability.schedule_rule.crew.mail.subject_captain' : 'availability.schedule_rule.crew.mail.subject', $this->facts());
    }

    protected function heading(): string
    {
        return __($this->asCaptain ? 'availability.departure.crew.mail.heading_captain' : 'availability.departure.crew.mail.heading', $this->facts());
    }

    protected function body(): string
    {
        return __($this->asCaptain ? 'availability.schedule_rule.crew.mail.body_captain' : 'availability.schedule_rule.crew.mail.body', $this->facts());
    }

    protected function action(): string
    {
        return __('availability.schedule_rule.crew.mail.action');
    }

    protected function url(): string
    {
        return Calendar::getUrl(panel: 'app');
    }

    /** @return array<string, string> */
    private function facts(): array
    {
        return [
            'name' => (string) $this->member->name,
            'operator' => $this->operatorName(),
            'trip' => (string) ($this->rule->product->title ?? ''),
            'days' => ScheduleRuleResource::daysLabel((int) $this->rule->weekday_mask),
            'time' => substr((string) $this->rule->start_time, 0, 5),
            'from' => $this->rule->valid_from->format('d/m/Y'),
        ];
    }
}
