<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Role;
use App\Exceptions\LastOwnerException;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\RoleAssignmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's role within one tenant (data-model §2.1).
 *
 * Separate from `users` even though a user belongs to exactly one tenant in the
 * MVP (ADR-0020 Option C), because a user may hold more than one role — manager
 * and crew is a normal combination on a small operation — and because keeping
 * roles here means a future many-to-many is a data migration rather than a
 * redesign of authentication.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $user_id
 * @property Role $role
 * @property int|null $granted_by_user_id
 */
class RoleAssignment extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<RoleAssignmentFactory> */
    use HasFactory;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'role' => Role::class,
        ];
    }

    protected static function booted(): void
    {
        static::deleting(static function (self $assignment): void {
            if ($assignment->role !== Role::Owner) {
                return;
            }

            $remainingOwners = static::query()
                ->where('role', Role::Owner)
                ->whereKeyNot($assignment->getKey())
                ->count();

            if ($remainingOwners === 0) {
                throw LastOwnerException::make();
            }
        });
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_user_id');
    }
}
