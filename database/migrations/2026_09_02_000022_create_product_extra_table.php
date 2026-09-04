<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which extras a product offers, and on what terms
 * (`docs/data-model.md` §2.3, spec CAT-12, PRC-10).
 *
 * **A pivot with data.** Its override columns exist so that a tenant-wide
 * "transfer from your hotel" can cost more on the full-day trip than on the
 * sunset cruise — without duplicating the extra and leaving an operator to keep
 * two rows in step.
 *
 * ## Every override is nullable, and null means inherit
 *
 * Including `is_required_override`, which is therefore a **tri-state boolean**:
 * null inherits, false forces optional, true forces required. A two-state
 * column could not express "this product says nothing about it", which is the
 * state almost every row is in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_extra', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('extra_id')->constrained('extras')->cascadeOnDelete();

            $table->unsignedInteger('price_cents_override')->nullable();
            $table->unsignedSmallInteger('max_qty_override')->nullable();

            // Tri-state, deliberately. See the class docblock.
            $table->boolean('is_required_override')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            // One row per product-extra pair: two would make "which override
            // applies" a matter of row order.
            $table->unique(['tenant_id', 'product_id', 'extra_id'], 'product_extra_uq');

            $table->index(['tenant_id', 'product_id', 'sort_order'], 'product_extra_tenant_product_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_extra');
    }
};
