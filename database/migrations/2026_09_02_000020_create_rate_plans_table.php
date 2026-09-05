<?php

declare(strict_types=1);

use App\Domain\Pricing\Actions\SaveRatePlan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a product costs, per season (`docs/data-model.md` §2.3, spec CAT-10,
 * PRC-23, AVL-19, AVL-20).
 *
 * §6 item 19, after `products`, `age_bands` and `seasons`.
 *
 * ## The unique index cannot enforce the rule it looks like it enforces
 *
 * `rate_plans_tenant_prod_season_uq` is `(tenant_id, product_id, season_id)`,
 * and `season_id` **null** is the product's default plan. §2.3 states the
 * caveat plainly: **MySQL and SQLite both treat NULLs as distinct in a unique
 * index**, so this constraint permits two default plans for one product and
 * always will.
 *
 * A partial index or an index on an expression would express it and is
 * unportable, which ENV-12 forbids. So the index does what it can — one plan
 * per product per *named* season — and
 * {@see SaveRatePlan} enforces the default,
 * with a nightly integrity check to report anything that got in another way.
 *
 * That is worth stating rather than leaving to be discovered: an index whose
 * name promises more than it delivers is how a duplicate default reaches
 * production and a product prices differently depending on which row was read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rate_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();

            // Null means **the product default** — the plan used when no season
            // contains the departure date. Cascades: a deleted season takes its
            // seasonal prices with it, and the default plan remains.
            $table->foreignId('season_id')->nullable()->constrained('seasons')->cascadeOnDelete();

            // An operator label, not guest-facing: "Early bird", "Χαμηλή".
            $table->string('name', 80)->nullable();

            // `per_vessel`: the whole boat. Integer cents (CNV-1).
            $table->unsignedInteger('vessel_price_cents')->nullable();
            $table->unsignedInteger('extra_hour_price_cents')->nullable();

            // Which of the two columns below is required is `DepositType`'s
            // answer, enforced in the Action so the API and importer share it.
            $table->string('deposit_type', 16)->default('none');
            $table->unsignedTinyInteger('deposit_percent')->nullable();
            $table->unsignedInteger('deposit_fixed_cents')->nullable();

            // AVL-19, AVL-20: booking windows, per plan so a high season can
            // demand more notice than the shoulder.
            $table->unsignedSmallInteger('min_lead_time_hours')->default(0);
            $table->unsignedSmallInteger('max_advance_days')->nullable();

            $table->unsignedSmallInteger('min_pax_override')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            // Real for a named season; **powerless for the default** — see the
            // class docblock.
            $table->unique(['tenant_id', 'product_id', 'season_id'], 'rate_plans_tenant_prod_season_uq');

            // Price resolution: every active plan for the product in one query,
            // then the season choice made in PHP (§2.3).
            $table->index(['tenant_id', 'product_id', 'is_active'], 'rate_plans_tenant_product_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_plans');
    }
};
