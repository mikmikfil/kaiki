<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Port;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Port>
 *
 * Both locales are always populated, because `docs/data-model.md` §1.6 requires
 * them and `SearchIndexObserver` refuses a save without them — a factory that
 * produced Greek only would fail on `create()` rather than on an assertion, and
 * every test that merely needed *a port* would be debugging i18n.
 *
 * The Greek is real Greek (ENV-13), and accented, so folding is exercised by
 * default rather than by a test that remembers to type a tonos.
 */
class PortFactory extends Factory
{
    protected $model = Port::class;

    /**
     * Real Athenian and island harbours, so a seeded catalogue reads like an
     * operator's and a screenshot is not full of "Lorem Marina".
     *
     * @var list<array{el: string, en: string}>
     */
    private const HARBOURS = [
        ['el' => 'Μαρίνα Ζέας', 'en' => 'Zea Marina'],
        ['el' => 'Λιμάνι Πειραιά', 'en' => 'Port of Piraeus'],
        ['el' => 'Παλιό Λιμάνι Αίγινας', 'en' => 'Aegina Old Harbour'],
        ['el' => 'Μαρίνα Αλίμου', 'en' => 'Alimos Marina'],
        ['el' => 'Λιμάνι Βουλιαγμένης', 'en' => 'Vouliagmeni Harbour'],
    ];

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $harbour = $this->faker->randomElement(self::HARBOURS);

        return [
            'name' => $harbour,
            'address' => $this->faker->streetAddress(),
            // Roughly the Saronic gulf, at the column's full 7 decimal places,
            // so a precision round-trip has something to lose.
            'lat' => $this->faker->randomFloat(7, 37.4000000, 38.0000000),
            'lng' => $this->faker->randomFloat(7, 23.4000000, 24.0000000),
            'instructions' => [
                'el' => 'Συνάντηση στο μπλε περίπτερο, 15 λεπτά πριν την αναχώρηση.',
                'en' => 'Meet at the blue kiosk, 15 minutes before departure.',
            ],
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    /** A port with no pin — the operator typed an address and nothing else. */
    public function withoutCoordinates(): self
    {
        return $this->state(fn (): array => ['lat' => null, 'lng' => null]);
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    /** @param array{el?: string, en?: string} $name */
    public function named(array $name): self
    {
        return $this->state(fn (): array => ['name' => $name]);
    }
}
