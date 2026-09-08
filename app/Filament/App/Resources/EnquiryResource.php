<?php

declare(strict_types=1);

namespace App\Filament\App\Resources;

use App\Enums\EnquiryStatus;
use App\Filament\App\Resources\EnquiryResource\Pages;
use App\Models\Enquiry;
use App\Models\User;
use App\Policies\EnquiryPolicy;
use App\Support\Tenancy;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The enquiry inbox, on `/app` (spec BKG-28 FIXED, BKG-29; TEN-8, SEC-3).
 *
 * ## The guest's words are read-only, and that is the point
 *
 * `docs/api.md` §4: free guest text *"is stored and returned exactly as written
 * and is never translated."* The message is a `Placeholder`, not a `Textarea` —
 * an editable field would let an operator amend what a stranger said, in a row
 * kept as the record of what they said.
 *
 * What the operator *does* own is the handling: a status, an assignee, and two
 * one-click actions for the two things they actually do.
 *
 * ## Spam is marked, never deleted
 *
 * §2.5: *"`status = spam` rows are excluded from counts and purged after 30
 * days."* There is no delete action here at all. An operator who suspects the
 * honeypot ate a real enquiry has thirty days to look, and nobody tuning the
 * filters is guessing from an empty table.
 *
 * ## Crew do not see this resource
 *
 * {@see EnquiryPolicy} requires `ManageBookings` to read as well
 * as to write. Crew have `ViewPaxList` so they know who is aboard today; a
 * person who has not booked anything is not on that list.
 */
class EnquiryResource extends Resource
{
    protected static ?string $model = Enquiry::class;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?int $navigationSort = 24;

    public static function getNavigationGroup(): ?string
    {
        return __('panel.groups.operations');
    }

    public static function getNavigationLabel(): string
    {
        return __('quotes.enquiry.nav');
    }

    public static function getModelLabel(): string
    {
        return __('quotes.enquiry.model.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('quotes.enquiry.model.plural');
    }

    /**
     * The open count, on the navigation item.
     *
     * `countable()` excludes spam (§2.5). A badge saying forty when
     * thirty-eight are for a Canadian pharmacy is a badge an operator learns to
     * ignore, which is worse than no badge at all.
     */
    public static function getNavigationBadge(): ?string
    {
        // Filament builds the navigation on the **login page** too, where no
        // tenant is resolved — and `BelongsToTenant` throws rather than scoping
        // to nobody (TEN-4). Without this, an unauthenticated visitor to
        // `/app/login` gets a 500 naming a model they have never heard of, and
        // the panel is unreachable for everyone including the person trying to
        // sign in and fix it.
        if (! Tenancy::check()) {
            return null;
        }

        $open = Enquiry::query()
            ->countable()
            ->whereIn('status', [EnquiryStatus::New->value, EnquiryStatus::InProgress->value])
            ->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function form(Form $form): Form
    {
        return $form->schema(static::formSchema());
    }

    /** @return array<int, Component> */
    public static function formSchema(): array
    {
        return [
            Section::make()
                ->schema([
                    // Read-only, deliberately. See the class docblock.
                    Placeholder::make('message')
                        ->label(__('quotes.enquiry.form.message.label'))
                        ->helperText(__('quotes.enquiry.form.message.help'))
                        ->content(static fn (Enquiry $record): string => $record->message)
                        ->columnSpanFull(),

                    Select::make('status')
                        ->label(__('quotes.enquiry.form.status.label'))
                        ->options(EnquiryStatus::options())
                        ->required(),

                    Select::make('assigned_user_id')
                        ->label(__('quotes.enquiry.form.assigned_user_id.label'))
                        ->options(static::userOptions(...))
                        ->searchable()
                        ->preload(),
                ])
                ->columns(2),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('quotes.enquiry.table.received'))
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('name')
                    ->label(__('quotes.enquiry.table.name'))
                    ->searchable(),

                TextColumn::make('email')
                    ->label(__('quotes.enquiry.table.email'))
                    ->searchable()
                    ->copyable(),

                TextColumn::make('phone')
                    ->label(__('quotes.enquiry.table.phone'))
                    ->toggleable(),

                TextColumn::make('product.name')
                    ->label(__('quotes.enquiry.table.product'))
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('preferred_date')
                    ->label(__('quotes.enquiry.table.preferred_date'))
                    ->date()
                    ->placeholder('—'),

                TextColumn::make('pax')
                    ->label(__('quotes.enquiry.table.pax'))
                    ->placeholder('—'),

                TextColumn::make('status')
                    ->label(__('quotes.enquiry.table.status'))
                    ->badge()
                    ->formatStateUsing(static fn (EnquiryStatus $state): string => $state->label()),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label(__('quotes.enquiry.table.status'))
                    ->options(EnquiryStatus::options()),
            ])
            ->actions([
                EditAction::make(),
                static::markAnsweredAction(),
                static::markSpamAction(),
            ]);
        // No delete action anywhere. See the class docblock: spam is a status,
        // and the GDPR purge is what removes rows.
    }

    /** The one write an operator does most often. */
    public static function markAnsweredAction(): Action
    {
        return Action::make('markAnswered')
            ->label(__('quotes.enquiry.actions.answered.label'))
            ->icon('heroicon-o-check-circle')
            ->visible(static fn (Enquiry $record): bool => $record->status->isOpen())
            ->action(static function (Enquiry $record): void {
                $record->forceFill([
                    'status' => EnquiryStatus::Answered,
                    'answered_at' => now(),
                ])->save();

                Notification::make()->success()->title(__('quotes.enquiry.actions.answered.done'))->send();
            });
    }

    /** A status, not a deletion (§2.5). */
    public static function markSpamAction(): Action
    {
        return Action::make('markSpam')
            ->label(__('quotes.enquiry.actions.spam.label'))
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(__('quotes.enquiry.actions.spam.help'))
            ->visible(static fn (Enquiry $record): bool => ! $record->status->isSpam())
            ->action(static function (Enquiry $record): void {
                $record->forceFill(['status' => EnquiryStatus::Spam])->save();

                Notification::make()->success()->title(__('quotes.enquiry.actions.spam.done'))->send();
            });
    }

    /** @return array<int, string> */
    public static function userOptions(): array
    {
        return User::query()
            ->get()
            ->mapWithKeys(static fn (User $user): array => [$user->getKey() => (string) $user->name])
            ->all();
    }

    /** @return Builder<Enquiry> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('product');
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEnquiries::route('/'),
            'edit' => Pages\EditEnquiry::route('/{record}/edit'),
        ];
    }
}
