<?php

declare(strict_types=1);

namespace App\Domain\Hosted\Support;

use App\Data\Availability\DepartureCalendarCriteria;

/**
 * The departures calendar's links, built from the page's own query string.
 *
 * Every control on that page is a link rather than a form field — the days,
 * the weeks, the month, the party, the part of the day, each trip — because
 * the page runs with no JavaScript (HOS-4) and a link is a filter that works
 * the moment it is pressed. This is what keeps those links honest: each one is
 * the current query with exactly one thing changed.
 *
 * Built on the current URL rather than a route name, so on an operator's own
 * domain (HOS-3) the links stay on that domain.
 */
final class CalendarQuery
{
    /**
     * @param  string  $base  the page's URL without its query
     * @param  array<string, mixed>  $params  what the page was asked, defaults left out
     */
    public function __construct(
        private readonly string $base,
        private readonly array $params,
    ) {}

    public static function for(string $base, DepartureCalendarCriteria $criteria, string $today, string $locale): self
    {
        return new self($base, array_filter([
            'lang' => $locale,
            'from' => $criteria->from === $today ? null : $criteria->from,
            'pax' => $criteria->pax === 2 ? null : $criteria->pax,
            'trip' => $criteria->tripSlugs === [] ? null : $criteria->tripSlugs,
            'part' => $criteria->part,
            'free' => $criteria->onlyFree ? 1 : null,
        ], static fn (mixed $value): bool => $value !== null));
    }

    /**
     * The page with some parameters changed; a null removes one.
     *
     * @param  array<string, mixed>  $changes
     */
    public function url(array $changes = [], ?string $fragment = null): string
    {
        $params = $this->params;

        // A month shown in the «Μήνας» sheet belongs to that one look, not to
        // whatever the guest presses next.
        unset($params['m']);

        foreach ($changes as $key => $value) {
            if ($value === null || $value === []) {
                unset($params[$key]);
            } else {
                $params[$key] = $value;
            }
        }

        $pairs = [];

        foreach ($params as $key => $value) {
            foreach ((array) $value as $one) {
                // `trip[]=a&trip[]=b` rather than `trip%5B0%5D=a`: the same array
                // to Laravel, and a link somebody can read before they send it.
                $pairs[] = (is_array($value) ? $key . '[]' : $key) . '=' . rawurlencode((string) $one);
            }
        }

        return $this->base
            . ($pairs === [] ? '' : '?' . implode('&', $pairs))
            . ($fragment === null ? '' : '#' . $fragment);
    }

    /** The filters cleared, the dates and the language kept. */
    public function cleared(): string
    {
        return $this->url(['pax' => null, 'trip' => null, 'part' => null, 'free' => null]);
    }

    /** Is this trip among the ones shown? With no `trip[]`, every trip is. */
    public function tripIsOn(string $slug): bool
    {
        $trips = (array) ($this->params['trip'] ?? []);

        return $trips === [] || in_array($slug, $trips, true);
    }

    /**
     * The link that switches one trip on or off.
     *
     * With every trip shown, pressing one hides it — the list starts all
     * ticked, as the mockup draws it. A set that ends up naming every trip is
     * written as no filter at all, so the URL does not grow a list of eight.
     *
     * @param  list<string>  $all  every trip the calendar lists
     */
    public function toggleTrip(string $slug, array $all): string
    {
        $current = (array) ($this->params['trip'] ?? []);
        $current = $current === [] ? $all : array_values(array_intersect($all, $current));

        $next = in_array($slug, $current, true)
            ? array_values(array_diff($current, [$slug]))
            : [...$current, $slug];

        $isAll = array_diff($all, $next) === [];

        return $this->url(['trip' => $isAll || $next === [] ? null : array_values($next)]);
    }

    /**
     * The same question asked of another page — the list and the month
     * («Λίστα | Μήνας») carry the filters across.
     */
    public function at(string $base): self
    {
        return new self($base, $this->params);
    }
}
