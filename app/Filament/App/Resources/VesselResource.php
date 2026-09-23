<?php

declare(strict_types=1);

namespace App\Filament\App\Resources;

use App\Domain\Media\Support\GalleryField;
use App\Enums\VesselAmenity;
use App\Enums\VesselStatus;
use App\Enums\VesselType;
use App\Filament\App\Navigation\SiblingScreens;
use App\Filament\App\Resources\VesselResource\Pages;
use App\Filament\Forms\TranslatableInput;
use App\Filament\Support\MoreActions;
use App\Models\Port;
use App\Models\Vessel;
use App\Rules\VesselCapacityNotLowered;
use App\Support\Tenancy;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ForceDeleteAction;
use Filament\Tables\Actions\RestoreAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Validation\Rules\Unique;

/**
 * The fleet on `/app` (spec CAT-1, CAT-2, SEC-3).
 *
 * Thin by design (CNV-5): the only rule here that is not a form affordance is
 * {@see VesselCapacityNotLowered}, and that delegates to the domain Action the
 * observer also calls. Nothing in this class decides anything the API and the
 * importer would decide differently.
 *
 * **No query here touches a JSON path.** `description` is translatable and
 * `name` is a plain Greek proper noun; both go through the companion columns
 * from #15, because MySQL and SQLite disagree about Greek and the local result
 * is the one a developer trusts.
 */
class VesselResource extends Resource
{
    protected static ?string $model = Vessel::class;

    /**
     * The uploader's own field, mapped to the `images` column it stores into
     * ({@see GalleryField}). Named apart from the column because the two are
     * different shapes: a list of paths on the form, `{path, alt}` in the row.
     */
    public const GALLERY_FIELD = 'gallery';

    protected static ?string $navigationIcon = 'heroicon-o-lifebuoy';

    protected static ?int $navigationSort = 10;

    /**
     * The `specs` keys this form renders (`docs/data-model.md` §3.9).
     *
     * Named here so the pages know which keys they own. §3.9 says the column is
     * "free-form but validated against a known key list" and that *"unknown keys
     * are preserved but not rendered"* — a form that simply wrote its own state
     * over the column would delete an importer's extra fields on the first edit,
     * and nothing would report it.
     *
     * @var list<string>
     */
    public const SPEC_KEYS = ['beam_m', 'year_built', 'engine', 'cruising_speed_kn', 'cabins', 'wc', 'amenities'];

    public static function getNavigationGroup(): ?string
    {
        return __('panel.groups.fleet');
    }

    /** Stays highlighted on the screens it shares a tab bar with (Menu 1). */
    public static function getNavigationItems(): array
    {
        return SiblingScreens::highlight(static::class, parent::getNavigationItems());
    }

    public static function getNavigationLabel(): string
    {
        return __('catalog.vessel.nav');
    }

