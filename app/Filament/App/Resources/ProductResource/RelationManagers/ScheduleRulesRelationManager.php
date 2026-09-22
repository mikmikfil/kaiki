<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\ProductResource\RelationManagers;

use App\Domain\Availability\Support\WeekdayMask;
use App\Domain\Catalog\Actions\SaveScheduleRule;
use App\Enums\BookingMode;
use App\Filament\App\Resources\ScheduleRuleResource;
use App\Models\Product;
use App\Models\ScheduleRule;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * When the trip runs, on the trip (product owner, 2026-09-17).
 *
 * «Δρομολόγια» used to be its own screen in the menu, where every rule began by
 * choosing the trip from a dropdown. The operator thinks "my Blue Lagoon trip
 * leaves Tuesdays at nine" — one thought, so one place: the trip's own
 * «Δρομολόγια» tab. `product_id` is not a field here, so it cannot be wrong.
 *
 * **Nothing about the rules moved.** The form is `ScheduleRuleResource`'s own,
 * with the trip select swapped for the owner record, and {@see SaveScheduleRule}
 * is still the only writer. The old screen stays reachable at its address for
 * bookmarks and the manual; it simply left the menu.
 *
 * ## Several times a day
 *
 * A trip can leave at 09:00, 13:00 and 17:00. A new schedule therefore takes a
 * list of times and becomes one rule per time, sharing days and dates (product
 * owner, 2026-09-17). Editing stays one rule, so 13:00 can be moved or paused on
 * its own, and the rule table and the generator are untouched.
 *
 * Shown for trips sold per seat only — a whole-boat charter has no timetable,
 * and a tab that could only ever say "not for this trip" is a tab to hide.
 */
class ScheduleRulesRelationManager extends RelationManager
{
    protected static string $relationship = 'scheduleRules';

