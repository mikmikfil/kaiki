<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crew typed by name (Mike, 2026-09-25: «στο πλήρωμα να μπορώ να βάλω και
 * χειροκίνητα ονοματεπώνυμα»): the crew's `captain_name` — people with no Kaiki
 * account, printed on the passenger list after the staff crew, sent no email.
 *
 * - `schedule_rules.crew_names` — copied to the departures it makes, like
 *   `crew_user_ids`.
 * - `departures.crew_names` — that one day's; a change by hand sets
 *   `crew_from_rule` false, as for the rest of the crew.
 *
 * A JSON list of strings, null when there are none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departures', function (Blueprint $table): void {
            $table->json('crew_names')->nullable();
        });

        Schema::table('schedule_rules', function (Blueprint $table): void {
            $table->json('crew_names')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('schedule_rules', function (Blueprint $table): void {
            $table->dropColumn('crew_names');
        });

        Schema::table('departures', function (Blueprint $table): void {
            $table->dropColumn('crew_names');
        });
    }
};
