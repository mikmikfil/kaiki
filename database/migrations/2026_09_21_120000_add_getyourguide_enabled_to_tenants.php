<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether an operator sells through GetYourGuide (ADR-0034, spec EXT-1).
 *
 * The inner of two locks. `channel_manager` (EXT-2, a Pennant flag) says
 * whether the integration is allowed to exist at all, and stays shut until
 * GetYourGuide's three-phase certification passes; this says which operator may
 * use it once it is. Set on /admin → Edit Merchant, with a typed reason, in the
 * same audited trail as boarding and site mode.
 *
 * **Null and false are both off.** Nobody's seats are offered to an OTA they
 * never signed with — the operator holds that contract themselves, not Kaiki
 * (ADR-0034), so switching this on for somebody who has not signed would offer
 * inventory under an agreement that does not exist.
 *
 * `docs/data-model.md` §6: a nullable column with no foreign key may be added
 * to an existing table without a rebuild on SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->boolean('getyourguide_enabled')->nullable()->default(false)->after('setup_guide_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('getyourguide_enabled');
        });
    }
};
