<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TripQuestionScope;
use App\Enums\TripQuestionType;
use App\Models\Product;
use App\Models\TripQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TripQuestion>
 *
 * A required yes/no, per booking: the commonest question an operator asks.
 */
class TripQuestionFactory extends Factory
{
    protected $model = TripQuestion::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'label' => ['el' => 'Χρειάζεστε μεταφορά από το ξενοδοχείο;', 'en' => 'Do you need a hotel transfer?'],
            'type' => TripQuestionType::YesNo,
            'scope' => TripQuestionScope::PerBooking,
            'options' => null,
            'is_required' => true,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    /** «Μέγεθος στολής», asked of every passenger. */
    public function sizePerPerson(): self
    {
        return $this->state(fn (): array => [
            'label' => ['el' => 'Μέγεθος στολής', 'en' => 'Wetsuit size'],
            'type' => TripQuestionType::Choice,
            'scope' => TripQuestionScope::PerPerson,
            'options' => [['el' => 'Μικρό', 'en' => 'Small'], ['el' => 'Μεγάλο', 'en' => 'Large']],
        ]);
    }
}
