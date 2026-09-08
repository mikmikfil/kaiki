<?php

declare(strict_types=1);

namespace App\Domain\Operations\Support;

use App\Enums\BookingStatus;
use App\Enums\ExportDateBasis;
use App\Enums\ExportType;
use App\Enums\PaymentStatus;
use App\Models\AgeBand;
use App\Models\Booking;
use App\Models\BookingGuest;
use App\Models\ExportJob;
use App\Support\Format\MoneyFormatter;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The rows of a bookings or guests export (spec OPS-17, OPS-10, OPS-18).
 *
 * ## Nothing here holds more than one chunk
 *
 * OPS-18's *"streamed to avoid memory pressure"* is not satisfied by streaming
 * the HTTP response — the file is built in a queued job long before anybody
 * downloads it, and the place memory is actually at risk is the query. An
 * operator with four seasons of trading has six figures of guest rows, and
 * `->get()` on that is the whole table in PHP arrays.
 *
 * So the traversal is `lazyById()`, which walks the primary key in chunks and
 * eager-loads each chunk's relations. **Not `cursor()`**: a cursor holds one
 * unbuffered result set open for the entire job, which on MySQL means a
 * connection that cannot be used for anything else — including the status
 * update this job makes when it finishes.
 *
 * ## Document numbers are absent rather than removed
 *
 * OPS-10's second half. The row is assembled from
 * {@see ExportType::columns()}, which has no case for a document number, and
 * `BookingGuest::$hidden` is not relied on because hiding is a serialisation
 * concern and this is not serialisation. The test asserts the property from the
 * outside: a guest with a document number, a finished file, and the number
 * nowhere in its bytes.
 *
 * ## Money is a plain decimal, not a formatted string
 *
 * {@see MoneyFormatter} is deliberately **not** used here,
 * and it is the reuse that looks obviously right until the accountant tries to
 * sum a column. Its output is a localised string — `1.234,50 €` — with a
 * thousands separator, a currency symbol and a non-breaking space in it, which
 * every spreadsheet reads as text.
 *
 * A dot decimal is not free either: Greek Excel expects a comma, so a
 * dot-decimal column may need the import dialog. That is the trade-off taken
 * knowingly — the file is machine-readable everywhere and needs one setting in
 * one tool, rather than being human-readable in one tool and text everywhere
 * else. The currency is its own column so nothing has to parse a symbol.
 */
final class ExportRows
{
    /**
     * Rows per chunk.
     *
     * Small enough that a chunk plus its eager-loaded relations is measured in
     * megabytes, large enough that a hundred thousand rows is not a hundred
     * thousand queries.
     */
    private const CHUNK = 500;

    public function __construct(private readonly ExportJob $job) {}

    /**
     * The header line, in the operator's language.
     *
     * The locale is the tenant's rather than the request's: the file outlives
     * the session that asked for it, and an operator who happened to be reading
     * the panel in English does not want Greek colleagues receiving English
     * headers for ever after.
     *
     * @return list<string>
     */
    public function header(): array
    {
        return array_map(
            fn (string $column): string => (string) __("exports.columns.{$this->job->type->value}.{$column}"),
            $this->job->type->columns(),
        );
    }

    /**
     * Walk the export, handing each row to the writer.
     *
     * Returns the number of data rows written, which is what the panel shows
     * and what makes an empty export legible — *"0 rows"* beside a date window
     * is an answer, and a file containing only headers is a mystery.
     *
     * @param  callable(list<string>): void  $write
     */
    public function stream(callable $write): int
    {
        return $this->job->type === ExportType::Guests
            ? $this->streamGuests($write)
            : $this->streamBookings($write);
    }

    /** @param callable(list<string>): void $write */
    private function streamBookings(callable $write): int
    {
        $count = 0;

        foreach ($this->bookings()->with(['product', 'vessel'])->lazyById(self::CHUNK) as $booking) {
            $write($this->bookingRow($booking));
            $count++;
        }

        return $count;
    }

    /**
     * @param  callable(list<string>): void  $write
     */
    private function streamGuests(callable $write): int
    {
        $count = 0;

        $query = BookingGuest::query()
            ->where('booking_guests.tenant_id', $this->job->tenant_id)
            // The booking is the row's whole context — reference, date, trip,
            // vessel — and it is also where every filter lives. A guest whose
            // booking is out of the window is out of the export.
            ->whereIn('booking_id', $this->bookings()->select('bookings.id'))
            ->with(['booking.product', 'booking.vessel', 'ageBand'])
            ->orderBy('booking_guests.id');

        foreach ($query->lazyById(self::CHUNK, 'booking_guests.id') as $guest) {
            $write($this->guestRow($guest));
            $count++;
        }

        return $count;
    }

