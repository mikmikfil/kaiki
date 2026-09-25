<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\ProductResource\Pages;

use App\Domain\Catalog\Support\ProductPublishChecklist;
use App\Domain\Pricing\Support\PriceTable;
use App\Enums\BookingMode;
use App\Enums\ProductCategory;
use App\Enums\VesselLicence;
use App\Filament\App\Resources\ProductResource;
use App\Filament\App\Resources\ProductResource\RelationManagers\ExtrasRelationManager;
use App\Filament\App\Resources\RatePlanResource;
use App\Filament\Forms\DepartureTimes;
use App\Filament\Forms\MoneyInput;
use App\Filament\Forms\TranslatableInput;
use App\Rules\MaxPaxWithinVesselCapacity;
use App\Support\Tenancy;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Group;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Wizard;
use Filament\Forms\Components\Wizard\Step;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Pages\CreateRecord\Concerns\HasWizard;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;

/**
 * The five steps of «Νέα εκδρομή» (product owner, 2026-09-22, direction Α).
 *
 * Separate from {@see CreateProduct} because that page is about *writing* — the
 * order the Actions have to run in — and this is about *asking*. They change
 * for different reasons: a new field is a change here, a new Action is a change
 * there.
 *
 * ## One question per step, in the order the answers are needed
 *
 * 1. **Τα βασικά** — what it is and how it sells. The booking mode is here and
 *    not later because everything below it changes shape around it.
 * 2. **Πότε φεύγει** — the duration, the meeting point and the whole timetable:
 *    a row per rule, each with its own days, times and window.
 * 3. **Τιμές** — the age bands with a price beside each and, under each band,
 *    any period that costs something else; or the boat's price for a charter,
 *    per period the same way.
 * 4. **Σελίδα** — everything the guest reads: the texts, the photographs, the
 *    itinerary, «Τι θα ζήσετε», the SEO lines. All of it optional.
 * 5. **Δημοσίευση** — the terms a trip cannot be published without, and the
 *    choice between publishing now and keeping it as a draft.
 *
 * ## Why step 4 exists at all
 *
 * The first cut of this wizard left the texts and the photographs out on the
 * argument that none of them stops a trip from selling, and that every extra
 * field is a reason to abandon a form. The product owner overruled it the same
 * day: *«όταν πρωτοφτιάχνω μια εκδρομή πρέπει να μπουν μέσα όλα»*. A trip
 * published with no description and no photograph does not sell either, and
 * the tab that would fix it is one the operator has never seen.
 *
 * What is still not here: the extras and the passenger questions. Both are
 * their own screens with their own tables, and neither is part of describing a
 * trip.
 */
trait HasTripWizard
{
    use HasWizard;

    /** «Αποθήκευση ως πρόχειρο» is running: the fields a draft may leave empty stop being required. */
    public bool $savingDraft = false;

    /**
     * The wizard beside «Πριν τη δημοσίευση», as on the edit page (2026-09-24):
     * four fifths for the steps, a fifth for the checklist, which fills in as
     * the steps are answered. On a phone it drops under the form.
     */
    public function form(Form $form): Form
    {
        return parent::form($form)
            ->schema([
                Grid::make(['default' => 1, 'xl' => 5])
                    ->schema([
                        Group::make([
                            Wizard::make($this->getSteps())
                                ->startOnStep($this->getStartStep())
                                ->cancelAction($this->getCancelFormAction())
                                ->submitAction($this->getSubmitFormAction())
                                ->skippable($this->hasSkippableSteps())
                                ->extraAttributes(['class' => 'ka-wizard']),
                        ])->columnSpan(['default' => 1, 'xl' => 4]),

                        Group::make([
                            Section::make(__('catalog.product.sections.checklist'))
                                ->compact()
                                ->extraAttributes(['class' => 'ka-checklist-side'])
                                ->schema([
                                    Placeholder::make('wizard_checklist')
                                        ->hiddenLabel()
                                        ->content(static fn (Get $get): View => self::checklistView($get))
                                        ->columnSpanFull(),

                                    // At any step, not only the last: a trip
                                    // started today and finished tomorrow is
                                    // how most first trips get made.
                                    Actions::make([
                                        FormAction::make('saveDraft')
                                            ->label(__('catalog.product.status_actions.save_draft'))
                                            ->color('gray')
                                            ->action(static fn (mixed $livewire) => $livewire->saveDraft()),
                                    ])->fullWidth(),
                                ]),
                        ])->columnSpan(['default' => 1, 'xl' => 1]),
                    ]),
            ])
            ->columns(null);
    }

