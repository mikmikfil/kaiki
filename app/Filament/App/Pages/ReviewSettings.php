<?php

declare(strict_types=1);

namespace App\Filament\App\Pages;

use App\Domain\Notifications\Actions\SendDueReminders;
use App\Domain\Notifications\Support\ReviewRequestSettings;
use App\Models\BrandProfile;
use App\Support\Tenancy;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Gate;

/**
 * «Αξιολογήσεις στην Google»: the review request's three settings (2026-09-17).
 *
 * «Μετά από 24 ώρες (επιλογή operator) να στέλνεται να μπουν για αξιολόγηση
 * στην Google.» A switch, how many hours after the trip, and the link the
 * guest is sent to. The sending is {@see SendDueReminders}; what is stored and
 * how it is read back is {@see ReviewRequestSettings}.
 *
 * ## The link is required once the switch is on
 *
 * An email asking for a review with nowhere to leave one is worse than none,
 * so the form refuses the save rather than storing a switch that the sweep
 * would silently ignore.
 *
 * ## Gated like branding
 *
 * What guests are sent after a trip is a front-of-house decision, the same job
 * as the logo and the search page, so it uses the brand profile's gate. Crew
 * reach none of them. Checked in `canAccess`, again in `mount`, and again in
 * `save`, because a Livewire action is a POST anybody can craft.
 */
class ReviewSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-star';

    /** Reached from the «Ρυθμίσεις» hub ({@see Settings}), not the sidebar. */
    protected static bool $shouldRegisterNavigation = false;

    protected static ?int $navigationSort = 95;

    protected static string $view = 'filament.app.pages.review-settings';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function getNavigationGroup(): ?string
    {
        return __('panel.groups.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('review_settings.nav');
    }

    public function getTitle(): string
    {
        return __('review_settings.title');
    }

    public function getSubheading(): ?string
    {
        return __('review_settings.subtitle');
    }

    public static function canAccess(): bool
    {
        return Gate::allows('viewAny', BrandProfile::class);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->getForm('form')?->fill(ReviewRequestSettings::for()->toArray());
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Section::make(__('review_settings.sections.request'))
                ->description(__('review_settings.sections.request_help'))
                ->schema([
                    Toggle::make('enabled')
                        ->label(__('review_settings.fields.enabled.label'))
                        ->helperText(__('review_settings.fields.enabled.help'))
                        ->live(),
                    TextInput::make('delay_hours')
                        ->label(__('review_settings.fields.delay_hours.label'))
                        ->helperText(__('review_settings.fields.delay_hours.help'))
                        ->numeric()
                        ->integer()
                        ->minValue(ReviewRequestSettings::MIN_DELAY_HOURS)
                        ->maxValue(ReviewRequestSettings::MAX_DELAY_HOURS)
                        ->suffix(__('review_settings.fields.delay_hours.suffix'))
                        ->required(),
                    TextInput::make('google_url')
                        ->label(__('review_settings.fields.google_url.label'))
                        ->helperText(__('review_settings.fields.google_url.help'))
                        ->url()
                        ->startsWith(['https://'])
                        ->maxLength(500)
                        ->required(fn (Get $get): bool => (bool) $get('enabled')),
                ]),
        ])->statePath('data');
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $tenant = Tenancy::current();

        abort_unless($tenant !== null, 403);
        abort_unless(Gate::allows('update', BrandProfile::query()->firstOrFail()), 403);

        $settings = ReviewRequestSettings::fromArray((array) $this->getForm('form')?->getState());

        $tenant->forceFill([
            'settings' => [
                ...(array) $tenant->settings,
                ReviewRequestSettings::SETTINGS_KEY => $settings->toArray(),
            ],
        ])->save();

        $this->getForm('form')?->fill($settings->toArray());

        Notification::make()
            ->title(__('review_settings.saved'))
            ->success()
            ->send();
    }
}
