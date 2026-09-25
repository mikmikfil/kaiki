<?php

declare(strict_types=1);

namespace App\Filament\App\Resources;

use App\Domain\Catalog\Actions\SaveCancellationPolicy;
use App\Domain\Catalog\Support\ProductPublishChecklist;
use App\Domain\Catalog\Support\TripPageContent;
use App\Domain\Hosted\Support\HostedUrl;
use App\Enums\AgeBandKind;
use App\Enums\AgeBandPricing;
use App\Enums\BookingMode;
use App\Enums\ProductCategory;
use App\Enums\ProductStatus;
use App\Enums\VesselLicence;
use App\Filament\App\Resources\ProductResource\Pages;
use App\Filament\App\Resources\ProductResource\RelationManagers\ExtrasRelationManager;
use App\Filament\App\Resources\ProductResource\RelationManagers\FaqsRelationManager;
use App\Filament\App\Resources\ProductResource\RelationManagers\QuestionsRelationManager;
use App\Filament\App\Resources\ProductResource\RelationManagers\RatePlansRelationManager;
use App\Filament\App\Resources\ProductResource\RelationManagers\ScheduleRulesRelationManager;
use App\Filament\Forms\TranslatableInput;
use App\Filament\Support\MoreActions;
use App\Models\CancellationPolicy;
use App\Models\Port;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\VatRate;
use App\Models\Vessel;
use App\Rules\MaxPaxWithinVesselCapacity;
use App\Support\Format\MoneyFormatter;
use App\Support\Locale\LocaleResolver;
use App\Support\Tenancy;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Group;
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
use Filament\Forms\Components\ToggleButtons;
use Filament\Forms\Components\View as ViewField;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action as TableAction;
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

    /**
     * The uploader's name for the gallery, so it cannot collide with the
     * `images` column it maps to ({@see TripPageContent::galleryToForm()}).
     */
    public const GALLERY_FIELD = 'gallery';

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
            /*
             * Five tabs and a sidebar (product owner, 2026-09-17; approved from
             * the mockup, built 2026-09-18).
             *
             * The form had grown to nine sections in one column — «χάος», in
             * his word — and the order of them was the order they were built
             * in. These five are the questions an operator actually asks, in
             * the order they ask them: what is it, when does it leave, what
             * does it cost, on what terms, and what does the guest read.
             *
             * The publish checklist leaves the first tab and becomes a column
             * of its own, because it is the one thing that is true of the whole
             * trip: a requirement living inside «Βασικά» could not say that a
             * price was missing without the operator opening a different tab to
             * find out.
             *
             * A save error in a tab that is not on screen opens that tab by
             * itself, which is Filament's behaviour and the reason the tabs are
             * a Tabs component rather than five links.
             *
             * **A fifth, not a third** (product owner, 2026-09-21, twice): the
             * checklist is a glance, not a panel, and at a third of the page it
             * was taking room from the trip itself. Four of five columns go to
             * the form; the chips in the last one shrink to match — see
             * `.ka-checklist-side` in `sea.blade.php`.
             */
            Grid::make(['default' => 1, 'xl' => 5])
                ->schema([
                    Group::make([
                        // One switch for the whole form, instead of a pair of
                        // language tabs above every translatable field.
                        ViewField::make('filament.app.form-locale-switch')
                            ->columnSpanFull(),

                        Tabs::make('trip')
                            ->contained(false)
                            ->persistTabInQueryString('tab')
                            ->extraAttributes(['class' => 'ka-line-tabs'])
                            ->tabs([
                                Tabs\Tab::make(__('catalog.product.tabs.basics'))
                                    ->badge(static::basicsBadge(...))
                                    ->badgeColor('warning')
                                    ->schema(static::basicsSections()),

                                Tabs\Tab::make(__('catalog.product.tabs.when'))
                                    ->badge(static::whenBadge(...))
                                    ->badgeColor(static fn (?Product $record): string => static::missingBadge($record, 'when') !== null
                                        || static::scheduleBadge($record) === __('catalog.product.tabs.no_schedule') ? 'warning' : 'gray')
                                    ->schema(static::whenSections()),

                                Tabs\Tab::make(__('catalog.product.tabs.prices'))
                                    ->badge(static fn (?Product $record): ?string => static::missingBadge($record, 'prices'))
                                    ->badgeColor('warning')
                                    ->schema(static::priceSections()),

                                Tabs\Tab::make(__('catalog.product.tabs.terms'))
                                    ->badge(static fn (?Product $record): ?string => static::missingBadge($record, 'terms'))
                                    ->badgeColor('warning')
                                    ->schema(static::termsSections()),

                                Tabs\Tab::make(__('catalog.product.tabs.page'))
                                    ->badge(static::pageBadge(...))
                                    ->badgeColor('gray')
                                    ->schema(static::pageSections()),
                            ]),
                    ])->columnSpan([
                        'default' => 1,
                        // Per breakpoint, because that is where Filament
                        // evaluates a closure: one returning the whole array
                        // is used as a string and throws.
                        'xl' => static fn (?Product $record): int => static::showsChecklist($record) ? 4 : 5,
                    ]),

                    Group::make([
                        // No description: «Πριν τη δημοσίευση» over a row of
                        // chips already says what the section is, and a line of
                        // prose repeating it was half the card's height.
                        Section::make(__('catalog.product.sections.checklist'))
                            ->compact()
                            ->extraAttributes(['class' => 'ka-checklist-side'])
                            ->schema(static::checklistSchema()),
                    ])
                        ->visible(static fn (?Product $record): bool => static::showsChecklist($record))
                        ->columnSpan(['default' => 1, 'xl' => 1]),
                ])
                ->columnSpanFull(),
        ];
    }

    /**
     * Is «Πριν τη δημοσίευση» worth a fifth of the screen on this trip?
     *
     * Product owner, 2026-09-22: *«όταν δημοσιεύω πρώτη φορά μια εκδρομή, μετά
     * μπαίνω να κάνω edit και εμφανίζεται το sidebar με το πριν την δημοσίευση.
     * νομίζω πρέπει να φύγει και να μεγαλώσει το width των στοιχείων της
     * εκδρομής»*. A list of things to do before publishing, on a trip that is
     * published, is a column of ticks — and it is taking a fifth of the width
     * from the form somebody opened this page to edit.
     *
     * **Not simply "is it active".** A trip goes live and then loses a price,
     * an age band or its last schedule: it is still `active`, and the checklist
     * is the one place that says which requirement it is now failing. So the
     * column comes back the moment something on it is unmet, on a published
     * trip exactly as on a draft.
     */
    public static function showsChecklist(?Product $record): bool
    {
        if (! $record instanceof Product || ! $record->exists) {
            return true;
        }

        if ($record->status !== ProductStatus::Active) {
            return true;
        }

        foreach (ProductPublishChecklist::requirements() as $requirement) {
            if (! ProductPublishChecklist::satisfies($record, $requirement)) {
                return true;
            }
        }

        return false;
    }

    /** «2 ενεργά», or «κανένα» in amber: a trip nobody can book on any day. */
    public static function scheduleBadge(?Product $record): ?string
    {
        if (! $record instanceof Product || ! $record->exists) {
            return null;
        }

        // No badge at all on a trip that has no repeating timetable by design.
        // A charter is sold as a whole boat on a date the guest names, and a
        // «κατόπιν προσφοράς» trip is agreed one request at a time — the tab
        // does not offer either of them a schedule, so «κανένα» in amber was an
        // alarm about something that cannot be fixed.
        if ($record->mode !== BookingMode::PerSeat) {
            return null;
        }

        $active = $record->scheduleRules()->where('is_active', true)->count();

        return $active === 0
            ? __('catalog.product.tabs.no_schedule')
            : trans_choice('catalog.product.tabs.active_schedules', $active, ['count' => $active]);
    }

    /**
     * Tab 1 — what the trip is: its name, its address, its boat, how it sells.
     *
     * @return array<int, Component>
     */
    public static function basicsSections(): array
    {
        return [
            Section::make(__('catalog.product.sections.basics'))
                ->icon('heroicon-o-map')
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
                        ->live()
                        ->preload(),

                    // A professional pleasure boat is chartered whole, on the
                    // reading of ν. 4926/2022 the lawyer is still to confirm —
                    // so selling one per seat is said, not refused (2026-09-24).
                    Placeholder::make('licence_warning')
                        ->hiddenLabel()
                        ->content(__('catalog.product.form.vessel.pleasure_per_seat'))
                        ->extraAttributes(['class' => 'ka-warning-note'])
                        ->columnSpanFull()
                        ->visible(static fn (Get $get): bool => static::modeOf($get) === BookingMode::PerSeat
                            && static::vesselLicence($get) === VesselLicence::ProfessionalPleasure),

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

                    // **«Σειρά εμφάνισης» is not a field any more**
                    // (Mike, 2026-09-22): *«βγάλε την σειρά εμφάνισης της
                    // εκδρομής. αυτό το νούμερο. η σειρά θέλω να μπορεί να
                    // γίνει με drag n drop μέσα από τη λίστα των εκδρομών»*.
                    //
                    // A number between 0 and 65535, typed on the form of one
                    // trip, is a way of arranging a list you cannot see: to put
                    // a trip third an operator had to open the other trips and
                    // read their numbers. The list itself is where the order is
                    // decided, so it is now dragged there — `sort_order` is
                    // still the column behind it, written by Filament's own
                    // reorder handler (see `table()`).
                    //
                    // The column keeps its default of 0 in the database, so a
                    // trip created before a first drag sorts by title with its
                    // neighbours instead of jumping to the top.

                    Toggle::make('is_featured')
                        ->label(__('catalog.product.form.is_featured.label'))
                        ->helperText(__('catalog.product.form.is_featured.help')),
                ])
                ->columns(2),
        ];
    }

    /**
     * Tab 2 — when it leaves: how long, how early to be there, how many fit,
     * and the timetable that generates the departures.
     *
     * @return array<int, Component>
     */
    public static function whenSections(): array
    {
        return [
            // A trip sold per seat takes its days and times from the «Δρομολόγια»
            // tab, so here it only has duration, check-in and meeting point. The
            // single departure time is a whole-boat charter's (2026-09-17).
            Section::make(static fn (Get $get): string => static::modeOf($get) === BookingMode::PerSeat
                ? __('catalog.product.sections.schedule_per_seat')
                : __('catalog.product.sections.schedule'))
                ->icon('heroicon-o-clock')
                ->schema([
                    Placeholder::make('schedule_where')
                        ->hiddenLabel()
                        // «Δρομολόγια» is the section at the foot of this same
                        // tab, not a tab of its own — it stopped being one when
                        // the form went to five tabs (2026-09-22). It is only
                        // there once the trip is saved, so say which of the two
                        // the operator is looking at.
                        ->content(static fn (?Product $record): string => $record instanceof Product && $record->exists
                            ? __('catalog.product.form.schedule_where')
                            : __('catalog.product.form.schedule_where_unsaved'))
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
                        // Optional, and said so, on a trip sold «κατόπιν
                        // προσφοράς»: the guest proposes a time in the request
                        // and `ProposedWindowBuilder` prefers theirs.
                        ->helperText(static fn (Get $get): string => static::modeOf($get) === BookingMode::Quote
                            ? __('catalog.product.form.default_start_time.quote_help')
                            : __('catalog.product.form.default_start_time.help'))
                        ->placeholder(static fn (Get $get): ?string => static::modeOf($get) === BookingMode::Quote
                            ? __('catalog.product.form.default_start_time.optional')
                            : null)
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

                    // «Λιμάνι αποβίβασης» on the passenger list (2026-09-24).
                    // Empty on a round trip, which is almost every trip.
                    Select::make('landing_port_id')
                        ->label(__('catalog.product.form.landing_port.label'))
                        ->helperText(__('catalog.product.form.landing_port.help'))
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
                ->icon('heroicon-o-user-group')
                ->schema([
                    TextInput::make('max_pax')
                        ->label(__('catalog.product.form.max_pax.label'))
                        // The boat's own ceiling, in the line under the field
                        // (Mike, 2026-09-23). Being told the number *before*
                        // typing is worth more than being refused after.
                        ->helperText(static fn (Get $get): string => static::maxPaxHelp($get))
                        ->integer()
                        ->required()
                        ->minValue(1)
                        ->maxValue(65535)
                        ->rules(static fn (Get $get): array => [
                            new MaxPaxWithinVesselCapacity(static::vesselIdOf($get)),
                        ]),

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
                // All three on one line on a large screen (Mike, 2026-09-24);
                // two on a tablet, one under the other on a phone.
                ->columns(['default' => 1, 'md' => 2, 'lg' => 3]),

            // The trip's own timetable (2026-09-17), which used to be a screen
            // of its own in the menu. On a saved trip sold per seat only: there
            // is nothing to hang a rule on before the first save, and a charter
            // has no repeating schedule.
            //
            // **No `Section` around it** (2026-09-24, rule Γ of the form
            // mockup): the manager draws its own card and heading, and the
            // wrapper made a box in a box with two titles, «Δρομολόγια» and
            // «Πότε φεύγει». The heading is the manager's, like the price lists.
            Livewire::make(
                ScheduleRulesRelationManager::class,
                static fn (?Product $record): array => [
                    'ownerRecord' => $record,
                    'pageClass' => Pages\EditProduct::class,
                ],
            )
                ->key('trip-schedule-rules')
                ->visible(static fn (?Product $record, Get $get): bool => $record instanceof Product
                    && $record->exists
                    && static::modeOf($get) === BookingMode::PerSeat)
                ->columnSpanFull(),
        ];
    }

    /**
     * Tab 3 — what it costs: who pays what, the table of periods, and the
     * extras that are sold beside the seat.
     *
     * @return array<int, Component>
     */
    public static function priceSections(): array
    {
        return [
            Section::make(__('catalog.product.sections.bands'))
                ->icon('heroicon-o-users')
                ->description(__('catalog.product.form.bands.help'))
                // A whole-boat charter sells the boat rather than passenger
                // categories, so the section is not offered there at all.
                ->visible(static fn (Get $get): bool => static::modeOf($get) === BookingMode::PerSeat)
                ->schema([
                    static::bandsRepeater(),
                ]),

            /*
             * The trip's price lists, moved into this tab (product owner,
             * 2026-09-21: *«γιατί οι τιμές υπάρχουν και πάνω και κάτω;»*).
             *
             * They were a relation manager below the form, under a heading that
             * also said «Τιμές» — so one page carried two price screens, and
             * the one an operator had to use **first** was the lower one, past
             * the save buttons, where nothing pointed at it. The grid below
             * this section prices bands that only exist once a list exists.
             *
             * Nothing about the pricing rules moved: {@see SaveRatePlan} is
             * still the only writer and the manager is still the same class.
             *
             * **No `Section` around it.** A relation manager draws its own card
             * and its own heading, so wrapping one produces the heading twice —
             * which is the very complaint this change answers. The heading and
             * the line under it are set on the manager's table instead, the way
             * «Πρόσθετα» sets its own.
             *
             * It keeps its gate here, because `Livewire::make()` does not run
             * the one a registered relation manager gets for free — TEN-8 says
             * crew read departures, not prices.
             */
            Livewire::make(
                RatePlansRelationManager::class,
                static fn (?Product $record): array => [
                    'ownerRecord' => $record,
                    'pageClass' => Pages\EditProduct::class,
                ],
            )
                ->key('trip-rate-plans')
                // Per seat, the price lists are gone from the trip (product
                // owner, 2026-09-24): periods are ticked below and the terms
                // are set once. A whole-boat charter still prices here — it has
                // no groups for a table to have rows.
                ->visible(static fn (?Product $record, Get $get): bool => $record instanceof Product
                    && $record->exists
                    && static::modeOf($get) !== BookingMode::PerSeat
                    && RatePlansRelationManager::canViewForRecord($record, Pages\EditProduct::class))
                ->columnSpanFull(),

            // «Περίοδοι»: the operator's periods, ticked for this trip, and
            // «Νέα περίοδος» without leaving it (2026-09-24).
            Section::make(__('pricing.periods.heading'))
                ->icon('heroicon-o-calendar-days')
                ->description(__('pricing.periods.intro'))
                ->visible(static fn (Get $get, mixed $livewire): bool => $livewire instanceof Pages\EditProduct
                    && static::modeOf($get) === BookingMode::PerSeat)
                ->schema([
                    ViewField::make('filament.app.product-price-periods'),
                ]),

            // Every group, every ticked period, in euros (product owner,
            // 2026-09-17). The table prices saved groups, so on a trip being
            // created the section is there but says, in one line, that it
            // appears after the first save: a missing section reads as "no
            // prices here".
            Section::make(__('pricing.price_table.heading'))
                ->icon('heroicon-o-currency-euro')
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

            // «Προκαταβολή και προθεσμίες», once for the trip; a period that
            // differs says so from the «⋯» on its column (2026-09-24).
            Section::make(__('pricing.periods.terms.section'))
                ->icon('heroicon-o-banknotes')
                ->description(__('pricing.periods.terms.intro'))
                ->visible(static fn (Get $get, mixed $livewire): bool => $livewire instanceof Pages\EditProduct
                    && static::modeOf($get) === BookingMode::PerSeat)
                ->schema([
                    ViewField::make('filament.app.product-price-terms'),
                ]),

            // «Πρόσθετα» (2026-09-17), beside the prices rather than in a tab of
            // its own at the foot of the page: an extra is a price, and an
            // operator setting up a trip is thinking about money once.
            //
            // Unwrapped for the same reason as the price lists above: the
            // manager already carries this heading on its own table.
            Livewire::make(
                ExtrasRelationManager::class,
                static fn (?Product $record): array => [
                    'ownerRecord' => $record,
                    'pageClass' => Pages\EditProduct::class,
                ],
            )
                ->key('trip-extras')
                ->visible(static fn (?Product $record): bool => $record instanceof Product && $record->exists)
                ->columnSpanFull(),
        ];
    }

    /**
     * Tab 4 — the terms: what happens on a cancellation, which VAT rate, what
     * the passengers have to give, and anything else this operator asks.
     *
     * @return array<int, Component>
     */
    public static function termsSections(): array
    {
        return [
            Section::make(__('catalog.product.sections.policy'))
                ->icon('heroicon-o-shield-check')
                ->schema([
                    static::cancellationPolicySelect(),

                    static::vatRateSelect(),

                    ...static::guestDetailsFields(),
                ])
                ->columns(2),

            // The operator's own questions (2026-09-17). They are asked at
            // checkout and they are conditions of travelling, so they belong
            // beside the policy rather than under the page's marketing text.
            Section::make(__('catalog.product.sections.questions'))
                ->icon('heroicon-o-chat-bubble-left-right')
                ->description(__('catalog.product.sections.questions_intro'))
                ->visible(static fn (?Product $record): bool => $record instanceof Product && $record->exists)
                ->schema([
                    Livewire::make(
                        QuestionsRelationManager::class,
                        static fn (?Product $record): array => [
                            'ownerRecord' => $record,
                            'pageClass' => Pages\EditProduct::class,
                        ],
                    )->key('trip-questions'),
                ]),
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
                ->icon('heroicon-o-document-text')
                ->schema([
                    /*
                     * **The line under the title** (Mike, 2026-09-23: *«κάτω
                     * από τον τίτλο να υπάρχει πεδίο υπότιτλος»* — and he was
                     * not sure which of the two existing fields was which).
                     *
                     * It already was the subtitle: the trip page renders it as
                     * the standfirst directly under the `<h1>`. What it did not
                     * do is *say* so — it was labelled «Περίληψη» with help
                     * about lists and search results, so it read as an abstract
                     * and got written like one. Renamed, and the help now names
                     * all three places it appears.
                     *
                     * **140 characters**, not the 100 he floated, because this
                     * one string does three jobs: the standfirst, the line on a
                     * card, and the `description` a search engine reads from the
                     * JSON-LD. A hundred is about one short Greek sentence,
                     * which is enough to say what a trip *is* and not enough to
                     * say why anybody would go. Two rows rather than three, so
                     * the shape of the box argues for brevity before the counter
                     * has to.
                     */
                    TranslatableInput::textarea(
                        'summary',
                        __('catalog.product.form.summary.label'),
                        __('catalog.product.form.summary.help'),
                        rows: 2,
                        maxLength: 140,
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

            /*
             * **The photographs** (product owner, 2026-09-22: *«και οι φωτός»*).
             *
             * `products.images` has been a real column since the catalogue was
             * built and `ImagePayload` has been serving it to the guest pages,
             * the API and the WordPress plugin all along — but **no screen ever
             * wrote to it**. The ten photographs on the demo trip came from the
             * seeder, and a real operator had no way to add one. That is why it
             * looked like a missing field rather than a missing feature.
             *
             * **One uploader, not a row per photograph** (same day, after
             * trying it: *«ανεβάζουμε όλο το gallery και η πρώτη γίνεται
             * featured»*). A gallery arrives as a folder of twelve files, and
             * «add a row, choose a file» twelve times is not how anybody has
             * ever uploaded a gallery. So: pick them all at once, drag the one
             * that should lead the card to the front.
             *
             * The **first is the featured one** — `ImagePayload` hands the
             * gallery back in this order and every card takes `[0]` — which is
             * why the order is the only thing that says so, rather than an
             * `is_cover` flag that could disagree with it.
             *
             * `products.images` keeps its `{path, alt}` shape (§3.15): the
             * uploader edits a list of paths and {@see TripPageContent} maps
             * between the two, carrying over the alt text of every photograph
             * that is still there.
             */
            Section::make(__('catalog.product.sections.images'))
                ->icon('heroicon-o-photo')
                ->description(__('catalog.product.form.images.help'))
                ->collapsible()
                ->schema([
                    FileUpload::make(self::GALLERY_FIELD)
                        ->hiddenLabel()
                        ->image()
                        ->multiple()
                        ->reorderable()
                        // New files land at the end, so uploading more never
                        // moves the photograph that leads the card.
                        ->appendFiles()
                        // One upload at a time: FilePond adds each file to the form when
                        // its upload finishes, so parallel uploads saved 1-2-3 as 2-1-3
                        // and the wrong photo led the card.
                        ->maxParallelUploads(1)
                        ->panelLayout('grid')
                        ->imagePreviewHeight('120')
                        // The disk the API and the hosted pages build URLs
                        // from; the default `local` cannot produce a URL at all.
                        ->disk((string) config('kaiki.catalog.uploads.disk'))
                        ->directory('products')
                        ->maxSize(5120)
                        ->maxFiles(40)
                        ->columnSpanFull(),
                ]),

            // «Περιεχόμενο σελίδας εκδρομής» (2026-09-16). Every field optional
            // and none on the CAT-15 checklist: an empty one is stored as null
            // and its section is simply absent from the trip page and from the
            // operator's WordPress site. `TripPageContent` turns these lines and
            // rows back into the columns' shapes on save.
            Section::make(__('catalog.product.sections.trip_page'))
                ->icon('heroicon-o-rectangle-stack')
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
                ->icon('heroicon-o-magnifying-glass')
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

            /*
             * **Οι συχνές ερωτήσεις της εκδρομής** (Mike, 23/9: *«όταν φτιάχνω
             * εκδρομή δεν υπάρχει FAQ»*).
             *
             * Στην καρτέλα «Σελίδα» και όχι δίπλα στις «Ερωτήσεις»: εκείνες
             * είναι ερωτήσεις *προς* τον επισκέπτη στο ταμείο και όρος του
             * ταξιδιού· αυτές είναι απαντήσεις *σε* αυτόν και εμφανίζονται στη
             * σελίδα της εκδρομής, μαζί με τα υπόλοιπα που διαβάζει.
             *
             * Σε αποθηκευμένη εκδρομή μόνο, όπως και τα δρομολόγια και τα
             * πρόσθετα: δεν υπάρχει τίποτα να κρεμάσει μια ερώτηση πριν την
             * πρώτη αποθήκευση.
             */
            Section::make(__('faq.on_product.title'))
                ->icon('heroicon-o-question-mark-circle')
                ->description(__('faq.on_product.help'))
                ->collapsible()
                ->visible(static fn (?Product $record): bool => $record instanceof Product && $record->exists)
                ->schema([
                    Livewire::make(
                        FaqsRelationManager::class,
                        static fn (?Product $record): array => [
                            'ownerRecord' => $record,
                            'pageClass' => Pages\EditProduct::class,
                        ],
                    )->key('trip-faqs'),
                ]),
        ];
    }

    /**
     * «Στοιχεία επιβατών», and the deadline that only means anything with it on.
     *
     * Extracted so the creation guide can ask the same question with the same
     * words (product owner, 2026-09-22). It was on the «Όροι» tab only, which
     * is a tab nobody opens on a trip they are still writing — and the
     * consequence is invisible until a guest reaches checkout and is asked for
     * no documents at all, which is where he found it.
     *
     * @return array<int, Component>
     */
    public static function guestDetailsFields(): array
    {
        return [
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
        ];
    }

    /**
     * Which tab each publish requirement is fixed on, so «λείπει 1» sits on
     * the tab that fixes it. Product owner, 2026-09-25: *«ενώ λέει ότι λείπει
     * ο τιμοκατάλογος, εμφανίζεται στα βασικά ότι λείπει 1»* — every unmet
     * requirement used to be counted on «Βασικά».
     *
     * @var array<string, list<string>>
     */
    public const TAB_REQUIREMENTS = [
        'basics' => [ProductPublishChecklist::VESSEL, ProductPublishChecklist::TITLE_LOCALES],
        'when' => [ProductPublishChecklist::MEETING_POINT],
        'prices' => [ProductPublishChecklist::AGE_BANDS, ProductPublishChecklist::RATE_PLAN, ProductPublishChecklist::PRICES],
        'terms' => [ProductPublishChecklist::CANCELLATION_POLICY],
    ];

    /** «λείπει 2» on the tab whose requirements a saved trip does not meet yet; nothing otherwise. */
    public static function missingBadge(?Product $record, string $tab): ?string
    {
        if (! $record instanceof Product || ! $record->exists) {
            return null;
        }

        $missing = count(array_intersect(ProductPublishChecklist::unmet($record), self::TAB_REQUIREMENTS[$tab] ?? []));

        return $missing === 0 ? null : trans_choice('catalog.product.tabs.missing', $missing, ['count' => $missing]);
    }

    public static function basicsBadge(?Product $record): ?string
    {
        return static::missingBadge($record, 'basics');
    }

    /** A missing meeting point first, in amber; otherwise the timetable's count. */
    public static function whenBadge(?Product $record): ?string
    {
        return static::missingBadge($record, 'when') ?? static::scheduleBadge($record);
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
        $groups = [];
        $first = LocaleResolver::installed()[0] ?? 'el';

        foreach (LocaleResolver::installed() as $locale) {
            $label = __("catalog.product.trip_page.{$field}.label");

            $groups[] = Group::make([
                Repeater::make("{$field}.{$locale}")
                    ->label($locale === $first ? $label : $label . ' · ' . __("enums.locale.{$locale}.label"))
                    ->helperText(__("catalog.product.trip_page.{$field}.help"))
                    ->simple(
                        TextInput::make('value')
                            ->label(__('catalog.product.trip_page.line'))
                            ->maxLength(160),
                    )
                    ->reorderable()
                    ->addActionLabel(__('catalog.product.trip_page.add_line'))
                    ->defaultItems(0),
                // The form's own language switch shows one of these and hides
                // the other; see `TranslatableInput`.
            ])->extraAttributes(['class' => "ka-locale ka-locale--{$locale}"]);
        }

        return Group::make($groups)->columnSpanFull();
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
                    ->toggleable()
                    ->visibleFrom('md'),

                TextColumn::make('mode')
                    ->label(__('catalog.product.table.mode'))
                    ->badge()
                    ->formatStateUsing(static fn (BookingMode $state): string => $state->label())
                    ->visibleFrom('md'),

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
                    ->label(__('catalog.product.table.max_pax'))
                    ->visibleFrom('md'),

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
                    ->toggleable()
                    ->visibleFrom('md'),
            ])
            ->defaultSort('sort_order')
            /*
             * Drag to arrange (Mike, 2026-09-22).
             *
             * `reorderable()` adds the handle column and writes `sort_order`
             * on drop. Two things it needs to be honest about:
             *
             * - It only sorts what is on the screen, so it is offered on the
             *   default sort alone. Dragging a row while the list is sorted by
             *   title would write positions that the next visit does not show.
             * - The guest side reads the same column: the hosted catalogue and
             *   the API both order by `sort_order`, so this is the operator
             *   arranging their own shop window, not a panel preference.
             */
            ->reorderable('sort_order')
            ->reorderRecordsTriggerAction(
                static fn (TableAction $action): TableAction => $action->label(__('catalog.product.table.reorder')),
            )
            // Status is a row of tabs above the list (`ListProducts::getTabs`),
            // with the number of drafts on its tab, rather than a filter
            // hidden behind a button.
            ->filters([
                TrashedFilter::make(),
            ])
            ->actions(MoreActions::row(EditAction::make(), [
                /*
                 * **«Δείτε τη σελίδα»** (product owner, 2026-09-22).
                 *
                 * The trip page is what the operator is really editing, and
                 * until now the only way to it was to find the site, find the
                 * trip and click through. A draft has no page — it is not
                 * published, and a link to a 404 teaches an operator the
                 * feature is broken rather than that the trip is not on sale —
                 * so the action is absent rather than dead.
                 */
                TableAction::make('preview')
                    ->label(__('catalog.product.table.preview'))
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->color('gray')
                    ->url(static fn (Product $record): ?string => self::previewUrl($record), shouldOpenInNewTab: true)
                    ->visible(static fn (Product $record): bool => self::previewUrl($record) !== null),
                DeleteAction::make(),
                RestoreAction::make(),
            ]));
    }

    /**
     * The guest's own address for this trip, or null when there is not one yet.
     *
     * Null for a draft and for an archived trip: neither is served, and a
     * button that leads to a «δεν βρέθηκε» is worse than no button. The URL
     * comes from {@see HostedUrl}, which is where the operator's domain, their
     * slug and the locale are decided — building it here would be a second
     * opinion about an address that appears in every link they paste.
     */
    public static function previewUrl(Product $record): ?string
    {
        $tenant = Tenancy::check() ? Tenancy::current() : null;

        if (! $tenant instanceof Tenant || $record->status !== ProductStatus::Active) {
            return null;
        }

        return HostedUrl::product($tenant, $record);
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
    /**
     * The cancellation policy, with the «+» that makes one.
     *
     * Shared by the edit page's «Όροι» tab and the fourth step of the new-trip
     * guide (2026-09-22). One definition, because the «+» carries a form of its
     * own — name, free-cancellation window, refund ladder — and two copies of
     * that would drift the first time a tier is added to one of them.
     */
    public static function cancellationPolicySelect(): Select
    {
        return Select::make('cancellation_policy_id')
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
            });
    }

    /** The VAT rate, defaulted from the account's own answer. */
    public static function vatRateSelect(): Select
    {
        return Select::make('vat_rate_id')
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
            ->preload();
    }

    /**
     * The boat currently chosen in the form, as an id.
     *
     * Read from the form rather than the record, because the operator may have
     * just picked a different boat and not saved yet — validating «Μέγιστα
     * άτομα» against the boat that *was* selected would refuse or allow the
     * wrong number.
     */
    /** The chosen boat's licence, for the per-seat warning. */
    public static function vesselLicence(Get $get): ?VesselLicence
    {
        $id = static::vesselIdOf($get);

        return $id === null ? null : Vessel::query()->whereKey($id)->first()?->licence_type;
    }

    public static function vesselIdOf(Get $get): ?int
    {
        $id = $get('vessel_id');

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * «Μέγιστα άτομα», with the boat's certified ceiling spelled out.
     *
     * CAT-5 refuses a trip that oversells its boat, and
     * {@see MaxPaxWithinVesselCapacity} has enforced that since M1 — except no
     * form ever attached it, so until 2026-09-23 the refusal existed and never
     * fired. It fires now, and this line is the half that comes first: a
     * certificate number an operator is being judged against is one they should
     * be able to read without going to look it up.
     */
    public static function maxPaxHelp(Get $get): string
    {
        $vessel = ($id = static::vesselIdOf($get)) === null ? null : Vessel::query()->find($id);

        return $vessel instanceof Vessel
            ? __('catalog.product.form.max_pax.help_vessel', [
                'vessel' => (string) $vessel->name,
                'capacity' => $vessel->capacity_max,
            ])
            : __('catalog.product.form.max_pax.help');
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

    /** The form's `kind` value, as a string from the browser or an enum from a fill. */
    public static function bandIsByStatus(mixed $kind): bool
    {
        return $kind === AgeBandKind::Status || $kind === AgeBandKind::Status->value;
    }

    /**
     * «Ομάδες επιβατών»: one folded row per group, the same on the edit page
     * and in the new-trip wizard (2026-09-24, so the two cannot drift apart).
     */
    public static function bandsRepeater(): Repeater
    {
        return Repeater::make('age_bands')
            // The section already says «Ηλικιακές κατηγορίες»; the
            // same words again over the list were the second of
            // two identical headings (2026-09-24).
            ->hiddenLabel()
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

                // By age («Παιδί», 3 έως 11) or by status («ΑμεΑ»,
                // «Φοιτητής»), product owner 2026-09-24. A status
                // group has no ages, and may ask for proof instead.
                ToggleButtons::make('kind')
                    ->label(__('catalog.product.form.bands.kind.label'))
                    ->options(AgeBandKind::class)
                    ->default(AgeBandKind::Age->value)
                    ->inline()
                    ->grouped()
                    ->live(),

                TextInput::make('min_age')
                    ->label(__('catalog.product.form.bands.min_age.label'))
                    ->integer()
                    ->required()
                    ->default(0)
                    ->minValue(0)
                    ->maxValue(120)
                    ->visible(static fn (Get $get): bool => ! static::bandIsByStatus($get('kind'))),

                TextInput::make('max_age')
                    ->label(__('catalog.product.form.bands.max_age.label'))
                    ->helperText(__('catalog.product.form.bands.max_age.help'))
                    ->integer()
                    ->minValue(0)
                    ->maxValue(120)
                    ->visible(static fn (Get $get): bool => ! static::bandIsByStatus($get('kind'))),

                // No «ποσοστό της βασικής» any more (product owner,
                // 2026-09-17): every band's price is euros, typed in
                // the price table below. A new band is `fixed`; an
                // existing percentage band keeps its mode untouched
                // until the table is saved and writes it as euros.
                Hidden::make('pricing_mode')
                    ->default(AgeBandPricing::Fixed->value),

                Hidden::make('price_multiplier_bp'),

                // The four switches together in one box, so the
                // band reads as name and ages, then its settings.
                Group::make([
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

                    // What stands in for an age check: the crew see
                    // the card at boarding, told so on the boarding
                    // list. No card number is asked
                    // at checkout (Mike, 2026-09-24: it proves
                    // nothing online and is data we would keep).
                    Toggle::make('requires_proof')
                        ->label(__('catalog.product.form.bands.requires_proof.label'))
                        ->helperText(__('catalog.product.form.bands.requires_proof.help'))
                        ->visible(static fn (Get $get): bool => static::bandIsByStatus($get('kind'))),
                ])
                    ->columns(['default' => 1, 'md' => 2])
                    ->columnSpanFull()
                    ->extraAttributes(['class' => 'ka-toggle-box']),
            ])
            // Rule Δ of the form mockup (2026-09-24): each band is
            // one closed row that says what it is — «Παιδί · 3 έως
            // 11 ετών · πιάνει θέση» — and opens when needed.
            ->collapsible()
            ->collapsed()
            // Three or four rows: «Σύμπτυξη όλων / Ανάπτυξη όλων»
            // over them was one more line of controls for nothing.
            ->collapseAllAction(static fn (Action $action): Action => $action->hidden())
            ->expandAllAction(static fn (Action $action): Action => $action->hidden())
            ->itemLabel(static fn (array $state): ?string => static::bandSummary($state))
            // Wrapped, not cut: on a phone «Ενήλικας · 12 ετών και
            // π…» hid the very part that tells two rows apart.
            ->truncateItemLabel(false)
            // A new trip starts with the usual three, which the
            // operator edits, removes or adds to — e.g. ΑΜΕΑ, which
            // may share ages with «Ενήλικας» (2026-09-17).
            ->default(static::defaultAgeBands(...))
            ->columns(['default' => 1, 'md' => 2, 'xl' => 4])
            ->columnSpanFull();
    }

    /**
     * A closed band's row: «Παιδί · 3 έως 11 ετών · δεν πιάνει θέση».
     *
     * The name, its ages, then only what sets it apart — base, no seat, with an
     * adult, no document — so three closed rows can be told apart without
     * opening one (rule Δ, 2026-09-24). The same age phrases as the price
     * table's rows, so a band reads the same in both places.
     *
     * @param  array<string, mixed>  $state
     */
    public static function bandSummary(array $state): ?string
    {
        $label = $state['label'] ?? null;
        $name = is_array($label) ? trim((string) ($label[app()->getLocale()] ?? $label['el'] ?? '')) : '';

        if ($name === '') {
            return null;
        }

        $min = is_numeric($state['min_age'] ?? null) ? (int) $state['min_age'] : 0;
        $max = is_numeric($state['max_age'] ?? null) ? (int) $state['max_age'] : null;

        $parts = [$name];

        if (static::bandIsByStatus($state['kind'] ?? null)) {
            $parts[] = __('catalog.product.form.bands.summary.by_status');

            if ((bool) ($state['requires_proof'] ?? false)) {
                $parts[] = __('catalog.product.form.bands.summary.proof');
            }
        } else {
            $parts[] = $max === null
                ? __('pricing.price_table.ages_from', ['min' => $min])
                : __('pricing.price_table.ages_between', ['min' => $min, 'max' => $max]);
        }

        if ((bool) ($state['is_base'] ?? false)) {
            $parts[] = __('pricing.price_table.base');
        }

        if (! (bool) ($state['counts_toward_capacity'] ?? true)) {
            $parts[] = __('pricing.price_table.no_seat');
        }

        if ((bool) ($state['requires_adult'] ?? false)) {
            $parts[] = __('catalog.product.form.bands.summary.with_adult');
        }

        if ((bool) ($state['no_document'] ?? false)) {
            $parts[] = __('catalog.product.form.bands.summary.no_document');
        }

        return implode(' · ', $parts);
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
        // None. Every manager this trip has is embedded in the tab it belongs
        // to — price lists and extras in «Τιμές», questions in «Σελίδα». A
        // manager registered here renders *below* the form and its save
        // buttons, which put the price lists on the same page as, and out of
        // sight of, the grid that prices them (product owner, 2026-09-21).
        return [];
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
