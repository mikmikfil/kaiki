<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Support;

use App\Domain\Operations\Support\FirstSteps;
use App\Models\BrandProfile;
use App\Models\CancellationPolicy;
use App\Models\Port;
use App\Models\Product;
use App\Models\Season;
use App\Models\Tenant;
use App\Models\Vessel;
use App\Observers\TenantObserver;
use App\Support\Tenancy;

/**
 * What an operator still has to set up, and what they have declined (#51, SAA-9, SAA-10).
 *
 * ## Every step is a question asked of the data, not a checkbox
 *
 * There is no stored "current step". An operator who adds their first boat from
 * the Σκάφη screen without ever opening the wizard has done that step, and a
 * pointer sitting in a column would still be telling them to do it. Deriving it
 * is what makes SAA-10's *"resumable"* free, and — the part that matters more —
 * correct: there is one answer to "is this done", and it is the same answer
 * whichever screen asks.
 *
 * Only two things are stored, because only two cannot be read back: which steps
 * were **skipped**, and whether the wizard was **finished**. See the migration
 * that adds them.
 *
 * ## Why this is not {@see FirstSteps}, which is also a checklist
 *
 * They answer different questions and one of them has a side effect.
 * `FirstSteps` is OPS-1: *this operator has never taken a booking, here is the
 * chain to their first one* — and while it applies it **suppresses the figures
 * widgets**, because six zeros on a first afternoon is a product that looks
 * broken. Folding SAA-9's steps into it would mean an operator with a full
 * calendar and an unfilled ΑΦΜ has their dashboard replaced by a checklist for
 * ever.
 *
 * So this covers the account — the business, its branding, its VAT, its first
 * boat and trip — and stops. It suppresses nothing. The two lists overlap on
 * the boat and the trip **by delegation** rather than by copying the query:
 * {@see FirstSteps::state()} owns those two answers and is asked for them.
 *
 * ## The last step is the only one that is not about data
 *
 * SAA-9 ends with *"the embed snippet and the hosted page link"*, and no row
 * anywhere records whether somebody read a snippet. `READY` is done when the
 * operator says they are done — which is what `onboarding_completed_at` is.
 */
final class SetupChecklist
{
    /** Legal name, ΑΦΜ, ΔΟΥ, address — the columns an invoice is built from. */
    public const BUSINESS = 'business';

    /** Logo, colours, font — `brand_profiles`, #17. */
    public const BRANDING = 'branding';

    /** The rate this account sells at, pre-filling every new product. */
    public const VAT = 'vat';

    /**
     * What a guest gets back when they cancel (product owner, 2026-09-17).
     *
     * Before the boat and the trip, because a trip cannot be published without
     * one and a new operator has none.
     */
    public const CANCELLATION = 'cancellation';

    /**
     * Where the boat leaves from (product owner, 2026-09-22).
     *
     * **Before the boat**, because a boat has a home port and a trip cannot be
     * published without a meeting point — and until this step existed a new
     * operator reached the trip guide with nothing to choose. It is the
     * shortest step in the guide: a name and a line of address.
     */
    public const PORT = 'port';

    /** A boat to sail on. Delegated to {@see FirstSteps}. */
    public const VESSEL = 'vessel';

    /**
     * The times of year the price changes (product owner, 2026-09-22).
     *
     * Between the boat and the trip because that is the order the answers are
     * needed in: an operator who has met periods here arrives at the trip form
     * knowing what the column «Θερινή» would be, and the price list they make
     * there can point at one. After the boat, because a season with nothing to
     * sail in it is an abstraction.
     *
     * **Often skipped, and that is a complete answer**: one price all year
     * needs no period, and the trip's own «Όλο τον χρόνο» list covers it. The
     * step says so rather than implying a period is required.
     */
    public const SEASON = 'season';

    /** Something to sell on it. Delegated to {@see FirstSteps}. */
    public const PRODUCT = 'product';

    /** The embed snippet and the hosted page — the hand-over, not a column. */
    public const READY = 'ready';

    /**
     * The steps in the order SAA-9 lists them.
     *
     * The order is not arbitrary and is not the order of difficulty: it is the
     * order in which each answer is needed by the next. Branding before a boat
     * because the hosted page exists from the first minute; VAT before a product
     * because it is what the product form pre-fills.
     *
     * @return list<string>
     */
    public static function steps(): array
    {
        return [
            self::BUSINESS,
            self::BRANDING,
            self::VAT,
            self::CANCELLATION,
            self::READY,
        ];
    }

