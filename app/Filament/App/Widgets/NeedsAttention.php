<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets;

use App\Domain\Operations\Support\AttentionItem;
use App\Domain\Operations\Support\AttentionItems;
use App\Domain\Operations\Support\FirstSteps;
use App\Filament\App\Pages\CalendarSync;
use App\Filament\App\Resources\BookingResource;
use App\Filament\App\Resources\DepartureResource;
use App\Filament\App\Resources\QuoteResource;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\IcalSource;
use App\Models\Quote;
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
 * date. Every row is a question for the operator.
 *
 * ## Every row leads somewhere (product owner, 2026-09-17)
 *
 * «Τι νόημα έχει αν δεν μπορείς να κάνεις τίποτα από εκεί;» The rows used to
 * say what was wrong and stop. Now each one is a box that opens the exact
 * screen where it is dealt with, and says in its button what that is:
 *
 * | Item                         | Opens                                  |
 * |------------------------------|----------------------------------------|
 * | Departure under its minimum  | that departure (cancel, move, manifest) |
 * | Missing passenger details    | that booking, and a call button         |
 * | Balance past its due date    | that booking (record payment), and call |
 * | Quote about to lapse         | that quote                              |
 * | Calendar feed failing        | «Συγχρονισμός ημερολογίων»              |
 *
 * A row whose screen this person may not open is left out: a decision they
 * cannot act on is exactly the complaint. Five show at first, soonest deadline
 * first, and «Όλα (N)» opens the rest in place.
 *
 * ## It disappears when it is empty
 *
 * A permanently visible panel saying "nothing needs attention" trains somebody
 * to stop looking at that part of the screen, and then it is invisible on the
 * morning it finally has something in it.
 */
class NeedsAttention extends Widget
{
    protected static string $view = 'filament.app.widgets.needs-attention';

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    /** How many rows show before «Όλα (N)». */
    public const FIRST = 5;

    public bool $showAll = false;

    /**
     * Refreshed on the same cadence as the rest of the dashboard: nothing on
     * this list changes in seconds.
     */
    protected static ?string $pollingInterval = '60s';

    public static function canView(): bool
    {
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
     * The rows this person can act on, each with where it leads.
     *
     * @return list<array{item: AttentionItem, url: string, action: string, phone: string|null}>
     */
    public function getRows(): array
    {
        $rows = [];

        foreach (self::items(everything: true) as $item) {
            $row = self::actionFor($item);

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    public function toggleAll(): void
    {
        $this->showAll = ! $this->showAll;
    }

    /**
     * Where one item is fixed, or null when this person cannot go there.
     *
     * @return array{item: AttentionItem, url: string, action: string, phone: string|null}|null
     */
    public static function actionFor(AttentionItem $item): ?array
    {
        $subject = $item->subject;
        $type = strstr($item->key, ':', true) ?: $item->key;

        return match (true) {
            $subject instanceof Departure && DepartureResource::canViewAny() => [
                'item' => $item,
                'url' => DepartureResource::getUrl('edit', ['record' => $subject]),
                'action' => __('attention.actions.departure'),
                'phone' => null,
            ],
            $subject instanceof Booking && BookingResource::canViewAny() => [
                'item' => $item,
                'url' => BookingResource::getUrl('view', ['record' => $subject]),
                'action' => __($type === 'balance' ? 'attention.actions.balance' : 'attention.actions.details'),
                'phone' => is_string($subject->guest_phone) && $subject->guest_phone !== '' ? $subject->guest_phone : null,
            ],
            $subject instanceof Quote && QuoteResource::canViewAny() => [
                'item' => $item,
                'url' => QuoteResource::getUrl('edit', ['record' => $subject]),
                'action' => __('attention.actions.quote'),
                'phone' => null,
            ],
            $subject instanceof IcalSource && CalendarSync::canAccess() => [
                'item' => $item,
                'url' => CalendarSync::getUrl(),
                'action' => __('attention.actions.calendar'),
                'phone' => null,
            ],
            default => null,
        };
    }

    /**
     * @return list<AttentionItem>
     */
    private static function items(bool $everything = false): array
    {
        $tenant = Tenancy::current();

        if ($tenant === null) {
            return [];
        }

        $items = new AttentionItems($tenant->timezone);

        return $everything ? $items->everything() : $items->all();
    }
}