    /**
     * «Αποθήκευση ως πρόχειρο»: the whole form, saved as a draft, with the
     * meeting point and the number of people allowed to wait. The title, the
     * boat and the booking mode are still asked — a draft nobody can name or
     * find is not a draft.
     */
    public function saveDraft(): void
    {
        $this->savingDraft = true;
        $this->data['wizard_publish'] = 'draft';

        try {
            $this->create();
        } finally {
            $this->savingDraft = false;
        }
    }

    /**
     * The edit page's checklist, answered from what has been typed so far —
     * the same requirements, words and view, so the list a first trip fills in
     * is the one the edit page will show.
     */
    private static function checklistView(Get $get): View
    {
        $mode = BookingMode::tryFrom((string) $get('mode'));
        $title = (array) ($get('title') ?? []);
        $prices = (array) ($get('wizard_prices') ?? []);
        $bands = (array) ($get('age_bands') ?? []);
        $filled = static fn (mixed $value): bool => $value !== null && trim((string) $value) !== '';

        $columns = [PriceTable::NEW_DEFAULT, ...array_map(
            static fn (mixed $id): string => 's' . (int) $id,
            (array) ($get('wizard_seasons') ?? []),
        )];

        $cell = static fn (string $item, string $column): bool => $filled($prices[$item][$column] ?? null);

        $met = [
            ProductPublishChecklist::TITLE_LOCALES => collect((array) config('kaiki.i18n.required_locales', ['el', 'en']))
                ->every(static fn (string $locale): bool => $filled($title[$locale] ?? null)),
            ProductPublishChecklist::VESSEL => $filled($get('vessel_id')),
            ProductPublishChecklist::MEETING_POINT => $filled($get('meeting_point_id')),
            ProductPublishChecklist::AGE_BANDS => $mode !== BookingMode::PerSeat || $bands !== [],
            ProductPublishChecklist::RATE_PLAN => match ($mode) {
                BookingMode::PerSeat => $bands !== [] && collect(array_keys($bands))->every(static fn ($item): bool => $cell((string) $item, PriceTable::NEW_DEFAULT)),
                BookingMode::PerVessel => $filled($get('wizard_vessel_price')),
                default => true,
            },
            ProductPublishChecklist::PRICES => $mode !== BookingMode::PerSeat || ($bands !== [] && collect(array_keys($bands))
                ->every(static fn ($item): bool => collect($columns)->every(static fn (string $column): bool => $cell((string) $item, $column)))),
            ProductPublishChecklist::CANCELLATION_POLICY => $filled($get('cancellation_policy_id')),
        ];

        $items = [];
        $remaining = 0;

        foreach (ProductPublishChecklist::requirements() as $requirement) {
            $ok = $met[$requirement] ?? false;
            $remaining += $ok ? 0 : 1;
            $items[] = [
                'met' => $ok,
                'label' => __("catalog.product.checklist.{$requirement}.label"),
                'unmet' => __("catalog.product.checklist.{$requirement}.unmet"),
            ];
        }

        return view('filament.app.product-checklist', ['record' => null, 'live' => true, 'items' => $items, 'remaining' => $remaining]);
    }

    /**
     * The edit page's tabs, as steps (Mike, 2026-09-24, approved from
     * `docs/mockups/wizard-like-edit.html`: «όλο το styling … και όχι μόνο το
     * styling αλλά και η ουσία»). Same names, same sections, same icons, in
     * the same order — what an operator learns creating a trip is exactly what
     * they see editing it — then «Δημοσίευση».
     *
     * @return array<int, Step>
     */
    public function getSteps(): array
    {
        return [
            $this->basicsStep(),
            $this->whenStep(),
            $this->pricesStep(),
            $this->termsStep(),
            $this->pageStep(),
            $this->publishStep(),
        ];
    }

