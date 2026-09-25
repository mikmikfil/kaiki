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
 * can sign in. Everything else — the legal name, the ΑΦΜ, the ΔΟΥ, the boats —
 * the operator fills in themselves, and a platform form that collected them
 * would be a form somebody has to read down a telephone before the customer
 * has an account.
 *
 * **Except the logo and the colours** (Mike, 2026-09-25: *«την αρχικοποίηση
 * θέλω να την κάνω από το admin»*). Those are set here, or on the edit page,
 * and the operator's first-time guide no longer asks for them.
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
    use HasTenantAccountFields;
    use HasTenantBrandingFields;

    protected static string $resource = TenantResource::class;

    /**
     * What the platform's own sections write, beyond what `OnboardOperator`
     * already takes as arguments.
     *
     * **Not `status`, and not `subscription_ends_at`.** The Action decides the
     * status from the plan — a trial starts `trialing` — and writing one here
     * would overrule it with whatever the form happened to default to. The
     * access date belongs to a contract that does not exist yet. Both are on
     * the edit page, where changing them is a decision with a reason.
     */
    private const PLATFORM_COLUMNS = [
        'check_in_enabled',
        'qr_check_in_enabled',
        'hosted_site_mode',
        'extra_person_pricing_enabled',
        'sms_enabled',
        'setup_guide_enabled',
        'getyourguide_enabled',
    ];

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

            /*
             * **The same questions the edit page asks**, from the same trait
             * (product owner, 2026-09-22: *«στη δημιουργία νέου merchant να
             * μπουν και τα features και όλα αυτά τα πεδία, μην εμφανίζονται
             * μόνο μετά τη δημιουργία του»*).
             *
             * Everything the platform decides is decided once, when the
             * customer is taken on — rather than created with seven fields and
             * then visited a second time to switch on what was agreed on the
             * telephone. Shared rather than copied: two lists of feature
             * switches drift the first time one is added, and the copy nobody
             * is looking at is the one that goes stale.
             */
            Section::make(__('tenants.create.account'))
                ->schema([
                    Select::make('plan')
                        ->label(__('tenants.columns.plan'))
                        ->options(Plan::options())
                        ->default(Plan::Trial->value)
                        ->required(),

                    Select::make('default_locale')
                        ->label(__('tenants.columns.locale'))
                        ->options([
                            'el' => __('enums.locale.el.label'),
                            'en' => __('enums.locale.en.label'),
                        ])
                        ->default('el')
                        ->required(),

                    Select::make('vertical')
                        ->label(__('tenants.columns.vertical'))
                        ->options(TenantVertical::options())
                        ->default(TenantVertical::Boats->value)
                        ->required(),

                    Toggle::make('is_sandbox')
                        ->label(__('tenants.columns.sandbox'))
                        ->helperText(__('tenants.edit.sandbox_help')),
                ])
                ->columns(3),

            $this->featuresSection(),

            $this->brandingSection(),

            $this->channelsSection(),
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

        $tenant = app(OnboardOperator::class)(
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

        /*
         * The rest of what the form asked, onto the row the Action made.
         *
         * Not arguments to `OnboardOperator`: that Action is the **shape** of
         * taking a customer on — a tenant, a brand profile and an invited owner
         * — and a dozen optional switches passed through it would make the one
         * thing it guarantees harder to read. These are ordinary columns with
         * their own defaults, and writing them after is the same write the edit
         * page makes.
         *
         * Only the keys the form actually sent, so a switch that was not on
         * screen keeps the database's default rather than being set to null.
         */
        $settings = array_intersect_key($data, array_flip(self::PLATFORM_COLUMNS));

        if ($settings !== []) {
            $tenant->forceFill($settings)->save();
        }

        // The logo and the colours, onto the brand profile the observer made
        // with the tenant — through the same Actions the operator's own screen
        // uses. See `HasTenantBrandingFields`.
        $branding = $data['branding'] ?? null;

        if (is_array($branding)) {
            $this->applyBranding($tenant, $branding);
        }

        return $tenant;
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
