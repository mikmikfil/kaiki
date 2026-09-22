<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\ProductResource\Pages;

use App\Enums\BookingMode;
use App\Enums\ProductCategory;
use App\Filament\App\Resources\ProductResource;
use App\Filament\App\Resources\RatePlanResource;
use App\Filament\Forms\MoneyInput;
use App\Filament\Forms\TranslatableInput;
use App\Support\Tenancy;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Wizard\Step;
use Filament\Forms\Get;
use Filament\Resources\Pages\CreateRecord\Concerns\HasWizard;
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

    /** @return array<int, Step> */
    public function getSteps(): array
    {
        return [
            $this->basicsStep(),
            $this->whenStep(),
            $this->pricesStep(),
            $this->pageStep(),
            $this->publishStep(),
        ];
    }

    private function basicsStep(): Step
    {
        return Step::make(__('catalog.product.wizard.basics.label'))
            ->description(__('catalog.product.wizard.basics.description'))
            ->icon('heroicon-o-identification')
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
            ])
            ->columns(2);
    }

    private function whenStep(): Step
    {
        return Step::make(__('catalog.product.wizard.when.label'))
            ->description(__('catalog.product.wizard.when.description'))
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
                    ->required(),

                TextInput::make('max_pax')
                    ->label(__('catalog.product.form.max_pax.label'))
                    ->helperText(__('catalog.product.form.max_pax.help'))
                    ->integer()
                    ->minValue(1)
                    ->maxValue(2000)
                    ->required(),

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
                        Repeater::make('times')
                            ->label(__('catalog.product.wizard.when.times'))
                            ->helperText(__('catalog.product.wizard.when.times_help'))
                            ->addActionLabel(__('availability.schedule_rule.form.start_times.add'))
                            ->simple(
                                TimePicker::make('time')
                                    // A clock time on the quay, not an instant.
                                    // Without this the panel's tenant-timezone
                                    // conversion stores 19:00 as 16:00
                                    // (2026-09-17, `ClockTimePickerTest`).
                                    ->timezone('UTC')
                                    ->seconds(false)
                                    ->native(false)
                                    ->distinct(),
                            )
                            ->defaultItems(1)
                            ->reorderable(false)
                            // Full width so the two dates below sit side by
                            // side: the times grow downwards as they are added,
                            // and a column that grows beside a date field
                            // leaves «Ισχύει έως» stranded on its own row.
                            ->columnSpanFull()
                            // «Σκαμμένο»: see `.ka-nest` in `sea.blade.php`.
                            ->extraFieldWrapperAttributes(['class' => 'ka-nest']),

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
                    ->itemLabel(static fn (array $state): ?string => self::scheduleLabel($state))
                    ->defaultItems(1)
                    ->collapsible()
                    ->columns(2)
                    ->columnSpanFull()
                    ->visible(static fn (Get $get): bool => $get('mode') === BookingMode::PerSeat->value),

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
            ->columns(2);
    }

    private function pricesStep(): Step
    {
        return Step::make(__('catalog.product.wizard.prices.label'))
            ->description(__('catalog.product.wizard.prices.description'))
            ->icon('heroicon-o-banknotes')
            ->schema([
                Placeholder::make('wizard_prices_intro')
                    ->hiddenLabel()
                    ->content(__('catalog.product.wizard.prices.intro'))
                    ->columnSpanFull()
                    ->visible(static fn (Get $get): bool => $get('mode') === BookingMode::PerSeat->value),

                Repeater::make('age_bands')
                    ->label(__('catalog.product.sections.bands'))
                    ->addActionLabel(__('catalog.product.form.bands.add'))
                    ->schema([
                        Hidden::make('code'),
                        Hidden::make('pricing_mode'),
                        Hidden::make('price_multiplier_bp'),
                        Hidden::make('is_base'),
                        Hidden::make('counts_toward_capacity'),
                        Hidden::make('requires_adult'),
                        Hidden::make('no_document'),

                        TranslatableInput::text(
                            'label',
                            __('catalog.product.form.bands.label.label'),
                            __('catalog.product.form.bands.label.help'),
                            maxLength: 60,
                        ),

                        TextInput::make('min_age')
                            ->label(__('catalog.product.form.bands.min_age.label'))
                            ->integer()
                            ->minValue(0)
                            ->maxValue(120)
                            ->required(),

                        TextInput::make('max_age')
                            ->label(__('catalog.product.form.bands.max_age.label'))
                            ->helperText(__('catalog.product.form.bands.max_age.help'))
                            ->integer()
                            ->minValue(0)
                            ->maxValue(120),

                        MoneyInput::make(
                            'wizard_price',
                            __('catalog.product.wizard.prices.price'),
                            __('catalog.product.wizard.prices.price_help'),
                        ),

                        /*
                         * **A period's own price, beside the band it prices**
                         * (product owner, 2026-09-22: *«και τιμές περίοδοι
                         * κλπ»*).
                         *
                         * A price list is a period and a price for every band,
                         * which on a form is a grid — and a grid of fields whose
                         * columns are rows of another repeater is exactly the
                         * thing that breaks the moment a band is renamed. So the
                         * question is turned around and asked per band, which is
                         * also how an operator says it: *«ο ενήλικας 45, το
                         * καλοκαίρι 55»*. The rows are gathered back into one
                         * list per period on save.
                         *
                         * Hidden when the operator has no periods yet: a select
                         * with nothing in it is a question with no answer.
                         */
                        Repeater::make('wizard_season_prices')
                            ->label(__('catalog.product.wizard.prices.seasons'))
                            ->helperText(__('catalog.product.wizard.prices.seasons_help'))
                            ->addActionLabel(__('catalog.product.wizard.prices.add_season'))
                            ->schema([
                                Select::make('season_id')
                                    ->label(__('pricing.rate_plan.form.season.label'))
                                    ->options(RatePlanResource::seasonOptions(...))
                                    ->required()
                                    // One price per period per band; a second row
                                    // for the same period is two answers to one
                                    // question.
                                    ->distinct(),

                                MoneyInput::make(
                                    'price',
                                    __('catalog.product.wizard.prices.price'),
                                    null,
                                ),
                            ])
                            ->columns(2)
                            ->defaultItems(0)
                            ->columnSpanFull()
                            ->extraFieldWrapperAttributes(['class' => 'ka-nest'])
                            ->visible(static fn (): bool => RatePlanResource::seasonOptions() !== []),
                    ])
                    ->default(ProductResource::defaultAgeBands(...))
                    ->columns(2)
                    ->columnSpanFull()
                    ->visible(static fn (Get $get): bool => $get('mode') === BookingMode::PerSeat->value),

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

                Placeholder::make('wizard_quote_note')
                    ->hiddenLabel()
                    ->content(__('catalog.product.wizard.prices.quote'))
                    ->columnSpanFull()
                    ->visible(static fn (Get $get): bool => $get('mode') === BookingMode::Quote->value),
            ])
            ->columns(2);
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
        return Step::make(__('catalog.product.wizard.page.label'))
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
                // The same two the «Όροι» tab uses — the policy select carries
                // the «+» that makes a first policy, which is exactly what a
                // new operator needs here.
                ProductResource::cancellationPolicySelect(),

                ProductResource::vatRateSelect()
                    ->helperText(__('catalog.product.wizard.publish.vat_help')),

                /*
                 * **«Στοιχεία επιβατών»** (product owner, 2026-09-22, after
                 * reaching a real checkout: *«δεν υπάρχουν πεδία για τα
                 * έγγραφα»*).
                 *
                 * They exist and they work — checkout asks every passenger for
                 * a name, a nationality, a date of birth and a document — but
                 * only for a trip whose switch is on, and the switch lived on
                 * the «Όροι» tab alone. That is a tab nobody opens on a trip
                 * they are still writing, and the consequence is invisible
                 * until a guest is asked for nothing on the way to paying.
                 *
                 * So the guide asks it, beside the other terms, where an
                 * operator is already thinking about what travelling requires.
                 */
                ...ProductResource::guestDetailsFields(),

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
            ])
            ->columns(2);
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
    private static function scheduleLabel(array $state): ?string
    {
        $weekdays = self::weekdays();

        $days = array_map(
            static fn (mixed $day): string => $weekdays[(int) $day] ?? '',
            array_values((array) ($state['days'] ?? [])),
        );

        $times = array_values(array_filter(array_map(
            // The picker holds «2026-09-22 09:00:00» in the browser and «09:00»
            // once saved; either way the label wants the clock.
            static fn (mixed $time): string => is_string($time) && preg_match('/([01]\d|2[0-3]):[0-5]\d/', $time, $match) === 1
                ? $match[0]
                : '',
            array_values((array) ($state['times'] ?? [])),
        )));

        $line = trim(implode(', ', array_filter($days)) . ' · ' . implode(', ', $times), ' ·');

        return $line === '' ? null : $line;
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
}
