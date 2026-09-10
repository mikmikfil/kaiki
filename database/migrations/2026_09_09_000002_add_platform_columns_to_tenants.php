<?php

declare(strict_types=1);

use App\Enums\TenantVertical;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two columns the platform owner needs and the operator never sees.
 *
 * ## `subscription_ends_at` — because "days left" had no answer for a payer
 *
 * `trial_ends_at` has existed since M0 and answers only for a trial. Once an
 * operator pays there is no date on the record at all, so the merchant list
 * could not tell the platform owner who lapses this week — which is the one
 * question that list gets opened for.
 *
 * It is deliberately **not** derived from a subscription table. M7 will build
 * one (ADR-0028: Viva, and the instalment machinery is ours to write), and this
 * column is what that machinery will keep current. Until then it is set by hand
 * from `/admin`, which is honest: a number typed by a person who knows is worth
 * more than a number computed from a table nobody writes to yet.
 *
 * ## `vertical` — a label, and not a second product
 *
 * See {@see TenantVertical}. It makes the merchant list readable by trade. It
 * does not make the catalogue anything other than boat-shaped, and the enum's
 * docblock says so at more length than this one should.
 *
 * ## Both are portable, which is why this is a new migration
 *
 * `docs/data-model.md` §6: a nullable column with no foreign key and a constant
 * default may be added to an existing table. Neither of these has an FK — the
 * vertical is an enum in code, not a lookup row — so neither needs the M0
 * migration edited and a `migrate:fresh` behind it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('vertical', 24)->nullable()->default(TenantVertical::Boats->value)->after('plan');

            // No index. The platform has tens of operators, not millions, and
            // an index on a two-value column of that size is a write cost for a
            // read that scans the table faster anyway.
            $table->timestamp('subscription_ends_at')->nullable()->after('trial_ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn(['vertical', 'subscription_ends_at']);
        });
    }
};