    private function basicsStep(): Step
    {
        return Step::make(__('catalog.product.tabs.basics'))
            ->description(__('catalog.product.wizard.basics.description'))
            ->icon('heroicon-o-identification')
            ->schema([
                Section::make(__('catalog.product.sections.basics'))
                    ->icon('heroicon-o-map')
                    ->schema([
                        TranslatableInput::text(
                            'title',
                            __('catalog.product.form.title.label'),
                            __('catalog.product.form.title.help'),
                            maxLength: 160,
                            configure: static fn (TextInput $input, string $locale): TextInput => $locale === 'el'
                                ? $input->live(onBlur: true)->afterStateUpdated(ProductResource::fillSlug(...))
                                : $input,
                        ),

                        // Made from the Greek title and not asked for. An operator who
                        // wants to change it can, on the trip's own «Βασικά» tab; on a
                        // first trip it is one more field with a rule attached.
                        Hidden::make('slug'),

                        Select::make('vessel_id')
                            ->label(__('catalog.product.form.vessel.label'))
                            ->helperText(__('catalog.product.form.vessel.help'))
                            ->options(ProductResource::vesselOptions(...))
                            ->searchable()
                            ->preload()
                            ->required(),

                        Select::make('category')
                            ->label(__('catalog.product.form.category.label'))
                            ->helperText(__('catalog.product.form.category.help'))
                            ->options(ProductCategory::options())
                            ->required(),

                        Radio::make('mode')
                            ->label(__('catalog.product.form.mode.label'))
                            ->helperText(__('catalog.product.form.mode.help'))
                            ->options(BookingMode::options())
                            ->descriptions(self::modeDescriptions())
                            ->default(BookingMode::PerSeat->value)
                            ->required()
                            ->live(),

                        // The same note the edit page gives (2026-09-24).
                        Placeholder::make('licence_warning')
                            ->hiddenLabel()
                            ->content(__('catalog.product.form.vessel.pleasure_per_seat'))
                            ->extraAttributes(['class' => 'ka-warning-note'])
                            ->columnSpanFull()
                            ->visible(static fn (Get $get): bool => $get('mode') === BookingMode::PerSeat->value
                                && ProductResource::vesselLicence($get) === VesselLicence::ProfessionalPleasure),
                    ])
                    ->columns(2),
            ]);
    }

