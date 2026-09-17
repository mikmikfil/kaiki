<?php

declare(strict_types=1);

use App\Models\RatePlan;
use App\Models\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «X € for up to N people, +Y € for each one more» on a whole-boat trip
 * (product owner, 2026-09-17).
 *
 * ## Why it exists
 *
 * The first operator moving to Kaiki prices every private and sunset trip this
 * way: 2.500 € for the catamaran with up to ten aboard, and 50 € for each
 * person past ten. A single boat price cannot say that, and the extra-hour
 * price answers a different question.
 *
 * ## A platform decision, per operator, off for everybody
 *
 * {@see Tenant::usesExtraPersonPricing()}. It is switched on from `/admin`,
 * next to the other operator switches and with the same audit trail, because
 * it is a pricing model rather than a preference: most operators sell a boat
 * as a boat, and two more fields on every price list would be two more fields
 * to misunderstand. Null reads as **off**.
 *
 * ## The two numbers live on the rate plan
 *
 * {@see RatePlan}: `included_pax` and `extra_pax_price_cents`, per period,
 * because the summer price list and the October one may well include a
 * different number of people. Both null means the plan has no such rule.
 *
 * ## Nullable, no foreign keys, so it can land here
 *
 * `docs/data-model.md` §6, as every column added to these tables since M1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->boolean('extra_person_pricing_enabled')->nullable()->default(false)->after('check_in_enabled');
        });

        Schema::table('rate_plans', function (Blueprint $table): void {
            $table->unsignedSmallInteger('included_pax')->nullable()->after('extra_hour_price_cents');
            $table->unsignedInteger('extra_pax_price_cents')->nullable()->after('included_pax');
        });
    }

    public function down(): void
    {
        Schema::table('rate_plans', function (Blueprint $table): void {
            $table->dropColumn(['included_pax', 'extra_pax_price_cents']);
        });

        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('extra_person_pricing_enabled');
        });
    }
};
