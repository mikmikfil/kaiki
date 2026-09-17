<?php

declare(strict_types=1);

namespace App\Domain\Booking\Support;

use App\Enums\GuestDocumentType;
use App\Models\AgeBand;
use App\Models\Booking;
use App\Models\BookingGuest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Throwable;

/**
 * The passengers at checkout, before payment (product owner, 2026-09-17).
 *
 * ## What is asked, and of whom
 *
 * Only on a trip whose «Στοιχεία επιβατών» is on
 * (`products.guest_details_required`). Then, for every person:
 *
 * - full name, nationality and date of birth, always;
 * - the document type, **passport or identity card** and nothing else;
 * - the number for either, and the expiry date for a passport only;
 * - no document at all for a passenger in a «Χωρίς έγγραφο» band
 *   ({@see AgeBand::isDocumentFree()}), which is what infant bands are.
 *
 * ## The date of birth has to fit the band
 *
 * Age on the **day of the trip**, not today: a child booked in March as «Παιδί
 * 3–11» who turns twelve in July is an adult on the boat, and the price paid
 * was for a child. Refusing it here is kinder than a quay argument.
 *
 * ## One place, for the page and the POST
 *
 * {@see self::rows()} is what the page renders and what {@see self::check()}
 * validates against, both keyed by `position` — the key
 * `SaveGuestDetails` writes by. The form used to post rows with no position
 * at all, so every passenger typed at checkout was silently dropped.
 *
 * Document numbers are never quoted back in a message (SEC-14).
 */
final class PassengerForm
{
    /**
     * One entry per person, in the party's order.
     *
     * @return list<array{position: int, guest: BookingGuest, band: AgeBand|null, label: string, no_document: bool}>
     */
    public static function rows(Booking $booking, string $locale): array
    {
        ManifestRows::ensure($booking);

        $labels = self::frozenLabels($booking, $locale);

        return BookingGuest::query()
            ->with('ageBand')
            ->where('booking_id', $booking->getKey())
            ->orderBy('position')
            ->get()
            ->map(static fn (BookingGuest $guest): array => [
                'position' => $guest->position,
                'guest' => $guest,
                'band' => $guest->ageBand,
                // The label frozen on the booking (§3.1) when there is one, so
                // the row says what the guest chose, not what the band is
                // called today.
                'label' => $labels[$guest->age_band_code] ?? (string) ($guest->ageBand?->getTranslation('label', $locale) ?? ''),
                'no_document' => $guest->isDocumentFree(),
            ])
            ->values()
            ->all();
    }

    /**
     * The shape of every row. Conditional requirements are {@see self::check()}'s.
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'guests' => ['required', 'array', 'min:1'],
            'guests.*.position' => ['required', 'integer', 'min:1'],
            'guests.*.full_name' => ['required', 'string', 'max:120'],
            'guests.*.nationality' => ['required', 'string', 'max:60'],
            'guests.*.date_of_birth' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'guests.*.document_type' => ['nullable', Rule::enum(GuestDocumentType::class)],
            'guests.*.document_number' => ['nullable', 'string', 'max:40'],
            'guests.*.document_expires_on' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * Field names in the guest's language, so a message reads «Επιβάτης 2:
     * η ημερομηνία γέννησης…» rather than `guests.1.date_of_birth`.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    public static function attributes(array $input): array
    {
        $names = [];

        foreach (array_keys((array) ($input['guests'] ?? [])) as $i) {
            $n = ['n' => (int) $i + 1];

            foreach (['full_name', 'nationality', 'date_of_birth', 'document_type', 'document_number', 'document_expires_on'] as $field) {
                $names["guests.$i.$field"] = __('guest.checkout.passenger_field', [
                    ...$n,
                    'field' => __('guest.checkout.fields.' . $field),
                ]);
            }
        }

        return $names;
    }

    /**
     * The rules that depend on the row: its band, and the document chosen.
     */
    public static function check(Validator $validator, Booking $booking): void
    {
        $byPosition = collect(self::rows($booking, app()->getLocale()))->keyBy('position');
        $tripDate = Carbon::parse($booking->local_date->toDateString());

        foreach ((array) ($validator->getData()['guests'] ?? []) as $i => $input) {
            if (! is_array($input)) {
                continue;
            }

            $row = $byPosition->get((int) ($input['position'] ?? 0));

            if ($row === null) {
                $validator->errors()->add("guests.$i.position", __('guest.checkout.passenger_unknown'));

                continue;
            }

            $n = (int) $i + 1;

            if (! $row['no_document']) {
                $type = GuestDocumentType::tryFrom((string) ($input['document_type'] ?? ''));

                if ($type === null) {
                    $validator->errors()->add("guests.$i.document_type", __('guest.checkout.errors.document_type', ['n' => $n]));
                }

                if (trim((string) ($input['document_number'] ?? '')) === '') {
                    $validator->errors()->add("guests.$i.document_number", __('guest.checkout.errors.document_number', ['n' => $n]));
                }

                if ($type?->needsExpiry() === true) {
                    $expiry = self::date($input['document_expires_on'] ?? null);

                    if ($expiry === null) {
                        $validator->errors()->add("guests.$i.document_expires_on", __('guest.checkout.errors.expiry_required', ['n' => $n]));
                    } elseif ($expiry->lt($tripDate)) {
                        $validator->errors()->add("guests.$i.document_expires_on", __('guest.checkout.errors.expired', ['n' => $n]));
                    }
                }
            }

            $band = $row['band'];
            $born = self::date($input['date_of_birth'] ?? null);

            if ($band instanceof AgeBand && $born !== null && $born->lte($tripDate)) {
                $age = (int) $born->diffInYears($tripDate);

                if (! $band->covers($age)) {
                    $validator->errors()->add("guests.$i.date_of_birth", __(
                        $band->max_age === null ? 'guest.checkout.errors.age_from' : 'guest.checkout.errors.age_range',
                        ['n' => $n, 'band' => $row['label'], 'min' => $band->min_age, 'max' => $band->max_age],
                    ));
                }
            }
        }
    }

    /**
     * `code => label` from the booking's own breakdown.
     *
     * @return array<string, string>
     */
    private static function frozenLabels(Booking $booking, string $locale): array
    {
        $labels = [];

        foreach ((array) $booking->pax_breakdown as $band) {
            $code = (string) ($band['code'] ?? '');
            $label = data_get($band, 'label.' . $locale) ?? data_get($band, 'label.el');

            if ($code !== '' && is_string($label) && $label !== '') {
                $labels[$code] = $label;
            }
        }

        return $labels;
    }

    private static function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value)?->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }
}
