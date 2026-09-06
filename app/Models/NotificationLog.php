<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NotificationChannel;
use App\Enums\NotificationProvider;
use App\Enums\NotificationStatus;
use App\Enums\NotificationTemplate;
use App\Models\Concerns\BelongsToTenant;
use App\Support\Tenancy;
use Database\Factories\NotificationLogFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Every email and SMS we attempted (`docs/data-model.md` §2.7, spec NTF-3).
 *
 * ## It records attempts, not outcomes
 *
 * The row is written **before** the send, in `queued`, and updated afterwards.
 * A crash between the two leaves a `queued` row that says what was about to
 * happen, which is the only version of this table worth having: a log written
 * after a successful send tells you nothing about the sends that failed.
 *
 * ## {@see self::alreadySent()} is what makes BKG-16 idempotent
 *
 * *"Each is idempotent per booking per reminder type."* The reminder Action
 * asks this question before dispatching, and `notif_logs_tenant_tmpl_idx`
 * answers it in one indexed read. It deliberately ignores `failed` rows: a
 * reminder that failed has not been sent, and refusing to retry it would make
 * one provider outage a permanently missing message.
 *
 * ## Pruned, never deleted by hand
 *
 * Twelve months (§2.7). An operator asking *"did the guest ever get the
 * confirmation"* six months later has to be able to find out — and "we sent it,
 * Postmark bounced it, here is the message id" is the only answer worth having.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $booking_id
 * @property int|null $departure_id
 * @property string|null $notifiable_type
 * @property int|null $notifiable_id
 * @property NotificationChannel $channel
 * @property NotificationTemplate $template
 * @property string $locale
 * @property string $to
 * @property string|null $subject
 * @property NotificationStatus $status
 * @property NotificationProvider|null $provider
 * @property string|null $provider_ref
 * @property string|null $error_message
 * @property int|null $cost_cents
 * @property Carbon|null $sent_at
 * @property Carbon|null $delivered_at
 */
class NotificationLog extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<NotificationLogFactory> */
    use HasFactory;

    use Prunable;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'channel' => NotificationChannel::class,
            'template' => NotificationTemplate::class,
            'status' => NotificationStatus::class,
            'provider' => NotificationProvider::class,
            'cost_cents' => 'integer',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return BelongsTo<Departure, $this> */
    public function departure(): BelongsTo
    {
        return $this->belongsTo(Departure::class);
    }

    /**
     * Has this exact message already gone to this booking (BKG-16)?
     *
     * **Per channel as well as per template**, and that is not pedantry: the
     * confirmation goes out as an email *and* a text, both under
     * `booking_confirmed`. A dedupe key of (booking, template) alone would let
     * the email suppress the SMS, and the guest would never get the text — with
     * a log that looked entirely healthy.
     *
     * `failed` rows are deliberately **not** counted. A reminder that failed has
     * not been sent, and treating it as sent would turn one provider outage
     * into a message a guest never receives and nobody ever notices.
     */
    public static function alreadySent(int $bookingId, NotificationTemplate $template, NotificationChannel $channel): bool
    {
        return static::query()
            ->where('booking_id', $bookingId)
            ->where('template', $template->value)
            ->where('channel', $channel->value)
            ->whereIn('status', [
                NotificationStatus::Queued->value,
                NotificationStatus::Sent->value,
                NotificationStatus::Delivered->value,
                // A bounce counts as sent: we did our part, and re-sending to a
                // mailbox that rejected us produces a second bounce rather than
                // a delivery. NTF-8 flags the booking so a person telephones.
                NotificationStatus::Bounced->value,
            ])
            ->exists();
    }

    /**
     * Resolve a log row from a delivery webhook, with no tenant in context.
     *
     * `notif_logs_provider_ref_idx` exists for this and deliberately does not
     * lead with `tenant_id`: NTF-8's bounce and complaint callbacks arrive from
     * Postmark carrying a message id and nothing else. The same textbook
     * `withoutTenancy()` case as {@see Payment::findByGatewayRef()}.
     */
    public static function findByProviderRef(NotificationProvider $provider, string $reference): ?self
    {
        if (trim($reference) === '') {
            return null;
        }

        return Tenancy::withoutTenancy(
            static fn (): ?self => static::query()
                ->where('provider', $provider->value)
                ->where('provider_ref', $reference)
                ->first(),
        );
    }

    /**
     * The operator's failure feed (BKG-14).
     *
     * @param  Builder<NotificationLog>  $query
     * @return Builder<NotificationLog>
     */
    public function scopeNeedingAttention(Builder $query): Builder
    {
        return $query->whereIn('status', [
            NotificationStatus::Failed->value,
            NotificationStatus::Bounced->value,
        ]);
    }

    /**
     * §2.7: twelve months, by a scheduled `model:prune`.
     *
     * `static::query()` rather than `$this->newQuery()`, because Larastan reads
     * the first as `Builder<static>` and the second as a builder over the
     * concrete model — and the framework's own `Prunable` signature is written
     * in terms of the model this trait sits on.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subMonths(12));
    }
}
