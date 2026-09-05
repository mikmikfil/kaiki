<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\VesselAmenity;
use App\Enums\VesselStatus;
use App\Enums\VesselType;
use App\Models\Port;
use App\Models\Vessel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vessel>
 *
 * `turnaround_buffer_minutes` is **null by default**, which is the inheriting
 * case (AVL-7) and therefore the one most tests should be exercising. A factory
 * that filled it in would make every availability test silently test the
 * override and leave the default path uncovered.
 *
 * `home_port_id` is likewise null: the column is nullable, and creating a Port
 * for every Vessel would make the isolation harness build two rows where it
 * asked for one. Use {@see self::atPort()} when the relation is the point.
 */
class VesselFactory extends Factory
{
    protected $model = Vessel::class;

    /**
     * Greek boat names, accented, so folding is exercised without a test having
     * to remember to type a tonos.
     *
     * @var list<string>
     */
    private const NAMES = ['Οδυσσέας', 'Ποσειδών', 'Γαλήνη', 'Αίολος', 'Θαλασσινή', 'Ναυσικά'];

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            // Unique per tenant (TEN-6), so the suffix stops a factory that
            // builds two vessels for one tenant from hitting the unique index
            // on a name collision it never asked for.
            //
            // The **suffix** carries the uniqueness, not the name. An earlier
            // version wrapped `randomElement` in `unique()`, which caps the
            // factory at six vessels per process — there are six names — and
            // the seventh dies with Faker's "Maximum retries of 10000 reached"
            // rather than anything that names a boat. #36 needed forty.
            'name' => $this->faker->randomElement(self::NAMES) . ' ' . $this->faker->unique()->numberBetween(1, 99999),
            'type' => $this->faker->randomElement(VesselType::cases()),
            'status' => VesselStatus::Active,
            'registration_number' => 'NP ' . $this->faker->numberBetween(1000, 9999),
            'length_cm' => $this->faker->numberBetween(800, 2400),
            'capacity_max' => $this->faker->numberBetween(8, 90),
            'crew_count' => $this->faker->numberBetween(1, 4),
            'captain_name' => null,
            'home_port_id' => null,
            // Null = inherit the tenant's buffer. The common case, on purpose.
            'turnaround_buffer_minutes' => null,
            'description' => [
                'el' => 'Παραδοσιακό σκάφος με σκιά, ηχοσύστημα και ψυγείο.',
                'en' => 'A traditional boat with shade, a sound system and a fridge.',
            ],
            'specs' => [
                'beam_m' => 4.2,
                'year_built' => 1978,
                'engine' => '2 × 180 hp',
                'cruising_speed_kn' => 9,
                'amenities' => [
                    VesselAmenity::ShadeCanopy->value,
                    VesselAmenity::Fridge->value,
                    VesselAmenity::Wc->value,
                ],
            ],
            'images' => [],
            'sort_order' => 0,
        ];
    }

    public function atPort(Port $port): self
    {
        return $this->state(fn (): array => ['home_port_id' => $port->getKey()]);
    }

    /** A vessel that sets its own turnaround rather than inheriting one. */
    public function withBuffer(int $minutes): self
    {
        return $this->state(fn (): array => ['turnaround_buffer_minutes' => $minutes]);
    }

    public function named(string $name): self
    {
        return $this->state(fn (): array => ['name' => $name]);
    }

    public function ofType(VesselType $type): self
    {
        return $this->state(fn (): array => ['type' => $type]);
    }

    public function capacity(int $max): self
    {
        return $this->state(fn (): array => ['capacity_max' => $max]);
    }

    public function inMaintenance(): self
    {
        return $this->state(fn (): array => ['status' => VesselStatus::Maintenance]);
    }
}
