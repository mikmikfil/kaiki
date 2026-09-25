<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Captain and crew per departure (the 24/9 list, #3; ν. 4926/2022 wants the
 * captain on the passenger list, and the boat's own `captain_name` is the
 * boat's usual skipper, not necessarily today's).
 *
 * - `captain_user_id` — one of the operator's people, when the captain has an
 *   account; `captain_name` — typed, when they do not (a freelance skipper).
 *   Neither set means the boat's `captain_name`, as before.
 * - `crew_user_ids` — the operator's people sailing with it. A JSON list on the
 *   row rather than a pivot: it is read with the departure, written only from
 *   its edit page, and a pivot would be one more table to scope by tenant.
 *
 * Nullable, no data change: every departure keeps reading the boat's captain
 * until somebody says otherwise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departures', function (Blueprint $table): void {
            $table->foreignId('captain_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('captain_name', 120)->nullable();
            $table->json('crew_user_ids')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('departures', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('captain_user_id');
            $table->dropColumn(['captain_name', 'crew_user_ids']);
        });
    }
};
