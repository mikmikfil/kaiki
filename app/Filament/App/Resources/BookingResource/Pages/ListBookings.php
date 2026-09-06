<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\BookingResource\Pages;

use App\Filament\App\Resources\BookingResource;
use App\Support\Authorization\Capability;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;

/**
 * The operator's booking list, and BKG-30's way in.
 *
 * The create button says **manual booking**, which is the only kind of booking
 * a person creates from this screen — every other booking arrives through the
 * widget, the API or an import. Labelling it "New booking" would invite an
 * operator to think this is where bookings normally come from.
 */
class ListBookings extends ListRecords
{
    protected static string $resource = BookingResource::class;

    /** @return array<int, CreateAction> */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('bookings.actions.create_manual'))
                ->icon('heroicon-o-phone')
                // TEN-8: taking a booking is managing bookings. Crew reach this
                // list because they need the pax list; they do not create rows.
                ->visible(static fn (): bool => Auth::user()?->hasCapability(Capability::ManageBookings) ?? false),
        ];
    }
}