    /**
     * The bookings this export covers.
     *
     * Three exclusions, and each has an obvious wrong version:
     *
     * - **Test bookings are out.** #118 established this for every dashboard
     *   figure; an accounting export that quietly disagreed with the dashboard
     *   would be reconciled once and never trusted again.
     * - **Drafts and expired holds are out.** A `draft` is fifteen minutes of
     *   somebody thinking about it, not a booking, and a file listing them
     *   shows revenue that never existed.
     * - **Soft-deleted rows are out**, by the model's own global scope.
     *
     * Cancelled and refunded bookings are deliberately **in**: an accountant
     * needs the refunds, and a file that showed only what went well would
     * overstate the year.
     *
     * @return Builder<Booking>
     */
    public function bookings(): Builder
    {
        $query = Booking::query()
            ->where('bookings.tenant_id', $this->job->tenant_id)
            ->where('bookings.is_test', false)
            ->whereNotIn('bookings.status', [
                BookingStatus::Draft->value,
                BookingStatus::Expired->value,
            ])
            ->orderBy('bookings.id');

        $this->applyStatuses($query);
        $this->applyRelationFilters($query);
        $this->applyWindow($query);

        return $query;
    }

    /** @param Builder<Booking> $query */
    private function applyStatuses(Builder $query): void
    {
        $statuses = $this->job->filters['statuses'] ?? null;

        if (! is_array($statuses) || $statuses === []) {
            return;
        }

        $values = array_values(array_filter(
            array_map(static fn (mixed $value): ?string => is_string($value) ? $value : null, $statuses),
            static fn (?string $value): bool => $value !== null
                && BookingStatus::tryFrom($value) instanceof BookingStatus,
        ));

        if ($values !== []) {
            $query->whereIn('bookings.status', $values);
        }
    }

    /**
     * Product and vessel, addressed by uuid.
     *
     * By uuid rather than id, because the filter is stored on the row for
     * reproducibility and a stored `id` is meaningless to anybody reading the
     * table later — and because §1.1 keeps `id` off every surface an operator
     * can see, which the panel's own select options are.
     *
     * @param  Builder<Booking>  $query
     */
    private function applyRelationFilters(Builder $query): void
    {
        $product = $this->job->filters['product_uuid'] ?? null;

        if (is_string($product) && $product !== '') {
            $query->whereHas('product', static fn (Builder $inner) => $inner->where('uuid', $product));
        }

        $vessel = $this->job->filters['vessel_uuid'] ?? null;

        if (is_string($vessel) && $vessel !== '') {
            $query->whereHas('vessel', static fn (Builder $inner) => $inner->where('uuid', $vessel));
        }
    }

    /**
     * The date window, on whichever column the operator chose.
     *
     * ## The inclusive end is the off-by-one this method exists to avoid
     *
     * `local_date` is a date and compares cleanly. The other two are
     * timestamps, where `<= '2026-09-30'` means midnight — so the last day of
     * every window silently vanishes. It does not look like a bug; it looks
     * like a quiet Tuesday, which is why the boundary is `< to_date + 1 day`
     * rather than `<=` anything.
     *
     * @param  Builder<Booking>  $query
     */
    private function applyWindow(Builder $query): void
    {
        $basis = $this->job->date_basis;
        $from = $this->job->from_date;
        $to = $this->job->to_date;

        if ($from === null && $to === null) {
            return;
        }

        if ($basis === ExportDateBasis::Paid) {
            $this->applyPaidWindow($query, $from, $to);

            return;
        }

        $column = $basis->column();

        if ($from !== null) {
            $query->where($column, '>=', $basis->isWholeDay()
                ? $from->toDateString()
                : $this->startOfDayUtc($from));
        }

        if ($to !== null) {
            $query->where($column, '<', $basis->isWholeDay()
                ? $to->copy()->addDay()->toDateString()
                : $this->startOfDayUtc($to->copy()->addDay()));
        }
    }

    /**
     * Bookings with money that arrived inside the window.
     *
     * An `EXISTS` over `payments` rather than a join: a booking with a deposit
     * and a balance has two succeeded payments, and a join would put it in the
     * file twice — an accountant double-counting a boat trip because of a SQL
     * decision nobody wrote down.
     *
     * Refunds are excluded from the *matching* even though their amounts still
     * appear in the row. A booking whose only event this month was a refund
     * belongs to the month the money went out, which is what a refund's own
     * `paid_at` is null for and what `refunded_at` records — that is the
     * question the `booked` basis answers better, and offering a third
     * half-answer here would be worse than not offering one.
     *
     * @param  Builder<Booking>  $query
     */
    private function applyPaidWindow(Builder $query, ?Carbon $from, ?Carbon $to): void
    {
        $query->whereExists(function (BuilderContract $inner) use ($from, $to): void {
            $inner->selectRaw('1')
                ->from('payments')
                ->whereColumn('payments.booking_id', 'bookings.id')
                ->where('payments.status', PaymentStatus::Succeeded->value)
                ->whereNull('payments.refunds_payment_id')
                ->whereNotNull('payments.paid_at');

            if ($from !== null) {
                $inner->where('payments.paid_at', '>=', $this->startOfDayUtc($from));
            }

            if ($to !== null) {
                $inner->where('payments.paid_at', '<', $this->startOfDayUtc($to->copy()->addDay()));
            }
        });
    }