    /**
     * The catalogue, which the guide no longer asks for.
     *
     * Product owner, 2026-09-22: *«λέω να φύγουν λιμάνια, πρώτο σκάφος και
     * εκδρομή και περίοδοι — αλλά μετά κάπως πρέπει να φαίνονται ότι πρέπει να
     * συμπληρωθούν, αλλά όχι μέσα στα steps»*.
     *
     * The guide is now four questions about the **account**: who you are on an
     * invoice, how you look, which VAT rate, on what terms you cancel. None of
     * them has a screen of its own that does the job better, which is what
     * makes a guide the right place to ask them.
     *
     * A port, a boat, a period and a trip are different: each has a real screen
     * built for it, and the trip has a five-step guide of its own. Asked inside
     * this one they were either a hand-off that threw the operator into the
     * panel, or a shortened copy of a form that already exists. They are still
     * **owed** — nothing sells without a boat and a trip — and they are still
     * reported by {@see state()} and shown on the dashboard, by
     * {@see FirstSteps} and the setup widget.
     * They are simply not questions in this guide.
     *
     * @return list<string>
     */
    public static function catalogueSteps(): array
    {
        return [
            self::PORT,
            self::VESSEL,
            self::SEASON,
            self::PRODUCT,
        ];
    }

    /**
     * Everything this class reports on: the guide's steps and the catalogue.
     *
     * Not the same as {@see steps()}, which is the guide alone. Both branches
     * of {@see state()} have to answer about the same set of keys, or a caller
     * reading it outside tenancy gets a shorter array than the one it gets
     * inside — which is how a dashboard list silently loses half its rows.
     *
     * @return list<string>
     */
    public static function reported(): array
    {
        return [...self::steps(), ...self::catalogueSteps()];
    }

    /**
     * The steps the operator is asked, without the closing screen.
     *
     * `READY` is the hand-over at the end, not a question, so it is not a row
     * in the step list and not part of «4 από 6».
     *
     * @return list<string>
     */
    public static function questions(): array
    {
        return array_values(array_diff(self::steps(), [self::READY]));
    }

    /**
     * Each step and whether it is done, in order.
     *
     * @return array<string, bool>
     */
    public static function state(): array
    {
        $tenant = Tenancy::check() ? Tenancy::current() : null;

        if ($tenant === null) {
            return array_fill_keys(self::reported(), false);
        }

        // Asked once. `FirstSteps::state()` runs two `exists()` queries and this
        // method is called by a widget on every dashboard render — twice, since
        // the widget wants both the state and the count.
        $catalogue = FirstSteps::state();

        // Every step, including the four the guide no longer asks: the
        // dashboard still tells the operator a boat is owed.
        return [
            self::BUSINESS => self::businessAnswered($tenant),
            self::BRANDING => self::brandingTouched($tenant),
            self::VAT => $tenant->default_vat_rate_id !== null,
            self::CANCELLATION => CancellationPolicy::query()->exists(),
            self::PORT => Port::query()->exists(),
            self::VESSEL => $catalogue[FirstSteps::VESSEL] ?? Vessel::query()->exists(),
            // Delegated since 22 September, when periods joined that list as
            // an optional step: one query, and one definition of «has this
            // operator set up a period».
            self::SEASON => $catalogue[FirstSteps::SEASON] ?? Season::query()->exists(),
            self::PRODUCT => $catalogue[FirstSteps::PRODUCT] ?? Product::query()->exists(),
            self::READY => $tenant->onboarding_completed_at !== null,
        ];
    }

    /**
     * The steps the operator has set aside.
     *
     * SAA-10 makes skipping a requirement rather than a convenience — an
     * operator whose accountant has not answered the VAT question must still
     * reach the panel and add their boats. A skipped step and an untouched step
     * are identical in the data and must not be identical on the screen.
     *
     * Unknown keys are dropped rather than trusted: this is a JSON column, and
     * a step that was renamed or removed would otherwise sit in it for ever,
     * counted against a total it is not part of.
     *
     * @return list<string>
     */
    public static function skipped(?Tenant $tenant = null): array
    {
        $tenant ??= Tenancy::check() ? Tenancy::current() : null;

        if ($tenant === null) {
            return [];
        }

        $stored = $tenant->onboarding_skipped_steps ?? [];

        return array_values(array_intersect(self::steps(), is_array($stored) ? $stored : []));
    }

