<?php

declare(strict_types=1);

namespace App\Domain\Booking\Actions;

use App\Enums\GuestDetailsStatus;
use App\Enums\GuestDocumentType;
use App\Models\Booking;
use App\Models\BookingGuest;
use App\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The manifest, filled in by the guest (spec TOK-8, SEC-14, GDR-4).
 *
 * ## The number of rows is fixed by the pax breakdown, and cannot be changed here
 *
 * TOK-8: *"with the number of guest rows fixed by the pax breakdown."* A form
 * that let a guest add a row would let them add a passenger to a boat with a
 * capacity, after the seats were counted and the money taken. So this writes
 * **into existing rows by position** and creates nothing: a submission naming a
 * position that does not exist is ignored rather than refused, because the
 * alternative is a guest who mistyped a URL losing the six rows they had just
 * filled in.
 *
 * ## Partial saves are the normal case
 *
 * Also TOK-8. A family fills in three passports on the sofa and the fourth when
 * somebody comes home; a form that demanded everything at once would be
 * abandoned. So every field is optional on the way in, and **completeness is
 * computed rather than validated**: `complete` only when every required field
 * for every guest is present, which is a question asked of the stored rows and
 * not of the submitted form.
 *
 * ## Document numbers are encrypted, never indexed, never logged
 *
 * SEC-14 and ADR-0012. The `encrypted` cast is on the model; what this Action
 * adds is the discipline of never putting one in an exception, a log line or a
 * validation message — which is why a rejected document number is reported as
 * "that document number is not valid" and never quoted back.
 */
final class SaveGuestDetails
{
    /**
     * @param  array<int, array<string, mixed>>  $rows  keyed by `position`
     * @return int how many guest rows were written
     */
    public function __invoke(Booking $booking, array $rows): int
    {
        $guests = BookingGuest::query()
            ->where('booking_id', $booking->getKey())
            ->get()
            ->keyBy('position');

        $written = 0;

        DB::transaction(function () use ($rows, $guests, $booking, &$written): void {
            foreach ($rows as $row) {
                $position = (int) ($row['position'] ?? 0);
                $guest = $guests->get($position);

                if (! $guest instanceof BookingGuest) {
                    // Ignored, not refused. See the class docblock: a stray
                    // position must not cost a guest the rows they did fill in.
                    continue;
                }

                $guest->forceFill($this->attributesFrom($row))->save();

                $written++;
            }

            $this->syncStatus($booking);
        });

        return $written;
    }

    /**
     * One submitted row, cleaned.
     *
     * Blank strings become null rather than empty strings: `complete` is
     * decided by `!== null`, and an empty string that passed for a passport
     * number would put a booking on the Λιμεναρχείο manifest with a blank in it.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function attributesFrom(array $row): array
    {
        return [
            'full_name' => self::nullIfBlank($row['full_name'] ?? null),
            'date_of_birth' => self::dateOrNull($row['date_of_birth'] ?? null),
            'nationality' => self::nullIfBlank($row['nationality'] ?? null),
            'document_type' => GuestDocumentType::tryFrom((string) ($row['document_type'] ?? '')),
            'document_number' => self::nullIfBlank($row['document_number'] ?? null),
            'document_expires_on' => self::dateOrNull($row['document_expires_on'] ?? null),
        ];
    }

    /**
     * `pending` or `complete`, asked of the stored rows (TOK-8).
     *
     * Never `not_required` from here: that is a property of the **product**
     * (`guest_details_required`), decided at confirmation, and a guest filling
     * in half a form must not be able to turn the requirement off.
     */
    public function syncStatus(Booking $booking): GuestDetailsStatus
    {
        if ($booking->guest_details_status === GuestDetailsStatus::NotRequired) {
            return GuestDetailsStatus::NotRequired;
        }

        $needsDocuments = self::documentsRequiredFor($booking);

        $incomplete = BookingGuest::query()
            ->where('booking_id', $booking->getKey())
            ->get()
            ->contains(fn (BookingGuest $guest): bool => ! self::isComplete($guest, $needsDocuments));

        $status = $incomplete ? GuestDetailsStatus::Pending : GuestDetailsStatus::Complete;

        $booking->forceFill(['guest_details_status' => $status])->save();

        return $status;
    }

    /**
     * Is this row finished?
     *
     * A name is always required — it is the manifest. The document is required
     * only when the operator asked for one, because most day trips do not need
     * a passport number and collecting one anyway would be personal data taken
     * for no stated purpose, which GDR-4 and TOK-9 both object to.
     */
    public static function isComplete(BookingGuest $guest, bool $needsDocuments): bool
    {
        if (self::nullIfBlank($guest->full_name) === null) {
            return false;
        }

        if (! $needsDocuments) {
            return true;
        }

        // A purged row is complete, not incomplete. After GDR-4's retention
        // window the document is *supposed* to be gone, and a booking that
        // flipped back to `pending` a year later would put a departure that
        // already sailed into the reminder scheduler.
        if ($guest->document_purged_at !== null) {
            return true;
        }

        return $guest->document_type !== null
            && self::nullIfBlank($guest->document_number) !== null
            && $guest->date_of_birth !== null
            && self::nullIfBlank($guest->nationality) !== null;
    }

    /** Did the operator ask for documents on this product? */
    public static function documentsRequiredFor(Booking $booking): bool
    {
        $product = Product::query()->find($booking->product_id);

        return $product !== null && (bool) $product->guest_details_required;
    }

    private static function nullIfBlank(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function dateOrNull(mixed $value): ?Carbon
    {
        $date = self::nullIfBlank($value);

        if ($date === null) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $date) ?: null;
        } catch (Throwable) {
            // A date the form could not produce. Dropped rather than thrown:
            // the field is optional on the way in and the guest can try again,
            // and an exception here would lose the rest of their submission.
            return null;
        }
    }
}
