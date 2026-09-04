<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\IcalFeed;
use App\Models\Vessel;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<IcalFeed> */
class IcalFeedFactory extends Factory
{
    protected $model = IcalFeed::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'vessel_id' => Vessel::factory(),
            'token' => IcalFeed::generateToken(),
            'include_departures' => true,
            'include_blocks' => true,
            // Off, as the column default is: an unauthenticated URL must not
            // leak a passenger list unless the operator opted in.
            'include_guest_names' => false,
            'is_active' => true,
            'access_count' => 0,
        ];
    }

    public function withGuestNames(): self
    {
        return $this->state(fn (): array => ['include_guest_names' => true]);
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