    /**
     * The first step that is neither done nor skipped, or null when none is left.
     *
     * This is where the wizard opens. A skipped step is passed over rather than
     * returned to, which is the difference between resuming and nagging.
     */
    public static function next(): ?string
    {
        $skipped = self::skipped();

        foreach (self::state() as $step => $done) {
            if (! $done && ! in_array($step, $skipped, true)) {
                return $step;
            }
        }

        return null;
    }

    /**
     * How many steps are settled, out of how many there are.
     *
     * A skipped step counts as settled. It is not done and the checklist says
     * so, but "2 of 6" that never reaches 6 because the operator declined one
     * on purpose is a progress bar that lies about being stuck.
     *
     * @return array{done: int, total: int}
     */
    public static function progress(): array
    {
        $skipped = self::skipped();
        $done = 0;
        $state = self::state();

        foreach (self::questions() as $step) {
            if (($state[$step] ?? false) || in_array($step, $skipped, true)) {
                $done++;
            }
        }

        return ['done' => $done, 'total' => count(self::questions())];
    }

    /**
     * Should this operator still be shown the checklist?
     *
     * Finishing the wizard is the only thing that stops it. Not "every step is
     * green" — an operator who skipped the VAT step is not finished, they are
     * waiting for their accountant, and the checklist is exactly where that
     * outstanding item should stay visible.
     */
    public static function applies(): bool
    {
        $tenant = Tenancy::check() ? Tenancy::current() : null;

        // Switched off on /admin for an operator the platform set up itself:
        // no guide, no menu item, no checklist (2026-09-17). «Δεν το
        // χρειάζομαι» does the same thing at the operator's own hand
        // (2026-09-22) — the page stays reachable from Ρυθμίσεις, but nothing
        // offers it any more.
        return $tenant instanceof Tenant
            && $tenant->usesSetupGuide()
            && ! $tenant->hasDismissedSetupGuide()
            && $tenant->onboarding_completed_at === null;
    }

    /**
     * Does the guide stand in front of the panel right now (2026-09-22)?
     *
     * *"The first time configurator should open fullscreen and not have access
     * to panel before setting this up"* — so a brand-new operator meets the
     * guide and nothing else. The gate is narrow on purpose: it lifts the
     * moment the operator finishes it, defers it or dismisses it, and it never
     * comes back on its own. An operator who has said "later" once has said it
     * for good; asking again next week is the nagging SAA-10 forbids, wearing a
     * different hat.
     *
     * What it is **not** is a lock on the account: {@see Tenant::hasSetGuideAside()}
     * is one click away on the gate itself, and the platform can switch the
     * whole guide off from /admin.
     */
    public static function blocksPanel(): bool
    {
        $tenant = Tenancy::check() ? Tenancy::current() : null;

        return $tenant instanceof Tenant
            && ! $tenant->hasSetGuideAside()
            && self::applies();
    }

    /**
     * Has the business half of an invoice been filled in?
     *
     * The legal name and the ΑΦΜ, and not the rest of the address. Those two are
     * what an invoice cannot be issued without (ADR-0002, MYD-*); a missing
     * ΓΕΜΗ number is a gap in a record rather than a blocked document, and a
     * step that stays red over one is a step nobody finishes.
     */
    private static function businessAnswered(Tenant $tenant): bool
    {
        return filled($tenant->legal_name) && filled($tenant->vat_number);
    }

    /**
     * Has anybody chosen anything about how this operator looks?
     *
     * Every colour column has a default and {@see TenantObserver}
     * creates the row when the tenant is created, so a `brand_profiles` row
     * existing proves nothing at all. What proves it is an image — a logo is the
     * one field nobody ends up with by accident — or a colour that differs from
     * the platform's own.
     */
    private static function brandingTouched(Tenant $tenant): bool
    {
        $profile = $tenant->brandProfile;

        if (! $profile instanceof BrandProfile) {
            return false;
        }

        if (filled($profile->logo_light_path) || filled($profile->logo_dark_path)) {
            return true;
        }

        // `config('kaiki.branding.defaults.colors')` is keyed `primary`, while
        // the column is `color_primary` — the config is the platform's palette
        // and the column is one profile's copy of it. `BrandProfileDefaultsTest`
        // already asserts the two agree, so comparing against the config here
        // is comparing against the column default without reading the schema.
        $defaults = (array) config('kaiki.branding.defaults.colors', []);

        foreach (['primary', 'secondary', 'accent'] as $key) {
            $default = $defaults[$key] ?? null;

            if ($default !== null && strcasecmp((string) $profile->{'color_' . $key}, (string) $default) !== 0) {
                return true;
            }
        }

        return false;
    }
}
