<?php

declare(strict_types=1);

use App\Domain\Operations\Support\WindForecast;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The wind a boat stops sailing in (ADR-0027).
 *
 * ## Per vessel, because a fleet-wide number is wrong for every boat in it
 *
 * A twelve-seat RIB and a forty-eight-seat kaiki do not stop at the same
 * figure, and neither does a sailing yacht. A platform-wide constant would
 * either flag days that were fine for the big boat or fail to flag the one that
 * put the small one in trouble — and the second of those is somebody's morning
 * on a rough sea.
 *
 * ## Nullable, and null means "do not tell me"
 *
 * Not a default of 6. An operator who has not set a limit has not asked for
 * this feature, and inventing one for them would put a warning on their
 * dashboard about a boat they know better than we do. Null is the honest
 * absence: {@see WindForecast::exceeds()} is
 * false for it, always.
 *
 * ## An `ALTER` here is allowed, unlike a foreign key
 *
 * `docs/data-model.md` §6 forbids adding a **foreign key** to an existing table
 * because SQLite cannot — a plain nullable column with no default is a
 * different thing and is portable on both engines.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vessels', function (Blueprint $table): void {
            // 0 to 12. `unsignedTinyInteger` rather than a string, because it is
            // compared numerically against a forecast on every render.
            $table->unsignedTinyInteger('max_wind_bft')->nullable()->after('turnaround_buffer_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('vessels', function (Blueprint $table): void {
            $table->dropColumn('max_wind_bft');
        });
    }
};
