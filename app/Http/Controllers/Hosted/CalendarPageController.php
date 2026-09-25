<?php

declare(strict_types=1);

namespace App\Http\Controllers\Hosted;

use App\Data\Availability\DepartureCalendarCriteria;
use App\Domain\Availability\LocalDateTimeResolver;
use App\Domain\Availability\Support\DepartureCalendar;
use App\Domain\Branding\Actions\GetBrandPayload;
use App\Domain\Hosted\Support\CalendarQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * `book.{platform-domain}/{operator-slug}/calendar` — «Ημερολόγιο» (2026-09-25).
 *
 * Every scheduled departure of every trip, fourteen days to a page, one line
 * each: direction Β of `docs/mockups/departures-calendar.html`, with Α's month
 * as a way to jump.
 *
 * Built the way the search page is, and for the same reasons: rendered by the
 * server, every filter in the query string, no JavaScript (HOS-4). The link is
 * the state — «the mornings of the week of the 9th, for four of us» is a URL a
 * guest can send to whoever they are travelling with.
 *
 * A malformed or past `from` is today rather than a refusal, as the search
 * page treats `date`: this is a page people land on from links.
 */
class CalendarPageController extends HostedController
{
    /** The largest party the page will price; a typo of `pax=200` is not a question. */
    private const MAX_PAX = 99;

    public function __construct(
        GetBrandPayload $brand,
        private readonly DepartureCalendar $calendar,
    ) {
        parent::__construct($brand);
    }

    public function show(Request $request, string $operator): Response
    {
        $tenant = $this->tenant();
        $locale = $this->resolveLocale($request, $tenant);

        $today = Carbon::now(LocalDateTimeResolver::timezone())->toDateString();
        $criteria = $this->criteria($request, $today);
        $result = ($this->calendar)($criteria);
        $month = $this->sheetMonth($request, $criteria->from, $today);

        $data = [
            'criteria' => $criteria,
            'result' => $result,
            'today' => $today,
            'query' => CalendarQuery::for($request->url(), $criteria, $today, $locale),
            'month' => $month,
            'monthMarks' => $this->calendar->month($result->products, $month),
            // The «Μήνας» sheet opens by itself when its own arrows were
            // pressed: a `<details>` has no memory, so the URL is it.
            'monthOpen' => $request->query('m') !== null,
        ];

        return $this->respond($request, 'hosted.calendar', 'hosted.partials.calendar-body', $data, $locale);
    }

    /**
     * The page, or — for `calendar.js` — only the block it swaps.
     *
     * @param  array<string, mixed>  $data
     */
    private function respond(Request $request, string $view, string $partial, array $data, string $locale): Response
    {
        $tenant = $this->tenant();

        // `calendar.js` asking for the next answer to a filter: only the block
        // it swaps, not the page around it. The same view renders it inside the
        // page, so the two cannot drift.
        if (self::wantsBody($request)) {
            return response(view($partial, [...$data, 'tenant' => $tenant, 'locale' => $locale])->render())
                ->header('Vary', 'X-Requested-With')
                ->header('Cache-Control', 'no-store, private');
        }

        $response = $this->render($request, $tenant, $view, fn (): array => [
            ...$data,
            'metaDescription' => __('hosted.calendar.meta', ['operator' => $tenant->name]),
        ], $locale);

        // One URL, two bodies: a cache must never hand the partial to a
        // browser that asked for the page, or the reverse.
        $response->headers->set('Vary', 'X-Requested-With');

        return $response;
    }

    /** Is this `calendar.js` asking for the swappable block rather than the page? */
    private static function wantsBody(Request $request): bool
    {
        return $request->header('X-Requested-With') === 'XMLHttpRequest'
            && $request->header('X-Kaiki-Calendar') === 'body';
    }

    protected function criteria(Request $request, string $today): DepartureCalendarCriteria
    {
        $from = $request->query('from');
        $from = is_string($from) && self::isDate($from) && $from >= $today ? $from : $today;

        $pax = $request->query('pax');
        $pax = is_numeric($pax) ? min(self::MAX_PAX, max(1, (int) $pax)) : 2;

        $trips = $request->query('trip', []);
        $trips = array_values(array_filter(
            is_array($trips) ? $trips : [$trips],
            static fn (mixed $slug): bool => is_string($slug) && preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug) === 1,
        ));

        $part = $request->query('part');

        return new DepartureCalendarCriteria(
            from: $from,
            days: DepartureCalendar::DAYS,
            pax: $pax,
            tripSlugs: $trips,
            part: is_string($part) && in_array($part, DepartureCalendarCriteria::PARTS, true) ? $part : null,
            onlyFree: $request->query('free') === '1',
        );
    }

    /** The month the «Μήνας» sheet shows: `?m=`, else the one the page starts in. */
    protected function sheetMonth(Request $request, string $from, string $today): Carbon
    {
        $raw = $request->query('m');

        if (is_string($raw) && preg_match('/^(\d{4})-(\d{2})$/', $raw, $parts) === 1 && checkdate((int) $parts[2], 1, (int) $parts[1])) {
            $month = Carbon::createFromDate((int) $parts[1], (int) $parts[2], 1)->startOfDay();

            // Never before this month: there is nothing to jump to there.
            return $month->lessThan(Carbon::parse($today)->startOfMonth()) ? Carbon::parse($today)->startOfMonth() : $month;
        }

        return Carbon::parse($from)->startOfMonth();
    }

    private static function isDate(string $value): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) === 1
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }
}
