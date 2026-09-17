<?php

declare(strict_types=1);

namespace App\Filament\App\Resources;

use App\Domain\Catalog\Actions\SaveCancellationPolicy;
use App\Domain\Catalog\Support\ProductPublishChecklist;
use App\Domain\Catalog\Support\TripPageContent;
use App\Enums\AgeBandPricing;
use App\Enums\BookingMode;
use App\Enums\ProductCategory;
use App\Enums\ProductStatus;
use App\Filament\App\Resources\ProductResource\Pages;
use App\Filament\App\Resources\ProductResource\RelationManagers\ExtrasRelationManager;
use App\Filament\App\Resources\ProductResource\RelationManagers\QuestionsRelationManager;
use App\Filament\App\Resources\ProductResource\RelationManagers\RatePlansRelationManager;
use App\Filament\App\Resources\ProductResource\RelationManagers\ScheduleRulesRelationManager;
use App\Filament\Forms\TranslatableInput;
use App\Models\CancellationPolicy;
use App\Models\Port;
use App\Models\Product;
use App\Models\VatRate;
use App\Models\Vessel;
use App\Support\Format\MoneyFormatter;
use App\Support\Locale\LocaleResolver;
use App\Support\Tenancy;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Livewire;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\View as ViewField;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\RestoreAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;

/**
 * The trip, on `/app` (spec CAT-4, CAT-5, CAT-7, CAT-15, SEC-3, TEN-8, I18N-1).
 *
 * The operator's first real encounter with the product, and the largest form in
 * the panel. Thin all the same (CNV-5): `SaveProduct` and `SaveAgeBands` hold
 * every rule, and what is here is affordance — which fields appear for which
 * mode, and what the operator is told is still missing.
 *
 * ## The publish checklist is shown, not only enforced
 *
 * CAT-15 is a gate at the moment of publishing. Enforced alone it is a wall an
 * operator hits after filling in a long form; the checklist section turns it
 * into something they can watch while they work, so the refusal is never the
 * first they hear of it. The keys come from
 * {@see ProductPublishChecklist} and the sentences from lang files, so the API
 * can answer with the same list in error codes (CNV-11).
 *
 * ## Age bands are a repeater, not a relation manager
 *
 * Every CAT-8 rule is set-level — exactly one base band, no overlapping ranges,
 * at least one band taking a seat. A relation manager saves one row at a time,
 * which means an operator correcting two bands would be refused halfway through
 * for a state they were in the middle of leaving. The repeater hands the whole
 * set to `SaveAgeBands` in one call, which is the only way those rules can be
 * judged.
 */
