<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Data\Availability\AvailabilityDayData;
use App\Data\Availability\AvailabilityRequestData;
use App\Data\Availability\VesselWindowData;
use App\Domain\Availability\Actions\CheckSeatAvailability;
use App\Domain\Availability\Actions\CheckVesselAvailability;
use App\Enums\ProductStatus;
use App\Models\AgeBand;
use App\Models\Departure;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\Vessel;

/**
 * A readable fixture for an availability scenario.
 *
 * ## Why a builder rather than a helper function per test
 *
 * Every edge case here needs the same six rows — a vessel with a certificate
 * and a turnaround, a product with a duration, two age bands, a rate plan, a
 * departure — and differs in one of them. Written inline that is fifty lines
 * per case, and the one line that matters is lost in the middle of it.
 *
 * The point of #32 is that each case reads as the requirement it comes from.
 * `->onVessel(certificate: 6)->departingAt('09:00')` is the scenario; the rest
 * is scaffolding that should be invisible.
 *
 * ## It builds rows, never expectations
 *
 * Deliberately no assertion helpers. A builder that could say "expect this to
 * be available" would let a case assert the implementation's own answer back at
 * itself, which is exactly the failure mode #32 exists to avoid — the issue
 * says so: *"writing them alongside the feature tends to produce tests that
 * assert the implementation rather than the specification."*
 */
final class AvailabilityScenarioBuilder
{
    private ?Vessel $vessel = null;

    private ?Product $product = null;

    private int $certificate = 12;

    private int $bufferMinutes = 60;

    private int $durationMinutes = 240;

    private bool $perVessel = false;

    private ?string $defaultStartTime = '09:00';

    private bool $flexibleStart = false;

    private int $seats = 10;

    public static function make(): self
    {
        return new self;
    }

    /** The boat: its legal certificate and its turnaround (AVL-7, AVL-25). */
    public function onVessel(int $certificate = 12, int $bufferMinutes = 60): self
    {
        $this->certificate = $certificate;
        $this->bufferMinutes = $bufferMinutes;

        return $this;
    }

    public function lasting(int $minutes): self
    {
        $this->durationMinutes = $minutes;

        return $this;
    }

    public function sellingSeats(int $seats): self
    {
        $this->seats = $seats;

        return $this;
    }

    /** A whole-boat charter rather than a shared sailing. */
    public function asCharter(?string $defaultStartTime = '10:00', bool $flexibleStart = false): self
    {
        $this->perVessel = true;
        $this->defaultStartTime = $defaultStartTime;
        $this->flexibleStart = $flexibleStart;

        return $this;
    }

    public function departingAt(string $localTime): self
    {
        $this->defaultStartTime = $localTime;

        return $this;
    }

    /** The vessel, created on first use so several products can share it. */
    public function vessel(): Vessel
    {
        return $this->vessel ??= Vessel::factory()->create([
            'capacity_max' => $this->certificate,
            'turnaround_buffer_minutes' => $this->bufferMinutes,
        ]);
    }

    /** The product, with its bands and a default rate plan. */
    public function product(): Product
    {
        if ($this->product instanceof Product) {
            return $this->product;
        }

        $factory = $this->perVessel ? Product::factory()->perVessel() : Product::factory();

        $product = $factory->create([
            'vessel_id' => $this->vessel()->getKey(),
            'status' => ProductStatus::Active,
            'duration_minutes' => $this->durationMinutes,
            'default_start_time' => $this->defaultStartTime,
            'flexible_start' => $this->flexibleStart,
            'max_pax' => $this->seats,
            'min_pax' => 0,
        ]);

        // Adult and infant: the pair every AVL-23 and AVL-25 case needs, and
        // the reason those two checks can disagree.
        AgeBand::factory()->create(['product_id' => $product->getKey()]);
        AgeBand::factory()->infant()->create(['product_id' => $product->getKey()]);

        RatePlan::factory()->create(['product_id' => $product->getKey()]);

        return $this->product = $product->load(['vessel', 'ageBands']);
    }

    /** A second product on the same boat — the shape most conflict cases need. */
    public function sharedProductOnSameVessel(int $seats = 10): Product
    {
        $product = Product::factory()->create([
            'vessel_id' => $this->vessel()->getKey(),
            'status' => ProductStatus::Active,
            'duration_minutes' => $this->durationMinutes,
            'max_pax' => $seats,
            'min_pax' => 0,
        ]);

        AgeBand::factory()->create(['product_id' => $product->getKey()]);

        return $product->load(['vessel', 'ageBands']);
    }

    /**
     * A departure of `$product`, at a local date and time.
     *
     * The duration is the product's, never the factory's default — a departure
     * running four hours longer than its own product would overlap everything
     * in sight and make a buffer assertion pass for the wrong reason.
     */
    public function departure(Product $product, string $localDate, string $localTime, int $seatsSold = 0, int $seatsHeld = 0): Departure
    {
        $departure = Departure::factory()
            ->at($localDate, $localTime, (int) $product->duration_minutes)
            ->create([
                'product_id' => $product->getKey(),
                'vessel_id' => $product->vessel_id,
                'capacity' => (int) $product->max_pax,
            ]);

        if ($seatsSold > 0 || $seatsHeld > 0) {
            $departure->forceFill(['seats_sold' => $seatsSold, 'seats_held' => $seatsHeld])->saveQuietly();
        }

        return $departure->refresh();
    }

    /**
     * Ask the per-seat service.
     *
     * @param  array<string, int>  $pax
     * @return list<AvailabilityDayData>
     */
    public function checkSeats(Product $product, string $from, ?string $to = null, array $pax = ['adult' => 2]): array
    {
        return app(CheckSeatAvailability::class)(
            $product,
            AvailabilityRequestData::forRange($from, $to ?? $from, $pax),
        );
    }

    /**
     * Ask the whole-boat service.
     *
     * @return list<VesselWindowData>
     */
    public function checkVessel(Product $product, string $from, ?string $to = null, ?string $startTime = null, int $extraHours = 0): array
    {
        return app(CheckVesselAvailability::class)(
            $product,
            AvailabilityRequestData::forRange($from, $to ?? $from),
            $startTime,
            $extraHours,
        );
    }
}
