<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A myDATA document (spec MYD-1…MYD-16, `docs/data-model.md` §4.6).
 *
 * ## Never deleted, by anything
 *
 * §1.4 lists `invoices` among the rows no code path removes — a financial and
 * legal record, corrected by a new row or a status change and never by a
 * `DELETE`. There is no `SoftDeletes` here and there must not be: a soft delete
 * is a delete an operator can perform, and this is a document in a tax register.
 * `booking_id` is `restrictOnDelete` for the same reason.
 *
 * ## `number` is null until the document is actually sent
 *
 * MYD-4.2. The number is allocated at the send attempt, so a `pending` row that
 * is never submitted does not burn one. **A `pending` row carrying a number is a
 * bug**, and {@see self::hasNumber()} is deliberately not called "is it valid" —
 * the two states are ordinary at different moments in the same row's life.
 *
 * ## `sent` means AADE said so
 *
 * MYD-5: the transition needs a response carrying a MARK. Not a 200, not a job
 * that completed. {@see self::isRegistered()} reads the status; the status is
 * only ever set by the code that saw the MARK.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $booking_id
 * @property InvoiceType $type
 * @property int|null $cancels_invoice_id
 * @property string $series
 * @property int|null $number null until allocated at the send attempt (MYD-4.2)
 * @property int $year
 * @property Carbon|null $issued_at
 * @property string|null $mark
 * @property string|null $uid
 * @property string|null $qr_url
 * @property int $net_cents
 * @property int $vat_cents
 * @property int $total_cents
 * @property int $vat_rate_bp
 * @property string|null $vat_category
 * @property string|null $income_classification
 * @property string|null $counterparty_vat
 * @property string|null $counterparty_name
 * @property string|null $counterparty_country
 * @property InvoiceStatus $status
 * @property string|null $last_error_code
 * @property string|null $last_error_message
 * @property string|null $last_error_message_el
 * @property int $retries
 * @property Carbon|null $next_retry_at
 * @property string|null $pdf_path
 * @property string $environment
 * @property int|null $issued_by_user_id
 */
class Invoice extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    use HasUuid;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => InvoiceType::class,
            'status' => InvoiceStatus::class,
            'issued_at' => 'datetime',
            'next_retry_at' => 'datetime',
            'number' => 'integer',
            'year' => 'integer',
            'net_cents' => 'integer',
            'vat_cents' => 'integer',
            'total_cents' => 'integer',
            'vat_rate_bp' => 'integer',
            'retries' => 'integer',
        ];
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * The document this one undoes, when it is a credit note (MYD-13).
     *
     * @return BelongsTo<Invoice, $this>
     */
    public function cancels(): BelongsTo
    {
        return $this->belongsTo(self::class, 'cancels_invoice_id');
    }

    /**
     * The credit notes raised against this one.
     *
     * Plural because a partial refund followed by the rest is two documents, and
     * a schema that assumed one would make the second impossible to record.
     *
     * @return HasMany<Invoice, $this>
     */
    public function creditNotes(): HasMany
    {
        return $this->hasMany(self::class, 'cancels_invoice_id');
    }

    /** @return BelongsTo<User, $this> */
    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }

    /** Has a number been allocated to this document yet? */
    public function hasNumber(): bool
    {
        return $this->number !== null;
    }

    /** Has AADE accepted it? */
    public function isRegistered(): bool
    {
        return $this->status === InvoiceStatus::Sent;
    }

    /**
     * The document reference an operator reads down the telephone.
     *
     * «ΑΛΠ Α/2026/41». The year is in it because the series resets annually and
     * a bare «Α/41» is ambiguous the moment a second January arrives.
     */
    public function reference(): string
    {
        if (! $this->hasNumber()) {
            return $this->type->code();
        }

        return sprintf('%s %s/%d/%d', $this->type->code(), $this->series, $this->year, $this->number);
    }

    /**
     * Is this one waiting for the sweeper to try it again?
     *
     * Both halves matter: a `pending` row with no `next_retry_at` has never been
     * attempted and is waiting for its first run, not for a retry.
     */
    public function isAwaitingRetry(?Carbon $now = null): bool
    {
        return $this->status === InvoiceStatus::Pending
            && $this->next_retry_at !== null
            && $this->next_retry_at->lessThanOrEqualTo($now ?? Carbon::now());
    }
}
