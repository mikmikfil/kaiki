<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One price per age band per rate plan (`docs/data-model.md` §2.3).
 *
 * **`per_seat` only.** A whole-boat charter has one price on the plan itself,
 * and a `quote` product has none at all — so a row here on either is a
 * contradiction the Action refuses rather than a value anything would read.
 *
 * ## Not every band needs a row
 *
 * A band priced by `multiplier` derives from the base band, so its row is
 * optional and usually absent. A band priced `fixed` **must** have one, because
 * there is nothing to derive from — validated on save, since a missing row here
 * is a passenger category with no price rather than an error anything would
 * notice at read time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rate_plan_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('rate_plan_id')->constrained('rate_plans')->cascadeOnDelete();
            $table->foreignId('age_band_id')->constrained('age_bands')->cascadeOnDelete();

            // Per person, integer cents (CNV-1). NOT NULL: a row that exists
            // says "this band costs this much", and a null would say nothing
            // while looking like an answer.
            $table->unsignedInteger('price_cents');

            $table->timestamps();

            $table->unique(['tenant_id', 'rate_plan_id', 'age_band_id'], 'rate_plan_prices_plan_band_uq');

            // The eager load: every price for a plan in one query.
            $table->index(['tenant_id', 'rate_plan_id'], 'rate_plan_prices_tenant_plan_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_plan_prices');
    }
};
