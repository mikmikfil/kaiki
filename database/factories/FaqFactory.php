<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Faq;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Faq>
 *
 * The default is **tenant-wide**, because that is the common case in the
 * product and a factory whose default named a product would make every test
 * that forgot to say so a test about the exception.
 */
class FaqFactory extends Factory
{
    protected $model = Faq::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'product_id' => null,
            'question' => [
                'el' => 'Χρειάζεται να ξέρω κολύμπι;',
                'en' => 'Do I need to know how to swim?',
            ],
            'answer' => [
                'el' => "Όχι. Δίνουμε σωσίβια σε όλους και η στάση για μπάνιο είναι προαιρετική.\n\nΤο πλήρωμα είναι δίπλα σας σε όλη τη διαδρομή.",
                'en' => "No. We hand out life jackets to everyone and the swimming stop is optional.\n\nThe crew is beside you the whole way.",
            ],
            'sort_order' => 0,
            'is_published' => true,
        ];
    }

    /** An entry about one trip rather than about the operator. */
    public function forProduct(Product $product): self
    {
        return $this->state(fn (): array => ['product_id' => $product->getKey()]);
    }

    public function unpublished(): self
    {
        return $this->state(fn (): array => ['is_published' => false]);
    }

    public function at(int $position): self
    {
        return $this->state(fn (): array => ['sort_order' => $position]);
    }

    /** A question and answer a test cares about the wording of. */
    public function asking(string $greek, string $english, ?string $answerEl = null, ?string $answerEn = null): self
    {
        return $this->state(fn (): array => [
            'question' => ['el' => $greek, 'en' => $english],
            'answer' => [
                'el' => $answerEl ?? "Απάντηση: {$greek}",
                'en' => $answerEn ?? "Answer: {$english}",
            ],
        ]);
    }
}
