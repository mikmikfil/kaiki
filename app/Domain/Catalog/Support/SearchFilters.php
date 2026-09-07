<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Support;

use App\Domain\Hosted\Support\BlockSettings;
use App\Http\Requests\Api\V1\SearchRequest;
use App\Models\Tenant;
use App\Support\Tenancy;

/**
 * Which search filters an operator exposes, and which ones a request may use.
 *
 * ## The point of the class: a disabled filter is **ignored**, not hidden
 *
 * Hiding a filter in the template and honouring it in the controller is the
 * version that passes a visual review and fails the operator who switched it
 * off precisely because their answer would be embarrassing — the fleet with one
 * boat that does not want a vessel filter, the operator whose cheapest trip is
 * €180 and does not want a price slider anchored at zero.
 *
 * So the settings are read **here**, on the way in, and
 * {@see SearchRequest} asks this class rather than the
 * query string. A crafted `?vessel=` on an operator who disabled the vessel
 * filter reaches the Action as nothing at all, and `meta.filters_enabled` in the
 * response says so out loud.
 *
 * ## Why the defaults are these four
 *
 * The design review of 2026-09-04 settled it: **date, port, party size and trip
 * type shown; duration, price ceiling and vessel available but off**. An
 * operator with three trips wants two filters, not seven, and the four that are
 * on are the ones a guest asks about unprompted — when, from where, how many of
 * us, what kind of day.
 *
 * ## Stored in `tenants.settings`, not in a column
 *
 * Seven booleans that only the hosted search page and this endpoint read, with
 * no query ever filtering on them. `docs/data-model.md` keeps `settings` for
 * exactly this, and a column per filter would be seven migrations for a
 * preference.
 */
final class SearchFilters
{
    /** Always on: a search with no date is not a search. */
    public const DATE = 'date';

    public const PORT = 'port';

    /** The party size, which is also what the price is computed for. */
    public const PARTY = 'party';

    public const TYPE = 'type';

    public const DURATION = 'duration';

    /** A ceiling on the **party** price, never on a per-person figure. */
    public const PRICE = 'price';

    public const VESSEL = 'vessel';

    /** Where the operator's choices live inside `tenants.settings`. */
    public const SETTINGS_KEY = 'search';

    /**
     * Every filter this feature knows about, in the order the form shows them.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::DATE, self::PORT, self::PARTY, self::TYPE, self::DURATION, self::PRICE, self::VESSEL];
    }

    /**
     * The ones an operator cannot switch off.
     *
     * A search page with no date and no party size is a catalogue listing, which
     * the home page already is. These two are what make it a search.
     *
     * @return list<string>
     */
    public static function fixed(): array
    {
        return [self::DATE, self::PARTY];
    }

    /**
     * The design review's defaults, for an operator who has never opened the
     * settings screen — which is most of them, most of the time.
     *
     * @return array<string, bool>
     */
    public static function defaults(): array
    {
        return [
            self::DATE => true,
            self::PORT => true,
            self::PARTY => true,
            self::TYPE => true,
            self::DURATION => false,
            self::PRICE => false,
            self::VESSEL => false,
        ];
    }

    /**
     * This operator's choices, filled in and with the fixed ones forced on.
     *
     * Unknown keys are dropped and missing ones default, for the reason
     * {@see BlockSettings} gives about the same shape
     * of column: this runs on **read**, on a page a guest is looking at, so a
     * row written against an older shape has to render rather than throw.
     *
     * @return array<string, bool>
     */
    public static function for(?Tenant $tenant = null): array
    {
        $tenant ??= Tenancy::current();

        $stored = $tenant?->settings[self::SETTINGS_KEY]['filters'] ?? [];
        $stored = is_array($stored) ? $stored : [];

        $filters = [];

        foreach (self::defaults() as $filter => $default) {
            $filters[$filter] = array_key_exists($filter, $stored)
                ? filter_var($stored[$filter], FILTER_VALIDATE_BOOL)
                : $default;
        }

        foreach (self::fixed() as $filter) {
            $filters[$filter] = true;
        }

        return $filters;
    }

    /**
     * The enabled ones, as a list — what `meta.filters_enabled` publishes.
     *
     * @return list<string>
     */
    public static function enabled(?Tenant $tenant = null): array
    {
        return array_keys(array_filter(self::for($tenant)));
    }

    public static function isEnabled(string $filter, ?Tenant $tenant = null): bool
    {
        return self::for($tenant)[$filter] ?? false;
    }

    /**
     * The operator's choices, ready to store.
     *
     * Normalised on the way in as well as on the way out: the form posts `"1"`
     * and `""` for a checkbox, and a JSON column hands both back as strings —
     * `strict_comparison` then never matches and the filter silently does the
     * opposite of what the operator chose.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, bool>
     */
    public static function normalise(array $input): array
    {
        $filters = [];

        foreach (self::defaults() as $filter => $default) {
            $filters[$filter] = array_key_exists($filter, $input)
                ? filter_var($input[$filter], FILTER_VALIDATE_BOOL)
                : $default;
        }

        foreach (self::fixed() as $filter) {
            $filters[$filter] = true;
        }

        return $filters;
    }
}
