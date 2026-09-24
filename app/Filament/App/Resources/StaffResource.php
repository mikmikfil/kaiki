<?php

declare(strict_types=1);

namespace App\Filament\App\Resources;

use App\Domain\Tenancy\Actions\InviteStaffMember;
use App\Enums\CrewSpecialty;
use App\Enums\Role;
use App\Exceptions\LastOwnerException;
use App\Filament\App\Pages\Settings;
use App\Filament\App\Resources\StaffResource\Pages;
use App\Filament\Support\MoreActions;
use App\Models\User;
use App\Support\Authorization\Capability;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The team, and how somebody joins it (TEN-8, `Capability::ManageStaff`).
 *
 * ## Why this class is arriving in M5 and not M0
 *
 * `UserPolicy`, `RoleAssignmentPolicy`, `ManageStaff` and the Greek strings were
 * all written when the roles were. The screen was not — so from M0 until now an
 * owner had no way to hand a colleague a login, and every role the spec
 * describes was reachable only by editing the database. Nothing failed, no test
 * went red, and the gap was invisible because a missing screen looks exactly
 * like a screen you have not needed yet.
 *
 * It surfaced while writing the manual's chapter on the three roles: a chapter
 * that explains what each role may do has to start by explaining how somebody
 * gets one.
 *
 * ## `User` is not tenant-owned, and that is why the query is explicit
 *
 * A person can hold roles at two operators — the same skipper working for two
 * companies is an ordinary arrangement here — so `User` deliberately has no
 * `BelongsToTenant` (data-model §2.1). The global scope that protects every
 * other resource in this panel therefore does not protect this one, and the
 * `tenant_id` filter below is not belt-and-braces: without it, this screen lists
 * every user of the platform.
 *
 * ## Nobody sets anybody else's password
 *
 * Inviting creates the account and mails a link the colleague uses to choose
 * their own. There is no password field on this screen at all, for the owner or
 * for anyone: a password typed by one person and read to another over the
 * telephone is a credential in a chat window, a notebook, and the memory of
 * somebody who will not always work here.
 */
class StaffResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    /** Reached from the «Ρυθμίσεις» hub ({@see Settings}), not the sidebar. */
    protected static bool $shouldRegisterNavigation = false;

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): ?string
    {
        return __('panel.groups.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('staff.nav');
    }

    public static function getModelLabel(): string
    {
        return __('staff.model.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('staff.model.plural');
    }

    /**
     * Only somebody who may manage staff sees the screen at all.
     *
     * `UserPolicy::viewAny()` is deliberately open — knowing who your colleagues
     * are is not privileged — but this screen is not a staff directory. It is
     * where roles are granted, and a manager who can see the granting controls
     * without being able to use them is a manager filing a support ticket about
     * a permission error.
     */
    public static function canAccess(): bool
    {
        return Auth::user()?->hasCapability(Capability::ManageStaff) === true;
    }

    public static function form(Form $form): Form
    {
        return $form->schema(static::formSchema());
    }

    /** @return array<int, Component> */
    public static function formSchema(bool $inviting = true): array
    {
        return [
            TextInput::make('name')
                ->label(__('staff.form.name.label'))
                ->required()
                ->maxLength(120),

            // How the platform addresses the person (2026-09-17). Also on «Το
            // προφίλ μου»; here so an owner setting up the team sees it.
            TextInput::make('salutation')
                ->label(__('staff.form.salutation.label'))
                ->helperText(__('staff.form.salutation.help'))
                ->maxLength(60)
                ->dehydrateStateUsing(static fn (?string $state): ?string => trim((string) $state) === '' ? null : trim((string) $state)),

            TextInput::make('email')
                ->label(__('staff.form.email.label'))
                ->helperText(__('staff.form.email.help'))
                ->email()
                // Optional since 2026-09-24 (Mike: «not everyone uses email»):
                // without one the person is «Χωρίς σύνδεση» — on the lists and
                // the passenger list, never signing in, never mailed.
                //
                // Editable while inviting, and on somebody who has none yet —
                // adding it is how they are invited. An address already set is
                // the account's login and where its reset link goes, so changing
                // it is a handover to another inbox, not an edit.
                ->disabled(static fn (?User $record): bool => ! $inviting && filled($record?->email))
                ->dehydrated(static fn (?User $record): bool => $inviting || blank($record?->email))
                ->unique(User::class, 'email', ignoreRecord: true)
                ->maxLength(190),

            // What they do on the boat, separate from what they may see in
            // Kaiki (2026-09-24). A departure's «Κυβερνήτης» list offers the
            // captains.
            ToggleButtons::make('specialty')
                ->label(__('staff.form.specialty.label'))
                ->helperText(__('staff.form.specialty.help'))
                ->options(CrewSpecialty::class)
                ->inline(),

            TextInput::make('phone')
                ->label(__('staff.form.phone.label'))
                ->helperText(__('staff.form.phone.help'))
                ->tel()
                ->maxLength(32),

            Select::make('locale')
                ->label(__('staff.form.locale.label'))
                ->helperText(__('staff.form.locale.help'))
                ->options(['el' => 'Ελληνικά', 'en' => 'English'])
                ->default('el')
                ->required(),

            /*
             * One role per person (2026-09-18).
             *
             * This was a checkbox list until today, because `role_assignments`
             * holds a row per role and the screen offered what the table
             * allowed. But the three roles are strictly nested — crew ⊂ manager
             * ⊂ owner, see `Capability::heldBy()` — so every combination a
             * person could tick was already expressed by the highest of them:
             * «owner and crew» granted exactly what «owner» granted, and the
             * screen was asking a question whose answer never mattered.
             *
             * A radio group answers it once. The table keeps its shape, and
             * `Role::highest()` is how a person who already holds two is read
             * back into this field.
             */
            Radio::make('role')
                ->label(__('staff.form.role.label'))
                ->helperText(__('staff.form.role.help'))
                ->options(fn (): array => collect(Role::cases())
                    ->mapWithKeys(fn (Role $role): array => [$role->value => $role->label()])
                    ->all())
                ->descriptions(fn (): array => collect(Role::cases())
                    ->mapWithKeys(fn (Role $role): array => [$role->value => $role->description()])
                    ->all())
                ->required(),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('staff.table.name'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('email')
                    ->label(__('staff.table.email'))
                    ->placeholder(__('staff.table.offline'))
                    ->searchable()
                    ->copyable(),

                TextColumn::make('specialty')
                    ->label(__('staff.table.specialty'))
                    ->formatStateUsing(static fn (?CrewSpecialty $state): string => $state?->label() ?? '')
                    ->placeholder('—'),

                TextColumn::make('roleAssignments.role')
                    ->label(__('staff.table.roles'))
                    ->badge()
                    ->formatStateUsing(fn (Role $state): string => $state->label()),

                /*
                 * Whether they have actually got in yet.
                 *
                 * An invitation that was never opened looks identical to a
                 * working account on every other column, and the operator finds
                 * out on the morning the person cannot sign in. Read from
                 * `email_verified_at`, which the reset flow stamps when the
                 * password is set — the first moment we know the address
                 * reached a real person who acted on it.
                 */
                TextColumn::make('email_verified_at')
                    ->label(__('staff.table.status'))
                    ->badge()
                    ->color(fn (?string $state): string => $state === null ? 'warning' : 'gray')
                    ->formatStateUsing(fn (?string $state): string => $state === null
                        ? __('staff.table.pending')
                        : __('staff.table.active')),
            ])
            ->actions(MoreActions::row(
                /*
                 * Editing is a modal with the role checkboxes filled by hand.
                 *
                 * `roles` is not a column — it is a `hasMany` of
                 * `role_assignments` — so Filament has nothing to hydrate the
                 * field from and a person with two roles would open with none
                 * ticked, then be saved with none. The email is disabled in the
                 * schema here: changing somebody else's login is a handover of
                 * their account to a different inbox, not an edit.
                 */
                EditAction::make()
                    // Filament composes «Επεξεργασία» with the model label and
                    // produces «Επεξεργασία Άτομο», which is not Greek.
                    ->modalHeading(__('staff.actions.edit.heading'))
                    ->modalSubmitActionLabel(__('staff.actions.edit.submit'))
                    ->form(static::formSchema(inviting: false))
                    ->fillForm(fn (User $record): array => [
                        'name' => $record->name,
                        'salutation' => $record->salutation,
                        'email' => $record->email,
                        'specialty' => $record->specialty?->value,
                        'phone' => $record->phone,
                        'locale' => $record->locale,
                        // A person who already holds two — from a seed, the API,
                        // or the checkbox list this replaced — opens on the one
                        // that speaks for both.
                        'role' => Role::highest($record->roles())?->value,
                    ])
                    ->action(function (User $record, array $data): void {
                        $actor = Auth::user();

                        if (! $actor instanceof User) {
                            return;
                        }

                        try {
                            DB::transaction(function () use ($record, $data, $actor): void {
                                $record->update([
                                    'name' => $data['name'],
                                    'salutation' => $data['salutation'] ?? null,
                                    'specialty' => $data['specialty'] ?? null,
                                    'phone' => $data['phone'] ?? null,
                                    'locale' => $data['locale'],
                                ]);

                                Pages\ListStaff::replaceRoles(
                                    $record,
                                    [Role::from($data['role'])],
                                    $actor,
                                );
                            });
                        } catch (LastOwnerException) {
                            Notification::make()
                                ->danger()
                                ->title(__('staff.actions.edit.last_owner'))
                                ->body(__('staff.actions.delete.last_owner_body'))
                                ->send();

                            return;
                        }

                        // An email given to somebody who had none: this is
                        // their invitation (2026-09-24).
                        $email = trim((string) ($data['email'] ?? ''));

                        if (blank($record->email) && $email !== '') {
                            $record->forceFill(['email' => $email])->save();
                            app(InviteStaffMember::class)->sendInvitation($record, $actor);
                        }

                        Notification::make()
                            ->success()
                            ->title(__('staff.actions.edit.done'))
                            ->send();
                    }), [
                        Action::make('resend')
                            ->label(__('staff.actions.resend.label'))
                            ->icon('heroicon-o-envelope')
                            ->color('gray')
                            ->requiresConfirmation()
                            ->modalHeading(__('staff.actions.resend.heading'))
                            ->modalDescription(__('staff.actions.resend.description'))
                            // Only for somebody who has not been in yet. Sending a
                            // "choose your password" link to a colleague who has one
                            // reads as a security incident.
                            ->visible(fn (User $record): bool => $record->email_verified_at === null && filled($record->email))
                            ->action(function (User $record): void {
                                $actor = Auth::user();

                                if (! $actor instanceof User) {
                                    return;
                                }

                                app(InviteStaffMember::class)->sendInvitation($record, $actor);

                                Notification::make()
                                    ->success()
                                    ->title(__('staff.actions.resend.done', ['email' => $record->email]))
                                    ->send();
                            }),

                        DeleteAction::make()
                            ->modalDescription(__('staff.actions.delete.description'))
                            /*
                     * `RoleAssignment` refuses to delete the last owner's role
                     * and throws. Caught here and turned into a sentence,
                     * because the alternative an operator sees is a five
                     * hundred — and the thing they were doing (removing
                     * somebody who has left) is reasonable, it is only the order
                     * that is wrong.
                     */
                            ->action(function (User $record): void {
                                try {
                                    DB::transaction(function () use ($record): void {
                                        $record->roleAssignments()->get()->each->delete();
                                        $record->delete();
                                    });
                                } catch (LastOwnerException) {
                                    Notification::make()
                                        ->danger()
                                        ->title(__('staff.actions.delete.last_owner'))
                                        ->body(__('staff.actions.delete.last_owner_body'))
                                        ->send();

                                    return;
                                }

                                Notification::make()
                                    ->success()
                                    ->title(__('staff.actions.delete.done'))
                                    ->send();
                            }),
                    ]))
            ->defaultSort('name');
    }

    /**
     * This operator's people, and nobody else's.
     *
     * See the class docblock: `User` has no `BelongsToTenant`, so this filter is
     * the only thing standing between this screen and every account on the
     * platform.
     *
     * @return Builder<User>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('tenant_id', Auth::user()?->tenant_id)
            ->where('is_super_admin', false)
            ->with('roleAssignments');
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStaff::route('/'),
        ];
    }
}
