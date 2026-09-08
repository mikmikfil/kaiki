<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\StaffResource\Pages;

use App\Domain\Tenancy\Actions\InviteStaffMember;
use App\Enums\Role;
use App\Filament\App\Resources\ApiKeyResource\Pages\ListApiKeys;
use App\Filament\App\Resources\StaffResource;
use App\Models\RoleAssignment;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The team list, with inviting and role editing on it.
 *
 * Both are **modal actions rather than pages**, which is the same shape
 * {@see ListApiKeys} uses and
 * for a related reason: each is one short form and one decision, and a
 * dedicated page for either would put a navigation step either side of a job
 * that takes fifteen seconds and is done twice a season.
 */
class ListStaff extends ListRecords
{
    protected static string $resource = StaffResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('invite')
                ->label(__('staff.actions.invite.label'))
                ->icon('heroicon-o-plus')
                ->modalHeading(__('staff.actions.invite.heading'))
                ->modalDescription(__('staff.actions.invite.description'))
                ->modalSubmitActionLabel(__('staff.actions.invite.submit'))
                ->form(StaffResource::formSchema())
                ->action(function (array $data): void {
                    $actor = Auth::user();

                    if (! $actor instanceof User) {
                        return;
                    }

                    $user = app(InviteStaffMember::class)(
                        name: $data['name'],
                        email: $data['email'],
                        roles: array_map(Role::from(...), $data['roles']),
                        invitedBy: $actor,
                        locale: $data['locale'],
                    );

                    Notification::make()
                        ->success()
                        ->title(__('staff.actions.invite.done', ['email' => $user->email]))
                        ->body(__('staff.actions.invite.done_body'))
                        ->send();
                }),
        ];
    }

    /**
     * Replace a person's roles with the set that was ticked.
     *
     * Deletes then inserts rather than diffing, because `RoleAssignment`'s
     * last-owner guard lives on `deleting` — a diff that removed the final owner
     * role would have to reimplement the check to know it was about to, and two
     * implementations of "is this the last owner" is exactly one too many.
     *
     * The whole swap is one transaction, so a refusal leaves the person with the
     * roles they had rather than with none.
     *
     * @param  list<Role>  $roles
     */
    public static function replaceRoles(User $user, array $roles, User $grantedBy): void
    {
        DB::transaction(function () use ($user, $roles, $grantedBy): void {
            $user->roleAssignments()->get()->each->delete();

            foreach ($roles as $role) {
                RoleAssignment::query()->create([
                    'user_id' => $user->getKey(),
                    'role' => $role,
                    'granted_by_user_id' => $grantedBy->getKey(),
                ]);
            }
        });
    }
}
