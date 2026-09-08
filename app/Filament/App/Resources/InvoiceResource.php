<?php

declare(strict_types=1);

namespace App\Filament\App\Resources;

use App\Domain\Compliance\Actions\IssueInvoice;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Filament\App\Resources\InvoiceResource\Pages;
use App\Jobs\SubmitInvoiceToMyData;
use App\Models\Invoice;
use App\Support\Authorization\Capability;
use App\Support\Format\MoneyFormatter;
use App\Support\Tenancy;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * The documents an operator has issued, and the ones that did not go
 * (spec MYD-5, MYD-9, MYD-10, MYD-11).
 *
 * Thin by design (CNV-5): every mutation calls an Action. Nothing here knows how
 * a number is allocated, how a payload is built or when a retry is due.
 *
 * ## Read-only, and more strictly than the vouchers screen
 *
 * `VoucherResource` has no edit form because a voucher's terms were given to
 * somebody in writing. An invoice is stronger than that: it is a document in a
 * state tax register, `docs/data-model.md` §1.4 puts it among the rows no code
 * path removes, and `InvoicePolicy::delete()` returns false for **everyone,
 * including the owner**.
 *
 * So there is no form, no create page and no delete action. Two things can be
 * done: read one, and ask a failed one to try again.
 *
 * ## The environment is a column, because MYD-11 says an operator must see it
 *
 * *"…the current endpoint is displayed in the panel so an operator can see they
 * are in test mode."* An operator who believes they are filing documents and is
 * not would find out from an accountant months later, and the cost of that
 * discovery is measured in years of trading. It is a badge, and a warning line
 * sits above the table whenever anything on the page is a test document.
 *
 * ## The failure column is Greek, and it is the stored one
 *
 * `last_error_message_el` was written by the job at the moment of the failure
 * (see `AadeErrors::inGreek()`). Rendering it live from the code would show a
 * different sentence once the dictionary grows — the same document explained two
 * ways on two afternoons, which is exactly what an operator quotes to their
 * accountant and then has to un-quote.
 */
