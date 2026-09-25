<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Support;

use App\Domain\Booking\Support\TestSeats;
use App\Domain\Operations\Support\DashboardFigures;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\DepartureStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Models\AnalyticsDaily;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\DiscountCode;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Vessel;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The operator's own numbers over a period they choose.
 *
 * ## Three bases, and the screen says which is which
 *
 * The temptation is one "period" that everything hangs off, and it produces a
 * page whose figures quietly disagree. A booking taken in June for an August
 * sailing, paid in two instalments, belongs to three different months depending
 * on the question — and every one of those answers is right for its question:
 *
 * - **Revenue** is money *received*, by `paid_at`. It is what reconciles
 *   against a bank statement, which is the only test an operator actually runs
 *   on a revenue figure.
 * - **Bookings and passengers** are sales *made*, by `created_at`. It is what
 *   answers "was the campaign any good".
 * - **Occupancy** is boats that *sailed*, by the departure's local date. It is
 *   the only one of the three that can be checked by standing on the quay.
 *
 * Each block on the page names its basis in a sentence. Getting this wrong is
 * not a rounding error: an operator who cannot reconcile one figure stops
 * believing all of them, and does not say so.
 *
 * ## Revenue is defined once, in {@see DashboardFigures}
 *
 * OPS-2 fixed that definition for the dashboard's "revenue this week": succeeded
 * payments by `paid_at`, **minus** refunds rather than excluding them, non-test
 * bookings only. This class generalises the window and changes nothing else. A
 * second definition of revenue in the same panel is how a figure stops matching
 * the export nobody re-checks.
 *
 * ## Test bookings are excluded from every figure (SAA-12)
 *
 * The same rule as the dashboard, and for the same reason: the sandbox writes
 * real rows, and a report counting them shows money that does not exist.
 *
 * ## Why the two series read rows instead of grouping in SQL
 *
 * A chart's buckets are *local* days, and the columns are UTC. Converting a
 * timestamp to a tenant's timezone inside a query is `CONVERT_TZ` on MySQL and
 * nothing at all on SQLite — engine-specific SQL, which this project does not
 * write, and the local stack could not run. So the two series read the
 * timestamp and the amount for the period and bucket them in PHP. That is
 * bounded by what happened in the period the operator asked about, which is
 * what a report is; everything else on the page is an aggregate.
 */
final class AnalyticsFigures
{
    /** A sailing this empty is worth a second look. */
    public const QUIET_FILL = 0.5;

    /**
     * Counted metrics per range, so the funnel and the visit count are one query.
     *
     * @var array<string, array<string, array{count: int, value: int}>>
     */
    private array $countsByRange = [];

    /**
     * The windows {@see leadTime()} sorts bookings into, in order.
     *
     * Five, because six was a chart nobody could read at a glance and four put
     * «a week out» and «a month out» in the same bar — the two an operator
     * plans differently.
     *
     * @var list<string>
     */
    public const LEAD_TIME_BUCKETS = ['same_day', 'two_days', 'week', 'month', 'earlier'];

    public function __construct(private readonly string $timezone) {}

    public static function forCurrentTenant(): self
    {
        return new self(Tenancy::current()?->timezone ?: config('app.timezone', 'UTC'));
    }

    public function timezone(): string
    {
        return $this->timezone;
    }

    // -- headline ---------------------------------------------------------

