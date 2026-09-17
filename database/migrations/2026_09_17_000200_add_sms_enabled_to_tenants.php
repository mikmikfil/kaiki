<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether an operator sends text messages at all (product owner, 2026-09-17).
 *
 * Until now SMS was one switch for the whole platform, `kaiki.notifications.
 * sms_enabled`, off. A text costs the operator money per message and needs a
 * gateway account of their own, so it is a thing the platform switches on for
 * the operator who asked, on /admin → Edit Merchant, with the same audited
 * trail as boarding and site mode. The config value stays as the platform's
 * kill switch above every operator.
 *
 * Null and false are both **off**: nobody gets texts they did not ask for.
 *
 * `docs/data-model.md` §6: a nullable column with no foreign key may be added
 * to an existing table without a rebuild on SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->boolean('sms_enabled')->nullable()->default(false)->after('extra_person_pricing_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('sms_enabled');
        });
    }
};