    private function whenStep(): Step
    {
        return Step::make(__('catalog.product.tabs.when'))
            ->description(__('catalog.product.wizard.when.description'))
            ->icon('heroicon-o-clock')
            ->schema([
                Section::make(static fn (Get $get): string => $get('mode') === BookingMode::PerSeat->value
                    ? __('catalog.product.sections.schedule_per_seat')
                    : __('catalog.product.sections.schedule'))
                    ->icon('heroicon-o-clock')
                    ->schema([
                        TextInput::make('duration_minutes')
                            ->label(__('catalog.product.form.duration_minutes.label'))
                            ->helperText(__('catalog.product.form.duration_minutes.help'))
                            ->suffix(__('catalog.product.form.duration_minutes.suffix'))
                            ->integer()
                            ->minValue(15)
                            ->maxValue(20160)
                            ->default(180)
                            ->required(),

                        TextInput::make('check_in_offset_minutes')
                            ->label(__('catalog.product.form.check_in_offset_minutes.label'))
                            ->helperText(__('catalog.product.form.check_in_offset_minutes.help'))
                            ->suffix(__('catalog.product.form.check_in_offset_minutes.suffix'))
                            ->integer()
                            ->minValue(0)
                            ->maxValue(1440)
                            ->default(30),

                        Select::make('meeting_point_id')
                            ->label(__('catalog.product.form.meeting_point.label'))
                            ->helperText(__('catalog.product.form.meeting_point.help'))
                            ->options(ProductResource::portOptions(...))
                            ->searchable()
                            ->preload()
                            ->required(static fn (mixed $livewire): bool => ! ($livewire->savingDraft ?? false)),

                        // «Λιμάνι αποβίβασης», as on the edit page (2026-09-24).
                        Select::make('landing_port_id')
                            ->label(__('catalog.product.form.landing_port.label'))
                            ->helperText(__('catalog.product.form.landing_port.help'))
                            ->options(ProductResource::portOptions(...))
                            ->searchable()
                            ->preload(),

                        /*
                         * A charter's single start time is a column on the trip — and
                         * on a trip sold «κατόπιν προσφοράς» it is **optional**
                         * (product owner, 2026-09-22: *«μπορεί να την ζητήσει ο
                         * χρήστης»*).
                         *
                         * The column has always been nullable and the quoting side has
                         * always honoured a proposed time — `ProposedWindowBuilder`
                         * takes the guest's over the trip's. Only the form said
                         * otherwise, with a helper line («την ίδια ώρα ξεκινούν όλοι»)
                         * that is simply untrue of a trip whose whole point is that the
                         * guest asks for a time.
                         */
                        TimePicker::make('default_start_time')
                            ->label(__('catalog.product.form.default_start_time.label'))
                            ->helperText(static fn (Get $get): string => $get('mode') === BookingMode::Quote->value
                                ? __('catalog.product.form.default_start_time.quote_help')
                                : __('catalog.product.form.default_start_time.help'))
                            ->placeholder(static fn (Get $get): ?string => $get('mode') === BookingMode::Quote->value
                                ? __('catalog.product.form.default_start_time.optional')
                                : null)
                            ->seconds(false)
                            ->timezone('UTC')
                            ->visible(static fn (Get $get): bool => $get('mode') !== BookingMode::PerSeat->value),
                    ])
                    ->columns(2),

                Section::make(__('catalog.product.sections.capacity'))
                    ->icon('heroicon-o-user-group')
                    ->schema([
                        // The same CAT-5 ceiling the edit form enforces (Mike,
                        // 2026-09-23). It matters more here: the boat was chosen a step
                        // ago, its certificate is not on screen, and a trip made in the
                        // guide is the first one an operator ever publishes.
                        TextInput::make('max_pax')
                            ->label(__('catalog.product.form.max_pax.label'))
                            ->helperText(static fn (Get $get): string => ProductResource::maxPaxHelp($get))
                            ->integer()
                            ->minValue(1)
                            ->maxValue(2000)
                            ->required(static fn (mixed $livewire): bool => ! ($livewire->savingDraft ?? false))
                            ->rules(static fn (Get $get): array => [
                                new MaxPaxWithinVesselCapacity(ProductResource::vesselIdOf($get)),
                            ]),

                        TextInput::make('min_pax')
                            ->label(__('catalog.product.form.min_pax.label'))
                            ->helperText(__('catalog.product.form.min_pax.help'))
                            ->integer()
                            ->default(0)
                            ->minValue(0)
                            ->maxValue(65535)
                            ->visible(static fn (Get $get): bool => $get('mode') === BookingMode::PerSeat->value),

                        TextInput::make('min_booking_pax')
                            ->label(__('catalog.product.form.min_booking_pax.label'))
                            ->helperText(__('catalog.product.form.min_booking_pax.help'))
                            ->integer()
                            ->default(1)
                            ->minValue(1)
                            ->maxValue(65535),
                    ])
                    ->columns(['default' => 1, 'md' => 2, 'lg' => 3]),

                Section::make(__('availability.schedule_rule.on_product.title'))
                    ->icon('heroicon-o-calendar-days')
                    ->description(__('availability.schedule_rule.on_product.help'))
                    ->visible(static fn (Get $get): bool => $get('mode') === BookingMode::PerSeat->value)
                    ->schema([
                        /*
                         * **The timetable, all of it** (product owner, 2026-09-22:
                         * *«και δρομολόγια extra αν υπάρχουν με ημέρες ώρες κλπ»*).
                         *
                         * This was one row of checkboxes and one time — enough for a
                         * trip that leaves Tuesdays at nine and nothing else. A real
                         * summer is «καθημερινά 10:00 και 18:00, και Σαββατοκύριακα
                         * 12:00 μέχρι τέλη Σεπτεμβρίου», and an operator who cannot say
                         * that here leaves the guide with a timetable that is wrong.
                         *
                         * So: a row per rule, each with its own days, its own list of
                         * times and its own window — the same four questions the trip's
                         * «Δρομολόγια» tab asks, in the same order and with the same
                         * meanings. One row is there to begin with, because a per-seat
                         * trip with no schedule has no day to sell.
                         */
                        Repeater::make('wizard_schedules')
                            ->label(__('catalog.product.wizard.when.schedules'))
                            ->helperText(__('catalog.product.wizard.when.schedules_help'))
                            ->addActionLabel(__('catalog.product.wizard.when.add_schedule'))
                            /*
                             * «Δρομολόγιο 2 — Τετάρτη, Πέμπτη» (Mike, 23/9).
                             *
                             * Τρία κλειστά δρομολόγια που λένε όλα «Δρομολόγιο» δεν
                             * είναι λίστα, είναι τρεις ίδιες γραμμές: για να βρει
                             * κανείς εκείνο της Τετάρτης πρέπει να τα ανοίξει ένα ένα.
                             * Ο αριθμός λέει πού βρίσκεται, οι μέρες λένε ποιο είναι.
                             */
                            ->schema([
                                CheckboxList::make('days')
                                    ->label(__('catalog.product.wizard.when.days'))
                                    ->helperText(__('catalog.product.wizard.when.days_help'))
                                    ->options(self::weekdays())
                                    ->columns(4)
                                    ->columnSpanFull(),

                                // Several a day, the way the «Δρομολόγια» tab takes them
                                // (2026-09-17): one rule per time, sharing the days and
                                // the window, so 13:00 can be paused on its own later.
                                // Chips since 24/9 (#11), the same field as the tab.
                                DepartureTimes::make('times')
                                    ->label(__('catalog.product.wizard.when.times'))
                                    ->helperText(__('catalog.product.wizard.when.times_help'))
                                    ->daysField('days')
                                    ->columnSpanFull(),

                                DatePicker::make('valid_from')
                                    ->label(__('availability.schedule_rule.form.valid_from.label'))
                                    ->helperText(__('catalog.product.wizard.when.valid_from_help'))
                                    ->native(false)
                                    ->default(static fn (): string => Carbon::today(Tenancy::current()->timezone)->toDateString()),

                                DatePicker::make('valid_until')
                                    ->label(__('availability.schedule_rule.form.valid_until.label'))
                                    ->helperText(__('catalog.product.wizard.when.valid_until_help'))
                                    ->native(false)
                                    // Both bounds inclusive, so a one-day window is valid.
                                    ->afterOrEqual('valid_from')
                                    ->validationMessages([
                                        'after_or_equal' => __('availability.schedule_rule.validation.inverted_window'),
                                    ]),
                            ])
                            /*
                             * «Δρομολόγιο 2 — Τετάρτη, Πέμπτη · 11:00» (Mike, 23/9).
                             *
                             * Η ετικέτα υπήρχε ήδη με τις μέρες και τις ώρες, αλλά
                             * **χωρίς αριθμό** — και επέστρεφε null σε άδειο δρομολόγιο,
                             * οπότε το φρέσκο κουτί δεν είχε καθόλου κεφαλίδα και τα
                             * τρία μαζί διαβάζονταν σαν ένα. Ο αριθμός λέει πού
                             * βρίσκεσαι, οι μέρες λένε ποιο είναι.
                             */
                            ->itemLabel(static fn (array $state, Repeater $component, string $uuid): string => self::scheduleLabel(
                                $state,
                                self::positionOf($component, $uuid),
                            ))
                            ->defaultItems(1)
                            ->collapsible()
                            ->columns(2)
                            ->columnSpanFull()
                            ->visible(static fn (Get $get): bool => $get('mode') === BookingMode::PerSeat->value),
                    ]),
            ]);
    }

