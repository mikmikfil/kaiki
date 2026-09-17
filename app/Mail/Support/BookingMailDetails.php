<?php

declare(strict_types=1);

namespace App\Mail\Support;

use App\Enums\GuestDetailsStatus;
use App\Enums\NotificationTemplate;
use App\Models\Booking;
use App\Models\Port;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;

/**
 * Everything the booking email says about a booking, worked out once
 * (product owner, 2026-09-17: design A «Κάρτα εισιτηρίου»).
 *
 * The template used to show a reference, a date and a meeting point's name, and
 * a guest wrote back to ask what time to be at the quay. The email now carries
 * the whole trip: when to arrive, when it leaves and comes back, where to meet
 * and how to find it, who is booked and what was paid, what to bring, the
 * cancellation terms, how to reach the operator, the ticket and — while it is
 * still missing — the passenger details.
 *
 * ## Arithmetic here, markup in the views
 *
 * Both halves of the email (NTF-6's HTML and plain text) render from this, so
 * they cannot disagree about the check-in time. Every value is already a
 * string in the booking's own locale, or null when there is nothing to say —
 * the views print what exists and leave out what does not, rather than a label
 * beside an empty value.
 *
 * ## Frozen where the booking froze it
 *
 * The party and its prices come off `pax_breakdown` and `extras_snapshot`, and
 * the cancellation sentence off `policy_snapshot` — what the guest agreed to,
 * never what the operator has typed since. The meeting point, the things to
 * bring and the operator's phone are read live, because a moved pontoon is news
 * the guest needs.
 */
final class BookingMailDetails
{
    /** The messages that carry the whole trip; the rest stay short. */
    private const FULL = [
        NotificationTemplate::BookingConfirmed,
        NotificationTemplate::BookingChanged,
        NotificationTemplate::PreDeparture24h,
    ];

    /**
     * @param  list<array{label: string, amount: string}>  $party
     * @param  list<string>  $bring
     */
    public function __construct(
        public readonly bool $full,
        public readonly string $locale,
        public readonly ?string $greeting,
        public readonly ?string $trip,
        public readonly ?string $boat,
        public readonly string $day,
        public readonly ?string $checkIn,
        public readonly string $departure,
        public readonly ?string $return,
        public readonly ?string $meetingName,
        public readonly ?string $meetingAddress,
        public readonly ?string $meetingInstructions,
        public readonly ?string $mapUrl,
        public readonly array $party,
        public readonly ?string $total,
        public readonly ?string $paid,
        public readonly ?string $balance,
        public readonly ?string $balanceDue,
        public readonly ?string $refund,
        public readonly array $bring,
        public readonly ?string $policy,
        public readonly ?string $phone,
        public readonly ?string $email,
        public readonly string $manageUrl,
        public readonly ?string $ticketUrl,
        public readonly ?string $detailsUrl,
        public readonly ?string $detailsBy,
    ) {}

