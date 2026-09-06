<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BookingSource;
use App\Enums\EnquiryStatus;
use App\Models\Enquiry;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Enquiry>
 *
 * No `product_id`: a general enquiry is the shape that has to keep working, and
 * a factory that always attached a product would let a null-product bug ship.
 */
class EnquiryFactory extends Factory
{
    protected $model = Enquiry::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'product_id' => null,
            'name' => 'Γιώργος Νικολάου',
            'email' => 'giorgos@example.gr',
            'phone' => '+306912345678',
            'preferred_date' => null,
            'pax' => null,
            'message' => 'Καλησπέρα, κάνετε ημερήσια εκδρομή στη Δήλο;',
            'locale' => 'el',
            'status' => EnquiryStatus::New,
            'source' => BookingSource::Widget,
            'ip_address' => '198.51.100.7',
        ];
    }

    public function spam(): self
    {
        return $this->state(fn (): array => ['status' => EnquiryStatus::Spam]);
    }

    public function answered(): self
    {
        return $this->state(fn (): array => [
            'status' => EnquiryStatus::Answered,
            'answered_at' => now(),
        ]);
    }
}