class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $navigationIcon = 'heroicon-o-map';

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): ?string
    {
        return __('panel.groups.catalogue');
    }

    public static function getNavigationLabel(): string
    {
        return __('catalog.product.nav');
    }

    public static function getModelLabel(): string
    {
        return __('catalog.product.model.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('catalog.product.model.plural');
    }

    public static function form(Form $form): Form
    {
        return $form->schema(static::formSchema());
    }

    /** @return array<int, Component> */
    public static function formSchema(): array
    {
        return [
            // Two tabs, the form otherwise unchanged (product owner, 2026-09-17,
            // round 2 option 1): what the trip is and how it sells, and what the
            // guest reads about it. Plain words with a thin bar under the open
            // one, styled in `filament.app.sea`. A save error in the tab not on
            // screen opens that tab by itself, which is Filament's behaviour.
            Tabs::make('trip')
                ->contained(false)
                ->persistTabInQueryString('tab')
                ->extraAttributes(['class' => 'ka-line-tabs'])
                ->tabs([
                    Tabs\Tab::make(__('catalog.product.tabs.basics'))
                        ->badge(static::basicsBadge(...))
                        ->badgeColor('warning')
                        ->schema(static::basicsSections()),
                    Tabs\Tab::make(__('catalog.product.tabs.page'))
                        ->badge(static::pageBadge(...))
                        ->badgeColor('gray')
                        ->schema(static::pageSections()),
                    // When it runs (2026-09-17): the trip's own timetable, which
                    // used to be a separate screen in the menu. On a saved trip
                    // sold per seat only; see `ScheduleRulesRelationManager`.
                    Tabs\Tab::make(__('catalog.product.tabs.schedule'))
                        ->badge(static::scheduleBadge(...))
                        ->badgeColor(static fn (?Product $record): string => static::scheduleBadge($record) === __('catalog.product.tabs.no_schedule') ? 'warning' : 'gray')
                        ->visible(static fn (?Product $record, Get $get): bool => $record instanceof Product
                            && $record->exists
                            && static::modeOf($get) === BookingMode::PerSeat)
                        ->schema([
                            Livewire::make(
                                ScheduleRulesRelationManager::class,
                                static fn (?Product $record): array => [
                                    'ownerRecord' => $record,
                                    'pageClass' => Pages\EditProduct::class,
                                ],
                            )->key('trip-schedule-rules'),
                        ]),
                ])
                ->columnSpanFull(),
        ];
    }

    /** «2 ενεργά», or «κανένα» in amber: a trip nobody can book on any day. */
    public static function scheduleBadge(?Product $record): ?string
    {
        if (! $record instanceof Product || ! $record->exists) {
            return null;
        }

        $active = $record->scheduleRules()->where('is_active', true)->count();

        return $active === 0
            ? __('catalog.product.tabs.no_schedule')
            : trans_choice('catalog.product.tabs.active_schedules', $active, ['count' => $active]);
    }

    /**
     * Everything the publish checklist asks about, and the prices.
     *
     * @return array<int, Component>
     */
    public static function basicsSections(): array
    {
        return [
            Section::make(__('catalog.product.sections.basics'))
                ->schema([
                    TranslatableInput::text(
                        'title',
                        __('catalog.product.form.title.label'),
                        __('catalog.product.form.title.help'),
                        maxLength: 160,
                        // The Greek title seeds the slug, and only the Greek
                        // one: it is the language every operator fills in, and
                        // two tabs racing to write the same field would let
                        // whichever was blurred last decide the address.
                        configure: static fn (TextInput $input, string $locale): TextInput => $locale === 'el'
                            ? $input->live(onBlur: true)->afterStateUpdated(static::fillSlug(...))
                            : $input,
                    ),

                    TextInput::make('slug')
                        ->label(__('catalog.product.form.slug.label'))
                        ->helperText(__('catalog.product.form.slug.help'))
                        ->required()
                        ->maxLength(120)
                        ->alphaDash()
                        // Per operator, like a vessel's name: the rule's raw
                        // query skips the tenant scope, so it is added here.
                        // Without it a taken slug reached the database and the
                        // operator got a crash page instead of this message.
                        ->unique(
                            ignoreRecord: true,
                            modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('tenant_id', Tenancy::id()),
                        )
                        ->validationMessages([
                            'unique' => __('catalog.product.form.slug.taken'),
                        ]),

                    Select::make('vessel_id')
                        ->label(__('catalog.product.form.vessel.label'))
                        ->helperText(__('catalog.product.form.vessel.help'))
                        ->options(static::vesselOptions(...))
                        ->searchable()
                        ->preload(),

                    Select::make('category')
                        ->label(__('catalog.product.form.category.label'))
                        ->helperText(__('catalog.product.form.category.help'))
                        ->options(ProductCategory::options())
                        ->required(),

                    Select::make('mode')
                        ->label(__('catalog.product.form.mode.label'))
                        ->helperText(__('catalog.product.form.mode.help'))
                        ->options(BookingMode::options())
                        ->required()
                        ->live(),

                    // Not a dropdown any more (product owner, 2026-09-17): the
                    // buttons under the form set it — «Αποθήκευση ως πρόχειρη»,
                    // «Δημοσίευση», «Εκτός πώλησης» — and `SaveProduct` still
                    // refuses `active` while the checklist is incomplete.
                    Hidden::make('status')
                        ->default(ProductStatus::Draft->value)
                        ->required(),

                    TextInput::make('sort_order')
                        ->label(__('catalog.shared.sort_order.label'))
                        ->helperText(__('catalog.shared.sort_order.help'))
                        ->integer()
                        ->default(0)
                        ->minValue(0)
                        ->maxValue(65535),

                    Toggle::make('is_featured')
                        ->label(__('catalog.product.form.is_featured.label'))
                        ->helperText(__('catalog.product.form.is_featured.help')),
                ])
                ->columns(2),

            Section::make(__('catalog.product.sections.checklist'))
                ->description(__('catalog.product.checklist.intro'))
                ->schema(static::checklistSchema()),

            // A trip sold per seat takes its days and times from the «Δρομολόγια»
            // tab, so here it only has duration, check-in and meeting point. The
            // single departure time is a whole-boat charter's (2026-09-17).
            Section::make(static fn (Get $get): string => static::modeOf($get) === BookingMode::PerSeat
                ? __('catalog.product.sections.schedule_per_seat')
                : __('catalog.product.sections.schedule'))
                ->schema([
                    Placeholder::make('schedule_where')
                        ->hiddenLabel()
                        ->content(__('catalog.product.form.schedule_where'))
                        ->visible(static fn (Get $get): bool => static::modeOf($get) === BookingMode::PerSeat)
                        ->columnSpanFull(),

                    TextInput::make('duration_minutes')
                        ->label(__('catalog.product.form.duration_minutes.label'))
                        ->helperText(__('catalog.product.form.duration_minutes.help'))
                        ->suffix(__('catalog.product.form.duration_minutes.suffix'))
                        ->integer()
                        ->required()
                        ->minValue(1)
                        ->maxValue(65535),

                    TimePicker::make('default_start_time')
                        ->label(__('catalog.product.form.default_start_time.label'))
                        ->helperText(__('catalog.product.form.default_start_time.help'))
                        // A clock time on the quay, not an instant: the panel's
                        // tenant-timezone conversion stored 09:00 as 06:00
                        // (2026-09-17). Same as a departure's `local_time`.
                        ->timezone('UTC')
                        ->seconds(false)
                        ->native(false)
                        ->visible(static fn (Get $get): bool => static::modeOf($get) !== BookingMode::PerSeat),

                    TextInput::make('check_in_offset_minutes')
                        ->label(__('catalog.product.form.check_in_offset_minutes.label'))
                        ->helperText(__('catalog.product.form.check_in_offset_minutes.help'))
                        ->suffix(__('catalog.product.form.check_in_offset_minutes.suffix'))
                        ->integer()
                        ->default(30)
                        ->minValue(0)
                        ->maxValue(65535),

                    Select::make('meeting_point_id')
                        ->label(__('catalog.product.form.meeting_point.label'))
                        ->helperText(__('catalog.product.form.meeting_point.help'))
                        ->options(static::portOptions(...))
                        ->searchable()
                        ->preload(),

                    // CAT-5: the flexible window is a whole-boat affordance.
                    // Hidden rather than merely refused, so an operator on a
                    // per-seat trip never fills in two times that the Action
                    // will silently clear.
                    Toggle::make('flexible_start')
                        ->label(__('catalog.product.form.flexible_start.label'))
                        ->helperText(__('catalog.product.form.flexible_start.help'))
                        ->live()
                        ->visible(static fn (Get $get): bool => static::modeOf($get)?->allowsFlexibleStart() === true),

                    TimePicker::make('earliest_start_time')
                        ->label(__('catalog.product.form.earliest_start_time.label'))
                        ->timezone('UTC')
                        ->seconds(false)
                        ->native(false)
                        ->visible(static fn (Get $get): bool => (bool) $get('flexible_start')),

                    TimePicker::make('latest_start_time')
                        ->label(__('catalog.product.form.latest_start_time.label'))
                        ->timezone('UTC')
                        ->seconds(false)
                        ->native(false)
                        ->visible(static fn (Get $get): bool => (bool) $get('flexible_start')),
                ])
                ->columns(2),

            Section::make(__('catalog.product.sections.capacity'))
                ->schema([
                    TextInput::make('max_pax')
                        ->label(__('catalog.product.form.max_pax.label'))
                        ->helperText(__('catalog.product.form.max_pax.help'))
                        ->integer()
                        ->required()
                        ->minValue(1)
                        ->maxValue(65535),

                    // `min_pax` is the guaranteed-departure threshold and only
                    // means anything when seats are counted (CAT-5).
                    TextInput::make('min_pax')
                        ->label(__('catalog.product.form.min_pax.label'))
                        ->helperText(__('catalog.product.form.min_pax.help'))
                        ->integer()
                        ->default(0)
                        ->minValue(0)
                        ->maxValue(65535)
                        ->visible(static fn (Get $get): bool => static::modeOf($get)?->usesMinPax() === true),

                    TextInput::make('min_booking_pax')
                        ->label(__('catalog.product.form.min_booking_pax.label'))
                        ->helperText(__('catalog.product.form.min_booking_pax.help'))
                        ->integer()
                        ->default(1)
                        ->minValue(1)
                        ->maxValue(65535),
                ])
                ->columns(2),

            Section::make(__('catalog.product.sections.bands'))
                ->description(__('catalog.product.form.bands.help'))
                // A whole-boat charter sells the boat rather than passenger
                // categories, so the section is not offered there at all.
                ->visible(static fn (Get $get): bool => static::modeOf($get) === BookingMode::PerSeat)
                ->schema([
                    Repeater::make('age_bands')
                        ->label(__('catalog.product.sections.bands'))
                        ->addActionLabel(__('catalog.product.form.bands.add'))
                        ->schema([
                            // Not asked any more (2026-09-17): made from the
                            // name on first save (SaveAgeBands::withCodes) and
                            // carried here unchanged, so prices stay attached.
                            Hidden::make('code'),

                            TranslatableInput::text(
                                'label',
                                __('catalog.product.form.bands.label.label'),
                                __('catalog.product.form.bands.label.help'),
                                maxLength: 60,
                            ),

                            TextInput::make('min_age')
                                ->label(__('catalog.product.form.bands.min_age.label'))
                                ->integer()
                                ->required()
                                ->default(0)
                                ->minValue(0)
                                ->maxValue(120),

                            TextInput::make('max_age')
                                ->label(__('catalog.product.form.bands.max_age.label'))
                                ->helperText(__('catalog.product.form.bands.max_age.help'))
                                ->integer()
                                ->minValue(0)
                                ->maxValue(120),

                            // No «ποσοστό της βασικής» any more (product owner,
                            // 2026-09-17): every band's price is euros, typed in
                            // the price table below. A new band is `fixed`; an
                            // existing percentage band keeps its mode untouched
                            // until the table is saved and writes it as euros.
                            Hidden::make('pricing_mode')
                                ->default(AgeBandPricing::Fixed->value),

                            Hidden::make('price_multiplier_bp'),

                            Toggle::make('is_base')
                                ->label(__('catalog.product.form.bands.is_base.label')),

                            Toggle::make('counts_toward_capacity')
                                ->label(__('catalog.product.form.bands.counts_toward_capacity.label'))
                                ->helperText(__('catalog.product.form.bands.counts_toward_capacity.help'))
                                ->default(true),

                            Toggle::make('requires_adult')
                                ->label(__('catalog.product.form.bands.requires_adult.label')),

                            // «Χωρίς έγγραφο» (2026-09-17): checkout asks these
                            // passengers for a name, nationality and date of
                            // birth, and no document.
                            Toggle::make('no_document')
                                ->label(__('catalog.product.form.bands.no_document.label'))
                                ->helperText(__('catalog.product.form.bands.no_document.help')),
                        ])
                        ->itemLabel(static function (array $state): ?string {
                            $label = $state['label'] ?? null;
                            $name = is_array($label) ? (string) ($label[app()->getLocale()] ?? $label['el'] ?? '') : '';

                            return $name !== '' ? $name : null;
                        })
                        // A new trip starts with the usual three, which the
                        // operator edits, removes or adds to — e.g. ΑΜΕΑ, which
                        // may share ages with «Ενήλικας» (2026-09-17).
                        ->default(static::defaultAgeBands(...))
                        ->columns(2)
                        ->columnSpanFull(),
                ]),

            // Every band, every period, in euros (product owner, 2026-09-17).
            // The table prices saved bands, so on a trip being created the
            // section is there but says, in one line, that it appears after
            // the first save: a missing section reads as "no prices here".
            Section::make(__('pricing.price_table.heading'))
                // The create page lands here after «Συνέχεια στις τιμές».
                ->id('prices')
                ->description(__('pricing.price_table.intro'))
                ->visible(static fn (Get $get): bool => static::modeOf($get) === BookingMode::PerSeat)
                ->schema([
                    Placeholder::make('price_table_after_save')
                        ->hiddenLabel()
                        ->content(__('pricing.price_table.after_first_save'))
                        ->visible(static fn (mixed $livewire): bool => ! $livewire instanceof Pages\EditProduct),
                    ViewField::make('filament.app.product-price-table')
                        ->visible(static fn (mixed $livewire): bool => $livewire instanceof Pages\EditProduct),
                ]),

            Section::make(__('catalog.product.sections.policy'))
                ->schema([
                    Select::make('cancellation_policy_id')
                        ->label(__('catalog.product.form.cancellation_policy.label'))
                        ->helperText(__('catalog.product.form.cancellation_policy.help'))
                        ->options(static::cancellationPolicyOptions(...))
                        ->searchable()
                        ->preload()
                        // A new operator has no policy, and a trip cannot be
                        // published without one, so the trip form can make it.
                        // Name, free-cancellation window and the refund ladder;
                        // weather, no-show and voucher keep their column
                        // defaults and are edited on the policy screen.
                        ->createOptionForm([
                            TranslatableInput::text(
                                'name',
                                __('pricing.cancellation.form.name.label'),
                                __('pricing.cancellation.form.name.help'),
                                maxLength: 80,
                            ),
                            TextInput::make('free_cancellation_hours')
                                ->label(__('pricing.cancellation.form.free_cancellation_hours.label'))
                                ->helperText(__('pricing.cancellation.form.free_cancellation_hours.help'))
                                ->integer()
                                ->minValue(0)
                                ->maxValue(65535)
                                ->suffix(__('pricing.cancellation.form.free_cancellation_hours.suffix')),
                            Repeater::make('tiers')
                                ->label(__('pricing.cancellation.form.tiers.label'))
                                ->helperText(__('pricing.cancellation.form.tiers.help'))
                                ->addActionLabel(__('pricing.cancellation.form.tiers.add'))
                                ->schema([
                                    TextInput::make('days_before')
                                        ->label(__('pricing.cancellation.form.tiers.days_before'))
                                        ->integer()
                                        ->required()
                                        ->minValue(0)
                                        ->maxValue(65535),
                                    TextInput::make('refund_percent')
                                        ->label(__('pricing.cancellation.form.tiers.refund_percent'))
                                        ->integer()
                                        ->required()
                                        ->minValue(0)
                                        ->maxValue(100)
                                        ->suffix('%'),
                                ])
                                ->reorderable(false)
                                ->defaultItems(0)
                                ->columns(2),
                        ])
                        ->createOptionUsing(static function (array $data): int {
                            /** @var list<array{days_before: int|string, refund_percent: int|string}> $tiers */
                            $tiers = array_values($data['tiers'] ?? []);
                            unset($data['tiers']);

                            return (int) app(SaveCancellationPolicy::class)(new CancellationPolicy, $data, $tiers)->getKey();
                        }),

                    Select::make('vat_rate_id')
                        ->label(__('catalog.product.form.vat_rate.label'))
                        ->helperText(__('catalog.product.form.vat_rate.help'))
                        ->options(static::vatRateOptions(...))
                        // The account's own answer, from the setup guide (#51).
                        // Per-product stays the truth — a cruise is passenger
                        // transport and a barbecue extra is catering, and
                        // data-model §2.3 says so — but an operator answers the
                        // question once and overrides it where it differs,
                        // rather than choosing from nothing every time.
                        //
                        // `default()` and not a fill, so it applies to a new
                        // product only: an existing one whose rate was chosen
                        // deliberately, or deliberately left empty, keeps what
                        // it has.
                        ->default(static::defaultVatRateId(...))
                        ->searchable()
                        ->preload(),

                    Toggle::make('guest_details_required')
                        ->label(__('catalog.product.form.guest_details_required.label'))
                        ->helperText(__('catalog.product.form.guest_details_required.help'))
                        ->live(),

                    TextInput::make('guest_details_deadline_hours')
                        ->label(__('catalog.product.form.guest_details_deadline_hours.label'))
                        ->helperText(__('catalog.product.form.guest_details_deadline_hours.help'))
                        ->suffix(__('catalog.product.form.guest_details_deadline_hours.suffix'))
                        ->integer()
                        ->default(48)
                        ->minValue(0)
                        ->maxValue(65535)
                        ->visible(static fn (Get $get): bool => (bool) $get('guest_details_required')),
                ])
                ->columns(2),
        ];
    }

    /**
     * What the guest reads: all of it optional.
     *
     * @return array<int, Component>
     */
    public static function pageSections(): array
    {
        return [
            Section::make(__('catalog.product.sections.content'))
                ->schema([
                    TranslatableInput::textarea(
                        'summary',
                        __('catalog.product.form.summary.label'),
                        __('catalog.product.form.summary.help'),
                        rows: 3,
                    ),

                    // The pill on a card's photograph («Δημοφιλές»). Short on
                    // purpose: past two words it covers the photograph it sits on.
                    TranslatableInput::text(
                        'badge',
                        __('catalog.product.form.badge.label'),
                        __('catalog.product.form.badge.help'),
                        required: false,
                        maxLength: 24,
                    ),

                    TranslatableInput::textarea(
                        'description',
                        __('catalog.product.form.description.label'),
                        __('catalog.product.form.description.help'),
                        rows: 8,
                    ),
                ]),

            // «Περιεχόμενο σελίδας εκδρομής» (2026-09-16). Every field optional
            // and none on the CAT-15 checklist: an empty one is stored as null
            // and its section is simply absent from the trip page and from the
            // operator's WordPress site. `TripPageContent` turns these lines and
            // rows back into the columns' shapes on save.
            Section::make(__('catalog.product.sections.trip_page'))
                ->description(__('catalog.product.trip_page.description'))
                ->collapsible()
                ->schema([
                    self::lineList('highlights'),

                    Repeater::make(TripPageContent::ITINERARY_FIELD)
                        ->label(__('catalog.product.trip_page.itinerary.label'))
                        ->helperText(__('catalog.product.trip_page.itinerary.help'))
                        ->schema([
                            // A picker rather than typed text (product owner,
                            // 2026-09-17). `H:i` keeps the stored «09:45» the
                            // page and the API already read.
                            TimePicker::make('time')
                                ->label(__('catalog.product.trip_page.itinerary.time.label'))
                                ->helperText(__('catalog.product.trip_page.itinerary.time.help'))
                                ->seconds(false)
                                ->native(false)
                                ->format('H:i')
                                ->displayFormat('H:i')
                                // A stop time is the boat's own clock, not an
                                // instant: no shift from the operator's
                                // timezone, which the panel gives every picker.
                                ->timezone('UTC')
                                // Not only «ΩΩ:ΛΛ»: in the browser the picker
                                // holds a full date-time («2026-09-17 09:45:00»)
                                // until save turns it into «09:45», and
                                // validation sees that first (2026-09-17).
                                ->regex('/^(\d{4}-\d{2}-\d{2} )?([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/')
                                ->columnSpan(1),
                            TranslatableInput::text(
                                'name',
                                __('catalog.product.trip_page.itinerary.name.label'),
                                null,
                                required: false,
                                maxLength: 120,
                            )->columnSpan(3),
                            TranslatableInput::textarea(
                                'description',
                                __('catalog.product.trip_page.itinerary.description.label'),
                                __('catalog.product.trip_page.itinerary.description.help'),
                                rows: 2,
                            )->columnSpanFull(),
                        ])
                        ->columns(4)
                        ->reorderable()
                        ->collapsible()
                        ->itemLabel(static function (array $state): string {
                            $name = collect((array) ($state['name'] ?? []))
                                ->first(static fn (mixed $text): bool => is_string($text) && trim($text) !== '');
                            // «09:45» out of whatever the picker holds.
                            $time = is_string($state['time'] ?? null)
                                && preg_match('/([01]\d|2[0-3]):[0-5]\d/', $state['time'], $match) === 1
                                ? $match[0]
                                : '';

                            return trim($time . ' ' . ($name ?? __('catalog.product.trip_page.itinerary.untitled')));
                        })
                        ->addActionLabel(__('catalog.product.trip_page.itinerary.add'))
                        ->defaultItems(0),

                    self::lineList('includes'),
                    self::lineList('excludes'),
                    self::lineList('what_to_bring'),
                ]),

            Section::make(__('catalog.product.sections.seo'))
                ->collapsed()
                ->schema([
                    TranslatableInput::text(
                        'meta_title',
                        __('catalog.product.form.meta_title.label'),
                        __('catalog.product.form.meta_title.help'),
                        required: false,
                        maxLength: 70,
                    ),

                    TranslatableInput::textarea(
                        'meta_description',
                        __('catalog.product.form.meta_description.label'),
                        __('catalog.product.form.meta_description.help'),
                        rows: 2,
                    ),
                ]),
        ];
    }

    /** «λείπει 2» on a saved trip that cannot be published yet; nothing otherwise. */
    public static function basicsBadge(?Product $record): ?string
    {
        if (! $record instanceof Product || ! $record->exists) {
            return null;
        }

        $missing = count(ProductPublishChecklist::unmet($record));

        return $missing === 0 ? null : trans_choice('catalog.product.tabs.missing', $missing, ['count' => $missing]);
    }

    /** «5 από 8»: how much of the guest's page is written, on a saved trip. */
    public static function pageBadge(?Product $record): ?string
    {
        if (! $record instanceof Product || ! $record->exists) {
            return null;
        }

        $fields = ['summary', 'description', 'highlights', 'itinerary_stops', 'includes', 'excludes', 'what_to_bring', 'meta_description'];

        $filled = count(array_filter($fields, static function (string $field) use ($record): bool {
            foreach ((array) $record->getTranslations($field) as $value) {
                if (is_string($value) ? trim($value) !== '' : ! empty($value)) {
                    return true;
                }
            }

            return false;
        }));

        return __('catalog.product.tabs.filled', ['filled' => $filled, 'total' => count($fields)]);
    }

    /**
     * One optional translatable list — «Τι θα ζήσετε», «Περιλαμβάνονται» — as a
     * list of lines per language, a tab per language.
     *
     * A simple repeater rather than a textarea split on newlines: each line is
     * its own item on the page and in the WordPress mirror, and a row per line is
     * what makes that visible — and reorderable — while it is being written.
     */
    private static function lineList(string $field): Component
    {
        $tabs = [];

        foreach (LocaleResolver::installed() as $locale) {
            $tabs[] = Tabs\Tab::make($locale)
                ->label(__("enums.locale.{$locale}.label"))
                ->schema([
                    Repeater::make("{$field}.{$locale}")
                        ->label(__("catalog.product.trip_page.{$field}.label"))
                        ->helperText(__("catalog.product.trip_page.{$field}.help"))
                        ->simple(
                            TextInput::make('value')
                                ->label(__('catalog.product.trip_page.line'))
                                ->maxLength(160),
                        )
                        ->reorderable()
                        ->addActionLabel(__('catalog.product.trip_page.add_line'))
                        ->defaultItems(0),
                ]);
        }

        return Tabs::make($field)->tabs($tabs)->columnSpanFull();
    }

    /**
     * Greeklish, from the Greek title, and only into an empty slug.
     *
     * ## Why it never overwrites
     *
     * The slug is the trip's public address. Once a page has been shared, put in
     * an email or indexed, changing it breaks the link — so an operator who has
     * typed one, or who is editing a trip that has been live all season, must
     * never have it rewritten because they fixed a typo in the title.
     *
     * Auto-filling only into an empty field gives the useful half (nobody has to
     * invent `iliovasilema-stin-aigina` by hand) without the dangerous half.
     *
     * ## `Str::slug($title, '-', 'el')`
     *
     * Laravel's own Greek transliteration map, not a hand-written table:
     * «Ηλιοβασίλεμα στην Αίγινα» becomes `hliovasilema-stin-aighina`. A map of
     * our own would be one more thing to get wrong for ξ, ψ and the accented
     * vowels, and it would drift from the one the framework already ships.
     */
    public static function fillSlug(Get $get, Set $set, ?string $state): void
    {
        $slug = self::slugFor(
            is_string($get('slug')) ? $get('slug') : null,
            $state,
        );

        if ($slug !== null) {
            $set('slug', $slug);
        }
    }

    /**
     * The slug to write, or null to leave the field alone.
     *
     * Split out from the Filament closure so the rule can be tested without a
     * form: `Get` and `Set` are objects bound to a live component, and a rule
     * this consequential should not be reachable only through a browser.
     */
    public static function slugFor(?string $existing, ?string $title): ?string
    {
        if ($existing !== null && trim($existing) !== '') {
            return null;
        }

        $title = trim((string) $title);

        return $title === '' ? null : Str::slug($title, '-', 'el');
    }

    /**
     * One line per CAT-15 requirement, met or not.
     *
     * On an unsaved product half the checklist cannot be answered — a rate plan
     * and an age band both need a product id — so it says so plainly rather
     * than reporting six things missing that the operator is in the middle of
     * supplying.
     *
     * @return array<int, Component>
     */
    public static function checklistSchema(): array
    {
        return [
            Placeholder::make('publish_checklist')
                // No label: the section already carries one, and a second
                // heading above a single row of chips is a heading for nothing.
                ->hiddenLabel()
                ->content(static function (?Product $record): View {
                    $items = [];
                    $remaining = 0;

                    foreach (ProductPublishChecklist::requirements() as $requirement) {
                        $met = $record !== null
                            && $record->exists
                            && ProductPublishChecklist::satisfies($record, $requirement);

                        if (! $met) {
                            $remaining++;
                        }

                        $items[] = [
                            'met' => $met,
                            'label' => __("catalog.product.checklist.{$requirement}.label"),
                            'unmet' => __("catalog.product.checklist.{$requirement}.unmet"),
                        ];
                    }

                    return view('filament.app.product-checklist', [
                        'record' => $record,
                        'items' => $items,
                        'remaining' => $remaining,
                    ]);
                })
                ->columnSpanFull(),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label(__('catalog.product.table.title'))
                    ->sortable(query: self::sortByTitle(...))
                    ->searchable(query: self::searchByTitle(...))
                    // A draft says why it is still a draft, from the same
                    // checklist the form shows (2026-09-17).
                    ->description(self::missingForDraft(...)),

                TextColumn::make('vessel.name')
                    ->label(__('catalog.product.table.vessel'))
                    ->toggleable(),

                TextColumn::make('mode')
                    ->label(__('catalog.product.table.mode'))
                    ->badge()
                    ->formatStateUsing(static fn (BookingMode $state): string => $state->label()),

                // Coloured, so a draft does not look like the grey mode badge
                // beside it.
                TextColumn::make('status')
                    ->label(__('catalog.product.table.status'))
                    ->badge()
                    ->formatStateUsing(static fn (ProductStatus $state): string => $state->label())
                    ->color(static fn (ProductStatus $state): string => match ($state) {
                        ProductStatus::Draft => 'warning',
                        ProductStatus::Active => 'success',
                        ProductStatus::Inactive, ProductStatus::Archived => 'gray',
                    }),

                TextColumn::make('max_pax')
                    ->label(__('catalog.product.table.max_pax')),

                // Derived (§1.9), so the list does not fan out across seasons,
                // plans and bands for every card. **Null renders as nothing**,
                // never as a zero: a `quote` product has no price by design and
                // a product with no resolvable plan is not sellable (PRC-5) —
                // "€0.00" on either is a free trip on a public page.
                TextColumn::make('price_from_cents')
                    ->label(__('catalog.product.table.price_from'))
                    ->formatStateUsing(static fn (?int $state): string => $state === null
                        ? ''
                        : __('catalog.product.table.price_from_value', [
                            'price' => MoneyFormatter::format($state),
                        ]))
                    ->alignEnd(),

                IconColumn::make('is_featured')
                    ->label(__('catalog.product.table.featured'))
                    ->boolean()
                    ->toggleable(),
            ])
            ->defaultSort('sort_order')
            // Status is a row of tabs above the list (`ListProducts::getTabs`),
            // with the number of drafts on its tab, rather than a filter
            // hidden behind a button.
            ->filters([
                TrashedFilter::make(),
            ])
            ->actions([EditAction::make(), DeleteAction::make(), RestoreAction::make()]);
    }

    /** «Λείπουν 2: Σκάφος, Τιμοκατάλογος» under a draft's title; nothing otherwise. */
    public static function missingForDraft(Product $record): ?string
    {
        if ($record->status !== ProductStatus::Draft) {
            return null;
        }

        $unmet = ProductPublishChecklist::unmet($record);

        if ($unmet === []) {
            return null;
        }

        return trans_choice('catalog.product.table.missing', count($unmet), [
            'count' => count($unmet),
            'items' => implode(', ', array_map(
                static fn (string $key): string => __("catalog.product.checklist.{$key}.label"),
                $unmet,
            )),
        ]);
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public static function sortByTitle(Builder $query, string $direction): Builder
    {
        return $query->orderByTranslation('title', $direction);
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public static function searchByTitle(Builder $query, string $search): Builder
    {
        return $query->whereTranslationMatches($search);
    }

    /** @return array<int, string> */
    public static function vesselOptions(): array
    {
        return Vessel::query()
            ->get()
            ->mapWithKeys(static fn (Vessel $vessel): array => [$vessel->getKey() => (string) $vessel->name])
            ->all();
    }

    /** @return array<int, string> */
    public static function portOptions(): array
    {
        return Port::query()
            ->get()
            ->mapWithKeys(static fn (Port $port): array => [$port->getKey() => (string) $port->name])
            ->all();
    }

    /**
     * Ενήλικας, Παιδί, Βρέφος: the set almost every trip starts from.
     *
     * No codes: they are made from the names on save. Prices are not here —
     * they go in the euro table like any other band's.
     *
     * @return list<array<string, mixed>>
     */
    public static function defaultAgeBands(): array
    {
        $band = static fn (string $el, string $en, int $min, ?int $max, array $flags = []): array => [
            'code' => null,
            'label' => ['el' => $el, 'en' => $en],
            'min_age' => $min,
            'max_age' => $max,
            'pricing_mode' => AgeBandPricing::Fixed->value,
            'is_base' => false,
            'counts_toward_capacity' => true,
            'requires_adult' => false,
            'no_document' => false,
            ...$flags,
        ];

        return [
            $band('Ενήλικας', 'Adult', 12, null, ['is_base' => true]),
            $band('Παιδί', 'Child', 3, 11),
            $band('Βρέφος', 'Infant', 0, 2, [
                'counts_toward_capacity' => false,
                'requires_adult' => true,
                'no_document' => true,
            ]),
        ];
    }

    /** @return array<int, string> */
    public static function cancellationPolicyOptions(): array
    {
        return CancellationPolicy::query()
            ->get()
            ->mapWithKeys(static fn (CancellationPolicy $policy): array => [$policy->getKey() => (string) $policy->name])
            ->all();
    }

    /**
     * The rate a new product starts on, or null if the account has not said.
     *
     * Checked against the selectable list rather than returned as stored. A
     * rate withdrawn from the platform after an operator chose it would
     * otherwise be pre-filled as an id the select cannot render — the field
     * would look empty and submit a value, which is the worst of both.
     */
    public static function defaultVatRateId(): ?int
    {
        $id = Tenancy::check() ? Tenancy::current()?->default_vat_rate_id : null;

        if ($id === null) {
            return null;
        }

        return array_key_exists((int) $id, static::vatRateOptions()) ? (int) $id : null;
    }

    /** @return array<int, string> */
    public static function vatRateOptions(): array
    {
        return VatRate::query()
            ->selectable()
            ->get()
            // Code plus percentage: an operator picking a VAT rate is picking
            // a number, and «Μειωμένος» alone does not say which one.
            ->mapWithKeys(static fn (VatRate $rate): array => [
                $rate->getKey() => $rate->code . ' — ' . $rate->percentLabel(),
            ])
            ->all();
    }

    /** The booking mode currently chosen in the form. */
    public static function modeOf(Get $get): ?BookingMode
    {
        $mode = $get('mode');

        return $mode instanceof BookingMode ? $mode : BookingMode::tryFrom((string) $mode);
    }

    /** @return Builder<Product> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    /**
     * The trip's prices, on the trip (#51 follow-up).
     *
     * Pricing one trip for two periods used to mean three screens and choosing
     * the trip from a dropdown twice. `product_id` cannot be wrong here,
     * because there is no such field — the trip is the record.
     *
     * @return array<int, class-string>
     */
    public static function getRelations(): array
    {
        return [
            RatePlansRelationManager::class,
            // «Πρόσθετα», free or paid (2026-09-17).
            ExtrasRelationManager::class,
            QuestionsRelationManager::class,
        ];
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'edit' => Pages\EditProduct::route('/{record}/edit'),
        ];
    }
}