    /** @param array<string, mixed> $extra */
    public static function for(Booking $booking, NotificationTemplate $template, string $locale, array $extra = []): self
    {
        $booking->loadMissing(['product.meetingPoint', 'vessel']);

        $product = $booking->product;
        $port = $product?->meetingPoint;
        // The branding page previews this with a booking it never saves, and so
        // has no tenant id: the operator whose page it is stands in.
        $tenant = $booking->tenant_id === null
            ? Tenancy::current()
            : Tenancy::withoutTenancy(static fn (): ?Tenant => Tenant::query()->find($booking->tenant_id));

        $starts = Carbon::parse($booking->local_date->toDateString() . ' ' . substr((string) $booking->local_time, 0, 5))
            ->locale($locale);

        $euros = static fn (int $cents): string => number_format($cents / 100, 2, ',', '.') . ' €';

        return new self(
            full: in_array($template, self::FULL, true),
            locale: $locale,
            greeting: self::blank($booking->guest_name) ? null : __('mail.common.greeting', ['name' => self::firstName((string) $booking->guest_name)], $locale),
            trip: $product instanceof Product ? self::text($product->getTranslation('title', $locale, true)) : null,
            boat: self::text($booking->vessel?->name),
            day: $starts->isoFormat('dddd D MMMM YYYY'),
            checkIn: $product instanceof Product && $product->check_in_offset_minutes > 0
                ? $starts->copy()->subMinutes($product->check_in_offset_minutes)->format('H:i')
                : null,
            departure: $starts->format('H:i'),
            return: $product instanceof Product && $product->duration_minutes > 0
                ? $starts->copy()->addMinutes($product->duration_minutes)->format('H:i')
                : null,
            meetingName: $port instanceof Port ? self::text($port->getTranslation('name', $locale, true)) : null,
            meetingAddress: $port instanceof Port ? self::text($port->address) : null,
            meetingInstructions: $port instanceof Port ? self::text($port->getTranslation('instructions', $locale, true)) : null,
            mapUrl: $port instanceof Port ? $port->mapsUrl() : null,
            party: self::party($booking, $locale, $euros),
            total: (int) $booking->total_cents > 0 ? $euros((int) $booking->total_cents) : null,
            paid: (int) $booking->paid_cents > 0 ? $euros((int) $booking->paid_cents) : null,
            balance: (int) $booking->balance_cents > 0 ? $euros((int) $booking->balance_cents) : null,
            balanceDue: (int) $booking->balance_cents > 0 && $booking->balance_due_at !== null
                ? $booking->balance_due_at->copy()->locale($locale)->isoFormat('dddd D/M')
                : null,
            refund: isset($extra['refunded_cents']) && (int) $extra['refunded_cents'] > 0
                ? $euros((int) $extra['refunded_cents'])
                : null,
            bring: $product instanceof Product ? self::lines($product->getTranslation('what_to_bring', $locale, true)) : [],
            policy: self::policy($booking, $locale),
            phone: $tenant instanceof Tenant ? self::text($tenant->phone ?? null) : null,
            email: $tenant instanceof Tenant ? self::text($tenant->email) : null,
            manageUrl: route('guest.booking', ['token' => $booking->manage_token]),
            ticketUrl: $tenant instanceof Tenant && $tenant->usesCheckIn()
                ? route('guest.ticket', ['token' => $booking->manage_token])
                : null,
            detailsUrl: $booking->guest_details_status === GuestDetailsStatus::Pending && ! self::blank($booking->guest_details_token)
                ? route('guest.details', ['token' => $booking->guest_details_token])
                : null,
            detailsBy: $booking->guest_details_status === GuestDetailsStatus::Pending && $booking->guest_details_deadline_at !== null
                ? $booking->guest_details_deadline_at->copy()->locale($locale)->isoFormat('D/M')
                : null,
        );
    }

    /**
     * «2 × Ενήλικας», then the extras, each with its total.
     *
     * Bookings from before the breakdown carried labels and prices (imports,
     * the earliest seeds) have only a band code: the label then comes from the
     * product's band of that code, and the amount is left blank rather than
     * printed as a misleading 0,00 €.
     *
     * @param  callable(int): string  $euros
     * @return list<array{label: string, amount: string}>
     */
    private static function party(Booking $booking, string $locale, callable $euros): array
    {
        $rows = [];
        $bands = null;

        foreach ((array) $booking->pax_breakdown as $line) {
            $qty = (int) ($line['qty'] ?? 0);

            if ($qty < 1) {
                continue;
            }

            $label = $line['label'] ?? null;

            if ($label === null || $label === '' || $label === []) {
                $bands ??= $booking->product?->ageBands()->get()->keyBy('code');
                $band = $bands?->get($line['code'] ?? '');
                $label = $band !== null ? $band->getTranslations('label') : ($line['code'] ?? '');
            }

            $rows[] = [
                'label' => $qty . ' × ' . self::label($label, $locale),
                'amount' => isset($line['total_cents']) ? $euros((int) $line['total_cents']) : '',
            ];
        }

        foreach ((array) $booking->extras_snapshot as $line) {
            $qty = (int) ($line['qty'] ?? 1);

            $rows[] = [
                'label' => $qty . ' × ' . self::label($line['label'] ?? ($line['ref'] ?? ''), $locale),
                'amount' => (bool) ($line['on_request'] ?? false)
                    ? __('mail.common.on_request', [], $locale)
                    : $euros((int) ($line['total_cents'] ?? 0)),
            ];
        }

        return $rows;
    }

    /** The frozen one-line cancellation terms, in the guest's language if written. */
    private static function policy(Booking $booking, string $locale): ?string
    {
        $summary = data_get($booking->policy_snapshot, 'summary');

        if (! is_array($summary)) {
            return null;
        }

        $text = $summary[$locale] ?? collect($summary)->first(
            static fn (mixed $value): bool => is_string($value) && trim($value) !== '',
        );

        return self::text($text);
    }

    private static function label(mixed $label, string $locale): string
    {
        if (is_array($label)) {
            $text = $label[$locale] ?? collect($label)->first(static fn (mixed $v): bool => is_string($v) && $v !== '');

            return is_string($text) ? $text : '';
        }

        return (string) $label;
    }

    /** @return list<string> */
    private static function lines(mixed $value): array
    {
        return array_values(array_filter(
            array_map(static fn (mixed $line): string => is_string($line) ? trim($line) : '', (array) $value),
            static fn (string $line): bool => $line !== '',
        ));
    }

    private static function firstName(string $name): string
    {
        return (string) strtok(trim($name), ' ');
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function blank(mixed $value): bool
    {
        return ! is_string($value) || trim($value) === '';
    }
}
