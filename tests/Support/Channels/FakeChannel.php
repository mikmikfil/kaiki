<?php

declare(strict_types=1);

namespace Tests\Support\Channels;

use App\Contracts\Channel;
use App\Domain\Channels\Data\ChannelResult;
use App\Enums\ChannelKey;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Product;
use App\Models\Tenant;

/**
 * A channel that answers however a test needs it to.
 *
 * GetYourGuide will not take a call from a test runner, and will not take one
 * from anybody until their certification passes — so every assertion about
 * pushing, reconciling and giving up has to be made against something. This is
 * that something, and it is why {@see Channel} is an interface rather than a
 * class its callers construct.
 *
 * ## It records what it was asked, not only what it answered
 *
 * `$pushed` is the point. The rule ADR-0034 leans on is that **a seat movement
 * pushes availability**, and from a `ChannelResult` alone a test cannot tell a
 * caller that pushed once from one that pushed never — both are silence. The
 * recorded departure ids are what make "after this booking, that departure was
 * pushed" an assertion instead of a hope.
 */
final class FakeChannel implements Channel
{
    /** @var list<int> departure ids, in the order they were pushed */
    public array $pushed = [];

    /** @var list<int> tenant ids `pullBookings()` was called for */
    public array $pulled = [];

    /** @var list<int> booking ids a cancellation was acknowledged for */
    public array $acknowledged = [];

    /** @var array<string, Product> external product id => what it maps to */
    private array $products = [];

    /** @var list<ChannelResult> */
    private array $answers = [];

    public function __construct(private readonly ChannelKey $key = ChannelKey::GetYourGuide) {}

    /** Answer with these, once per call, in order. The last one repeats. */
    public function willAnswer(ChannelResult ...$results): self
    {
        $this->answers = array_merge($this->answers, $results);

        return $this;
    }

    /** Pretend this external id is mapped to this product. */
    public function mapping(string $externalProductId, Product $product): self
    {
        $this->products[$externalProductId] = $product;

        return $this;
    }

    public function key(): ChannelKey
    {
        return $this->key;
    }

    public function pushAvailability(Departure $departure): ChannelResult
    {
        $this->pushed[] = (int) $departure->getKey();

        return $this->nextAnswer(count($this->pushed) - 1);
    }

    public function pullBookings(Tenant $tenant): ChannelResult
    {
        $this->pulled[] = (int) $tenant->getKey();

        return $this->nextAnswer(count($this->pulled) - 1);
    }

    public function productFor(Tenant $tenant, string $externalProductId): ?Product
    {
        return $this->products[$externalProductId] ?? null;
    }

    public function acknowledgeCancellation(Booking $booking): ChannelResult
    {
        $this->acknowledged[] = (int) $booking->getKey();

        return $this->nextAnswer(count($this->acknowledged) - 1);
    }

    /** How many times availability was pushed for one departure. */
    public function pushCountFor(Departure $departure): int
    {
        return count(array_filter(
            $this->pushed,
            fn (int $id): bool => $id === (int) $departure->getKey(),
        ));
    }

    private function nextAnswer(int $index): ChannelResult
    {
        if ($this->answers === []) {
            return ChannelResult::done();
        }

        return $this->answers[$index] ?? end($this->answers);
    }
}
