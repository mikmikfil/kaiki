<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\BookingResource\Pages;

use App\Domain\Booking\Actions\CreateManualBooking;
use App\Exceptions\HoldRefused;
use App\Filament\App\Resources\BookingResource;
use App\Models\Booking;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * BKG-30's manual booking, taken by phone or at a desk.
 *
 * ## The form does not build the row
 *
 * `handleRecordCreation` hands everything to {@see CreateManualBooking}, which
 * runs the same pricing engine the website runs (BKG-31), applies the
 * operator's adjustment into the price snapshot, carries BKG-32's capacity
 * override with its audit row, and marks the booking paid by cash or bank
 * (BKG-33). Filament's default — `Booking::create($data)` — would be a second
 * insert path drifting from the first in exactly the fields that matter: the
 * VAT split, the pax breakdown, the frozen policy.
 *
 * ## A refused hold is a message, not a stack trace
 *
 * The two refusals an operator can actually hit are "those seats are gone" and
 * "that would put more people aboard than the vessel is licensed to carry" —
 * and the second has no override at all (AVL-25). Both come from lang files
 * (CNV-11) and both leave the form filled in, so the operator changes the
 * departure rather than retyping a phone call.
 */
class CreateBooking extends CreateRecord
{
    protected static string $resource = BookingResource::class;

    public function getTitle(): string
    {
        return __('bookings.actions.create_manual');
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return BookingResource::createFromForm($data);
        } catch (HoldRefused $refused) {
            Notification::make()
                ->danger()
                ->title($refused->getMessage())
                ->persistent()
                ->send();

            $this->halt();
        }

        // Unreachable — `halt()` throws. Present because the signature promises
        // a Model and PHP cannot see through the exception.
        return new Booking;
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
