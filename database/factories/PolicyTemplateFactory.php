<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PolicyTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PolicyTemplate>
 *
 * A middling ladder by default — free for a day, half back up to a week — so a
 * test that only needs *a* template gets one whose printed lines exercise both
 * branches of {@see PolicyTemplate::ladder()}: the free window and the tiers. A
 * template with an empty ladder would quietly stop covering half of it.
 */
class PolicyTemplateFactory extends Factory
{
    protected $model = PolicyTemplate::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'code' => 'template-' . Str::lower(Str::random(8)),
            'name' => ['el' => 'Δοκιμαστική πολιτική', 'en' => 'Test policy'],
            'summary' => ['el' => 'Για δοκιμή', 'en' => 'For testing'],
            'free_cancellation_hours' => 24,
            'tiers' => [['days_before' => 7, 'refund_percent' => 50]],
            'sort_order' => 0,
            'is_active' => true,
        ];
    }

    /** A ladder with no free window, only thresholds. */
    public function tiersOnly(): self
    {
        return $this->state(fn (): array => ['free_cancellation_hours' => null]);
    }

    /** Retired: kept for the operators who took it, offered to nobody new. */
    public function retired(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
