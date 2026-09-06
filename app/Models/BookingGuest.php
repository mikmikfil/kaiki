<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GuestDocumentType;
use App\Models\Concerns\BelongsToTenant;
use App\Support\Tenancy;
use Database\Factories\BookingGuestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One person aboard (`docs/data-model.md` §2.5).
 *
 * **Where the personal data lives**, which makes this the model with the
 * strictest rules in the project:
 *
 * - `document_number` is an `encrypted` cast, never indexed, and nulled by the
 *   retention job after `tenants.guest_document_retention_days` (ADR-0012).
 * - It is `$hidden`, so it cannot reach a log context, a queue payload or a
 *   Sentry breadcrumb by being serialised — the same defence
 *   {@see IntegrationCredential} carries, for the same reason.
 * - `CLAUDE.md`: it is never logged or exported *unless an explicit operator
 *   action requests it*, and the manifest export decrypts row by row inside the
 *   job.
 *
 * A row exists for every person, capacity-counting or not — an infant still
 * needs a manifest line — with `full_name` null until the guest-details form is
 * submitted.
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $booking_id
 * @property int|null $age_band_id
 * @property string $age_band_code
 * @property int $position
 * @property string|null $full_name
 * @property Carbon|null $date_of_birth
 * @property string|null $nationality
 * @property GuestDocumentType|null $document_type
 * @property string|null $document_number encrypted
 * @property Carbon|null $document_expires_on
 * @property Carbon|null $document_purged_at
 * @property string $ticket_code
 * @property Carbon|null $checked_in_at
 * @property bool $is_lead
 */
class BookingGuest extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<BookingGuestFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * The document number never leaves this object by accident.
     *
     * @var list<string>
     */
    protected $hidden = ['document_number'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'document_type' => GuestDocumentType::class,
            'document_number' => 'encrypted',
            'date_of_birth' => 'date',
            'document_expires_on' => 'date',
            'document_purged_at' => 'datetime',
            'checked_in_at' => 'datetime',
            'position' => 'integer',
            'is_lead' => 'boolean',
        ];
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return BelongsTo<AgeBand, $this> */
    public function ageBand(): BelongsTo
    {
        return $this->belongsTo(AgeBand::class);
    }

    /**
     * Has this guest supplied what the operator asked for?
     *
     * `$requiresDocuments` is the operator's setting rather than a property of
     * the row: a day trip needs a name, a charter leaving Greek waters needs a
     * passport, and the same guest row is complete in one case and not the
     * other.
     */
    public function hasCompleteDetails(bool $requiresDocuments = false): bool
    {
        if ($this->full_name === null || trim($this->full_name) === '') {
            return false;
        }

        if (! $requiresDocuments) {
            return true;
        }

        // A purged document is still *complete*: the guest supplied it and the
        // retention job removed it. Reading a purge as an omission would mark
        // every old booking incomplete and re-chase guests who did nothing
        // wrong.
        return $this->document_number !== null || $this->document_purged_at !== null;
    }

    /**
     * Resolve a QR scan, with no tenant in context.
     *
     * Crew scan a code standing on a pier: no session, no subdomain, nothing
     * but the code. `ticket_code` is globally unique for exactly this, and the
     * lookup is the same textbook `withoutTenancy()` case as
     * {@see ApiKey::findByPrefix()} — it is what *resolves* the tenant, so it
     * necessarily runs before one exists.
     */
    public static function findByTicketCode(string $code): ?self
    {
        if (trim($code) === '') {
            return null;
        }

        return Tenancy::withoutTenancy(
            static fn (): ?self => static::query()->where('ticket_code', $code)->first(),
        );
    }
}
