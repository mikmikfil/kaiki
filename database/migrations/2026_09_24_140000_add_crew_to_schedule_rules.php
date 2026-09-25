<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Captain and crew set once, on the schedule (Mike, 2026-09-24, from
 * docs/mockups/captain-crew.html): a rule that makes 122 departures is where
 * an operator says who sails them, not each departure.
 *
 * - `schedule_rules.captain_user_id / captain_name / crew_user_ids` — the same
 *   three a departure has, copied to its departures.
 * - `departures.crew_from_rule` — true until somebody changes that one day by
 *   hand; a change to the rule then leaves it alone.
 * - `departures.crew_reminded_at` — the 24-hours-before reminder, sent once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedule_rules', function (Blueprint $table): void {
            $table->foreignId('captain_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('captain_name', 120)->nullable();
            $table->json('crew_user_ids')->nullable();
        });

        Schema::table('departures', function (Blueprint $table): void {
            $table->boolean('crew_from_rule')->default(true);
            $table->timestamp('crew_reminded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('departures', function (Blueprint $table): void {
            $table->dropColumn(['crew_from_rule', 'crew_reminded_at']);
        });

        Schema::table('schedule_rules', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('captain_user_id');
            $table->dropColumn(['captain_name', 'crew_user_ids']);
        });
    }
};