    private function pricesStep(): Step
    {
        $perSeat = static fn (Get $get): bool => $get('mode') === BookingMode::PerSeat->value;

        return Step::make(__('catalog.product.tabs.prices'))
            ->description(__('catalog.product.wizard.prices.description'))
            ->icon('heroicon-o-banknotes')
            ->schema([
                Section::make(__('catalog.product.sections.bands'))
                    ->icon('heroicon-o-users')
                    ->description(__('catalog.product.form.bands.help'))
                    ->visible($perSeat)
                    ->schema([
                        ProductResource::bandsRepeater()->live(),
                    ]),

                Section::make(__('pricing.periods.heading'))
                    ->icon('heroicon-o-calendar-days')
                    ->description(__('pricing.periods.intro'))
                    ->visible(static fn (Get $get): bool => $perSeat($get) && RatePlanResource::seasonOptions() !== [])
                    ->schema([
                        /*
                         * The pricing flow of the edit page (2026-09-24, Mike: «όταν
                         * φτιάχνω νέα εκδρομή δεν το έχεις κάνει έτσι όμως ε;»):
                         * groups above, then the periods ticked, then a price per group
                         * for «Όλο τον χρόνο» and each ticked period, then the deposit
                         * and deadlines once. Saved by the same SavePriceTable as the
                         * edit page, so the two screens cannot drift apart again.
                         *
                         * The rows are keyed by the group's repeater item, which does
                         * not change when the group is renamed.
                         */
                        CheckboxList::make('wizard_seasons')
                            ->options(RatePlanResource::seasonOptions(...))
                            ->hiddenLabel()
                            ->columns(['default' => 1, 'md' => 3])
                            ->live()
                            ->columnSpanFull()
                            // Cards, like the edit page's ticks (`.ka-period-cards`).
                            ->extraAttributes(['class' => 'ka-period-cards'])
                            ->visible(static fn (Get $get): bool => $get('mode') === BookingMode::PerSeat->value
                                && RatePlanResource::seasonOptions() !== []),
                    ]),

                Section::make(__('pricing.price_table.heading'))
                    ->icon('heroicon-o-currency-euro')
                    ->description(__('pricing.price_table.intro'))
                    ->visible($perSeat)
                    ->schema([
                        Grid::make(['default' => 1])
                            ->schema(static fn (Get $get): array => self::wizardPriceRows($get))
                            ->columnSpanFull()
                            ->visible(static fn (Get $get): bool => $get('mode') === BookingMode::PerSeat->value),
                    ]),

                Section::make(__('pricing.periods.terms.section'))
                    ->icon('heroicon-o-banknotes')
                    ->description(__('pricing.periods.terms.intro'))
                    ->visible($perSeat)
                    ->schema([
                        Group::make(ManagesPriceTable::termFields())
                            ->statePath('wizard_terms')
                            ->columns(['default' => 1, 'md' => 3])
                            ->columnSpanFull()
                            ->visible(static fn (Get $get): bool => $get('mode') === BookingMode::PerSeat->value),
                    ]),

                Section::make(__('catalog.product.wizard.prices.vessel_section'))
                    ->icon('heroicon-o-currency-euro')
                    ->visible(static fn (Get $get): bool => $get('mode') === BookingMode::PerVessel->value)
                    ->schema([
                        MoneyInput::make(
                            'wizard_vessel_price',
                            __('pricing.rate_plan.form.vessel_price_cents.label'),
                            __('pricing.rate_plan.form.vessel_price_cents.help'),
                        )->visible(static fn (Get $get): bool => $get('mode') === BookingMode::PerVessel->value),

                        /*
                         * **«Μέχρι N άτομα, +Y € ο καθένας παραπάνω»** — the charter
                         * shape some operators sell (2026-09-17), switched on per
                         * operator by the platform.
                         *
                         * The guide asked for the boat's price and stopped, so an
                         * operator who prices this way finished the guide with half a
                         * price list and no sign that the other half existed. Same two
                         * fields as the trip's own «Τιμές», same gate, same words.
                         */
                        TextInput::make('wizard_included_pax')
                            ->label(__('pricing.on_product.included_pax.label'))
                            ->helperText(__('pricing.on_product.included_pax.help'))
                            ->integer()
                            ->minValue(1)
                            ->maxValue(999)
                            ->visible(static fn (Get $get): bool => $get('mode') === BookingMode::PerVessel->value
                                && Tenancy::current()?->usesExtraPersonPricing() === true),

                        MoneyInput::make(
                            'wizard_extra_pax_price',
                            __('pricing.on_product.extra_pax_price.label'),
                            __('pricing.on_product.extra_pax_price.help'),
                        )->visible(static fn (Get $get): bool => $get('mode') === BookingMode::PerVessel->value
                            && Tenancy::current()?->usesExtraPersonPricing() === true),

                        // The same question for a charter, where there are no bands to
                        // hang it on: the boat's price, per period.
                        Repeater::make('wizard_vessel_season_prices')
                            ->label(__('catalog.product.wizard.prices.seasons'))
                            ->helperText(__('catalog.product.wizard.prices.seasons_help'))
                            ->addActionLabel(__('catalog.product.wizard.prices.add_season'))
                            ->schema([
                                Select::make('season_id')
                                    ->label(__('pricing.rate_plan.form.season.label'))
                                    ->options(RatePlanResource::seasonOptions(...))
                                    ->required()
                                    ->distinct(),

                                MoneyInput::make(
                                    'price',
                                    __('pricing.rate_plan.form.vessel_price_cents.label'),
                                    null,
                                ),
                            ])
                            ->columns(2)
                            ->defaultItems(0)
                            ->columnSpanFull()
                            ->extraFieldWrapperAttributes(['class' => 'ka-nest'])
                            ->visible(static fn (Get $get): bool => $get('mode') === BookingMode::PerVessel->value
                                && RatePlanResource::seasonOptions() !== []),
                    ])
                    ->columns(2),

                Placeholder::make('wizard_quote_note')
                    ->hiddenLabel()
                    ->content(__('catalog.product.wizard.prices.quote'))
                    ->columnSpanFull()
                    ->visible(static fn (Get $get): bool => $get('mode') === BookingMode::Quote->value),

                // «Πρόσθετα», as on the edit page's «Τιμές» (Mike, 2026-09-24:
                // «δεν βλέπω κάπου τις πρόσθετες υπηρεσίες»). The same fields
                // as the trip's own table, saved the same way on create.
                Section::make(__('catalog.extra.on_product.title'))
                    ->icon('heroicon-o-sparkles')
                    ->description(__('catalog.extra.on_product.help'))
                    ->schema([
                        Repeater::make('wizard_extras')
                            ->hiddenLabel()
                            ->addActionLabel(__('catalog.extra.on_product.add'))
                            ->schema(ExtrasRelationManager::fields())
                            ->itemLabel(static fn (array $state): ?string => self::bandName((array) ['label' => $state['name'] ?? null]) ?: null)
                            ->defaultItems(0)
                            ->collapsible()
                            ->columns(2),
                    ]),
            ]);
    }

