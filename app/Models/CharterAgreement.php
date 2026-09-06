<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AgreementStatus;
use App\Exceptions\AgreementEvidenceLocked;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Database\Factories\CharterAgreementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Ναυλοσύμφωνο (`docs/data-model.md` §2.6, §6 item 45).
 *
 * ## The document is M6; the invariant is here
 *
 * #88 brings the **table** forward because §0 forbids adding a foreign key to
 * an existing table on SQLite, and this one has three. Nothing generates a
 * charter agreement yet — that is M6, and it is blocked on a legal question
 * about ΚΥΑ Α.Π. 3133.1/47821 rather than on any code.
 *
 * What lands with the table is the one rule that would be expensive to add
 * afterwards: **accepted evidence is never overwritten.** §2.6 states it, and a
 * rule stated only in prose is a rule the first M6 implementation breaks.
 *
 * ## Why a model guard and not just the unique index
 *
 * `charter_agr_tenant_booking_uq` stops a *second* row for the same template
 * version. It does nothing about an `update` on the row that already exists —
 * which is exactly the shape a regeneration takes: same booking, same version,
 * new PDF, and `guest_accepted_at` quietly replaced by null.
 *
 * So {@see self::booted()} refuses any write that would change the content or
 * the evidence of an accepted row. `status` is deliberately excluded from that
 * list and then rejected separately by {@see AgreementStatus::allowedTransitions()},
 * which has no edge out of `accepted` at all — the two guards answer different
 * questions and a single one would have to answer both badly.
 *
 * ## Regeneration is a new version, and the caller is told which
 *
 * {@see self::openVersionFor()} is the whole of the M6 seam: hand it a booking
 * and a template version, and it returns the row to work on. If the current one
 * is accepted, that is a **new row** — the old evidence sits beside it, which
 * is what §2.6 means by *"you create a new version instead"*.
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $booking_id
 * @property string $template_key
 * @property string $template_version
 * @property array<string, mixed> $fields_snapshot
 * @property string|null $pdf_path
 * @property string|null $pdf_hash
 * @property Carbon|null $generated_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $guest_accepted_at
 * @property string|null $guest_accepted_ip
 * @property string|null $guest_accepted_user_agent
 * @property string|null $guest_accepted_name
 * @property Carbon|null $operator_signed_at
 * @property int|null $operator_signed_by_user_id
 * @property AgreementStatus $status
 */
class CharterAgreement extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<CharterAgreementFactory> */
    use HasFactory;

    use HasUuid;

    /**
     * What an accepted row will not let you change.
     *
     * The rendered content and the four evidence columns §2.6 calls *"the
     * legally interesting part"*. `sent_at` and the operator's signature are
     * absent on purpose: countersigning after the guest accepted is the normal
     * order of events, not a tampering attempt.
     *
     * @var list<string>
     */
    public const FROZEN_ONCE_ACCEPTED = [
        'template_key',
        'template_version',
        'fields_snapshot',
        'pdf_path',
        'pdf_hash',
        'generated_at',
        'guest_accepted_at',
        'guest_accepted_ip',
        'guest_accepted_user_agent',
        'guest_accepted_name',
    ];

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            // §3.8. Both parties' names, the skipper and the vessel's
            // registration go in here, so it is encrypted at rest like every
            // other snapshot of personal data.
            'fields_snapshot' => 'encrypted:array',
            'generated_at' => 'datetime',
            'sent_at' => 'datetime',
            'guest_accepted_at' => 'datetime',
            'operator_signed_at' => 'datetime',
            'status' => AgreementStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (self $agreement): void {
            $original = $agreement->getOriginal('status');

            $wasAccepted = $original instanceof AgreementStatus
                ? $original->isEvidenceLocked()
                : $original === AgreementStatus::Accepted->value;

            if (! $wasAccepted) {
                return;
            }

            $touched = array_values(array_intersect(
                self::FROZEN_ONCE_ACCEPTED,
                array_keys($agreement->getDirty()),
            ));

            if ($touched !== []) {
                throw AgreementEvidenceLocked::forColumns($agreement, $touched);
            }

            // Nothing leaves `accepted` — see AgreementStatus. Checked here
            // rather than only in a caller, because the caller that skips it is
            // the one that erases the evidence.
            if ($agreement->isDirty('status')) {
                throw AgreementEvidenceLocked::forStatus($agreement);
            }
        });
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return BelongsTo<User, $this> */
    public function signedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_signed_by_user_id');
    }

    /** Is this row's evidence frozen? */
    public function isEvidenceLocked(): bool
    {
        return $this->status->isEvidenceLocked();
    }

    /**
     * The row an M6 generator should write to, for this booking and version.
     *
     * Three outcomes, and the third is the point:
     *
     * - no row yet — a fresh `draft`;
     * - a row that nobody has accepted — that row, reused, because
     *   regenerating an unaccepted draft is just regenerating a draft;
     * - an **accepted** row — a new row at the next version, so the accepted
     *   one survives untouched (§2.6).
     *
     * The version is bumped rather than reusing the caller's, because the
     * unique key includes it: reusing it would collide with the very row this
     * is protecting, and an M6 caller that discovered that by catching a
     * constraint violation would be one `->firstOrCreate()` away from
     * overwriting the evidence instead.
     */
    public static function openVersionFor(Booking $booking, string $templateVersion, string $templateKey = 'default'): self
    {
        $existing = static::query()
            ->where('booking_id', $booking->getKey())
            ->where('template_version', $templateVersion)
            ->first();

        if ($existing instanceof self && ! $existing->isEvidenceLocked()) {
            return $existing;
        }

        return new self([
            'booking_id' => $booking->getKey(),
            'template_key' => $templateKey,
            'template_version' => $existing instanceof self
                ? self::nextVersionAfter($booking, $templateVersion)
                : $templateVersion,
            'fields_snapshot' => [],
            'status' => AgreementStatus::Draft,
        ]);
    }

    /**
     * The next free version string for this booking.
     *
     * `2026.1` becomes `2026.1-v2`, then `-v3`. Deliberately a suffix rather
     * than arithmetic on the operator's own version: `2026.1` is a *template*
     * version chosen by whoever wrote the template, and incrementing it would
     * claim a template that may exist and say something different.
     */
    private static function nextVersionAfter(Booking $booking, string $templateVersion): string
    {
        $base = preg_replace('/-v\d+$/', '', $templateVersion) ?? $templateVersion;

        $taken = static::query()
            ->where('booking_id', $booking->getKey())
            ->where(function ($query) use ($base): void {
                $query->where('template_version', $base)
                    ->orWhere('template_version', 'like', $base . '-v%');
            })
            ->count();

        return $base . '-v' . ($taken + 1);
    }
}
