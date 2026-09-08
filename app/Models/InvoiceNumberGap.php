<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\InvoiceNumberGapFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A number that left the counter and never reached a document (spec MYD-4.4).
 *
 * Written once and never touched again — the same rule as `invoices`, and for a
 * sharper reason: a gap that could be tidied away is a gap that will be, on the
 * afternoon it looks embarrassing, which is exactly when the record matters.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $series
 * @property int $year
 * @property int $number
 * @property int|null $invoice_id
 * @property string $reason_code
 * @property string|null $reason_detail
 */
class InvoiceNumberGap extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<InvoiceNumberGapFactory> */
    use HasFactory;

    /** The send was refused permanently — AADE will never accept this payload. */
    public const REASON_PERMANENT_REJECTION = 'permanent_rejection';

    /** The attempts ran out (MYD-10) after the number had been taken. */
    public const REASON_RETRIES_EXHAUSTED = 'retries_exhausted';

    /** The operator cancelled the document between allocation and send. */
    public const REASON_CANCELLED_BEFORE_SEND = 'cancelled_before_send';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'number' => 'integer',
        ];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * «Α/2026/41», the way an accountant asks about it.
     */
    public function reference(): string
    {
        return sprintf('%s/%d/%d', $this->series, $this->year, $this->number);
    }
}