    /**
     * «Όροι», as on the edit page: the cancellation policy, the VAT and what
     * the guest is asked at checkout — the policy select carries the «+» that
     * makes a first policy. Until 2026-09-24 these sat inside «Δημοσίευση».
     */
    private function termsStep(): Step
    {
        return Step::make(__('catalog.product.tabs.terms'))
            ->description(__('catalog.product.wizard.terms.description'))
            ->icon('heroicon-o-shield-check')
            ->schema(ProductResource::termsSections());
    }

    /**
     * What the guest reads — the whole of it (product owner, 2026-09-22: *«και
     * κείμενα τα πάντα»*, *«και οι φωτός»*).
     *
     * This step used to be the wizard's deliberate omission, on the argument
     * that none of it stops a trip from selling. The argument was wrong in the
     * way that matters: *«όταν πρωτοφτιάχνω μια εκδρομή πρέπει να μπουν μέσα
     * όλα»*. A trip published out of a guide with no description and no
     * photograph is a trip nobody books, and going back for it means finding a
     * tab the operator has never seen.
     *
     * **{@see ProductResource::pageSections()} verbatim**, not a shortened copy:
     * the summary, the badge, the description, the gallery, «Τι θα ζήσετε», the
     * itinerary and the SEO lines are the same fields on the same columns, so
     * there is one place to change them and nothing here can drift from the
     * tab. Every one is optional and the step can be walked past untouched.
     */
    private function pageStep(): Step
    {
        return Step::make(__('catalog.product.tabs.page'))
            ->description(__('catalog.product.wizard.page.description'))
            ->icon('heroicon-o-photo')
            ->schema([
                Placeholder::make('wizard_page_intro')
                    ->hiddenLabel()
                    ->content(__('catalog.product.wizard.page.intro'))
                    ->columnSpanFull(),

                ...ProductResource::pageSections(),
            ]);
    }

