<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\VatRate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The rate an operator sells at, answered once (#51, CAT-11, CAT-11b).
 *
 * ## Per-product is correct, and is not what an operator expects to type
 *
 * `products.vat_rate_id` is per-product and nullable, and it must stay that
 * way: a cruise is passenger transport at one rate and a barbecue extra is
 * catering at another, which `docs/data-model.md` §2.3 states outright. But an
 * operator reasonably expects VAT to be *something you set up once when
 * configuring*, and today it is not — every new product picks a rate from
 * nothing. This column is the answer to the question asked once; the per-product
 * field keeps the override.
 *
 * ## It should have landed in M1, and the reason it can land here anyway
 *
 * #51 says this column "has to land in M1", because §6 forbids a migration that
 * adds a **foreign key** to an existing table: SQLite cannot do it, and local
 * development runs on SQLite. It never landed, and by the letter of that rule
 * the `tenants` table would now need rebuilding.
 *
 * It does not, because the rule is about foreign keys and this is a plain
 * indexed column without one. {@see Tenant::defaultVatRate()} resolves the
 * relation in PHP and {@see VatRate} rows are platform-owned reference data
 * that is never deleted — `is_selectable` is how a rate is withdrawn (#47) — so
 * there is no cascade for a constraint to enforce. What is given up is the
 * database refusing a dangling id, and what is bought is a migration that runs
 * on both engines. The relation returns null for an id that no longer resolves,
 * which is the same answer the column's own null gives.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->unsignedBigInteger('default_vat_rate_id')->nullable()->after('currency');

            $table->index('default_vat_rate_id', 'tenants_default_vat_rate_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropIndex('tenants_default_vat_rate_id_index');
            $table->dropColumn('default_vat_rate_id');
        });
    }
};
