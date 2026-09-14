<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether an operator checks anybody in at all.
 *
 * ## The switch beside it was only half of the question
 *
 * `qr_check_in_enabled` answers "do they scan?" and its docblock describes the
 * operator it exists for: one boat, twelve people on the quay, a skipper who
 * took most of the bookings on the telephone. Off, that operator still gets a
 * passenger list with a tap beside every name — and a good number of them do
 * not do that either. They know who is coming, they can see the twelve people
 * in front of them, and a screen asking them to confirm each one is a second
 * list to keep in step with the one in their head.
 *
 * So this is the other half: **off, there is no check-in screen at all**. The
 * passenger manifest is still printed, the departure still completes, the
 * booking still moves to `completed` on the day — what goes away is the
 * boarding gesture and the page it lives on.
 *
 * ## It governs the QR, rather than sitting beside it
 *
 * Two independent switches can be set to a pair that means nothing: scanning
 * on, check-in off. {@see Tenant::usesQrCheckIn()} therefore returns false
 * whenever this one is off, and the `/admin` form only offers the QR toggle
 * while check-in is on. One switch cannot contradict the other, in the column
 * or on the screen.
 *
 * ## On for everybody who exists today
 *
 * The same argument the QR column made: a default that switched boarding off
 * the morning the migration ran would take a working page away from every
 * operator who had one. Off is something somebody chooses, with a reason
 * recorded in that operator's own audit trail.
 *
 * ## Nullable with a constant default, so it can land here
 *
 * `docs/data-model.md` §6, exactly as `deposits_enabled` and
 * `qr_check_in_enabled` did before it: a nullable column with no foreign key
 * and a constant default may be added to an existing table without a rebuild on
 * SQLite. Readers treat null as **on**, through {@see Tenant::usesCheckIn()}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->boolean('check_in_enabled')->nullable()->default(true)->after('qr_check_in_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('check_in_enabled');
        });
    }
};