    /**
     * Money received in the period, net of refunds, in cents.
     *
     * {@see DashboardFigures::revenueThisWeek()} is the same query with a
     * different window, and the comment there is the definition.
     */
    public function revenue(LocalRange $range): int
    {
        $row = $this->paymentsIn($range)
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN kind = ? THEN -amount_cents ELSE amount_cents END), 0) as net',
                [PaymentKind::Refund->value],
            )
            ->first();

        return (int) ($row->net ?? 0);
    }

    /**
     * Bookings made in the period, the passengers on them, and what they came to.
     *
     * `pax_total` is the manifest headcount rather than `pax_capacity_total`:
     * an operator asking how many people they carried means people, and an
     * infant who takes no seat is still somebody who came.
     *
     * @return array{bookings: int, pax: int, value: int}
     */
    public function sales(LocalRange $range): array
    {
        $row = $this->committedBookings()
            ->where('created_at', '>=', $range->startUtc)
            ->where('created_at', '<', $range->endUtcExclusive)
            ->selectRaw('COUNT(*) as bookings, COALESCE(SUM(pax_total), 0) as pax, COALESCE(SUM(total_cents), 0) as value')
            ->first();

        return [
            'bookings' => (int) ($row->bookings ?? 0),
            'pax' => (int) ($row->pax ?? 0),
            'value' => (int) ($row->value ?? 0),
        ];
    }

    /** The average booking, in cents, or null when there were none to average. */
    public function averageBooking(LocalRange $range): ?int
    {
        $sales = $this->sales($range);

        return $sales['bookings'] === 0 ? null : (int) round($sales['value'] / $sales['bookings']);
    }

    // -- over time --------------------------------------------------------

    /**
     * Revenue and bookings per bucket, with the quiet days left in.
     *
     * Filled from {@see LocalRange::buckets()} rather than from whatever the
     * data grouped into: a Tuesday with no bookings is a real zero, and a chart
     * that omits it draws a straight line through the quiet week an operator
     * most wants to see.
     *
     * @return list<array{bucket: string, revenue: int, bookings: int}>
     */
    public function series(LocalRange $range): array
    {
        $revenue = $range->buckets();
        $bookings = $range->buckets();

        foreach ($revenue as $bucket => $_) {
            $revenue[$bucket] = 0;
            $bookings[$bucket] = 0;
        }

        foreach ($this->paymentsIn($range)->get(['paid_at', 'kind', 'amount_cents']) as $payment) {
            $bucket = $range->bucketOf(Carbon::parse($payment->paid_at)->setTimezone($this->timezone)->toDateString());

            if (! array_key_exists($bucket, $revenue)) {
                continue;
            }

            $signed = $payment->kind === PaymentKind::Refund ? -$payment->amount_cents : $payment->amount_cents;
            $revenue[$bucket] += (int) $signed;
        }

        $made = $this->committedBookings()
            ->where('created_at', '>=', $range->startUtc)
            ->where('created_at', '<', $range->endUtcExclusive)
            ->get(['created_at']);

        foreach ($made as $booking) {
            $bucket = $range->bucketOf($booking->created_at->setTimezone($this->timezone)->toDateString());

            if (array_key_exists($bucket, $bookings)) {
                $bookings[$bucket]++;
            }
        }

        $series = [];

        foreach ($revenue as $bucket => $amount) {
            $series[] = [
                'bucket' => (string) $bucket,
                'revenue' => (int) $amount,
                'bookings' => (int) $bookings[$bucket],
            ];
        }

        return $series;
    }

    // -- per trip and per boat --------------------------------------------

    /**
     * Bookings, passengers and revenue per trip.
     *
     * Revenue follows the money rather than the booking, so a trip's figure is
     * what was actually paid for it in the period — the same basis as the
     * headline, so the rows sum to it.
     *
     * @return list<array{label: string, bookings: int, pax: int, revenue: int}>
     */
    public function byProduct(LocalRange $range): array
    {
        return $this->breakdown($range, 'product_id', function (array $ids): array {
            return Product::query()
                ->whereIn('id', $ids)
                ->get()
                ->mapWithKeys(static fn (Product $product): array => [$product->getKey() => (string) $product->title])
                ->all();
        });
    }

    /**
     * The same, per boat.
     *
     * @return list<array{label: string, bookings: int, pax: int, revenue: int}>
     */
    public function byVessel(LocalRange $range): array
    {
        return $this->breakdown($range, 'vessel_id', function (array $ids): array {
            return Vessel::query()
                ->whereIn('id', $ids)
                ->get()
                ->mapWithKeys(static fn (Vessel $vessel): array => [$vessel->getKey() => (string) $vessel->name])
                ->all();
        });
    }

    // -- occupancy --------------------------------------------------------

    /**
     * Seats sold against seats offered, for everything that sailed.
     *
     * Cancelled and blocked departures are excluded: a boat that did not go has
     * no empty seats to account for, and counting its capacity would make a
     * cancelled week look like a badly sold one.
     *
     * @return array{capacity: int, sold: int, departures: int, rate: float|null}
     */
    public function occupancy(LocalRange $range): array
    {
        $row = $this->sailings($range)
            ->selectRaw('COUNT(*) as departures, COALESCE(SUM(capacity), 0) as capacity, COALESCE(SUM(' . TestSeats::realSoldSql() . '), 0) as sold')
            ->first();

        $capacity = (int) ($row->capacity ?? 0);
        $sold = (int) ($row->sold ?? 0);

        return [
            'departures' => (int) ($row->departures ?? 0),
            'capacity' => $capacity,
            'sold' => $sold,
            // Null rather than zero for a period with no sailings: "0% full" and
            // "nothing sailed" are different facts and the screen says so.
            'rate' => $capacity === 0 ? null : $sold / $capacity,
        ];
    }

    /**
     * Occupancy per calendar month of the range.
     *
     * `substr(local_date, 1, 7)` rather than a date function: `local_date` is
     * already the operator's own calendar day, stored as text, and `substr` is
     * the one spelling SQLite and MySQL 8 agree on.
     *
     * @return list<array{month: string, capacity: int, sold: int, rate: float|null}>
     */
    public function occupancyByMonth(LocalRange $range): array
    {
        $rows = $this->sailings($range)
            ->selectRaw('substr(local_date, 1, 7) as month, COALESCE(SUM(capacity), 0) as capacity, COALESCE(SUM(' . TestSeats::realSoldSql() . '), 0) as sold')
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        // `getAttribute()` rather than a property: these are aggregate aliases
        // that no model declares, and reading them as properties is exactly
        // what PHPStan is right to refuse — a typo in an alias would otherwise
        // be a silent null all the way to the screen.
        return $rows->map(static function (Departure $row): array {
            $capacity = (int) $row->getAttribute('capacity');
            $sold = (int) $row->getAttribute('sold');

            return [
                'month' => (string) $row->getAttribute('month'),
                'capacity' => $capacity,
                'sold' => $sold,
                'rate' => $capacity === 0 ? null : $sold / $capacity,
            ];
        })->all();
    }

    /**
     * Occupancy per trip, for the sailings in the range.
     *
     * @return list<array{label: string, capacity: int, sold: int, rate: float|null}>
     */
    public function occupancyByProduct(LocalRange $range): array
    {
        $rows = $this->sailings($range)
            ->selectRaw('product_id, COALESCE(SUM(capacity), 0) as capacity, COALESCE(SUM(' . TestSeats::realSoldSql() . '), 0) as sold')
            ->groupBy('product_id')
            ->get();

        $titles = Product::query()
            ->whereIn('id', $rows->pluck('product_id')->filter()->all())
            ->get()
            ->mapWithKeys(static fn (Product $product): array => [$product->getKey() => (string) $product->title])
            ->all();

        return $rows->map(static function (Departure $row) use ($titles): array {
            $capacity = (int) $row->getAttribute('capacity');
            $sold = (int) $row->getAttribute('sold');

            return [
                'label' => (string) ($titles[(int) $row->getAttribute('product_id')] ?? '—'),
                'capacity' => $capacity,
                'sold' => $sold,
                'rate' => $capacity === 0 ? null : $sold / $capacity,
            ];
        })->sortBy('rate')->values()->all();
    }

    /**
     * The emptiest sailings in the range, worst first.
     *
     * This is the block that turns into money. An empty seat cannot be sold
     * afterwards, so a list of the departures that went out half full is the
     * only part of a statistics page an operator can act on next week — by
     * moving a time, dropping a day, or discounting one sailing rather than the
     * whole catalogue.
     *
     * @return list<array{date: string, time: string, label: string, capacity: int, sold: int, rate: float}>
     */
    public function quietSailings(LocalRange $range, float $fill = self::QUIET_FILL, int $limit = 10): array
    {
        $departures = $this->sailings($range)
            ->where('capacity', '>', 0)
            // The comparison is done in SQL so the limit is meaningful: without
            // it this would read every sailing in the period to throw most away.
            ->whereRaw('seats_sold < capacity * ?', [$fill])
            // `* 1.0` rather than a CAST: SQLite spells the type `REAL` and
            // MySQL 8 does not accept that in a CAST at all. Multiplying by a
            // float is the one spelling both agree on.
            ->orderByRaw('seats_sold * 1.0 / capacity')
            ->limit($limit)
            ->get(['local_date', 'local_time', 'product_id', 'capacity', 'seats_sold']);

        $titles = Product::query()
            ->whereIn('id', $departures->pluck('product_id')->filter()->all())
            ->get()
            ->mapWithKeys(static fn (Product $product): array => [$product->getKey() => (string) $product->title])
            ->all();

        return $departures->map(static fn (Departure $departure): array => [
            // `local_date` is cast to a date, so a plain string cast would hand
            // the view a midnight timestamp to print.
            'date' => $departure->local_date->format('Y-m-d'),
            'time' => substr((string) $departure->local_time, 0, 5),
            'label' => (string) ($titles[(int) $departure->product_id] ?? '—'),
            'capacity' => (int) $departure->capacity,
            'sold' => (int) $departure->seats_sold,
            'rate' => (int) $departure->seats_sold / max(1, (int) $departure->capacity),
        ])->all();
    }

    // -- where they came from ---------------------------------------------

    /**
     * Bookings and their value per channel.
     *
     * No tracking anywhere near this: `bookings.source` is written when the
     * booking is created, and says which surface it came through — the widget
     * on somebody's site, a hosted page, the WordPress plugin, or an operator
     * typing it in.
     *
     * @return list<array{source: string, bookings: int, pax: int, value: int}>
     */
    public function bySource(LocalRange $range): array
    {
        $rows = $this->committedBookings()
            ->where('created_at', '>=', $range->startUtc)
            ->where('created_at', '<', $range->endUtcExclusive)
            // Aliased away from `source`, because the model casts that column to
            // `BookingSource` and would hand this row an enum where a string is
            // being read. An alias the model knows nothing about stays a string.
            ->selectRaw('source as channel, COUNT(*) as bookings, COALESCE(SUM(pax_total), 0) as pax, COALESCE(SUM(total_cents), 0) as value')
            ->groupBy('channel')
            ->orderByDesc('value')
            ->get();

        return $rows->map(static function (Booking $row): array {
            $channel = (string) $row->getAttribute('channel');

            return [
                'source' => $channel,
                'label' => BookingSource::tryFrom($channel)?->label() ?? $channel,
                'bookings' => (int) $row->getAttribute('bookings'),
                'pax' => (int) $row->getAttribute('pax'),
                'value' => (int) $row->getAttribute('value'),
            ];
        })->all();
    }

    /**
     * «Κουπόνια»: each discount code's uses and what its bookings came to, for
     * bookings made in the period (2026-09-17).
     *
     * By the operator's «Εσωτερικό όνομα» first, because that is the question —
     * which campaign — and the code second. Codes with no use in the period are
     * left out: a list of zeroes answers nothing.
     *
     * @return list<array{name: string, code: string, uses: int, revenue: int, discount: int}>
     */
    public function byDiscountCode(LocalRange $range): array
    {
        $rows = $this->committedBookings()
            ->where('created_at', '>=', $range->startUtc)
            ->where('created_at', '<', $range->endUtcExclusive)
            ->whereNotNull('discount_code_id')
            ->selectRaw('discount_code_id as code_id, COUNT(*) as uses, COALESCE(SUM(total_cents), 0) as revenue, COALESCE(SUM(discount_cents), 0) as discount')
            ->groupBy('code_id')
            ->orderByDesc('revenue')
            ->get();

        $codes = DiscountCode::query()->withTrashed()
            ->whereIn('id', $rows->map(static fn (Booking $row): int => (int) $row->getAttribute('code_id'))->all())
            ->get()
            ->keyBy('id');

        return $rows->map(static function (Booking $row) use ($codes): array {
            $code = $codes->get((int) $row->getAttribute('code_id'));

            return [
                'name' => $code instanceof DiscountCode ? $code->name : '—',
                'code' => $code instanceof DiscountCode ? $code->code : '—',
                'uses' => (int) $row->getAttribute('uses'),
                'revenue' => (int) $row->getAttribute('revenue'),
                'discount' => (int) $row->getAttribute('discount'),
            ];
        })->values()->all();
    }

    /**
     * The campaigns and sites that produced bookings.
     *
     * Bookings rather than visits, which is the whole point of doing it this
     * way: these rows exist because somebody paid, so they need no cookie, no
     * consent question and no third party. What they cannot say is how many
     * people came and did not book — that needs the visit counting of Phase B.
     *
     * @param  'utm_source'|'utm_campaign'|'utm_medium'  $column
     * @return list<array{value: string, bookings: int, revenue: int}>
     */
    public function byCampaign(LocalRange $range, string $column = 'utm_source', int $limit = 10): array
    {
        $rows = $this->committedBookings()
            ->where('created_at', '>=', $range->startUtc)
            ->where('created_at', '<', $range->endUtcExclusive)
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->selectRaw("{$column} as value, COUNT(*) as bookings, COALESCE(SUM(total_cents), 0) as revenue")
            ->groupBy('value')
            ->orderByDesc('bookings')
            ->limit($limit)
            ->get();

        return $rows->map(static fn (Booking $row): array => [
            'value' => (string) $row->getAttribute('value'),
            'bookings' => (int) $row->getAttribute('bookings'),
            'revenue' => (int) $row->getAttribute('revenue'),
        ])->all();
    }

    /**
     * The sites that sent bookers, by host.
     *
     * The host and not the full address: a referrer carrying a path is a
     * hundred rows that are all the same website, and the operator's question
     * is which site rather than which page.
     *
     * @return list<array{value: string, bookings: int}>
     */
    public function byReferrer(LocalRange $range, int $limit = 10): array
    {
        $urls = $this->committedBookings()
            ->where('created_at', '>=', $range->startUtc)
            ->where('created_at', '<', $range->endUtcExclusive)
            ->whereNotNull('referrer_url')
            ->where('referrer_url', '!=', '')
            ->pluck('referrer_url');

        $hosts = [];

        foreach ($urls as $url) {
            $host = parse_url((string) $url, PHP_URL_HOST);

            if (! is_string($host) || $host === '') {
                continue;
            }

            $host = preg_replace('/^www\./', '', strtolower($host)) ?? $host;
            $hosts[$host] = ($hosts[$host] ?? 0) + 1;
        }

        arsort($hosts);

        $rows = [];

        foreach (array_slice($hosts, 0, $limit, true) as $host => $count) {
            $rows[] = ['value' => (string) $host, 'bookings' => (int) $count];
        }

        return $rows;
    }

    // -- what went wrong --------------------------------------------------

    /**
     * Cancellations and no-shows among the bookings made in the period.
     *
     * The denominator includes the cancelled ones, which is the only way the
     * ratio means anything: a rate computed against bookings that survived
     * would fall as cancellations rose.
     *
     * @return array{cancelled: int, no_show: int, total: int, rate: float|null}
     */
    public function cancellations(LocalRange $range): array
    {
        $from = $range->startUtc;
        $to = $range->endUtcExclusive;

        $cancelled = Booking::query()
            ->where('is_test', false)
            ->whereIn('status', [BookingStatus::Cancelled->value, BookingStatus::Refunded->value])
            ->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->count();

        $kept = $this->committedBookings()->where('created_at', '>=', $from)->where('created_at', '<', $to)->count();

        $noShow = $this->committedBookings()
            ->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->where('no_show', true)
            ->count();

        $total = $cancelled + $kept;

        return [
            'cancelled' => $cancelled,
            'no_show' => $noShow,
            'total' => $total,
            'rate' => $total === 0 ? null : $cancelled / $total,
        ];
    }

    // -- visits and the funnel (ADR-0032) ----------------------------------

    /**
     * The counted steps in the range, in funnel order.
     *
     * ## Counts, and the ratio between consecutive steps — not a conversion rate
     *
     * Cookieless means no visitor is followed from one step to the next
     * (ADR-0032), so "40% of people who looked went on to book" is a sentence
     * this data cannot support. What it can support is "there were 40 of these
     * and 16 of those", and the ratio between two counts. The page says which,
     * because a number called something it is not is worse than no number.
     *
     * @return list<array{metric: string, count: int, value: int, ratio: float|null}>
     */
    public function funnel(LocalRange $range): array
    {
        $counted = $this->counts($range);
        $steps = [];
        $previous = null;

        foreach (AnalyticsMetric::funnel() as $metric) {
            $count = $counted[$metric->value]['count'] ?? 0;

            $steps[] = [
                'metric' => $metric->value,
                'count' => $count,
                'value' => $counted[$metric->value]['value'] ?? 0,
                // Null for the first step and for a step whose predecessor was
                // never counted: a ratio to nothing is a division nobody can
                // read, and 0/0 is not "nought per cent".
                // Cast, because PHP's `/` hands back an `int` when the division
                // is exact — `0 / 25` is `0`, not `0.0` — and a shape that is
                // sometimes int and sometimes float is one every caller has to
                // remember.
                'ratio' => $previous === null || $previous === 0 ? null : (float) ($count / $previous),
            ];

            $previous = $count;
        }

        return $steps;
    }

    /**
     * Which days of the week sail, and with how many people on board.
     *
     * The question behind it is a schedule: an operator looking at seven
     * numbers can see that Tuesday is half of Saturday and move a departure,
     * which no daily chart of a whole season shows — thirty Tuesdays and thirty
     * Saturdays are one flat line until they are folded.
     *
     * Grouped on the **sailing** day (`local_date`), not on the day the
     * booking was made. «Πότε ταξιδεύουν» and «πότε αγοράζουν» are two
     * different questions and the second one is {@see leadTime()}.
     *
     * Folded in PHP rather than in the database on purpose: every engine
     * spells weekday extraction differently (`strftime('%w')`, `DAYOFWEEK`,
     * `EXTRACT(DOW)`) and they do not agree on which day is 0, so a query that
     * works on SQLite quietly reports Sunday's figures under Monday on MySQL.
     *
     * Monday first, which is the week as it is read here.
     *
     * @return list<array{weekday: int, bookings: int, pax: int}>
     */
    public function byWeekday(LocalRange $range): array
    {
        $rows = $this->committedBookings()
            ->whereBetween('local_date', [$range->startLocalDate, $range->endLocalDate])
            ->selectRaw('local_date, COUNT(*) as bookings, COALESCE(SUM(pax_total), 0) as pax')
            ->groupBy('local_date')
            ->get();

        // Every day of the week, including the ones nobody sailed: a week with
        // Tuesday missing reads as a six-day week rather than as a quiet day.
        $week = [];

        for ($weekday = 1; $weekday <= 7; $weekday++) {
            $week[$weekday] = ['weekday' => $weekday, 'bookings' => 0, 'pax' => 0];
        }

        foreach ($rows as $row) {
            // `getAttribute`, like `counts()` below: the two sums are columns
            // of this query, not properties of a Booking.
            $weekday = (int) Carbon::parse((string) $row->getAttribute('local_date'))->isoWeekday();

            $week[$weekday]['bookings'] += (int) $row->getAttribute('bookings');
            $week[$weekday]['pax'] += (int) $row->getAttribute('pax');
        }

        return array_values($week);
    }

    /**
     * How long before the boat leaves a booking is made.
     *
     * The figure that decides when the advertising money is spent and how late
     * a departure can still be filled. An operator whose bookings are mostly
     * same-day is running a business on the quay; one whose bookings are a
     * month out is running it on a website, and the two are managed
     * differently.
     *
     * The buckets are days, not hours, and the first one is «today»: a guest
     * who books at nine for a ten o'clock sailing and one who books the night
     * before are both last-minute, and splitting them would be precision about
     * a decision nobody makes differently.
     *
     * Counted from `created_at` in the operator's own timezone to the sailing's
     * local date, so «same day» means the same calendar day where the boat is —
     * a booking at 01:00 Athens time is not yesterday's because UTC says so.
     *
     * @return list<array{bucket: string, bookings: int}>
     */
    public function leadTime(LocalRange $range): array
    {
        $rows = $this->committedBookings()
            ->where('created_at', '>=', $range->startUtc)
            ->where('created_at', '<', $range->endUtcExclusive)
            ->get(['created_at', 'local_date']);

        $buckets = array_fill_keys(self::LEAD_TIME_BUCKETS, 0);

        foreach ($rows as $row) {
            $made = Carbon::parse((string) $row->getAttribute('created_at'))->setTimezone($this->timezone)->startOfDay();
            $sails = Carbon::parse((string) $row->getAttribute('local_date'))->startOfDay();

            // Negative days happen: a booking taken by the operator for a
            // sailing that has already gone, entered after the fact. It is
            // still a sale and it is still last-minute.
            $days = max(0, (int) $made->diffInDays($sails, false));

            $buckets[self::leadTimeBucket($days)]++;
        }

        return array_map(
            static fn (string $bucket): array => ['bucket' => $bucket, 'bookings' => $buckets[$bucket]],
            self::LEAD_TIME_BUCKETS,
        );
    }

    /** Which of {@see LEAD_TIME_BUCKETS} a number of days falls in. */
    private static function leadTimeBucket(int $days): string
    {
        return match (true) {
            $days === 0 => 'same_day',
            $days <= 2 => 'two_days',
            $days <= 7 => 'week',
            $days <= 30 => 'month',
            default => 'earlier',
        };
    }

    /** How many hosted pages were served in the range. */
    public function visits(LocalRange $range): int
    {
        return $this->counts($range)[AnalyticsMetric::PageView->value]['count'] ?? 0;
    }

    /** Has anything at all been counted yet? */
    public function hasCounts(LocalRange $range): bool
    {
        return $this->counts($range) !== [];
    }

    /**
     * Every counted metric in the range, summed.
     *
     * One query for the whole block, cached on the instance: the page asks for
     * the funnel and the visit count separately, and they are the same rows.
     *
     * @return array<string, array{count: int, value: int}>
     */
    private function counts(LocalRange $range): array
    {
        // **The tenant is in the key.** Without it, one instance asked for two
        // operators in one request answers the second with the first one's
        // numbers — which is a cross-tenant leak wearing the clothes of a
        // performance optimisation, and is exactly what the isolation suite
        // exists to catch. Found by a test that counted two tenants and asked
        // both.
        $key = (Tenancy::current()?->getKey() ?? 0) . ':' . $range->startLocalDate . '/' . $range->endLocalDate;

        if (array_key_exists($key, $this->countsByRange)) {
            return $this->countsByRange[$key];
        }

        $rows = AnalyticsDaily::query()
            ->whereBetween('date', [$range->startLocalDate, $range->endLocalDate])
            ->selectRaw('metric, COALESCE(SUM(count), 0) as counted, COALESCE(SUM(value_cents), 0) as value')
            ->groupBy('metric')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row->getAttribute('metric')?->value] = [
                'count' => (int) $row->getAttribute('counted'),
                'value' => (int) $row->getAttribute('value'),
            ];
        }

        return $this->countsByRange[$key] = $counts;
    }

    /** Is there test data being kept out of these figures? */
    public function hasTestBookings(): bool
    {
        return Booking::query()->where('is_test', true)->exists();
    }

    // -- the shared halves -------------------------------------------------

    /**
     * Succeeded payments in the window, on bookings that are not test data.
     *
     * @return Builder<Payment>
     */
    private function paymentsIn(LocalRange $range): Builder
    {
        return Payment::query()
            ->where('status', PaymentStatus::Succeeded->value)
            ->whereNotNull('paid_at')
            ->where('paid_at', '>=', $range->startUtc)
            ->where('paid_at', '<', $range->endUtcExclusive)
            ->whereHas('booking', fn (Builder $query): Builder => $query->where('is_test', false));
    }

    /**
     * Bookings that are going to happen, or have.
     *
     * The same three statuses {@see DashboardFigures} counts, and for the same
     * reasons: a `draft` is a fifteen-minute hold and a `pending_payment` is a
     * checkout in flight. Neither is a sale, and a report that counted them
     * would rise every time somebody opened the booking form.
     *
     * @return Builder<Booking>
     */
    private function committedBookings(): Builder
    {
        return Booking::query()
            ->where('is_test', false)
            ->whereIn('status', [
                BookingStatus::Confirmed->value,
                BookingStatus::CheckedIn->value,
                BookingStatus::Completed->value,
            ]);
    }

    /**
     * Departures that actually sailed in the range.
     *
     * ## Which means they have already left
     *
     * A departure in three weeks has a capacity and almost no seats sold yet,
     * and counting it as occupancy is how "this year" reported 0.4% on an
     * operator whose summer was nearly full: nineteen thousand seats offered,
     * most of them on boats that have not gone anywhere. Found by looking at
     * the page rather than at the query.
     *
     * So the window is closed at **now**: a sailing that has not left has no
     * occupancy to report, and a forward-looking period simply has less in it.
     * The same filter is what keeps the "emptiest sailings" list from being a
     * list of next month's empty boats, which is a schedule rather than a
     * finding.
     *
     * Cancelled and blocked ones are out for the other half of the reason: a
     * boat that did not go has no empty seats either, and counting its capacity
     * would make a cancelled week look like a badly sold one.
     *
     * @return Builder<Departure>
     */
    private function sailings(LocalRange $range): Builder
    {
        return Departure::query()
            ->whereBetween('local_date', [$range->startLocalDate, $range->endLocalDate])
            ->where('starts_at_utc', '<', Carbon::now())
            ->where('is_blocked', false)
            ->where('status', '!=', DepartureStatus::Cancelled->value);
    }

    /**
     * One grouped table: count and passengers from the bookings, revenue from
     * the payments made against them.
     *
     * Two queries rather than one join with two aggregates in it — a booking
     * with three payments would multiply its own pax count by three, which is
     * the classic fan-out and is invisible until somebody checks a row by hand.
     *
     * @param  callable(list<int>): array<int, string>  $labels
     * @return list<array{label: string, bookings: int, pax: int, revenue: int}>
     */
    private function breakdown(LocalRange $range, string $column, callable $labels): array
    {
        $from = $range->startUtc;
        $to = $range->endUtcExclusive;

        $made = $this->committedBookings()
            ->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->whereNotNull($column)
            ->selectRaw("{$column} as grouped, COUNT(*) as bookings, COALESCE(SUM(pax_total), 0) as pax")
            ->groupBy('grouped')
            ->get()
            ->keyBy('grouped');

        // Built from scratch rather than from `paymentsIn()`: that one keeps
        // test bookings out with `whereHas`, whose subquery is also called
        // `bookings`, and a query cannot join a table it is already aliasing.
        // The join does the same job here, in one pass.
        $paid = Payment::query()
            ->join('bookings', 'bookings.id', '=', 'payments.booking_id')
            ->where('payments.status', PaymentStatus::Succeeded->value)
            ->whereNotNull('payments.paid_at')
            ->where('payments.paid_at', '>=', $range->startUtc)
            ->where('payments.paid_at', '<', $range->endUtcExclusive)
            ->where('bookings.is_test', false)
            ->whereNotNull("bookings.{$column}")
            ->selectRaw(
                "bookings.{$column} as grouped, COALESCE(SUM(CASE WHEN payments.kind = ? THEN -payments.amount_cents ELSE payments.amount_cents END), 0) as revenue",
                [PaymentKind::Refund->value],
            )
            ->groupBy('grouped')
            ->get()
            ->keyBy('grouped');

        $ids = array_map('intval', array_unique([...$made->keys()->all(), ...$paid->keys()->all()]));

        if ($ids === []) {
            return [];
        }

        $names = $labels($ids);
        $rows = [];

        foreach ($ids as $id) {
            $rows[] = [
                'label' => (string) ($names[$id] ?? '—'),
                'bookings' => (int) ($made[$id]->bookings ?? 0),
                'pax' => (int) ($made[$id]->pax ?? 0),
                'revenue' => (int) ($paid[$id]->revenue ?? 0),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $b['revenue'] <=> $a['revenue']);

        return $rows;
    }
}