class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?int $navigationSort = 25;

    public static function getNavigationGroup(): ?string
    {
        return __('panel.groups.operations');
    }

    public static function getNavigationLabel(): string
    {
        return __('mydata.nav');
    }

    public static function getModelLabel(): string
    {
        return __('mydata.model.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('mydata.model.plural');
    }

    /**
     * Money, so `ViewFinancials` — the same gate as the dashboard's two figures.
     *
     * TEN-8 keeps pricing and financials from crew, and an invoice is the most
     * financial row in the product: totals, VAT, a counterparty ΑΦΜ.
     */
    public static function canAccess(): bool
    {
        return Auth::user()?->hasCapability(Capability::ViewFinancials) === true;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->label(__('mydata.table.reference'))
                    // Not a database column: «ΑΛΠ Α/2026/41» is assembled by the
                    // model, and a pending document has no number yet. Said in
                    // words rather than left blank, because an empty cell in a
                    // numbered series reads as data loss.
                    ->state(fn (Invoice $record): string => $record->hasNumber()
                        ? $record->reference()
                        : __('mydata.table.no_number'))
                    ->description(fn (Invoice $record): string => $record->type->label())
                    ->searchable(['number', 'mark']),

                TextColumn::make('booking.reference')
                    ->label(__('mydata.table.booking'))
                    ->searchable()
                    // `booking_id` is non-null by schema — `restrictOnDelete`
                    // makes sure a booking cannot leave an invoice behind — so
                    // the link is unconditional rather than defensively guarded
                    // against a state the database refuses to produce.
                    ->url(fn (Invoice $record): string => BookingResource::getUrl(
                        'view',
                        ['record' => $record->booking_id],
                    )),

                TextColumn::make('total_cents')
                    ->label(__('mydata.table.total'))
                    ->alignEnd()
                    ->formatStateUsing(fn (int $state, Invoice $record): string => MoneyFormatter::format(
                        // A credit note is money going the other way, and a
                        // column of unsigned numbers would make a series of
                        // sales and corrections add up to nonsense at a glance.
                        $record->type->isCredit() ? -$state : $state,
                        app()->getLocale(),
                        MoneyFormatter::currency(),
                    )),

                TextColumn::make('status')
                    ->label(__('mydata.table.status'))
                    ->badge()
                    ->color(fn (InvoiceStatus $state): string => $state->color())
                    ->formatStateUsing(fn (InvoiceStatus $state): string => $state->label())
                    // The Greek explanation, stored at the moment it happened.
                    ->description(fn (Invoice $record): ?string => $record->last_error_message_el),

                TextColumn::make('issued_at')
                    ->label(__('mydata.table.issued_at'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->placeholder('—'),

                TextColumn::make('environment')
                    ->label(__('mydata.table.environment'))
                    ->badge()
                    ->color(fn (string $state): string => $state === 'live' ? 'gray' : 'warning')
                    ->formatStateUsing(fn (string $state): string => __("mydata.environment.{$state}"))
                    // Hidden by default: on a correctly configured account every
                    // row says the same thing, and a column that never varies is
                    // a column that stops being read. The warning above the
                    // table is what carries the message when it matters.
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('mydata.table.status'))
                    ->options(fn (): array => collect(InvoiceStatus::cases())
                        ->mapWithKeys(fn (InvoiceStatus $case): array => [$case->value => $case->label()])
                        ->all()),

                SelectFilter::make('type')
                    ->label(__('mydata.table.type'))
                    ->options(fn (): array => collect(InvoiceType::cases())
                        ->mapWithKeys(fn (InvoiceType $case): array => [$case->value => $case->label()])
                        ->all()),
            ])
            ->actions([
                Action::make('retry')
                    ->label(__('mydata.actions.retry.label'))
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->requiresConfirmation()
                    // Only a document that gave up. A `pending` one is already
                    // coming back on its own, and a second job for it would take
                    // a second number.
                    ->visible(fn (Invoice $record): bool => $record->status === InvoiceStatus::Failed)
                    ->action(function (Invoice $record): void {
                        // Back to `pending` so the job will act on it — the
                        // sender ignores anything that is not, which is what
                        // stops a registered document being sent twice.
                        $record->forceFill([
                            'status' => InvoiceStatus::Pending,
                            'next_retry_at' => null,
                        ])->save();

                        SubmitInvoiceToMyData::dispatch((int) $record->tenant_id, (int) $record->getKey());

                        Notification::make()
                            ->success()
                            ->title(__('mydata.actions.retry.done'))
                            ->send();
                    }),
            ])
            // MYD-11's warning, between the page title and the rows. Only when
            // a test document actually exists — see `ListInvoices`.
            ->header(fn (): ?View => Pages\ListInvoices::hasTestDocuments()
                ? view('filament.app.mydata-environment-warning')
                : null)
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading(__('mydata.empty.heading'))
            ->emptyStateDescription(__('mydata.empty.description'));
    }

    /** @return Builder<Invoice> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('booking');
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInvoices::route('/'),
        ];
    }

    /**
     * The count of documents that gave up, on the navigation item.
     *
     * The same shape the failure feed uses. An invoice AADE refused is money the
     * operator's books do not yet reflect, and it is the one thing on this
     * screen that needs somebody to notice without opening it.
     *
     * `IssueInvoice` is imported for the docblock's sake — this class calls no
     * action itself, which is the point of CNV-5.
     *
     * @see IssueInvoice
     */
    public static function getNavigationBadge(): ?string
    {
        // The login page builds the navigation too, and has no tenant. Third
        // time in this panel — {@see EnquiryResource::getNavigationBadge()} and
        // {@see NotificationLogResource::getNavigationBadge()} both carry the
        // same guard and both carry a comment saying why, and this class was
        // written today without either. The consequence is not a missing badge:
        // `BelongsToTenant` throws rather than scoping to nobody (TEN-4), so
        // `/app/login` 500s and the panel is unreachable for everybody,
        // including the person trying to sign in and fix it.
        //
        // Asserted for every badge in the panel by `NavigationBadgeTest`, so
        // there is no fourth time.
        if (! Tenancy::check()) {
            return null;
        }

        $failed = static::getEloquentQuery()->where('status', InvoiceStatus::Failed)->count();

        return $failed === 0 ? null : (string) $failed;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }
}
