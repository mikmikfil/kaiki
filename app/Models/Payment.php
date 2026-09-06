<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentGatewayName;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Support\Tenancy;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Money that moved (`docs/data-model.md` §2.5, PAY-8, PAY-9, PAY-10).
 *
 * **Never deleted and never soft-deleted.** There is no `SoftDeletes` trait
 * here and there should never be one: a payment that can disappear is a payment
 * an operator cannot reconcile against their own bank statement, and the
 * `restrictOnDelete` on `booking_id` is the same rule pointing the other way.
 *
 * ## `paid_cents` is recomputed from these rows, never incremented
 *
 * PAY-10 says so and the reason is the same one that governs `seats_held`: an
 * increment is only correct if every previous one was, and money that drifts is
 * money somebody has to reconcile by hand. {@see self::paidCentsFor()} is the
 * one place the sum is defined.
 *
 * ## The idempotency key is minted before the call, not after
 *
 * PAY-9. A key generated from the gateway's response cannot dedupe the request
 * that produced it — which is the exact failure it exists to prevent, since the
 * dangerous retry is the one where we never saw a response at all.
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $booking_id
 * @property PaymentGatewayName $gateway
 * @property PaymentKind $kind
 * @property int $amount_cents
 * @property string $currency
 * @property PaymentStatus $status
 * @property string|null $gateway_ref
 * @property string|null $gateway_transaction_ref
 * @property int|null $refunds_payment_id
 * @property string $idempotency_key
 * @property string|null $checkout_url
 * @property array<string, mixed>|null $raw_payload encrypted
 * @property string|null $failure_code
 * @property Carbon|null $paid_at
 * @property Carbon|null $refunded_at
 */
class Payment extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * The gateway's own response never leaves this object by accident.
     *
     * It carries a cardholder name, the last four digits, and whatever else the
     * provider felt like including — the same posture `IntegrationCredential`
     * takes for the request side.
     *
     * @var list<string>
     */
    protected $hidden = ['raw_payload'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'gateway' => PaymentGatewayName::class,
            'kind' => PaymentKind::class,
            'status' => PaymentStatus::class,
            'raw_payload' => 'encrypted:array',
            'amount_cents' => 'integer',
            'paid_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return BelongsTo<Payment, $this> */
    public function refunds(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'refunds_payment_id');
    }

    /**
     * PAY-10's sum, in one place: succeeded charges minus succeeded refunds.
     *
     * Defined here rather than inside `ConfirmBooking` so that the confirmation
     * path, the webhook, the manual mark-as-paid and the nightly integrity
     * check cannot each arrive at a slightly different figure. `withoutTenancy`
     * is deliberately **not** used — this always runs inside a resolved tenant,
     * and a payment sum that crossed tenants would be the worst possible bug in
     * this file.
     */
    public static function paidCentsFor(int $bookingId): int
    {
        $rows = static::query()
            ->where('booking_id', $bookingId)
            ->where('status', PaymentStatus::Succeeded->value)
            ->get(['kind', 'amount_cents']);

        $paid = 0;

        foreach ($rows as $row) {
            $paid += $row->kind->isIncoming() ? $row->amount_cents : -$row->amount_cents;
        }

        // Clamped at zero: a booking refunded more than it was charged is a
        // data error worth surfacing elsewhere, not a negative `paid_cents`
        // that every downstream sum then has to defend against.
        return max(0, $paid);
    }

    /** Refunds that actually settled, for the pro-rata split in ADR-0017. */
    public static function refundedCentsFor(int $bookingId): int
    {
        return (int) static::query()
            ->where('booking_id', $bookingId)
            ->where('status', PaymentStatus::Succeeded->value)
            ->where('kind', PaymentKind::Refund->value)
            ->sum('amount_cents');
    }

    /**
     * Resolve a payment from a webhook, with no tenant in context.
     *
     * `payments_gateway_ref_idx` exists for this and deliberately does not lead
     * with `tenant_id`. The same textbook `withoutTenancy()` case as
     * {@see ApiKey::findByPrefix()} — this lookup is what *resolves* the tenant.
     */
    public static function findByGatewayRef(PaymentGatewayName $gateway, string $reference): ?self
    {
        if (trim($reference) === '') {
            return null;
        }

        return Tenancy::withoutTenancy(
            static fn (): ?self => static::query()
                ->where('gateway', $gateway->value)
                ->where('gateway_ref', $reference)
                ->first(),
        );
    }

    /**
     * Payments still waiting on a gateway that may never answer.
     *
     * BKG-10's sweeper reads this: a guest who closed the tab leaves a
     * `pending` row and a `pending_payment` booking holding seats.
     *
     * @param  Builder<Payment>  $query
     * @return Builder<Payment>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            PaymentStatus::Pending->value,
            PaymentStatus::Processing->value,
        ]);
    }

    /**
     * The operator's error feed (CXL-10, PAY-12).
     *
     * A **failed refund** is the one payment failure an operator has to act on
     * rather than wait out. A failed charge is a guest whose card was declined
     * and who will try again or will not; a failed refund is money the operator
     * has said they would give back and has not, on a booking the guest already
     * believes is settled.
     *
     * It is deliberately not "every failed payment": a feed that shows both
     * shows mostly declined cards, and the one row that needs a person is
     * indistinguishable from the forty that do not.
     *
     * @param  Builder<Payment>  $query
     * @return Builder<Payment>
     */
    public function scopeNeedingAttention(Builder $query): Builder
    {
        return $query
            ->where('kind', PaymentKind::Refund->value)
            ->where('status', PaymentStatus::Failed->value);
    }
}