    protected static ?string $icon = 'heroicon-o-clock';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('availability.schedule_rule.on_product.title');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Product
            && $ownerRecord->mode === BookingMode::PerSeat
            && parent::canViewForRecord($ownerRecord, $pageClass);
    }

    /**
     * The old screen's form, reordered for someone who has never set one up:
     * days and time first — the question actually being answered — then the
     * dates, then the rarely-touched boat, seats and switch, then the preview.
     * The trip select goes (it is the trip), and so does «Ορίζοντας», a
     * technical setting that keeps its default of 180 days here.
     */
    public function form(Form $form): Form
    {
        [$what, $when, $window, $capacity, $preview] = ScheduleRuleResource::formSchema();

        $window->schema(array_values(array_filter(
            $window->getChildComponents(),
            static fn ($component): bool => ! ($component instanceof Field && $component->getName() === 'generate_days_ahead'),
        )));

        $when->schema([
            ...array_map(
                static fn (Component $component): Component => $component instanceof Field && $component->getName() === 'start_time'
                    ? $component->visibleOn('edit')
                    : $component,
                $when->getChildComponents(),
            ),
            Repeater::make('start_times')
                ->label(__('availability.schedule_rule.form.start_times.label'))
                ->helperText(__('availability.schedule_rule.form.start_times.help'))
                ->simple(
                    TimePicker::make('time')
                        // Tenant-local, like `start_time`; see the resource.
                        ->timezone('UTC')
                        ->seconds(false)
                        ->native(false)
                        ->required()
                        ->distinct(),
                )
                ->addActionLabel(__('availability.schedule_rule.form.start_times.add'))
                ->defaultItems(1)
                ->minItems(1)
                ->reorderable(false)
                // «Σκαμμένο»: see `.ka-nest` in `sea.blade.php`.
                ->extraFieldWrapperAttributes(['class' => 'ka-nest'])
                ->visibleOn('create'),
        ]);

        $other = Section::make(__('availability.schedule_rule.on_product.other'))
            ->schema([
                ...array_values(array_filter(
                    $what->getChildComponents(),
                    static fn ($component): bool => ! ($component instanceof Select && $component->getName() === 'product_id'),
                )),
                ...$capacity->getChildComponents(),
            ])
            ->columns(2)
            ->collapsible()
            ->collapsed();

        return $form->schema([
            Hidden::make('product_id'),
            Hidden::make('generate_days_ahead')->default(180),
            $when,
            $window,
            $other,
            $preview,
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('availability.schedule_rule.on_product.title'))
            ->description(__('availability.schedule_rule.on_product.help'))
            ->columns([
                TextColumn::make('weekday_mask')
                    ->label(__('availability.schedule_rule.table.days'))
                    ->formatStateUsing(static fn (int $state): string => ScheduleRuleResource::daysLabel($state))
                    ->wrap(),

                TextColumn::make('start_time')
                    ->label(__('availability.schedule_rule.table.start_time'))
                    ->formatStateUsing(static fn (string $state): string => substr($state, 0, 5)),

                TextColumn::make('valid_from')
                    ->label(__('availability.schedule_rule.table.window'))
                    ->formatStateUsing(static fn (ScheduleRule $record): string => $record->valid_from->isoFormat('D MMM YYYY')
                        . ' – '
                        . ($record->valid_until?->isoFormat('D MMM YYYY') ?? __('availability.schedule_rule.table.open_ended'))),

                TextColumn::make('capacity_override')
                    ->label(__('availability.schedule_rule.table.capacity'))
                    ->formatStateUsing(static fn (ScheduleRule $record): string => (string) $record->effectiveCapacity()),

                IconColumn::make('is_active')
                    ->label(__('availability.schedule_rule.table.is_active'))
                    ->boolean(),
            ])
            ->defaultSort('start_time')
            ->emptyStateHeading(__('availability.schedule_rule.on_product.empty'))
            ->emptyStateDescription(__('availability.schedule_rule.on_product.empty_help'))
            ->headerActions([
                CreateAction::make()
                    ->label(__('availability.schedule_rule.on_product.add'))
                    ->modalHeading(__('availability.schedule_rule.on_product.add'))
                    ->using(fn (array $data): ScheduleRule => $this->createForEachTime($data)),
            ])
            ->actions([
                EditAction::make()
                    ->mutateRecordDataUsing(static function (array $data, ScheduleRule $record): array {
                        $data['weekdays'] = WeekdayMask::toDays($record->weekday_mask);

                        return $data;
                    })
                    ->using(fn (ScheduleRule $record, array $data): ScheduleRule => $this->save($record, $data)),
                DeleteAction::make(),
            ]);
    }

    /**
     * One rule per chosen time, all or none: a refusal on the third time must
     * not leave the first two behind.
     *
     * @param  array<string, mixed>  $data
     */
    private function createForEachTime(array $data): ScheduleRule
    {
        $times = array_values(array_filter(
            array_map(static fn (mixed $time): string => is_scalar($time) ? (string) $time : '', (array) ($data['start_times'] ?? [])),
            static fn (string $time): bool => $time !== '',
        ));
        unset($data['start_times']);

        if ($times === []) {
            throw ValidationException::withMessages([
                'mountedTableActionsData.0.start_times' => __('availability.schedule_rule.form.start_times.help'),
            ]);
        }

        return DB::transaction(function () use ($times, $data): ScheduleRule {
            $rules = array_map(
                fn (string $time): ScheduleRule => $this->save(new ScheduleRule, [...$data, 'start_time' => $time], errorKey: 'start_times'),
                $times,
            );

            return $rules[0];
        });
    }

    /**
     * The same translation `ConsumesWeekdays` does for the old screen: seven
     * checkboxes to the bitmask, blanks to nulls, and the Action's refusal
     * re-keyed onto the field the operator can see.
     *
     * @param  array<string, mixed>  $data
     */
    private function save(ScheduleRule $record, array $data, string $errorKey = 'start_time'): ScheduleRule
    {
        /** @var Product $product */
        $product = $this->getOwnerRecord();

        /** @var array<int, int|string> $days */
        $days = (array) ($data['weekdays'] ?? []);
        unset($data['weekdays']);

        $data['weekday_mask'] = WeekdayMask::fromDays($days);
        $data['product_id'] = $product->getKey();

        foreach (['vessel_id', 'valid_until', 'capacity_override'] as $key) {
            if (($data[$key] ?? null) === '') {
                $data[$key] = null;
            }
        }

        try {
            return app(SaveScheduleRule::class)($record, $product, $data);
        } catch (ValidationException $exception) {
            $messages = [];

            foreach ($exception->errors() as $key => $bag) {
                $field = match ($key) {
                    'weekday_mask' => 'weekdays',
                    'start_time' => $errorKey,
                    default => $key,
                };

                $messages['mountedTableActionsData.0.' . $field] = $bag;
            }

            throw ValidationException::withMessages($messages);
        }
    }
}
