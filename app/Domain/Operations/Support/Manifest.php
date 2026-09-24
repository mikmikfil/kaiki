<?php

declare(strict_types=1);

namespace App\Domain\Operations\Support;

use App\Domain\Operations\Actions\GenerateManifest;
use App\Enums\BookingStatus;
use App\Enums\ManifestColumn;
use App\Models\Booking;
use App\Models\BookingAnswer;
use App\Models\BookingGuest;
use App\Models\Departure;
use App\Models\Port;
use App\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Everyone on board, and the head count the harbour cares about
 * (spec OPS-8, OPS-9, CMP-2).
 *
 * ## OPS-9 is the requirement that is easy to get wrong
 *
 * > *"includes every person on board, including age bands that do not count
 * > toward capacity, and states the total head count against
 * > `vessel.capacity_max`"*
 *
 * An infant on a lap does not take a seat and **is** a body on the boat. Every
 * other count in this system is a capacity count, so the obvious implementation
 * — reuse `pax_capacity_total` — produces a manifest that is short by exactly
 * the number of babies, and nobody notices until the coastguard does.
 *
 * So this counts `booking_guests` rows, which is one per person, and states the
 * total against the vessel's licensed maximum rather than against the
 * departure's sold seats.
 *
 * ## A guest with no details is a row, not an omission
 *
 * A booking whose manifest is not filled in yet still has people on it. Leaving
 * them off would make a short manifest look complete; showing them as blank
 * rows is what sends the operator to chase the details before sailing, which is
 * the thing that has to happen.
 *
 * ## The document number is decrypted here and nowhere else
 *
 * `BookingGuest` hides it from serialisation (GDR-6) and the cast decrypts on
 * property access. Reading it is the whole reason a manifest is a logged action
 * — {@see GenerateManifest} does the logging, and
 * this class never decides on its own to include the column.
 */
final class Manifest
{
    /**
     * @param  list<ManifestColumn>  $columns
     * @param  list<array<string, string>>  $rows
     * @param  array<string, string|null>  $header
     */
    private function __construct(
        public readonly array $columns,
        public readonly array $rows,
        public readonly array $header,
        public readonly int $onBoard,
        public readonly ?int $capacityMax,
        public readonly int $missingDetails,
    ) {}

