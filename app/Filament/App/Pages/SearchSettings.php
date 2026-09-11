<?php

declare(strict_types=1);

namespace App\Filament\App\Pages;

use App\Domain\Catalog\Support\SearchFilters;
use App\Models\BrandProfile;
use App\Support\Tenancy;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Gate;

/**
 * Which filters the search page offers (#105).
 *
 * ## Seven toggles, and two of them are fixed
 *
 * The design review of 2026-09-04 settled the defaults — date, port, party size
 * and trip type shown; duration, price ceiling and vessel available but off —
 * on the grounds that an operator with three trips wants two filters, not seven.
 * This screen is where they change that.
 *
 * Date and party size are shown **disabled and on**: a search with neither is a
 * catalogue listing, which the home page already is. Rendering them at all,
 * rather than hiding them, is what stops an operator hunting for the switch that
 * turns the date picker off.
 *
 * ## Switching a filter off actually removes it
 *
 * {@see SearchFilters} is read on the way *in* to both the endpoint and the
 * page, so a crafted query string cannot re-enable one. This screen would be
 * decorative otherwise, which is the failure the issue's own note names.
 *
 * ## Gated like branding, because it is the same job
 *
 * A page has no model to hang a policy on, and Filament allows what it cannot
 * check. What an operator's search page offers is a front-of-house presentation
 * choice — the same job as the logo, the colours and the home page — so it is
 * gated on the brand profile, which `ManageBranding` already governs. Crew reach
 * none of the three.
 */
class SearchSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-magnifying-glass';

    /** Reached from the «Ρυθμίσεις» hub ({@see Settings}), not the sidebar. */
    protected static bool $shouldRegisterNavigation = false;

    protected static ?int $navigationSort = 90;

    protected static string $view = 'filament.app.pages.search-settings';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function getNavigationGroup(): ?string
    {
        return __('panel.groups.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('search_settings.nav');
    }

    public function getTitle(): string
    {
        return __('search_settings.title');
    }

    public function getSubheading(): ?string
    {
        return __('search_settings.subtitle');
    }

    /**
     * SEC-3, both hooks: `canAccess` keeps it out of the navigation and refuses
     * the URL, and `mount()` refuses again for a link that was already open when
     * a role changed.
     */
    public static function canAccess(): bool
    {
        return Gate::allows('viewAny', BrandProfile::class);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->getForm('form')?->fill(['filters' => SearchFilters::for()]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Section::make(__('search_settings.sections.filters'))
                ->description(__('search_settings.sections.filters_help'))
                ->schema($this->toggles())
                ->columns(2),
        ])->statePath('data');
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $tenant = Tenancy::current();

        abort_unless($tenant !== null, 403);

        // TEN-9 through the same gate the branding screen uses: a lapsed
        // subscription refuses the write with the sentence that explains it.
        abort_unless(Gate::allows('update', $this->brandProfile()), 403);

        $state = (array) $this->getForm('form')?->getState();

        /** @var array<string, mixed> $filters */
        $filters = (array) ($state['filters'] ?? []);

        $tenant->forceFill([
            'settings' => [
                ...(array) $tenant->settings,
                SearchFilters::SETTINGS_KEY => ['filters' => SearchFilters::normalise($filters)],
            ],
        ])->save();

        // Refilled from the normaliser rather than left as submitted, so the
        // two fixed filters snap back to on in front of the operator instead of
        // appearing to have been turned off.
        $this->getForm('form')?->fill(['filters' => SearchFilters::for($tenant->refresh())]);

        Notification::make()
            ->title(__('search_settings.saved'))
            ->success()
            ->send();
    }

    /**
     * The operator's own search page, for the "view it" link.
     *
     * Built from the hosted host rather than through `route()`, which would
     * resolve against the panel's host and produce a link that 404s from the one
     * screen whose job is to show the operator their page.
     */
    public function publicUrl(): string
    {
        $tenant = Tenancy::current();
        $host = (string) config('kaiki.tenancy.hosted_host');

        return $tenant === null ? '#' : "https://{$host}/{$tenant->slug}/search";
    }

    /** @return array<int, Component> */
    protected function toggles(): array
    {
        $fixed = SearchFilters::fixed();

        return array_map(
            fn (string $filter): Component => Toggle::make("filters.{$filter}")
                ->label(__("search_settings.filters.{$filter}.label"))
                ->helperText(__("search_settings.filters.{$filter}.help"))
                // Shown rather than hidden, and unswitchable. An operator
                // hunting for the missing date toggle is worse served than one
                // who can see why it does not move.
                ->disabled(in_array($filter, $fixed, true))
                ->default(SearchFilters::defaults()[$filter]),
            SearchFilters::all(),
        );
    }

    protected function brandProfile(): BrandProfile
    {
        return BrandProfile::query()->firstOrFail();
    }
}
