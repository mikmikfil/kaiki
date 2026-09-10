<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Actions;

use App\Enums\Role;
use App\Mail\StaffInvitationMail;
use App\Models\RoleAssignment;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Give a colleague a way in (TEN-8, `Capability::ManageStaff`).
 *
 * ## This did not exist, and nothing said so
 *
 * `UserPolicy`, `RoleAssignmentPolicy`, `Capability::ManageStaff` and the Greek
 * strings «Ομάδα», «Ανάθεση ρόλου» and «Αφαίρεση ρόλου» were all written in M0.
 * The screen was not, and neither was a command — so from M0 until now
 * **an owner could not hand their skipper a login through the product at all**.
 * Every role in `docs/spec.md` was reachable only by editing the database.
 *
 * Found while writing the manual's chapter on the three roles: a chapter that
 * explains what each role can do has to begin by explaining how somebody gets
 * one, and there was no answer.
 *
 * ## The password is never chosen by the inviter
 *
 * The user row is created with a long random string nobody ever sees, and the
 * invitation carries a **password-reset link** the colleague uses to set their
 * own. The alternative — an owner typing a password and reading it down the
 * telephone — puts a working credential into a chat window and a notebook, and
 * leaves it known to somebody who no longer needs it.
 *
 * Reusing the reset token rather than inventing an invitation token is the
 * whole security argument: Laravel's tokens are hashed at rest, single-use,
 * expiring, and invalidated when spent. A second token type is a second chance
 * to get all four wrong, on the link that opens an operator's business.
 *
 * ## One transaction, and the email outside it
 *
 * The user and their roles commit together — a colleague with a login and no
 * role can sign in and see nothing, which reads as a broken product rather than
 * an incomplete invitation. The mail is sent after the commit, because a
 * mail server that is slow or briefly down must not roll back an account that
 * was created correctly. A colleague who never received the message can be sent
 * it again; one whose account vanished cannot.
 */
final class InviteStaffMember
{
    /**
     * @param  list<Role>  $roles
     */
    public function __invoke(
        string $name,
        string $email,
        array $roles,
        User $invitedBy,
        ?string $locale = null,
    ): User {
        $tenant = Tenancy::current();

        if (! $tenant instanceof Tenant) {
            throw new RuntimeException('A staff invitation needs a resolved tenant.');
        }

        $user = DB::transaction(function () use ($name, $email, $roles, $invitedBy, $locale, $tenant): User {
            $user = User::query()->create([
                'tenant_id' => $tenant->getKey(),
                'name' => $name,
                'email' => $email,
                // Never used. The account is unusable until the invitee spends
                // their reset link, and this is what makes "unusable" true
                // rather than "empty password" — which some guards treat as a
                // match.
                'password' => Str::random(64),
                'locale' => $locale ?? $tenant->default_locale,
                'is_super_admin' => false,
            ]);

            foreach ($roles as $role) {
                RoleAssignment::query()->create([
                    'user_id' => $user->getKey(),
                    'role' => $role,
                    'granted_by_user_id' => $invitedBy->getKey(),
                ]);
            }

            return $user;
        });

        $this->sendInvitation($user, $invitedBy);

        return $user->refresh();
    }

    /**
     * Mint a reset token and mail the link.
     *
     * Split out so re-sending an invitation is the same code path as sending
     * the first one — a colleague who deleted the email, or whose token expired
     * before they got to a computer, is the common case rather than the odd one.
     *
     * ## The link has to be **signed**, and it was not
     *
     * `filament.app.auth.password-reset.reset` carries Filament's `signed`
     * middleware. `route()` builds the address without a signature, so every
     * invitation this Action has sent led to **403 Invalid signature** — which
     * means no invited colleague could set a password, and the invitation has
     * never worked outside a test that called the Action and read the model
     * rather than opening the link.
     *
     * Found by walking `docs/testing/` in a browser, which is the only place it
     * could be found: nothing that asserts on `$user->password` notices that
     * the URL it never visits would refuse.
     *
     * `signedRoute` rather than `temporarySignedRoute`, because the expiry that
     * matters already exists and belongs to the token —
     * `config('auth.passwords.users.expire')` — and a second, different clock on
     * the same link produces one that dies for a reason the page cannot explain.
     */
    public function sendInvitation(User $user, User $invitedBy): void
    {
        $token = Password::broker()->createToken($user);

        $url = URL::signedRoute('filament.app.auth.password-reset.reset', [
            'token' => $token,
            'email' => $user->email,
        ]);

        Mail::to($user->email)->send(new StaffInvitationMail($user, $url, $invitedBy));
    }
}