    private function publishStep(): Step
    {
        return Step::make(__('catalog.product.wizard.publish.label'))
            ->description(__('catalog.product.wizard.publish.description'))
            ->icon('heroicon-o-rocket-launch')
            ->schema([
                Radio::make('wizard_publish')
                    ->label(__('catalog.product.wizard.publish.question'))
                    ->options([
                        'publish' => __('catalog.product.wizard.publish.now'),
                        'draft' => __('catalog.product.wizard.publish.draft'),
                    ])
                    ->descriptions([
                        'publish' => __('catalog.product.wizard.publish.now_help'),
                        'draft' => __('catalog.product.wizard.publish.draft_help'),
                    ])
                    ->default('publish')
                    ->required()
                    ->columnSpanFull(),
            ]);
    }

    /**
     * What each booking mode means, under its own radio.
     *
     * @return array<string, string>
     */
    private static function modeDescriptions(): array
    {
        $descriptions = [];

        foreach (BookingMode::cases() as $mode) {
            $descriptions[$mode->value] = __("catalog.product.wizard.modes.{$mode->value}");
        }

        return $descriptions;
    }

    /**
     * «Δευτέρα, Τετάρτη · 09:00, 18:00» on a collapsed schedule row.
     *
     * Days and times both, because a second rule is almost always the same
     * trip at another hour — a label that named only the days would read the
     * same on every row.
     *
     * @param  array<string, mixed>  $state
     */
    private static function scheduleLabel(array $state, int $position): string
    {
        $days = self::daysInWords((array) ($state['days'] ?? []));

        $times = array_values(array_filter(array_map(
            // The picker holds «2026-09-22 09:00:00» in the browser and «09:00»
            // once saved; either way the label wants the clock.
            static fn (mixed $time): string => is_string($time) && preg_match('/([01]\d|2[0-3]):[0-5]\d/', $time, $match) === 1
                ? $match[0]
                : '',
            array_values((array) ($state['times'] ?? [])),
        )));

        $line = trim($days . ' · ' . implode(', ', $times), ' ·');

        // Always a header, even on the empty box a fresh «Κι άλλο δρομολόγιο»
        // opens: three unlabelled cards read as one.
        return $line === ''
            ? __('catalog.product.wizard.when.schedule_item_empty', ['n' => $position])
            : __('catalog.product.wizard.when.schedule_item', ['n' => $position, 'days' => $line]);
    }

    /**
     * Monday first, the way a week reads here — and the way the weekday mask
     * is numbered (`ScheduleRule`, Monday = bit 0).
     *
     * @return array<int, string>
     */
    private static function weekdays(): array
    {
        $days = [];

        foreach (range(1, 7) as $day) {
            $days[$day] = __("catalog.product.wizard.weekdays.{$day}");
        }

        return $days;
    }

