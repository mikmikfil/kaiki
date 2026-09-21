<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\TenantResource\Pages;

use App\Domain\Audit\Actions\RecordAuditEntry;
use App\Domain\Audit\Data\AuditEntryData;
use App\Domain\Channels\Support\ChannelManagerFlag;
use App\Domain\Tenancy\Support\SetupChecklist;
use App\Enums\AuditAction;
use App\Enums\ChannelKey;
use App\Enums\HostedSiteMode;
use App\Enums\IntegrationProvider;
use App\Enums\Plan;
use App\Enums\TenantStatus;
use App\Enums\TenantVertical;
use App\Filament\Admin\Resources\TenantResource;
use App\Models\ChannelProductMap;
use App\Models\IcalSource;
use App\Models\IntegrationCredential;
use App\Models\Tenant;
use App\Support\Tenancy;
use BackedEnum;
use DateTimeInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

/**
 * What the platform owner may change about an operator (SAA-1, SEC-16).
 *
 * ## Each field is on this screen for a reason
 *
 * - **Plan** — so an upgrade can be honoured the day it is agreed, rather than
 *   the day the billing code lands.
 * - **Status** — suspension is the only real answer to somebody who has stopped
 *   paying, and it was unreachable through the product.
 * - **Access ends** — see `subscription_ends_at`'s migration: a paying operator
 *   had no date on their record at all, so "who lapses this week" had no answer.
 * - **Sandbox** — a new operator should be able to be put in test mode by the
 *   person setting them up.
 * - **Trade** — a label for the merchant list. It does not change the product.
 * - **QR boarding** — whether tickets carry a QR and the crew get a scanning
 *   page (BKG-20, amended 2026-09-11). A one-boat operator boards from the
 *   passenger list, and the platform decides this with them when they sign up.
 * - **Selling through GetYourGuide** — whether this operator's seats are offered
 *   on an OTA they signed with themselves (ADR-0034). On its own tab, with the
 *   other channels; see {@see self::form()} for why a channel is not a switch.
 *
 * The list has grown past the five it opened with, which is what the tabs of
 * 2026-09-21 are for.
 *
 * Everything else about an operator is theirs: their name, their address, their
 * VAT number, their colours. A platform screen that could rewrite those is a
 * platform screen somebody will use to "fix" a customer's data for them.
 *
 * ## SEC-16, in three parts, all of them here
 *
 * > confirmed and audit-logged with actor, timestamp and reason
 *
 * The **confirmation** is the save action's modal. The **reason** is a required
 * field on it — not on the form, so it belongs to the act rather than to the
 * record, and so it cannot be left over from a previous edit. The **audit row**
 * is written to the *operator's own* trail, because an operator asking "who put
 * us on read-only?" is asking about their account, and an answer they cannot
 * see is not an answer.
 *
 * `TenantPolicy` says who may do this; none of the three above is a policy's to
 * enforce, which is why they are asserted separately in `TenantResourceTest`.
 *
 * ## Only what actually moved is recorded
 *
 * The context carries the fields that changed and their before-and-after, and
 * nothing else. A row saying "plan, status, vertical, sandbox, ends_at" on an
 * edit that changed one of them is a row nobody can read a year later — and the
 * trail is kept for seven.
 *
 * No personal data reaches it (ADR-0025 §3): a plan, a status and a date are
 * facts about an account, not about a person.
 */
class EditTenant extends EditRecord
{
    protected static string $resource = TenantResource::class;

    /**
     * The fields whose movement is worth seven years of storage.
     *
     * Named once, so the snapshot and the diff cannot drift apart — which is
     * how an audit trail quietly stops recording one of them.
     */
    private const AUDITED = ['plan', 'status', 'vertical', 'is_sandbox', 'subscription_ends_at', 'check_in_enabled', 'qr_check_in_enabled', 'hosted_site_mode', 'extra_person_pricing_enabled', 'sms_enabled', 'setup_guide_enabled', 'getyourguide_enabled'];

    /** The operator's own words, captured by the confirmation and not by the form. */
    public ?string $auditReason = null;

