<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\BookingResource\Pages;

use App\Filament\App\Resources\BookingResource;
use App\Support\Authorization\Capability;
use App\Support\Format\MoneyFormatter;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;

/**
 * One booking, read-only.
 *
 * A confirmed booking is not an editable record. Changing its party size, its
 * date or its total in a form would move seats, invalidate a price snapshot and
 * contradict a policy snapshot the guest was shown — each of which has an
 * Action that does it properly. Until those Actions have panel surfaces the
 * honest thing is a view rather than a form that half works.
 *
 * The **money section is absent for crew** rather than empty (TEN-8): a section
 * of blanks still tells somebody the numbers exist and that they are the person
 * not allowed to see them.
 */
class ViewBooking extends ViewRecord
{
    protected static string $resource = BookingResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        $money = static fn (int $state): string => MoneyFormatter::format(
            $state,
            app()->getLocale(),
            MoneyFormatter::currency(),
        );

        return $infolist->schema([
            Section::make(__('bookings.view.trip'))
                ->schema([
                    TextEntry::make('reference')->label(__('bookings.table.reference'))->copyable(),
                    TextEntry::make('product.title')->label(__('bookings.table.product')),
                    TextEntry::make('local_date')->label(__('bookings.table.date'))->date(),
                    TextEntry::make('local_time')->label(__('bookings.view.time')),
                    TextEntry::make('status')->label(__('bookings.table.status'))->badge(),
                    TextEntry::make('source')->label(__('bookings.table.source'))->badge(),
                ])
                ->columns(3),

            Section::make(__('bookings.view.guest'))
                ->schema([
                    TextEntry::make('guest_name')->label(__('bookings.table.guest')),
                    TextEntry::make('guest_email')->label(__('bookings.view.email'))->copyable(),
                    TextEntry::make('guest_phone')->label(__('bookings.view.phone'))->copyable(),
                    TextEntry::make('pax_total')->label(__('bookings.table.pax')),
                    TextEntry::make('special_requests')->label(__('bookings.view.special_requests'))->columnSpanFull(),
                ])
                ->columns(3),

            Section::make(__('bookings.view.money'))
                ->schema([
                    TextEntry::make('total_cents')->label(__('bookings.table.total'))->formatStateUsing($money),
                    TextEntry::make('paid_cents')->label(__('bookings.view.paid'))->formatStateUsing($money),
                    TextEntry::make('balance_cents')->label(__('bookings.view.balance'))->formatStateUsing($money),
                ])
                ->columns(3)
                ->visible(static fn (): bool => Auth::user()?->hasCapability(Capability::ViewFinancials) ?? false),
        ]);
    }
}
