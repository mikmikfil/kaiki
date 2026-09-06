<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\GuestDocumentType;
use App\Models\Booking;
use App\Models\BookingGuest;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BookingGuest>
 *
 * The default is a manifest row **with no name**, which is what confirmation
 * actually creates: rows exist one per person from the moment a booking is
 * confirmed, and the guest-details flow fills them in later. A factory that
 * defaulted to a completed row would make every test of the incomplete path
 * have to undo it.
 */
class BookingGuestFactory extends Factory
{
    protected $model = BookingGuest::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'booking_id' => Booking::factory(),
            'age_band_id' => null,
            'age_band_code' => 'adult',
            'position' => 1,
            'full_name' => null,
            'ticket_code' => strtoupper(Str::random(24)),
            'is_lead' => false,
        ];
    }

    public function lead(string $name = 'Maria Papadopoulou'): self
    {
        return $this->state(fn (): array => ['is_lead' => true, 'position' => 1, 'full_name' => $name]);
    }

    public function named(string $name): self
    {
        return $this->state(fn (): array => ['full_name' => $name]);
    }

    /**
     * With a passport supplied.
     *
     * Obvious nonsense on purpose: a realistic-looking document number in a
     * fixture is one somebody eventually pastes into an issue.
     */
    public function withDocument(): self
    {
        return $this->state(fn (): array => [
            'full_name' => 'Maria Papadopoulou',
            'document_type' => GuestDocumentType::Passport,
            'document_number' => 'TEST-DOC-000000',
            'document_expires_on' => now()->addYears(5)->toDateString(),
        ]);
    }

    /** The retention job has been through: supplied once, gone now. */
    public function purged(): self
    {
        return $this->withDocument()->state(fn (): array => [
            'document_number' => null,
            'document_purged_at' => now()->subDay(),
        ]);
    }
}