    /**
     * Four tabs, and the fourth is why there are any (product owner, 2026-09-21).
     *
     * This was one column of nine controls: boarding, which is the crew's;
     * published pages, which are the guest's; extra-person pricing; text
     * messages, which cost money; the setup guide, which is temporary; and now
     * a sales channel, which is a commercial agreement. Nothing said which was
     * which, and three more channels are queued behind GetYourGuide.
     *
     * **A sales channel is not a toggle**, and that is the part grouping alone
     * could not fix. Each one wants to show whether credentials have been
     * entered, how many trips are mapped and when it last spoke — so they get a
     * tab with room, rather than three more rows in a list of switches.
     *
     * The same move the trip form made on 2026-09-18 and the operator's
     * settings made on 2026-09-11. `/admin` was the last screen still in one
     * column.
     *
     * ## What is deliberately not a tab
     *
     * **«Ιστορικό».** Every change here is audited with a typed reason, and the
     * trail is written to the *operator's* own panel — an operator asking who
     * put them on read-only is asking about their account. A platform-wide view
     * across every merchant is a real feature with its own estimate on the
     * roadmap, not something to improvise into a tab here.
     */
    public function form(Form $form): Form
    {
        return $form->schema([
            Tabs::make('merchant')
                ->columnSpanFull()
                // So a save, or a reload after one, comes back to the tab the
                // change was made on instead of to the first one.
                ->persistTabInQueryString()
                ->tabs([
                    Tabs\Tab::make(__('tenants.edit.subscription'))->schema([
                        $this->subscriptionSection(),
                        $this->accountSection(),
                    ]),

                    Tabs\Tab::make(__('tenants.edit.features'))->schema([
                        $this->featuresSection(),
                    ]),

                    Tabs\Tab::make(__('tenants.edit.channels'))
                        // The count is the useful part of a tab label here: it
                        // answers "does this merchant sell anywhere else" from
                        // the tab bar, without opening it.
                        ->badge(fn (?Tenant $record): ?string => self::channelBadge($record))
                        ->schema([
                            $this->channelsSection(),
                        ]),
                ]),
        ]);
    }

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
                        ->formatStateUsing(fn (?bool $state): bool => $state !== false)
                        // The QR toggle reads this, so the form has to know the
                        // moment it moves rather than on the next round trip.
                        ->live(),

                    Toggle::make('qr_check_in_enabled')
                        ->label(__('tenants.columns.qr_check_in'))
                        ->helperText(__('tenants.edit.qr_check_in_help'))
                        // Null is on (see `Tenant::usesQrCheckIn()`); a toggle
                        // showing a null as off would switch it off on save.
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
     * Save, behind a confirmation that asks why.
     *
     * The default save button is replaced rather than added to: two ways to
     * save, one of which skips the reason, is the same as having no reason.
     *
     * @return array<int, Action>
     */
    protected function getFormActions(): array
    {
        return [
            $this->getSaveFormAction()
                ->requiresConfirmation()
                ->modalHeading(__('tenants.edit.confirm_heading'))
                ->modalDescription(__('tenants.edit.confirm_body'))
                ->form([
                    Textarea::make('auditReason')
                        ->label(__('tenants.edit.reason'))
                        ->helperText(__('tenants.edit.reason_help'))
                        ->required()
                        ->minLength(3)
                        ->maxLength(500),
                ])
                ->action(function (array $data): void {
                    $this->auditReason = (string) ($data['auditReason'] ?? '');

                    $this->save();
                }),
            $this->getCancelFormAction(),
        ];
    }

    /**
     * The five values as they were, captured before the write.
     *
     * @var array<string, scalar|null>
     */
    private array $before = [];

    /**
     * Snapshot the old values while they are still the old values.
     *
     * `getOriginal()` in `afterSave()` does **not** work: Eloquent syncs a
     * model's originals to the saved attributes as part of `save()`, so by the
     * time the row is written the "before" and the "after" are the same thing
     * and the audit row comes out empty. That is not a theory — this method
     * exists because the test asserting the trail found exactly nothing.
     */
    protected function beforeSave(): void
    {
        /** @var Tenant $tenant */
        $tenant = $this->getRecord();

        $this->before = $this->snapshot($tenant);
    }

