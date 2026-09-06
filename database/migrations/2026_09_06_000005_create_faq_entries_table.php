<?php

declare(strict_types=1);

use App\Models\FaqEntry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The operator's own questions and answers.
 *
 * A scope addition from the design review of 4 September, and the cheapest
 * feature in M3 by a distance: an operator answers "do you provide towels" once
 * instead of forty times, and HOS-2's structured data makes the answer show up
 * in search under the question somebody actually typed.
 *
 * ## `product_id` is nullable, and it lands now because it cannot land later
 *
 * "What should I bring?" is a question about a trip; "do you sail in August?"
 * is a question about the business. Both are ordinary, and only the second one
 * is needed by this issue — #104's product pages are what fill the first.
 *
 * The column is here anyway, for the reason `charter_agreements` shipped in M2:
 * **SQLite cannot add a foreign key to an existing table**, so a nullable FK is
 * a decision with exactly one chance. It is not dead in the meantime — the FAQ
 * page groups the product-specific entries under their trip, so an operator can
 * write and see both from the day this ships.
 *
 * ## The answer is plain text, like every other operator field
 *
 * Same rule and the same renderer as #102's blocks
 * ({@see \App\Domain\Hosted\Support\BlockText}): escaped first, paragraphs
 * second, no markup ever. An FAQ answer is a particularly attractive place for
 * a rich-text field — operators want to link their cancellation policy — and it
 * is a particularly bad one, because this text goes into JSON-LD, where a
 * stray tag is a search-console error rather than a visible mistake.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faq_entries', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // Null means "about the operator"; a product means "about that
            // trip". See the class docblock for why it is here already.
            $table->foreignId('product_id')->nullable()->constrained('products')->cascadeOnDelete();

            // Translatable (§1.6), and both required — an entry is a pair, and
            // half of one is not an FAQ. {@see FaqEntry} enforces it.
            $table->json('question');
            $table->json('answer');

            $table->unsignedInteger('sort_order')->default(0);

            // Hidden rather than deleted, like a home-page block: an operator
            // whose answer stops being true off-season should not lose the text.
            $table->boolean('is_visible')->default(true);

            $table->timestamps();

            // The read: one tenant's visible entries, grouped by product, in
            // order — from the index alone.
            $table->index(['tenant_id', 'is_visible', 'product_id', 'sort_order'], 'faq_entries_render_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faq_entries');
    }
};
