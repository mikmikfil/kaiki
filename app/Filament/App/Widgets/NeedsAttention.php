<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets;

use App\Domain\Operations\Support\AttentionItem;
use App\Domain\Operations\Support\AttentionItems;
use App\Domain\Operations\Support\FirstSteps;
use App\Support\Tenancy;
use Filament\Widgets\Widget;

/**
 * «Χρειάζονται προσοχή» — the decisions waiting on a person (spec OPS-1).
 *
 * ## Why this is not the failure feed
 *
 * OPS-21's feed collects things the *system* could not do — a refused payment,
 * a myDATA submission that bounced — and every row there ends in a retry. This
 * panel collects things the system did correctly and cannot finish alone: a
 * boat short of its minimum, a passport that has not arrived, money past its
 * date. Nothing here can be retried, because none of it is broken. Every row is
 * a question for the operator.
 *
 * Keeping them apart is what stops the list becoming unskimmable — a mixed list
 * where half the rows want a click and half want a judgement is one an operator
 * stops reading.
 *
 * ## It disappears when it is empty
 *
 * Deliberately, and it is the same argument {@see FirstSteps} makes about six
 * zeros: a permanently visible panel saying "nothing needs attention" trains
 * somebody to stop looking at that part of the screen, and then it is invisible
 * on the morning it finally has something in it. An empty panel is not
 * reassurance; it is furniture.
 */
class NeedsAttention extends Widget
{
    protected static string $view = 'filament.app.widgets.needs-attention';

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    /**
     * Refreshed on the same cadence as the rest of the dashboard.
     *
     * A minute rather than ten seconds: nothing on this list changes in
     * seconds, and a panel that re-queries five tables every ten seconds is a
     * database load an operator never asked for.
     */
    protected static ?string $pollingInterval = '60s';

    public static function canView(): bool
    {
        // Hidden on a brand-new account for the same reason the figures are:
        // FirstSteps owns that screen, and an attention list with nothing in it
        // says less than the four steps that get somebody started.
        return ! FirstSteps::applies() && self::items() !== [];
    }

    /**
     * @return list<AttentionItem>
     */
    public function getItems(): array
    {
        return self::items();
    }

    /**
     * @return list<AttentionItem>
     */
    private static function items(): array
    {
        $tenant = Tenancy::current();

        if ($tenant === null) {
            return [];
        }

        return (new AttentionItems($tenant->timezone))->all();
    }
}
