<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ExtraPricing;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\BookingExtraFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Translatable\HasTranslations;

/**
 * An extra as a queryable row (`docs/data-model.md` §2.5).
 *
 * The relational twin of `bookings.extras_snapshot`. Both exist because they
 * answer different questions: these rows are what "how many snorkel kits did we
 * sell in July" and the on-request to-do list read; the JSON is the frozen copy
 * that renders a guest's confirmation email a year later after the `extras` row
 * has been deleted.
 *
 * `extra_name` is a **translatable snapshot** — the guest bought the thing that
 * was called this, in the language they were reading. It carries no search or
 * sort companion (ADR-0008), because nothing sorts a booking's extras: they are
 * displayed in the order they were bought.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $booking_id
 * @property int|null $extra_id
 * @property array<string, string>|string $extra_name
 * @property ExtraPricing $pricing_type
 * @property int $qty
 * @property int $unit_price_cents
 * @property int $total_cents
 * @property bool $is_on_request
 * @property Carbon|null $fulfilled_at
 */
class BookingExtra extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<BookingExtraFactory> */
    use HasFactory;

    use HasTranslations;

    protected $guarded = [];

    /** @var list<string> */
    public array $translatable = ['extra_name'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'pricing_type' => ExtraPricing::class,
            'qty' => 'integer',
            'unit_price_cents' => 'integer',
            'total_cents' => 'integer',
            'is_on_request' => 'boolean',
            'fulfilled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return BelongsTo<Extra, $this> */
    public function extra(): BelongsTo
    {
        return $this->belongsTo(Extra::class);
    }

    /**
     * Does this line contribute to the booking total?
     *
     * An on-request extra has no price until the operator agrees one, so it
     * contributes nothing. Counting it at zero and correcting later is how a
     * guest ends up charged for something the checkout showed as free.
     */
    public function countsTowardTotal(): bool
    {
        return ! $this->is_on_request;
    }

    /** Still waiting on the operator to say yes and set a price. */
    public function isAwaitingFulfilment(): bool
    {
        return $this->is_on_request && $this->fulfilled_at === null;
    }
}
