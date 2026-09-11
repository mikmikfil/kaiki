<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether an operator boards people by scanning a QR code.
 *
 * ## A small operator does not scan anybody
 *
 * One boat, twelve people on the quay, and a skipper who took most of the
 * bookings on the telephone. A QR on every ticket and a scanning page on the
 * crew's phones is machinery for a problem that business does not have: the
 * printed passenger list and a tick beside each name is the whole of boarding.
 *
 * ## The platform's switch, not the operator's
 *
 * The product owner's instruction (2026-09-11). It sits on the `/admin` edit
 * screen beside the plan and the sandbox flag, and is audited the same way.
 * Whether it later becomes something an operator turns on for themselves is a
 * separate decision.
 *
 * ## On, for everyone, including every operator who exists today
 *
 * Existing operators have tickets in guests' bags with a QR on them, and a
 * default that switched those off would make every one of them stop scanning
 * the morning the migration ran. Off is something somebody chooses.
 *
 * ## Nullable with a constant default, so it can land here
 *
 * `docs/data-model.md` §6, exactly as `deposits_enabled` did: a nullable column
 * with no foreign key and a constant default may be added to an existing table
 * without a rebuild on SQLite. Readers treat null as **on**, through
 * {@see Tenant::usesQrCheckIn()}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->boolean('qr_check_in_enabled')->nullable()->default(true)->after('deposits_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('qr_check_in_enabled');
        });
    }
};
