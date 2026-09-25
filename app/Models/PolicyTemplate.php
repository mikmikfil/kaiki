<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasKaikiTranslations;
use Database\Factories\PolicyTemplateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One cancellation ladder the setup guide offers a new operator.
 *
 * Platform-owned, like {@see VatRate}: this is the menu the platform puts in
 * front of an operator on their first afternoon, not data an operator keeps.
 * Choosing one writes them a {@see CancellationPolicy} of their own — see the
 * migration for why that is a copy and not a link.
 *
 * ## The printed ladder is derived, never typed
 *
 * {@see self::ladder()} builds the lines a new operator reads out of the same
 * `free_cancellation_hours` and `tiers` the guide will actually write. Before
 * this table the numbers lived in `Setup::presetLadder()` and the words in
 * `lang/{el,en}/setup.php`, and nothing held them together: the card could
 * promise a full refund at seven days while the policy written behind it said
 * something else, and no test would have noticed. There is now one source and
 * the words come out of it.
 *
 * @property int $id
 * @property string $code
 * @property string $name translatable
 * @property string|null $summary translatable
 * @property int|null $free_cancellation_hours
 * @property list<array{days_before: int, refund_percent: int}> $tiers
 * @property int $sort_order
 * @property bool $is_active
 */
class PolicyTemplate extends Model
{
    /** @use HasFactory<PolicyTemplateFactory> */
    use HasFactory;

    use HasKaikiTranslations;

    protected $guarded = [];

    /** @var list<string> */
    public array $translatable = ['name', 'summary'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tiers' => 'array',
            'free_cancellation_hours' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The templates a new operator is offered, in the order the admin arranged.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOffered(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * The ladder as a person reads it: a threshold and what comes back.
     *
     * Always ends with a zero line, because "and nothing after that" is the
     * half of a cancellation policy people actually get wrong. The free window
     * leads when there is one; the tiers follow, largest threshold first.
     *
     * @return list<array{when: string, refund: string}>
     */
    public function ladder(?string $locale = null): array
    {
        $locale ??= app()->getLocale();
        $lines = [];

        if ($this->free_cancellation_hours !== null) {
            $lines[] = [
                'when' => trans_choice('setup.policy.ladder.free_hours', $this->free_cancellation_hours, ['hours' => $this->free_cancellation_hours], $locale),
                'refund' => '100%',
            ];
        }

        foreach ($this->sortedTiers() as $tier) {
            $lines[] = [
                'when' => trans_choice('setup.policy.ladder.days_before', $tier['days_before'], ['days' => $tier['days_before']], $locale),
                'refund' => $tier['refund_percent'] . '%',
            ];
        }

        $lines[] = [
            'when' => __('setup.policy.ladder.after', [], $locale),
            'refund' => '0%',
        ];

        return $lines;
    }

    /**
     * The ladder largest threshold first, which is the order it is evaluated in
     * and the only order it reads correctly in.
     *
     * Sorted here rather than trusted from the column: the admin repeater lets
     * rows be dragged, and a ladder entered bottom-up would otherwise print
     * upside down while still behaving correctly.
     *
     * @return list<array{days_before: int, refund_percent: int}>
     */
    public function sortedTiers(): array
    {
        $tiers = array_values(array_filter(
            $this->tiers ?? [],
            static fn (mixed $tier): bool => is_array($tier) && isset($tier['days_before'], $tier['refund_percent']),
        ));

        usort($tiers, static fn (array $a, array $b): int => (int) $b['days_before'] <=> (int) $a['days_before']);

        return array_map(static fn (array $tier): array => [
            'days_before' => (int) $tier['days_before'],
            'refund_percent' => (int) $tier['refund_percent'],
        ], $tiers);
    }
}
