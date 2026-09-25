<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\TenantResource\Pages;

use App\Domain\Channels\Support\ChannelManagerFlag;
use App\Domain\Tenancy\Support\SetupChecklist;
use App\Enums\ChannelKey;
use App\Enums\HostedSiteMode;
use App\Enums\IntegrationProvider;
use App\Enums\Plan;
use App\Enums\TenantStatus;
use App\Enums\TenantVertical;
use App\Models\ChannelProductMap;
use App\Models\IcalSource;
use App\Models\IntegrationCredential;
use App\Models\Tenant;
use App\Support\Tenancy;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Get;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

/**
 * What the platform decides about an operator, asked in one place.
 *
 * The plan, the vertical, the sandbox flag, every feature switch and the
 * channels. They were `EditTenant`'s private methods, which meant a new
 * merchant was created with seven fields and everything else appeared only
 * **after** it existed — product owner, 2026-09-22: *«στη δημιουργία νέου
 * merchant να μπουν και τα features και όλα αυτά τα πεδία, μην εμφανίζονται
 * μόνο μετά τη δημιουργία του»*.
 *
 * Shared rather than copied, because two lists of feature switches drift the
 * first time one is added: the copy that is not on screen is the one nobody
 * remembers. Both pages ask exactly the same questions, in the same words,
 * with the same defaults.
 *
 * **The audit is still the edit page's own.** Creating an operator is audited
 * as a creation, with every value it was given; changing one of these
 * afterwards is a separate entry naming what moved. See `EditTenant::AUDITED`.
 */
trait HasTenantAccountFields
{
    private function subscriptionSection(): Section
    {
        return Section::make(__('tenants.edit.subscription'))
            ->description(__('tenants.edit.subscription_help'))
            ->schema([
                Select::make('plan')
                    ->label(__('tenants.columns.plan'))
                    ->options(Plan::options())
                    ->required(),

                Select::make('status')
                    ->label(__('tenants.columns.status'))
                    ->options(TenantStatus::options())
                    ->required(),

                DatePicker::make('subscription_ends_at')
                    ->label(__('tenants.columns.access_ends'))
                    ->helperText(__('tenants.edit.access_ends_help'))
                    ->native(false),
            ])
            ->columns(3);
    }

    private function accountSection(): Section
    {
        return Section::make(__('tenants.edit.account'))
            ->schema([
                Select::make('vertical')
                    ->label(__('tenants.columns.vertical'))
                    ->options(TenantVertical::options())
                    ->required(),

                Toggle::make('is_sandbox')
                    ->label(__('tenants.columns.sandbox'))
                    ->helperText(__('tenants.edit.sandbox_help')),
            ])
            ->columns(2);
    }

