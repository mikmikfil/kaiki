<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\Actions\Action as PageAction;
use Filament\Actions\ActionGroup as PageActionGroup;
use Filament\Tables\Actions\Action as TableAction;
use Filament\Tables\Actions\ActionGroup as TableActionGroup;

/**
 * One visible action and a «⋯» for the rest (phone audit, 2026-09-23).
 *
 * On a phone every row of a list is a card, and a card that carried
 * «Επεξεργασία · Διαγραφή · Επαναφορά» inline spent two lines of thumb-sized
 * links on things done once a season — with a red «Διαγραφή» alone on the
 * second line, one slip from the thing the operator came for. Edit pages did
 * the same at the top: ~370px of buttons before the first field.
 *
 * The rule, the same on every list and every page, at every width:
 *
 *  - **one** action stays visible — the one the row or page is for (edit a
 *    trip, open a departure's manifest, retry an invoice);
 *  - everything else, and **always** anything destructive, goes behind «⋯».
 *
 * Every width rather than the phone only, because Filament decides a table's
 * actions on the server, which does not know the screen; rendering both sets
 * and hiding one with CSS would register every action twice. A «⋯» on a desktop
 * row is the common pattern, and the row itself still opens the record.
 *
 * An empty group hides itself (Filament's `ActionGroup::isHidden()`), so a
 * list whose only secondary action is invisible for a row shows no «⋯» there.
 */
final class MoreActions
{
    /**
     * @param  array<int, TableAction|TableActionGroup>  $more
     * @return array<int, TableAction|TableActionGroup>
     */
    public static function row(?TableAction $primary, array $more): array
    {
        $group = TableActionGroup::make($more)
            ->label(__('panel.more_actions'))
            ->tooltip(__('panel.more_actions'))
            ->icon('heroicon-m-ellipsis-horizontal')
            ->color('gray');

        return $primary === null ? [$group] : [$primary, $group];
    }

    /**
     * @param  array<int, PageAction>  $visible
     * @param  array<int, PageAction|PageActionGroup>  $more
     * @return array<int, PageAction|PageActionGroup>
     */
    public static function header(array $visible, array $more): array
    {
        return [
            ...$visible,
            PageActionGroup::make($more)
                ->label(__('panel.more_actions'))
                ->tooltip(__('panel.more_actions'))
                ->icon('heroicon-m-ellipsis-horizontal')
                ->color('gray')
                ->button()
                ->hiddenLabel(),
        ];
    }
}
