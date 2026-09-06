<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BookingSource;
use App\Enums\EnquiryStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Database\Factories\EnquiryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * "Ask a question" (`docs/data-model.md` §2.5, spec BKG-28 FIXED, BKG-29).
 *
 * ## It is not a booking, and it never touches availability
 *
 * BKG-28's second clause is the load-bearing one. There is no departure here,
 * no hold, no counter and no relation that could reach one — `product_id` is
 * nullable and is a label, not a subject. `EnquiryEndpointTest` counts queries
 * against the availability tables and asserts zero, because "well, we know the
 * product and the date, so let us check" is one helpful line away and would put
 * the most spam-exposed endpoint in the system on the engine's hot path.
 *
 * ## The honeypot is not a property of this model
 *
 * §2.5: *"a honeypot field that is never persisted."* `company_website` is
 * validated and discarded by the form request; there is no column, no cast and
 * no accessor. A stored honeypot value is a column nobody remembers the purpose
 * of, and in three years somebody renders it on a screen.
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int|null $product_id
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property Carbon|null $preferred_date
 * @property int|null $pax
 * @property string $message
 * @property string $locale
 * @property EnquiryStatus $status
 * @property int|null $converted_booking_id
 * @property Carbon|null $answered_at
 * @property int|null $assigned_user_id
 * @property BookingSource $source
 * @property string|null $ip_address
 * @property string|null $user_agent
 */
class Enquiry extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<EnquiryFactory> */
    use HasFactory;

    use HasUuid;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => EnquiryStatus::class,
            'source' => BookingSource::class,
            // A **local** date (§1.5): the guest means "the fourth of July",
            // not an instant, so it is never cast to a datetime.
            'preferred_date' => 'date',
            'pax' => 'integer',
            'answered_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Booking, $this> */
    public function convertedBooking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'converted_booking_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /**
     * Everything the operator's inbox counts (§2.5).
     *
     * Spam is excluded rather than deleted — see {@see EnquiryStatus}. An
     * operator whose dashboard counted it would be told they have forty
     * messages waiting, of which thirty-eight are for a Canadian pharmacy.
     *
     * @param  Builder<Enquiry>  $query
     * @return Builder<Enquiry>
     */
    public function scopeCountable(Builder $query): Builder
    {
        return $query->where('status', '!=', EnquiryStatus::Spam->value);
    }
}