    private function featuresSection(): Section
    {
        return Section::make(__('tenants.edit.features'))
            ->description(__('tenants.edit.features_help'))
            ->schema([
                Fieldset::make(__('tenants.edit.group_boarding'))->columns(1)->schema([
                    // The wider of the two, and first: an operator who boards
                    // nobody has no use for the question below it.
                    Toggle::make('check_in_enabled')
                        ->label(__('tenants.columns.check_in'))
                        ->helperText(__('tenants.edit.check_in_help'))
                        // On for a new owner too: Filament's toggle defaults to off, and
                        // the create page saved that `false` over the column's default.
                        ->default(true)
                        ->formatStateUsing(fn (?bool $state): bool => $state !== false)
                        // The QR toggle reads this, so the form has to know the
                        // moment it moves rather than on the next round trip.
                        ->live(),

                    Toggle::make('qr_check_in_enabled')
                        ->label(__('tenants.columns.qr_check_in'))
                        ->helperText(__('tenants.edit.qr_check_in_help'))
                        // Null is on (see `Tenant::usesQrCheckIn()`); a toggle
                        // showing a null as off would switch it off on save.
                        ->default(true)
                        ->formatStateUsing(fn (?bool $state): bool => $state !== false)
                        // Hidden rather than disabled while check-in is off:
                        // "scanning, on" under "check-in, off" is a pair that
                        // means nothing, and a greyed-out control still invites
                        // somebody to wonder which one wins.
                        //
                        // `!== false` and not a truthy test, for the same reason
                        // `Tenant::usesCheckIn()` reads it that way: the column
                        // is null for every operator who existed before it, and
                        // a truthy test hid this toggle from all of them — which
                        // is how the manual's screenshot of this very section
                        // failed to capture.
                        ->visible(fn (Get $get): bool => $get('check_in_enabled') !== false),
                ]),

                Fieldset::make(__('tenants.edit.group_guest'))->columns(1)->schema([
                    // ADR-0029's two states (amended 2026-09-11), decided by the
                    // platform with the operator — the same place and the same
                    // trail as QR boarding. The operator's own screen only shows it.
                    Radio::make('hosted_site_mode')
                        ->label(__('tenants.columns.hosted_site_mode'))
                        ->helperText(__('tenants.edit.hosted_site_mode_help'))
                        ->options(HostedSiteMode::options())
                        ->descriptions(array_combine(
                            array_map(static fn (HostedSiteMode $mode): string => $mode->value, HostedSiteMode::cases()),
                            array_map(
                                static fn (HostedSiteMode $mode): string => (string) __(
                                    HostedSiteMode::translationNamespace() . '.' . $mode->value . '.help'
                                ),
                                HostedSiteMode::cases(),
                            ),
                        ))
                        // The column's own default, so the create form opens on
                        // it rather than on nothing. On the edit page the record
                        // supplies the value and this is never reached.
                        ->default(HostedSiteMode::Full->value)
                        ->required(),
                ]),

                Fieldset::make(__('tenants.edit.group_money'))->columns(1)->schema([
                    // «Up to N people, +Y € for each extra» on whole-boat prices
                    // (2026-09-17). Off for everybody: switched on for the
                    // operator who prices that way, with the same trail as the
                    // switches above. Null is off (`Tenant::usesExtraPersonPricing()`).
                    Toggle::make('extra_person_pricing_enabled')
                        ->label(__('tenants.columns.extra_person_pricing'))
                        ->helperText(__('tenants.edit.extra_person_pricing_help'))
                        ->formatStateUsing(fn (?bool $state): bool => $state === true),

                    // Text messages (2026-09-17). Off for everybody: a text
                    // costs the operator money and needs their own gateway
                    // account. Null is off (`Tenant::usesSms()`), and the
                    // platform config still overrides every operator.
                    Toggle::make('sms_enabled')
                        ->label(__('tenants.columns.sms'))
                        ->helperText(__('tenants.edit.sms_help'))
                        ->formatStateUsing(fn (?bool $state): bool => $state === true),
                ]),

                Fieldset::make(__('tenants.edit.group_start'))->columns(1)->schema([
                    // The first-time setup guide (2026-09-17). On for everybody;
                    // off for an operator the platform set up itself. Null is on
                    // (`Tenant::usesSetupGuide()`), so the toggle must not show a
                    // null as off and switch it off on save.
                    Toggle::make('setup_guide_enabled')
                        ->label(__('tenants.columns.setup_guide'))
                        ->helperText(__('tenants.edit.setup_guide_help'))
                        ->default(true)
                        ->formatStateUsing(fn (?bool $state): bool => $state !== false),

                    // Where the operator has got to, so the platform knows
                    // whether to call them. Read in their tenant, because every
                    // step is a tenant-scoped question.
                    Placeholder::make('setup_progress')
                        ->label(__('tenants.edit.setup_progress'))
                        ->content(fn (?Tenant $record): HtmlString => self::setupProgress($record)),
                ]),
            ]);
    }

    /**
     * Where an operator sells besides their own pages (ADR-0034).
     *
     * Its own tab because a channel is not a switch. GetYourGuide alone already
     * has three things worth showing beside the toggle — whether credentials
     * have been entered, how many trips are mapped, when it last called — and
     * Viator, Click&Boat and agency accounts are behind it.
     */
    private function channelsSection(): Section
    {
        return Section::make(__('tenants.edit.channels'))
            ->description(__('tenants.edit.channels_help'))
            ->schema([
                // Shown *instead of* the switch while the platform lock is
                // shut, rather than leaving the tab empty. An empty tab reads
                // as a broken screen; this one says which lock is closed and
                // who can open it, which is the question somebody standing here
                // actually has.
                Placeholder::make('channel_manager_locked')
                    ->label(__('tenants.edit.channels_locked'))
                    ->content(fn (): string => (string) __('tenants.edit.channels_locked_help'))
                    ->visible(fn (): bool => ! ChannelManagerFlag::isOpen()),

                // Selling through GetYourGuide (ADR-0034). Off for everybody:
                // the operator holds that contract themselves, so switching it
                // on for somebody who has not signed one would offer their
                // seats under an agreement that does not exist. Null is off
                // (`Tenant::usesGetYourGuide()`).
                //
                // Hidden entirely while the platform's `channel_manager` flag
                // is shut, for the reason the QR toggle is hidden rather than
                // greyed: a disabled control invites somebody to wonder which
                // switch wins, and this one has an answer nobody in /admin can
                // change — it is opened from a console, by whoever holds the
                // certification email.
                Toggle::make('getyourguide_enabled')
                    ->label(__('tenants.columns.getyourguide'))
                    ->helperText(__('tenants.edit.getyourguide_help'))
                    ->formatStateUsing(fn (?bool $state): bool => $state === true)
                    ->visible(fn (): bool => ChannelManagerFlag::isOpen()),

                // What the operator has actually done with it, which the toggle
                // cannot say. A switch that is on and a connection that works
                // are different facts, and support tickets are about the gap.
                Placeholder::make('getyourguide_state')
                    ->label(__('tenants.edit.channel_state'))
                    ->content(fn (?Tenant $record): HtmlString => self::channelState($record))
                    ->visible(fn (): bool => ChannelManagerFlag::isOpen()),

                // Calendars the operator pulls in. Not gated by anything — they
                // predate both locks — and worth seeing here because another
                // platform's calendar blocks the same boats GetYourGuide sells.
                Placeholder::make('ical_state')
                    ->label(__('tenants.edit.channel_ical'))
                    ->content(fn (?Tenant $record): HtmlString => self::icalState($record)),
            ]);
    }