    /**
     * Midnight on the operator's own clock, expressed in UTC.
     *
     * The window an operator types is in their timezone; the column is UTC
     * (§1.4). Comparing a local date against a UTC timestamp puts three hours
     * of every summer day in the wrong month, which is invisible in a file of
     * a thousand rows and wrong in every one of them.
     */
    private function startOfDayUtc(Carbon $date): string
    {
        return $date
            ->copy()
            ->startOfDay()
            ->shiftTimezone($this->timezone())
            ->utc()
            ->toDateTimeString();
    }

    private function timezone(): string
    {
        $timezone = $this->job->tenant?->timezone;

        return is_string($timezone) && $timezone !== ''
            ? $timezone
            : (string) config('kaiki.defaults.timezone', 'Europe/Athens');
    }

    /** @return list<string> */
    private function bookingRow(Booking $booking): array
    {
        return [
            $booking->reference,
            $booking->status->label(),
            $booking->created_at?->toDateTimeString() ?? '',
            $booking->local_date->toDateString(),
            substr($booking->local_time, 0, 5),
            (string) $booking->product?->title,
            // `vessel_id` is nullable — a quote-mode booking has no boat yet —
            // so this column is empty rather than absent.
            (string) $booking->vessel?->name,
            $booking->guest_name,
            $booking->guest_email,
            (string) ($booking->guest_phone ?? ''),
            (string) $booking->pax_total,
            $this->currency($booking),
            $this->amount($booking->subtotal_cents),
            $this->amount($booking->extras_cents),
            $this->amount($booking->discount_cents),
            $this->amount($booking->total_cents),
            // Basis points as a percentage, because "13" is what an accountant
            // reads and "1300" is what the column stores.
            $this->rate($booking->vat_rate_bp),
            $this->amount($booking->vat_cents),
            $this->amount($booking->paid_cents),
            $this->amount($booking->refunded_cents),
            $this->amount($booking->balance_cents),
            // Labels, not values. `widget` and `wp_plugin` are how this system
            // spells them; an operator reading a column called "source" wants
            // to know the booking came from their own website.
            $booking->source->label(),
            $booking->cancelled_at?->toDateTimeString() ?? '',
            $booking->cancel_reason?->label() ?? '',
        ];
    }

    /** @return list<string> */
    private function guestRow(BookingGuest $guest): array
    {
        $booking = $guest->booking;
        $ageBand = $guest->ageBand;

        return [
            (string) $booking?->reference,
            (string) $booking?->local_date->toDateString(),
            (string) $booking?->product?->title,
            (string) $booking?->vessel?->name,
            (string) $guest->position,
            // A guest whose details nobody filled in is a blank name, not a
            // missing row — the same decision the manifest makes, and for the
            // same reason: a short list that looks complete is worse than one
            // with visible gaps.
            (string) ($guest->full_name ?? ''),
            $guest->date_of_birth?->toDateString() ?? '',
            (string) ($guest->nationality ?? ''),
            $guest->age_band_code,
            // An age band deleted since the booking leaves `age_band_id` null
            // (`nullOnDelete`), and the honest default is that the person took
            // a seat — understating the head count is the error that matters.
            $this->boolean($ageBand instanceof AgeBand ? $ageBand->counts_toward_capacity : true),
            $guest->checked_in_at?->toDateTimeString() ?? '',
            $this->boolean($guest->no_show),
        ];
    }

    /** Integer cents as a plain decimal. See the class docblock. */
    private function amount(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    /** Basis points as a percentage: `1300` is `13.00`. */
    private function rate(int $basisPoints): string
    {
        return number_format($basisPoints / 100, 2, '.', '');
    }

    /**
     * Yes and no, translated.
     *
     * `1`/`0` would be smaller and is what a developer reads. This file is read
     * by an operator and their accountant, and I18N-1 does not stop at the
     * screen.
     */
    private function boolean(bool $value): string
    {
        return (string) __($value ? 'exports.yes' : 'exports.no');
    }

    private function currency(Booking $booking): string
    {
        $currency = $booking->tenant?->currency;

        return is_string($currency) && $currency !== '' ? $currency : 'EUR';
    }
}
