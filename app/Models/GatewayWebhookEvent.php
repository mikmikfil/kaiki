<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentGatewayName;
use App\Enums\WebhookEventStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An inbound webhook, recorded before anything is believed
 * (`docs/data-model.md` §2.7, spec PAY-5, PAY-6, PAY-7, AVL-47).
 *
 * ## The one model in this project that is not tenant-owned
 *
 * There is no `BelongsToTenant` here and that is deliberate rather than an
 * omission. §2.7: *"not tenant-owned at write time"* — the row is written before
 * the tenant is known, and `tenant_id` is backfilled once the payment is
 * matched.
 *
 * `vat_rates` is the other non-tenant-owned model and it is a different case:
 * platform-owned forever. This one **acquires** a tenant, which is a third
 * shape, and the isolation harness names it explicitly for that reason.
 *
 * ## Written first, in its own transaction, before any money logic
 *
 * The endpoint responds 2xx as soon as this row exists (brief §3, PAY-6's
 * five-second budget) and everything else happens in a queued job keyed on its
 * id. So a payload that later turns out to be unprocessable is still *recorded*,
 * still replayable, and still visible in the platform's failure feed — rather
 * than lost with the request that carried it.
 *
 * ## `signature_valid = false` rows are kept
 *
 * PAY-7 asks for an unverified webhook to be *logged with its source IP*, not
 * merely refused. A stream of them from one address is an attack an operator's
 * platform should be able to see, and it cannot see what it did not write down.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property PaymentGatewayName $provider
 * @property string $event_id
 * @property string|null $event_type
 * @property bool $signature_valid
 * @property array<string, mixed> $payload encrypted
 * @property WebhookEventStatus $status
 * @property int|null $payment_id
 * @property string|null $error_message
 * @property Carbon|null $processed_at
 * @property Carbon $received_at
 */
class GatewayWebhookEvent extends Model
{
    protected $guarded = [];

    /**
     * The gateway's payload never leaves this object by accident.
     *
     * It carries a cardholder name, the last four digits and whatever else the
     * provider felt like including — the same posture `payments.raw_payload`
     * and `integration_credentials.credentials` take (SEC-9, MYD-15).
     *
     * @var list<string>
     */
    protected $hidden = ['payload'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'provider' => PaymentGatewayName::class,
            'status' => WebhookEventStatus::class,
            'payload' => 'encrypted:array',
            'signature_valid' => 'boolean',
            'processed_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Events an operator or the platform needs to look at.
     *
     * PAY-7's *"verified webhook for an unknown booking is stored and surfaced
     * in the super-admin gateway error feed rather than discarded"*. Reads
     * `gw_events_status_idx`, which is cross-tenant because the feed is.
     *
     * @param  Builder<GatewayWebhookEvent>  $query
     * @return Builder<GatewayWebhookEvent>
     */
    public function scopeNeedingAttention(Builder $query): Builder
    {
        return $query->whereIn('status', [
            WebhookEventStatus::Failed->value,
            // `received` and never processed is a queue that stopped; an
            // orphan is a payment we cannot match. Both need a human.
            WebhookEventStatus::Orphaned->value,
        ]);
    }

    /** Has this already been dealt with? AVL-47's replay check. */
    public function isSettled(): bool
    {
        return $this->status === WebhookEventStatus::Processed
            || $this->status === WebhookEventStatus::Ignored;
    }
}