    /**
     * The trail, written after the row is saved and against the operator.
     *
     * `afterSave` rather than `beforeSave`, so nothing is recorded for a write
     * that failed.
     */
    protected function afterSave(): void
    {
        /** @var Tenant $tenant */
        $tenant = $this->getRecord();

        $changes = $this->changes($tenant);

        if ($changes === []) {
            // Nothing moved. A row saying an operator was "updated" with no
            // change in it is noise in a trail kept for seven years.
            return;
        }

        app(RecordAuditEntry::class)(
            new AuditEntryData(
                action: AuditAction::TenantUpdated,
                subjectType: 'Tenant',
                subjectId: (int) $tenant->getKey(),
                subjectLabel: $tenant->name,
                reason: $this->auditReason,
                context: $changes,
            ),
            $tenant,
            userId: auth()->id(),
            ipAddress: request()->ip(),
        );
    }

    /**
     * What moved, as `field_from` / `field_to` scalars.
     *
     * Flat rather than nested, because `AuditEntryData::$context` is typed
     * `array<string, scalar|null>` — and it is typed that way so the
     * personal-data scanner can reason about it.
     *
     * @return array<string, scalar|null>
     */
    private function changes(Tenant $tenant): array
    {
        $context = [];

        foreach ($this->snapshot($tenant) as $field => $after) {
            $before = $this->before[$field] ?? null;

            if ($before !== $after) {
                $context["{$field}_from"] = $before;
                $context["{$field}_to"] = $after;
            }
        }

        return $context;
    }

    /**
     * The five auditable fields, flattened to scalars.
     *
     * @return array<string, scalar|null>
     */
    private function snapshot(Tenant $tenant): array
    {
        $values = [];

        foreach (self::AUDITED as $field) {
            $values[$field] = $this->scalar($tenant->getAttribute($field));
        }

        return $values;
    }

    /** A value the audit context can hold: a string, a bool, a null. */
    private function scalar(mixed $value): string|bool|null
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value,
            $value instanceof BackedEnum => (string) $value->value,
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            is_scalar($value) => (string) $value,
            default => null,
        };
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }

    /**
     * «Επαναφορά οδηγού»: the guide starts over for this operator.
     *
     * Clears the finish mark and the skipped steps; what the operator has filled
     * in stays, because every step is read from the data. Audited with a reason,
     * like the switches. No delete: seven years of invoices and audit rows hang
     * off this row — see `TenantPolicy`.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('resetSetupGuide')
                ->label(__('tenants.edit.setup_reset'))
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading(__('tenants.edit.setup_reset'))
                ->modalDescription(__('tenants.edit.setup_reset_body'))
                ->form([
                    Textarea::make('reason')
                        ->label(__('tenants.edit.reason'))
                        ->helperText(__('tenants.edit.reason_help'))
                        ->required()
                        ->minLength(3)
                        ->maxLength(500),
                ])
                ->action(function (array $data): void {
                    /** @var Tenant $tenant */
                    $tenant = $this->getRecord();

                    $tenant->forceFill([
                        'onboarding_completed_at' => null,
                        'onboarding_skipped_steps' => null,
                    ])->save();

                    app(RecordAuditEntry::class)(
                        new AuditEntryData(
                            action: AuditAction::TenantUpdated,
                            subjectType: 'Tenant',
                            subjectId: (int) $tenant->getKey(),
                            subjectLabel: $tenant->name,
                            reason: (string) ($data['reason'] ?? ''),
                            context: ['setup_guide_reset' => true],
                        ),
                        $tenant,
                        userId: auth()->id(),
                        ipAddress: request()->ip(),
                    );

                    Notification::make()->success()->title(__('tenants.edit.setup_reset_done'))->send();
                }),
        ];
    }

    /**
     * The count on the «Κανάλια» tab, or nothing.
     *
     * Only live channels are counted, and iCal counts. A merchant pulling two
     * calendars is selling the same hulls somewhere else, which is the thing
     * the number is there to warn about — a tab that said «0» while two
     * calendars quietly blocked boats would be worse than no number.
     */
    private static function channelBadge(?Tenant $tenant): ?string
    {
        if (! $tenant instanceof Tenant || ! $tenant->exists) {
            return null;
        }

        $live = $tenant->usesGetYourGuide() && ChannelManagerFlag::isOpen() ? 1 : 0;

        $live += Tenancy::forTenant($tenant, static fn (): int => IcalSource::query()
            ->where('is_active', true)
            ->count());

        return $live > 0 ? (string) $live : null;
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
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $data;
    }
}
