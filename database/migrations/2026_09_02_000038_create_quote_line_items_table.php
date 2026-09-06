<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The lines an operator writes on a quote (`docs/data-model.md` §2.5,
 * §6 item 38, spec BKG-27).
 *
 * ## Free text, integer cents
 *
 * BKG-27 allows operator-authored free-text lines — "Σκάφος με πλήρωμα, 8 ώρες"
 * is a line nobody could have derived from a rate plan. What it does **not**
 * relax is the arithmetic: `unit_price_cents` and `total_cents` are integers
 * like every other money column in the schema (§1.4), because a quote that
 * accepted a decimal would be the one place in the product where a cent could
 * go missing.
 *
 * ## The sign is in `kind`, not in the number
 *
 * §1.4 again, and §2.5 says it in as many words: *"positive even for
 * `discount` — the `kind` carries the sign."* A negative `unit_price_cents`
 * would make every `SUM` in the system a question about which rows were
 * included, and `unsignedInteger` makes the wrong version fail at the database
 * rather than in a total nobody checked.
 *
 * ## Translatable labels on an operator-written line
 *
 * `label` and `description` are JSON for the same reason every other
 * guest-facing string is: I18N-1 wants both languages, and a quote sent to a
 * Greek guest and forwarded to their English-speaking partner is the ordinary
 * case rather than the exotic one. An operator who fills in only one locale
 * gets the fallback chain, which is `spatie/laravel-translatable`'s job.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_line_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('quote_id')->constrained('quotes')->cascadeOnDelete();

            $table->json('label');
            $table->json('description')->nullable();

            /* `charter` | `extra` | `fee` | `discount` — PHP enum `QuoteLineKind`. */
            $table->string('kind', 16)->default('charter');

            $table->unsignedSmallInteger('qty')->default(1);

            // Positive, always — see the class docblock.
            $table->unsignedInteger('unit_price_cents')->default(0);
            $table->unsignedInteger('total_cents')->default(0);

            // The operator's order, which is the order the guest reads. Not the
            // insertion order: a line added last may belong second.
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['tenant_id', 'quote_id', 'sort_order'], 'quote_lines_tenant_quote_sort_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_line_items');
    }
};
