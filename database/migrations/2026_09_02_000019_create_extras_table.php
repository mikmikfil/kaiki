<?php

declare(strict_types=1);

use App\Support\Locale\TranslationColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add-ons an operator sells alongside a trip (`docs/data-model.md` §2.3,
 * spec CAT-12, PRC-9, PRC-10).
 *
 * §6 item 22, after `vat_rates` (#47) because an extra may carry its own rate,
 * and after `products` because the pivot points at both.
 *
 * ## Scoping is expressed by the pivot, not by a column
 *
 * An extra with `is_tenant_wide = true` and **no** pivot rows applies to every
 * product. Add a pivot row and it applies to those products only. That is one
 * mechanism rather than two, and it is what lets a tenant-wide "transfer from
 * your hotel" cost more on the full-day trip without duplicating the extra.
 *
 * ## Its own VAT rate, overriding the product's
 *
 * ADR-0002: the cruise is passenger transport at one rate and the barbecue
 * alongside it is catering at another. Null falls back to the product's rate,
 * which is the common case.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extras', function (Blueprint $table): void {
            $table->id();

            // The widget posts extra uuids in its payload, so this is public.
            $table->uuid()->unique('extras_uuid_unique');

            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $table->json('name');
            $table->json('description')->nullable();

            $table->string('pricing_type', 16);

            // Integer cents (CNV-1). **Null for `on_request`**, and refused
            // rather than ignored when one is supplied: a price stored but not
            // charged is a price somebody eventually charges.
            $table->unsignedInteger('price_cents')->nullable();

            $table->foreignId('vat_rate_id')->nullable()->constrained('vat_rates')->restrictOnDelete();

            // Null is unlimited. Enforced server-side (PRC-10) — a client-side
            // limit is a suggestion.
            $table->unsignedSmallInteger('max_qty')->nullable();

            $table->boolean('is_tenant_wide')->default(false);
            $table->boolean('is_required')->default(false);

            // Reserved: an "extra crew seat" would consume capacity. Always
            // false in the MVP, and the column exists now because adding a
            // NOT NULL boolean later is a table rebuild on SQLite (§6).
            $table->boolean('counts_toward_capacity')->default(false);

            $table->string('image_path', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->text(TranslationColumns::SEARCH)->nullable();

            foreach (TranslationColumns::sortColumnsFor(['name']) as $column) {
                $table->string($column, 191)->nullable();
            }

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'is_active', 'sort_order'], 'extras_tenant_active_idx');

            // The resolver's first question: which extras apply to every
            // product before the pivot is consulted at all.
            $table->index(['tenant_id', 'is_tenant_wide', 'is_active'], 'extras_tenant_wide_idx');

            foreach (TranslationColumns::sortColumnsFor(['name']) as $column) {
                $table->index(['tenant_id', $column], "extras_tenant_{$column}_idx");
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extras');
    }
};
