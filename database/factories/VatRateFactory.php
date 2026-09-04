<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\VatRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VatRate>
 *
 * **No default `rate_bp`, and that is a requirement rather than an oversight.**
 *
 * CAT-11b and MYD-6a: no seeder, no factory default and no test fixture may
 * present a percentage as authoritative. A factory that quietly defaulted to
 * `1300` would put 13% into every test that did not think about VAT, and the
 * first person to read one of those tests would reasonably conclude the project
 * had decided the rate. Brief §10 says transport is *typically* 13% and
 * explicitly refuses to fix it; that refusal has to survive contact with the
 * fixtures.
 *
 * So every caller states the rate it is testing with. It costs one array key
 * and it means a rate in a test is always a rate somebody chose for a reason.
 */
class VatRateFactory extends Factory
{
    protected $model = VatRate::class;

    /**
     * A deliberately absurd rate. `1` basis point is 0.01% — no tax authority
     * on earth charges it, so it cannot be mistaken for a real figure, and a
     * test asserting on it is obviously asserting on a fixture.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'test_' . $this->faker->unique()->numberBetween(1, 99999),
            'rate_bp' => 1,
            // AADE category 8, "records without VAT" — the honest category for
            // a rate that is not a rate.
            'vat_category' => '8',
            'description' => [
                'el' => 'Δοκιμαστικός συντελεστής',
                'en' => 'Test rate',
            ],
            'valid_from' => now()->subYear()->toDateString(),
            'valid_to' => null,
            'is_selectable' => true,
        ];
    }

    /** A rate superseded by a later one: still referenced, no longer offered. */
    public function retired(): self
    {
        return $this->state(fn (): array => [
            'valid_to' => now()->subMonth()->toDateString(),
            'is_selectable' => false,
        ]);
    }

    /** An explicit rate, in basis points, for a test that is about the number. */
    public function atBasisPoints(int $rateBp): self
    {
        return $this->state(fn (): array => ['rate_bp' => $rateBp]);
    }
}
