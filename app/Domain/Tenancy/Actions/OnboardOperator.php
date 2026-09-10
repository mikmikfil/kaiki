<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Actions;

use App\Enums\Plan;
use App\Enums\Role;
use App\Enums\TenantStatus;
use App\Enums\TenantVertical;
use App\Models\Tenant;
use App\Models\User;
use App\Observers\TenantObserver;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Take on a new operator (SAA-9, SAA-10, TEN-8a).
 *
 * ## Until this existed, a customer could only be added by a seeder
 *
 * Every screen in `/admin` assumed the operator was already there. `TenantPolicy`
 * refused `create` and said so in a comment — *"onboarding creates operators
 * (SAA-10, M7)"* — which was true about the plan and not true about the code:
 * onboarding did not exist, so nothing created operators. The platform owner had
 * a merchant list they could not add a merchant to.
 *
 * ## Three things, one transaction, and the mail outside it
 *
 * The tenant, its brand profile and its owner commit together. A tenant with no
 * owner is a business nobody can sign in to, and a half-made one is worse than a
 * failed one — somebody would find it in the list a week later and wonder.
 *
 * The brand profile is not created here: {@see TenantObserver} does it on
 * `created`, which is BRD-3's own wording — *"when a tenant is created"* — and
 * is why a seeder and a factory get one too. A step in this Action would be a
 * step one of the four creation paths forgets.
 *
 * The invitation email goes out afterwards, through {@see InviteStaffMember},
 * for the reason that Action states at length: a slow mail server must not roll
 * back an account that was created correctly.
 *
 * ## Nobody types the owner's password, including us
 *
 * TEN-8a, and it is the same rule an owner follows when inviting their own
 * crew: the account is created with a random string nobody ever sees, and the
 * owner sets their own through a reset link. A platform owner who typed a
 * password would be putting a working credential for somebody else's business
 * into an email and a notebook.
 *
 * ## The trial clock starts here
 *
 * `status` is `trialing` and `trial_ends_at` is set, because an operator created
 * with neither is one the merchant list shows as «Χωρίς ημερομηνία» for ever —
 * and "who lapses this week" is the question that list exists to answer.
 */
final class OnboardOperator
{
    /** How long a new operator gets before somebody has to decide (brief §11). */
    public const TRIAL_DAYS = 14;

    public function __construct(private readonly InviteStaffMember $invite) {}

    /**
     * @param  array<string, mixed>  $attributes  overrides, for a seeder or a test
     */
    public function __invoke(
        string $name,
        string $slug,
        string $email,
        string $ownerName,
        string $ownerEmail,
        User $createdBy,
        Plan $plan = Plan::Trial,
        TenantVertical $vertical = TenantVertical::Boats,
        string $locale = 'el',
        bool $isSandbox = false,
        array $attributes = [],
    ): Tenant {
        $tenant = DB::transaction(fn (): Tenant => Tenant::query()->create([
            'name' => $name,
            'slug' => $slug,
            'email' => $email,
            'plan' => $plan,
            'vertical' => $vertical,
            'status' => TenantStatus::Trialing,
            'trial_ends_at' => Carbon::now()->addDays(self::TRIAL_DAYS),
            'default_locale' => $locale,
            // Both, always. An operator who only ever sells in Greek still has
            // English guests, and the alternative — one language now, the other
            // when somebody notices — leaves half the catalogue untranslated at
            // the moment it is needed.
            'supported_locales' => ['el', 'en'],
            'is_sandbox' => $isSandbox,
            // `tenants.settings` is `NOT NULL` with no default — it is a JSON
            // bag every reader treats as a map, and a null there is a fatal on
            // the first `data_get`. The factory has always supplied it; nothing
            // else created a tenant, so nothing else had to.
            'settings' => [],
            ...$attributes,
        ]));

        // Inside the new tenant, because `InviteStaffMember` reads the current
        // one — it is written for an owner inviting their crew, where the
        // tenant is ambient. A platform admin has none of their own, so it is
        // supplied rather than resolved.
        Tenancy::forTenant($tenant, function () use ($tenant, $ownerName, $ownerEmail, $createdBy): void {
            $this->invite->__invoke(
                name: $ownerName,
                email: $ownerEmail,
                roles: [Role::Owner],
                invitedBy: $createdBy,
                locale: $tenant->default_locale,
            );
        });

        return $tenant->refresh();
    }
}
