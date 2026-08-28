<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roles held by a user within one tenant (data-model §2.1).
 *
 * `role` is a varchar backed by the PHP `Role` enum, never a MySQL ENUM —
 * SQLite has no ENUM type and every migration must run on both engines
 * (data-model §0).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 32);
            $table->foreignId('granted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Idempotent grants: granting a role twice is a no-op, not a duplicate row.
            $table->unique(['tenant_id', 'user_id', 'role'], 'role_assign_tenant_user_role_uq');
            // "List all crew" for the departure-day SMS.
            $table->index(['tenant_id', 'role'], 'role_assign_tenant_role_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_assignments');
    }
};