    /**
     * Which row of the repeater this is, counting from one.
     *
     * Filament hands `itemLabel` the item's uuid and not its position, so the
     * position is looked up among the repeater's own child containers — the
     * same array the template is iterating when it asks for the label, and
     * already built by then. Not `getState()`: dehydrating a repeater while
     * rendering one of its own item headers is a loop.
     */
    private static function positionOf(Repeater $component, string $uuid): int
    {
        // Cast both sides to string before comparing. The keys are uuids in
        // the browser but plain integers when a test fills the form, and a
        // strict search for the string "0" in `[0, 1]` finds nothing at all —
        // which silently numbered every row 1.
        $keys = array_map(static fn (mixed $key): string => (string) $key, array_keys($component->getChildComponentContainers()));

        $position = array_search($uuid, $keys, true);

        return is_int($position) ? $position + 1 : 1;
    }

    /**
     * «Δευτέρα–Παρασκευή, Κυριακή» — the days a rule sails, short enough for a
     * collapsed header.
     *
     * Runs of three or more consecutive days are written as a range, which is
     * how an operator says it and what keeps a daily trip from spelling out all
     * seven. Two in a row stay listed, because «Δευτέρα–Τρίτη» is longer than
     * «Δευτέρα, Τρίτη» and reads as though something were left out.
     *
     * @param  list<mixed>|array<array-key, mixed>  $days
     */
    private static function daysInWords(array $days): string
    {
        $names = self::weekdays();

        $chosen = array_values(array_unique(array_filter(
            array_map(static fn (mixed $day): int => (int) $day, $days),
            static fn (int $day): bool => $day >= 1 && $day <= 7,
        )));

        sort($chosen);

        if ($chosen === []) {
            return '';
        }

        $parts = [];
        $run = [array_shift($chosen)];

        $flush = static function (array $run) use ($names, &$parts): void {
            $parts[] = count($run) >= 3
                ? $names[$run[0]] . '–' . $names[$run[count($run) - 1]]
                : implode(', ', array_map(static fn (int $day): string => $names[$day], $run));
        };

        foreach ($chosen as $day) {
            if ($day === $run[count($run) - 1] + 1) {
                $run[] = $day;

                continue;
            }

            $flush($run);
            $run = [$day];
        }

        $flush($run);

        return implode(', ', $parts);
    }

    /**
     * «Τιμές σε ευρώ» in the wizard: a line per group, a field per column —
     * «Όλο τον χρόνο» and every ticked period. `wizard_prices.{item}.{column}`,
     * with the columns keyed as the edit page's table keys them.
     *
     * @return list<Component>
     */
    private static function wizardPriceRows(Get $get): array
    {
        $bands = (array) ($get('age_bands') ?? []);
        $options = RatePlanResource::seasonOptions();
        $ticked = array_values(array_filter(
            array_map('intval', (array) ($get('wizard_seasons') ?? [])),
            static fn (int $id): bool => array_key_exists($id, $options),
        ));

        $columns = [PriceTable::NEW_DEFAULT => __('pricing.on_product.season.default')];

        foreach ($ticked as $id) {
            $columns['s' . $id] = (string) $options[$id];
        }

        $rows = [];

        // A line per group — its name, then a price per column — the way the
        // edit page's table reads (2026-09-24).
        foreach ($bands as $item => $band) {
            $fields = [
                Placeholder::make("wizard_prices_name_{$item}")
                    ->hiddenLabel()
                    ->content(self::bandName((array) $band))
                    ->extraAttributes(['class' => 'ka-price-row-name']),
            ];

            foreach ($columns as $key => $label) {
                $fields[] = MoneyInput::make("wizard_prices.{$item}.{$key}", $label, null);
            }

            $rows[] = Grid::make(['default' => 1, 'md' => min(5, count($columns) + 1)])
                ->schema($fields)
                ->extraAttributes(['class' => 'ka-price-row']);
        }

        return $rows;
    }

    /**
     * An age band's own name, for its collapsed header.
     *
     * `label` is translatable, so the state is a map of locales. The panel's
     * locale first, then anything that was typed — a band named only in English
     * on a Greek panel should still say its name rather than «Κατηγορία».
     *
     * @param  array<string, mixed>  $state
     */
    private static function bandName(array $state): string
    {
        $label = $state['label'] ?? null;

        $written = is_array($label)
            ? array_filter($label, static fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            : (is_string($label) && trim($label) !== '' ? [$label] : []);

        $name = $written[app()->getLocale()] ?? (reset($written) ?: null);

        return is_string($name) ? $name : __('catalog.product.sections.bands');
    }
}