    /**
     * How far the operator got through the first-run guide.
     *
     * It used to be «Τα στοιχεία σας: έγινε» strings joined with `<br>`, which
     * read like debug output. The one question somebody on this screen has is
     * *should I ring them*, and counting six words to answer it is six too many
     * — so the answer comes first, as a badge and a bar, and the steps follow.
     *
     * **Rendered through a Blade view rather than built here as a string.** The
     * panel ships a compiled stylesheet holding only the utilities its own
     * components use: a Tailwind colour class written by hand and not in that
     * build renders black, silently. That is how the statistics chart's bars
     * came out black on 2026-09-14. The view uses `x-filament::badge` and
     * `x-filament::icon`, whose classes are in the build by construction.
     */
    private static function setupProgress(?Tenant $tenant): HtmlString
    {
        if (! $tenant instanceof Tenant || ! $tenant->exists) {
            return new HtmlString('');
        }

        return Tenancy::forTenant($tenant, static function () use ($tenant): HtmlString {
            $state = SetupChecklist::state();
            $skipped = SetupChecklist::skipped($tenant);
            $progress = SetupChecklist::progress();
            $steps = [];

            foreach (SetupChecklist::questions() as $step) {
                $steps[] = [
                    'label' => (string) __('setup.steps.' . $step . '.label'),
                    // Three states, not two. «Αργότερα» is a step the operator
                    // deliberately passed over and «μένει» is one they have not
                    // reached — the first needs no phone call and the second
                    // might, which is the whole reason to look at this.
                    'state' => match (true) {
                        $state[$step] ?? false => 'done',
                        in_array($step, $skipped, true) => 'later',
                        default => 'open',
                    },
                    'note' => (string) __('tenants.edit.setup_step_later'),
                ];
            }

            $finished = $tenant->onboarding_completed_at !== null;

            return new HtmlString(view('filament.admin.tenant-setup-progress', [
                'steps' => $steps,
                'finished' => $finished,
                'headline' => (string) ($finished
                    ? __('tenants.edit.setup_finished')
                    : __('setup.widget.progress', ['done' => $progress['done'], 'total' => $progress['total']])),
            ])->render());
        });
    }

    /**
     * What the operator has actually done with GetYourGuide.
     *
     * The switch being on says the platform allowed it. It does not say the
     * operator entered their keys, mapped a single trip, or that GetYourGuide
     * has ever called — and every support ticket about this integration will be
     * about that gap. Three facts, each of which the toggle cannot carry.
     */
    private static function channelState(?Tenant $tenant): HtmlString
    {
        if (! $tenant instanceof Tenant || ! $tenant->exists) {
            return new HtmlString('');
        }

        if (! $tenant->usesGetYourGuide()) {
            return new HtmlString(e(__('tenants.edit.channel_state_off')));
        }

        return Tenancy::forTenant($tenant, static function (): HtmlString {
            $credential = IntegrationCredential::query()
                ->where('provider', IntegrationProvider::GetYourGuide->value)
                ->first();

            $mapped = ChannelProductMap::query()
                ->where('channel', ChannelKey::GetYourGuide->value)
                ->count();

            $lines = [
                __('tenants.edit.channel_credentials') . ': ' . ($credential instanceof IntegrationCredential
                    ? __('tenants.edit.channel_credentials_given')
                    : __('tenants.edit.channel_credentials_missing')),
                __('tenants.edit.channel_inbound') . ': ' . ($credential?->inbound_username !== null
                    ? __('tenants.edit.channel_inbound_given')
                    : __('tenants.edit.channel_inbound_missing')),
                __('tenants.edit.channel_mapped') . ': ' . $mapped,
            ];

            return new HtmlString(implode('<br>', array_map(static fn (string $line): string => e($line), $lines)));
        });
    }

    /** How many calendars this operator reads, and when they last answered. */
    private static function icalState(?Tenant $tenant): HtmlString
    {
        if (! $tenant instanceof Tenant || ! $tenant->exists) {
            return new HtmlString('');
        }

        return Tenancy::forTenant($tenant, static function (): HtmlString {
            $sources = IcalSource::query()->where('is_active', true)->get();

            if ($sources->isEmpty()) {
                return new HtmlString(e(__('tenants.edit.channel_ical_none')));
            }

            $last = $sources->max('last_success_at');

            return new HtmlString(e(__('tenants.edit.channel_ical_some', [
                'count' => $sources->count(),
                'when' => $last instanceof Carbon ? $last->diffForHumans() : __('tenants.edit.channel_ical_never'),
            ])));
        });
    }
}
