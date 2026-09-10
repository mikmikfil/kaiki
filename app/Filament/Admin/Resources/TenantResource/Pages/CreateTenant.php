<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\TenantResource\Pages;

use App\Domain\Tenancy\Actions\InviteStaffMember;
use App\Domain\Tenancy\Actions\OnboardOperator;
use App\Enums\Plan;
use App\Enums\TenantVertical;
use App\Filament\Admin\Resources\TenantResource;
use App\Models\Tenant;
use App\Models\User;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Taking on a customer (SAA-9, SAA-10, TEN-8a).
 *
 * ## Seven fields, because the eighth is the operator's own job
 *
 * The business, its address on the web, where invoices go, and one person who
 * can sign in. Everything else — the legal name, the ΑΦΜ, the ΔΟΥ, the logo,
 * the colours, the boats — the operator fills in themselves, and a platform
 * form that collected them would be a form somebody has to read down a
 * telephone before the customer has an account.
 *
 * ## The password is not one of the fields, deliberately
 *
 * TEN-8a: the owner is invited and sets their own through a reset link. Typing
 * a password here would put a working credential for somebody else's business
 * into an email, a chat window and a notebook — and leave it known to a person
 * who does not need it. {@see InviteStaffMember}
 * makes the same argument at length for an owner inviting their crew; it is the
 * same rule one level up.
 *
 * ## The slug is suggested and then left alone
 *
 * It is the operator's public address — `book.kaiki.gr/aegean-blue` — and it
 * appears in every link they will ever paste. Derived from the name on the first
 * keystroke as a convenience, and **not** re-derived afterwards: somebody who
 * corrects a typo in the company name a minute later must not silently change
 * the address of pages that may already be linked.
 */
class CreateTenant extends CreateRecord
{
    protected static string $resource = TenantResource::class;

    public function form(Form $form): Form
    {
        return $form->schema([
            Section::make(__('tenants.create.business'))
                ->description(__('tenants.create.business_help'))
                ->schema([
                    TextInput::make('name')
                        ->label(__('tenants.columns.name'))
                        ->required()
                        ->maxLength(180)
                        // Only while the slug is untouched, and only on the way
                        // in. See the class docblock.
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (Get $get, callable $set, ?string $state): void {
                            if (($get('slug') ?? '') === '') {
                                $set('slug', Str::slug((string) $state));
                            }
                        }),

                    TextInput::make('slug')
                        ->label(__('tenants.columns.slug'))
                        ->helperText(__('tenants.create.slug_help'))
                        ->required()
                        ->maxLength(60)
                        // The shape the hosted routes constrain on. A slug the
                        // router will not match is an operator whose pages 404
                        // from the day they are created.
                        ->rule('regex:/^[a-z0-9][a-z0-9-]*$/')
                        ->unique(table: 'tenants', column: 'slug'),

                    TextInput::make('email')
                        ->label(__('tenants.create.billing_email'))
                        ->helperText(__('tenants.create.billing_email_help'))
                        ->email()
                        ->required()
                        ->maxLength(190),
                ])
                ->columns(3),

            Section::make(__('tenants.create.owner'))
                ->description(__('tenants.create.owner_help'))
                ->schema([
                    TextInput::make('owner_name')
                        ->label(__('tenants.create.owner_name'))
                        ->required()
                        ->maxLength(120),

                    TextInput::make('owner_email')
                        ->label(__('tenants.create.owner_email'))
                        ->email()
                        ->required()
                        ->maxLength(190)
                        // Across the whole table, not within the tenant: the
                        // email is the login and two accounts cannot share one.
                        ->rule(Rule::unique('users', 'email')),
                ])
                ->columns(2),

            Section::make(__('tenants.create.account'))
                ->schema([
                    Select::make('plan')
                        ->label(__('tenants.columns.plan'))
                        ->options(Plan::options())
                        ->default(Plan::Trial->value)
                        ->required(),

                    Select::make('vertical')
                        ->label(__('tenants.columns.vertical'))
                        ->options(TenantVertical::options())
                        ->default(TenantVertical::Boats->value)
                        ->required(),

                    Select::make('default_locale')
                        ->label(__('tenants.columns.locale'))
                        ->options([
                            'el' => __('enums.locale.el.label'),
                            'en' => __('enums.locale.en.label'),
                        ])
                        ->default('el')
                        ->required(),

                    Toggle::make('is_sandbox')
                        ->label(__('tenants.columns.sandbox'))
                        ->helperText(__('tenants.edit.sandbox_help')),
                ])
                ->columns(3),
        ]);
    }

    /**
     * The Action does the work; this page only collects the answers.
     *
     * Overridden rather than letting Filament call `Tenant::create()`, because
     * an operator is not one row: it is a tenant, a brand profile and an owner
     * who has to be invited, and two of those would be missing from a record
     * created the ordinary way.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $admin */
        $admin = auth()->user();

        return app(OnboardOperator::class)(
            name: (string) $data['name'],
            slug: (string) $data['slug'],
            email: (string) $data['email'],
            ownerName: (string) $data['owner_name'],
            ownerEmail: (string) $data['owner_email'],
            createdBy: $admin,
            plan: Plan::from((string) $data['plan']),
            vertical: TenantVertical::from((string) $data['vertical']),
            locale: (string) $data['default_locale'],
            isSandbox: (bool) ($data['is_sandbox'] ?? false),
        );
    }

    protected function getCreatedNotification(): ?Notification
    {
        /** @var Tenant $tenant */
        $tenant = $this->getRecord();

        // Named rather than generic, because the thing that just happened that
        // the platform owner cannot see is the **email**: an operator who never
        // received their invitation looks identical to one who has not opened
        // it yet.
        return Notification::make()
            ->success()
            ->title(__('tenants.create.created', ['name' => $tenant->name]))
            ->body(__('tenants.create.invited'));
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