    public static function getModelLabel(): string
    {
        return __('catalog.vessel.model.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('catalog.vessel.model.plural');
    }

    public static function form(Form $form): Form
    {
        return $form->schema(static::formSchema());
    }

    /** @return array<int, Component> */
    public static function formSchema(): array
    {
        return [
            Section::make(__('catalog.vessel.sections.identity'))
                ->schema([
                    TextInput::make('name')
                        ->label(__('catalog.vessel.form.name.label'))
                        ->helperText(__('catalog.vessel.form.name.help'))
                        ->required()
                        // Matches the column; longer would truncate silently.
                        ->maxLength(120)
                        // TEN-6: unique per tenant, enforced by the composite
                        // index. Checked here too so the operator gets a
                        // sentence instead of a constraint-violation page —
                        // and `ignoreRecord` so editing a boat without renaming
                        // it is not a collision with itself.
                        //
                        // Trashed rows are **included**, matching the index:
                        // `vessels_tenant_name_unique` cannot exclude them
                        // (see the migration), so a form that ignored them
                        // would accept a name the INSERT then rejects.
                        //
                        // Scoped to the tenant **by hand**, and this is not
                        // optional. Laravel's `unique` rule runs through the
                        // DatabasePresenceVerifier, which builds a raw query —
                        // so `BelongsToTenant`'s global scope does not apply
                        // and the check would silently span every operator on
                        // the platform. The index is `(tenant_id, name)`: two
                        // operators may both have a boat called Οδυσσέας, and
                        // without this the second is told the name is taken by
                        // a fleet they cannot see.
                        ->unique(
                            ignoreRecord: true,
                            modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('tenant_id', Tenancy::id()),
                        )
                        ->validationMessages([
                            'unique' => __('catalog.vessel.form.name.taken', ['name' => '']),
                        ]),

                    Select::make('type')
                        ->label(__('catalog.vessel.form.type.label'))
                        ->options(VesselType::options())
                        ->required()
                        ->native(false),

                    Select::make('status')
                        ->label(__('catalog.vessel.form.status.label'))
                        ->helperText(__('catalog.vessel.form.status.help'))
                        ->options(VesselStatus::options())
                        ->default(VesselStatus::Active->value)
                        ->required()
                        ->native(false),

                    TextInput::make('registration_number')
                        ->label(__('catalog.vessel.form.registration_number.label'))
                        ->helperText(__('catalog.vessel.form.registration_number.help'))
                        ->maxLength(40),

                    TranslatableInput::textarea(
                        'description',
                        __('catalog.vessel.form.description.label'),
                        __('catalog.vessel.form.description.help'),
                    ),
                ])
                ->columns(2),

            Section::make(__('catalog.vessel.sections.capacity'))
                ->schema([
                    TextInput::make('capacity_max')
                        ->label(__('catalog.vessel.form.capacity_max.label'))
                        ->helperText(__('catalog.vessel.form.capacity_max.help'))
                        ->integer()
                        ->required()
                        ->minValue(1)
                        ->maxValue(65535)
                        // The guard, stated where the operator can act on it.
                        // Only on edit: a vessel being created has promised
                        // nothing, and there is no record to compare against.
                        ->rules(fn (?Vessel $record): array => $record === null
                            ? []
                            : [new VesselCapacityNotLowered($record)]),

                    TextInput::make('crew_count')
                        ->label(__('catalog.vessel.form.crew_count.label'))
                        ->integer()
                        ->minValue(0)
                        ->maxValue(255)
                        ->default(1)
                        ->required(),

                    TextInput::make('captain_name')
                        ->label(__('catalog.vessel.form.captain_name.label'))
                        ->helperText(__('catalog.vessel.form.captain_name.help'))
                        ->maxLength(120),

                    // Metres in the form, centimetres in the column. The column
                    // is an integer because a length that renders as 13.499999
                    // on a spec sheet is as wrong as a float price.
                    TextInput::make('length_m')
                        ->label(__('catalog.vessel.form.length_cm.label'))
                        ->helperText(__('catalog.vessel.form.length_cm.help'))
                        ->numeric()
                        ->step('0.1')
                        ->minValue(0)
                        ->maxValue(655)
                        ->suffix('m'),
                ])
                ->columns(2),

            Section::make(__('catalog.vessel.sections.operations'))
                ->schema([
                    Select::make('home_port_id')
                        ->label(__('catalog.vessel.form.home_port.label'))
                        ->helperText(__('catalog.vessel.form.home_port.help'))
                        ->placeholder(__('catalog.vessel.form.home_port.none'))
                        // Ordered through the companion column, and rendered
                        // through the model so the I18N-5 fallback applies —
                        // a port with only a Greek name still has a label.
                        ->options(fn (): array => Port::query()
                            ->orderByTranslation('name')
                            ->get()
                            ->mapWithKeys(fn (Port $port): array => [$port->getKey() => $port->name])
                            ->all())
                        ->searchable()
                        ->preload()
                        ->native(false)
                        // A new operator has no ports yet, so without this the
                        // list is empty and the boat cannot get a home port
                        // from the setup wizard at all. Name, address and map
                        // link only; photo and directions stay on the port
                        // screen.
                        ->createOptionForm([
                            TranslatableInput::text(
                                'name',
                                __('catalog.port.form.name.label'),
                                __('catalog.port.form.name.help'),
                            ),
                            TextInput::make('address')
                                ->label(__('catalog.port.form.address.label'))
                                ->maxLength(255),
                            TextInput::make('maps_url')
                                ->label(__('catalog.port.form.maps_url.label'))
                                ->url()
                                ->maxLength(255),
                        ])
                        ->createOptionUsing(static fn (array $data): int => (int) Port::query()->create([
                            'name' => $data['name'] ?? [],
                            'address' => $data['address'] ?? null,
                            'maps_url' => $data['maps_url'] ?? null,
                            'is_active' => true,
                        ])->getKey()),

                    TextInput::make('turnaround_buffer_minutes')
                        ->label(__('catalog.vessel.form.turnaround_buffer_minutes.label'))
                        ->helperText(__('catalog.vessel.form.turnaround_buffer_minutes.help', [
                            'default' => static::tenantBuffer(),
                        ]))
                        ->integer()
                        ->minValue(0)
                        ->maxValue(65535)
                        // **Not** defaulted. Null means inherit (AVL-7), and
                        // pre-filling the tenant's 60 here would turn every new
                        // boat into an override — after which changing the
                        // account setting would stop changing anything.
                        ->placeholder((string) static::tenantBuffer())
                        ->suffix('min'),

                    TextInput::make('max_wind_bft')
                        ->label(__('catalog.vessel.form.max_wind_bft.label'))
                        ->helperText(__('catalog.vessel.form.max_wind_bft.help'))
                        ->integer()
                        ->minValue(0)
                        ->maxValue(12)
                        // **Not** defaulted, for a sharper reason than the
                        // buffer above: an operator who has not set a limit has
                        // not asked for a weather warning, and inventing a 6 for
                        // them would put an alert on their dashboard about a
                        // boat they know better than we do. Null is silence
                        // (ADR-0027).
                        ->suffix('Bft'),

                    // «Σειρά εμφάνισης» δεν πληκτρολογείται πια (Mike, 23/9,
                    // όπως και στις εκδρομές την προηγούμενη μέρα): η σειρά
                    // ορίζεται σέρνοντας τις γραμμές στη λίστα, όπου ο
                    // διοργανωτής βλέπει τι μετακινεί. Η στήλη μένει — απλώς
                    // τη γράφει το drop αντί για ένα πεδίο με νούμερα που
                    // έπρεπε να τα κρατάει κανείς στο μυαλό του.
                ])
                ->columns(2),

            Section::make(__('catalog.vessel.sections.specs'))
                ->schema([
                    // The §3.9 key list. Unknown keys already in the column are
                    // preserved by EditVessel rather than dropped, because §3.9
                    // says free-form data survives even when nothing renders it.
                    TextInput::make('specs.beam_m')
                        ->label(__('catalog.vessel.form.specs.beam_m.label'))
                        ->numeric()
                        ->step('0.1'),

                    TextInput::make('specs.year_built')
                        ->label(__('catalog.vessel.form.specs.year_built.label'))
                        ->integer()
                        ->minValue(1800)
                        ->maxValue((int) date('Y') + 1),

                    TextInput::make('specs.engine')
                        ->label(__('catalog.vessel.form.specs.engine.label'))
                        ->placeholder(__('catalog.vessel.form.specs.engine.placeholder'))
                        ->maxLength(120),

                    TextInput::make('specs.cruising_speed_kn')
                        ->label(__('catalog.vessel.form.specs.cruising_speed_kn.label'))
                        ->numeric()
                        ->minValue(0),

                    TextInput::make('specs.cabins')
                        ->label(__('catalog.vessel.form.specs.cabins.label'))
                        ->integer()
                        ->minValue(0),

                    TextInput::make('specs.wc')
                        ->label(__('catalog.vessel.form.specs.wc.label'))
                        ->integer()
                        ->minValue(0),

                    CheckboxList::make('specs.amenities')
                        ->label(__('catalog.vessel.form.specs.amenities.label'))
                        ->helperText(__('catalog.vessel.form.specs.amenities.help'))
                        // A fixed list with EL/EN labels, so the widget can map
                        // each value to an icon (§3.9). Free text here would
                        // make "WC", "wc" and "Τουαλέτα" three amenities.
                        ->options(VesselAmenity::options())
                        ->columns(2)
                        ->columnSpanFull(),
                ])
                ->columns(3)
                ->collapsed(),

            Section::make(__('catalog.vessel.sections.media'))
                ->schema([
                    /*
                     * **Ένας uploader, και η πρώτη είναι η κύρια** (Mike,
                     * 23/9) — ακριβώς ό,τι πήραν οι εκδρομές στις 22/9.
                     *
                     * Ήταν Repeater, μία φωτογραφία ανά γραμμή, με το alt
                     * δίπλα της. Το σκεπτικό ήταν σωστό — το alt ανήκει στην
                     * εικόνα — αλλά «πρόσθεσε γραμμή, διάλεξε αρχείο» δώδεκα
                     * φορές δεν είναι τρόπος να ανεβάσει κανείς gallery. Και
                     * τώρα η σειρά μετράει διπλά: η καρτέλα «Το σκάφος» είναι
                     * λωρίδα φωτογραφιών, και η πρώτη την ανοίγει.
                     *
                     * Η στήλη κρατά το σχήμα `{path, alt}` της §3.15· το alt
                     * μιας φωτογραφίας που μένει επιβιώνει ενός νέου ανεβάσματος
                     * ({@see GalleryField}). Αυτό που χάνεται είναι η συγγραφή
                     * alt από εδώ — συνειδητά, όπως και στις εκδρομές: το
                     * ανέβασμα είναι η συχνή πράξη, η περιγραφή η σπάνια, και
                     * ένα gallery που δεν ανεβαίνει δεν έχει τι να περιγράψει.
                     */
                    FileUpload::make(self::GALLERY_FIELD)
                        ->label(__('catalog.vessel.form.images.label'))
                        ->helperText(__('catalog.vessel.form.images.help'))
                        ->image()
                        ->multiple()
                        ->reorderable()
                        // Τα νέα αρχεία πάνε στο τέλος, ώστε ένα επιπλέον
                        // ανέβασμα να μη μετακινεί ποτέ την πρώτη.
                        ->appendFiles()
                        ->panelLayout('grid')
                        ->imagePreviewHeight('120')
                        // The disk `GET /api/v1/products` builds URLs from (#36).
                        // Filament's default follows `FILESYSTEM_DISK`, which is
                        // `local` — a disk that cannot produce a URL at all, so the
                        // API returned nothing for every image the panel uploaded.
                        ->disk((string) config('kaiki.catalog.uploads.disk'))
                        ->directory('vessels')
                        ->maxSize(5120)
                        ->maxFiles(40)
                        ->columnSpanFull(),
                ])
                ->collapsed(),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('catalog.vessel.table.name'))
                    // Both through companion columns. `name` is a plain column,
                    // but a raw `order by name` sorts Greek differently on the
                    // two engines and a raw `like` matches differently — which
                    // is the same defect ADR-0008 exists to prevent, and it does
                    // not care that this column is not JSON.
                    ->sortable(query: self::sortByName(...))
                    ->searchable(query: self::searchByName(...)),

                TextColumn::make('type')
                    ->label(__('catalog.vessel.table.type'))
                    ->badge()
                    ->formatStateUsing(fn (VesselType $state): string => $state->label())
                    ->sortable(),

                TextColumn::make('capacity_max')
                    ->label(__('catalog.vessel.table.capacity_max'))
                    ->numeric()
                    ->sortable(),

                TextColumn::make('homePort.name')
                    ->label(__('catalog.vessel.table.home_port'))
                    ->placeholder(__('catalog.vessel.form.home_port.none'))
                    ->toggleable(),

                TextColumn::make('turnaround')
                    ->label(__('catalog.vessel.table.turnaround'))
                    // Shows *which* number is in force and where it came from.
                    // A column reading "60" for both an inherited and an
                    // overridden boat is the display that makes AVL-7's
                    // inheritance invisible.
                    ->state(fn (Vessel $record): string => $record->inheritsTurnaroundBuffer()
                        ? __('catalog.vessel.table.turnaround_inherited', ['minutes' => $record->effectiveTurnaroundBufferMinutes()])
                        : __('catalog.vessel.table.turnaround_own', ['minutes' => $record->effectiveTurnaroundBufferMinutes()]))
                    ->color(fn (Vessel $record): ?string => $record->inheritsTurnaroundBuffer() ? 'gray' : null)
                    ->toggleable(),

                TextColumn::make('status')
                    ->label(__('catalog.vessel.table.status'))
                    ->badge()
                    ->formatStateUsing(fn (VesselStatus $state): string => $state->label())
                    ->color(fn (VesselStatus $state): string => match ($state) {
                        VesselStatus::Active => 'success',
                        VesselStatus::Maintenance => 'warning',
                        VesselStatus::Inactive => 'gray',
                    })
                    ->sortable(),
            ])
            ->defaultSort('sort_order')
            /*
             * Σέρνοντας, όχι πληκτρολογώντας (Mike, 23/9) — το ίδιο που έγινε
             * στις εκδρομές στις 22/9, και για τον ίδιο λόγο.
             *
             * Το `reorderable()` βάζει τη λαβή και γράφει το `sort_order` στο
             * drop. Δύο πράγματα που πρέπει να ειπωθούν:
             *
             * - Ταξινομεί μόνο ό,τι είναι στην οθόνη, οπότε προσφέρεται πάνω
             *   στην προεπιλεγμένη ταξινόμηση. Σύρσιμο μιας γραμμής ενώ η λίστα
             *   είναι ταξινομημένη κατ' όνομα θα έγραφε θέσεις που η επόμενη
             *   επίσκεψη δεν δείχνει.
             * - Το ίδιο `sort_order` διαβάζεται και στην πλευρά του επισκέπτη,
             *   άρα ο διοργανωτής τακτοποιεί τη δική του βιτρίνα, δεν ρυθμίζει
             *   το panel του.
             */
            ->reorderable('sort_order')
            ->filters([
                SelectFilter::make('status')
                    ->label(__('catalog.vessel.table.status'))
                    ->options(VesselStatus::options()),
                SelectFilter::make('type')
                    ->label(__('catalog.vessel.table.type'))
                    ->options(VesselType::options()),
                TrashedFilter::make(),
            ])
            ->actions(MoreActions::row(EditAction::make(), [
                DeleteAction::make(),
                RestoreAction::make(),
                ForceDeleteAction::make(),
            ]))
            ->searchPlaceholder(__('catalog.shared.search_placeholder'));
    }

    /**
     * Ordering, through the folded companion column.
     *
     * A named method rather than an inline closure so the `Builder` carries its
     * model — `orderByFolded()` is a scope on {@see Vessel}, and a bare
     * `Builder` has no idea it exists.
     *
     * @param  Builder<Vessel>  $query
     * @return Builder<Vessel>
     */
    public static function sortByName(Builder $query, string $direction): Builder
    {
        return $query->orderByFolded('name', $direction);
    }

    /**
     * Searching, over the folded `name` and the translated `description` alike.
     *
     * @param  Builder<Vessel>  $query
     * @return Builder<Vessel>
     */
    public static function searchByName(Builder $query, string $search): Builder
    {
        return $query->whereTranslationMatches($search);
    }

    /** @return Builder<Vessel> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVessels::route('/'),
            'create' => Pages\CreateVessel::route('/create'),
            'edit' => Pages\EditVessel::route('/{record}/edit'),
        ];
    }

    /** The account-wide turnaround an unset vessel inherits (AVL-7). */
    public static function tenantBuffer(): int
    {
        return Tenancy::current()->turnaround_buffer_minutes ?? Vessel::defaultTurnaroundBufferMinutes();
    }
}
