<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Booking;
use App\Models\BookingAnswer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookingAnswer>
 *
 * «Ναι» to a per-booking yes/no, with the question copied as checkout copies it.
 */
class BookingAnswerFactory extends Factory
{
    protected $model = BookingAnswer::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'booking_id' => Booking::factory(),
            'booking_guest_id' => null,
            'trip_question_id' => null,
            'question' => [
                'uuid' => null,
                'label' => ['el' => 'Χρειάζεστε μεταφορά;', 'en' => 'Do you need a transfer?'],
                'type' => 'yes_no',
                'scope' => 'per_booking',
                'options' => [],
            ],
            'answer' => 'yes',
        ];
    }
}