    /**
     * A header value as the sheet prints it: «20/09/2026» and «15:00», the way
     * a Greek port official reads them. The stored values stay ISO, because
     * the file name is built from them.
     */
    public function shown(string $key): ?string
    {
        $value = $this->header[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        return match ($key) {
            'date' => Carbon::parse((string) $value)->format('d/m/Y'),
            'time' => substr((string) $value, 0, 5),
            default => (string) $value,
        };
    }

    /**
     * The manifest for one departure (per-seat), or one booking (per-vessel).
     *
     * @param  list<ManifestColumn>  $columns
     */
    public static function forDeparture(Departure $departure, array $columns): self
    {
        $bookings = Booking::query()
            ->where('departure_id', $departure->getKey())
            ->whereIn('status', self::sailingStatuses())
            ->orderBy('guest_name')
            ->get();

        return self::build($bookings, $columns, [
            'trip' => $departure->product?->title,
            'vessel' => $departure->vessel?->name,
            'date' => $departure->local_date->toDateString(),
            'time' => $departure->local_time,
            'port' => self::portName($departure->product?->meetingPoint, $departure->vessel?->homePort),
            'landing_port' => self::landingPortName($departure->product, $departure->vessel?->homePort),
            'licence' => $departure->vessel?->licence_type?->label(),
            'captain' => $departure->captainName(),
            'crew' => implode(', ', $departure->crewNames()) ?: null,
        ], $departure->vessel?->capacity_max);
    }

    /**
     * @param  list<ManifestColumn>  $columns
     */
    public static function forBooking(Booking $booking, array $columns): self
    {
        return self::build(new Collection([$booking]), $columns, [
            'trip' => $booking->product?->title,
            'vessel' => $booking->vessel?->name,
            'date' => $booking->local_date?->toDateString(),
            'time' => $booking->local_time,
            'port' => self::portName($booking->product?->meetingPoint, $booking->vessel?->homePort),
            'landing_port' => self::landingPortName($booking->product, $booking->vessel?->homePort),
            'licence' => $booking->vessel?->licence_type?->label(),
            'captain' => $booking->departure?->captainName() ?? $booking->vessel?->captain_name,
            'crew' => $booking->departure === null ? null : (implode(', ', $booking->departure->crewNames()) ?: null),
        ], $booking->vessel?->capacity_max);
    }

    /**
     * @param  Collection<int, Booking>  $bookings
     * @param  list<ManifestColumn>  $columns
     * @param  array<string, string|null>  $header
     */
    private static function build(Collection $bookings, array $columns, array $header, ?int $capacityMax): self
    {
        $rows = [];
        $missing = 0;

        foreach ($bookings as $booking) {
            $guests = BookingGuest::query()
                ->with('answers')
                ->where('booking_id', $booking->getKey())
                ->orderBy('position')
                ->get();

            if ($guests->isEmpty()) {
                // Nobody has filled the manifest in for this booking. The people
                // still exist and still get on the boat, so they get placeholder
                // rows — one per head — rather than being silently absent.
                for ($position = 1; $position <= $booking->pax_total; $position++) {
                    $rows[] = self::blankRow($columns, $booking);
                    $missing++;
                }

                continue;
            }

            foreach ($guests as $guest) {
                // A name is what makes a row usable at the quayside — the
                // document is the operator's own setting and a day trip does
                // not ask for one, so `hasCompleteDetails(false)` is the right
                // question here even on a manifest that carries the column.
                if (! $guest->hasCompleteDetails()) {
                    $missing++;
                }

                $rows[] = self::row($columns, $booking, $guest);
            }
        }

        return new self(
            columns: $columns,
            rows: $rows,
            header: $header,
            // OPS-9: bodies, not seats.
            onBoard: count($rows),
            capacityMax: $capacityMax,
            missingDetails: $missing,
        );
    }

    /**
     * @param  list<ManifestColumn>  $columns
     * @return array<string, string>
     */
    private static function row(array $columns, Booking $booking, BookingGuest $guest): array
    {
        $row = [];

        foreach ($columns as $column) {
            $row[$column->value] = match ($column) {
                ManifestColumn::FullName => (string) ($guest->full_name ?? ''),
                ManifestColumn::Sex => $guest->sex?->letter() ?? '',
                ManifestColumn::DateOfBirth => $guest->date_of_birth?->toDateString() ?? '',
                ManifestColumn::Nationality => (string) ($guest->nationality ?? ''),
                ManifestColumn::DocumentType => $guest->document_type?->label() ?? '',
                // Decrypted on property access by the cast. A purged document
                // reads as purged rather than as missing (ADR-0012): the guest
                // did supply it, and the retention job removed it.
                ManifestColumn::DocumentNumber => $guest->document_purged_at !== null
                    ? trans('manifest.purged')
                    : (string) ($guest->document_number ?? ''),
                ManifestColumn::Reference => $booking->reference,
                ManifestColumn::AgeBand => $guest->age_band_code,
                ManifestColumn::CheckedIn => $guest->checked_in_at !== null ? trans('manifest.yes') : '',
                ManifestColumn::Answers => self::answers($booking, $guest),
            };
        }

        return $row;
    }

    /**
     * The passenger's answers, and the booking's on the lead row, so a
     * per-booking «Μεταφορά: Ναι» appears once per party rather than on
     * every name in it.
     */
    private static function answers(Booking $booking, BookingGuest $guest): string
    {
        $answers = $guest->answers->all();

        if ($guest->is_lead) {
            $answers = [
                ...BookingAnswer::query()
                    ->where('booking_id', $booking->getKey())
                    ->whereNull('booking_guest_id')
                    ->orderBy('id')
                    ->get()
                    ->all(),
                ...$answers,
            ];
        }

        return BookingAnswer::joined($answers);
    }

    /**
     * @param  list<ManifestColumn>  $columns
     * @return array<string, string>
     */
    private static function blankRow(array $columns, Booking $booking): array
    {
        $row = [];

        foreach ($columns as $column) {
            $row[$column->value] = match ($column) {
                ManifestColumn::Reference => $booking->reference,
                // The gap is visible on the sheet, which is what sends somebody
                // to chase it before the boat leaves.
                default => trans('manifest.blank'),
            };
        }

        return $row;
    }

    /**
     * Where the boat leaves from: the trip's meeting point, or the boat's home
     * port when the trip does not name one.
     *
     * A method rather than `?->name ?? ?->name` at the call site. PHPStan reads
     * a nullsafe on the left of `??` as redundant and asks for `->name`, which
     * at runtime is "Attempt to read property on null" the first time a product
     * has no meeting point — so the shape that satisfies the analyser is the
     * shape that breaks, and writing it out once ends the argument.
     */
    /**
     * «Λιμάνι αποβίβασης»: the trip's landing port, or where they boarded on a
     * round trip. Always said, never left blank: the list asks for both.
     */
    private static function landingPortName(?Product $product, ?Port $homePort): ?string
    {
        if ($product?->landingPort instanceof Port) {
            return $product->landingPort->name;
        }

        return self::portName($product?->meetingPoint, $homePort);
    }

    private static function portName(?Port $meetingPoint, ?Port $homePort): ?string
    {
        if ($meetingPoint instanceof Port) {
            return $meetingPoint->name;
        }

        return $homePort instanceof Port ? $homePort->name : null;
    }

    /**
     * The statuses of a booking whose people are actually sailing.
     *
     * A cancelled booking on a manifest is a person the crew would count and
     * wait for on the quay.
     *
     * @return list<string>
     */
    private static function sailingStatuses(): array
    {
        return [
            BookingStatus::Confirmed->value,
            BookingStatus::CheckedIn->value,
            BookingStatus::Completed->value,
        ];
    }
}
