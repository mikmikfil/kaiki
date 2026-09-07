<?php

declare(strict_types=1);

use App\Models\Faq;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The operator's frequently asked questions.
 *
 * Added by the design review of 4 September, alongside `home_page_blocks`. The
 * questions an operator answers on the telephone all day — "do I need to know
 * how to swim", "what happens if it rains", "where exactly do we meet" — are
 * answered once here and read by every guest who would otherwise have rung.
 *
 * ## `product_id` is nullable, and that is the whole data model
 *
 * Most answers are about the **operator**: how to find the harbour, what to
 * bring, whether children are welcome. A few are about **one trip**: this
 * particular cruise stops for a swim and that one does not. So an entry belongs
 * to the tenant, and may additionally name a product.
 *
 * The tempting shape is a `faq_product` pivot, which sounds more flexible and
 * is worse: it makes the common case the awkward one, because every general
 * answer then has to be attached to every trip the operator owns, and stays
 * wrong the day they add their sixth boat. Tenant-wide by default,
 * product-specific by exception, matches how the questions actually arrive.
 *
 * `cascadeOnDelete` on the product: an answer about a trip that no longer
 * exists is an answer nobody can ask about. The tenant-wide entries are
 * untouched, because their `product_id` is null.
 *
 * ## No search companions (ADR-0008)
 *
 * `question` and `answer` are translatable JSON, and ADR-0008's companion
 * columns exist so that a query can sort or filter without touching a JSON
 * path. Nothing sorts or filters these: the panel table orders by `sort_order`,
 * which is an ordinary integer column, and the realistic list is eight rows
 * long. Adding a `search_index` here would be three columns and an observer for
 * a search box no operator would use. {@see Faq} says the same thing where
 * somebody would look for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faqs', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // Null means "about the operator", which is the common case. See
            // the class docblock for why this is not a pivot.
            $table->foreignId('product_id')->nullable()->constrained('products')->cascadeOnDelete();

            // Translatable (§1.6). Both required at the form rather than by the
            // observer — see {@see Faq} — and neither is nullable, because an
            // FAQ entry with no question is not an entry.
            $table->json('question');
            $table->json('answer');

            // The operator's own order. The most-asked question is rarely the
            // first one written, and a list ordered by creation date is a list
            // the operator cannot fix without deleting and retyping.
            $table->unsignedInteger('sort_order')->default(0);

            // Unpublished rows stay editable in the panel and appear on no
            // guest surface. Hidden rather than deleted, for the reason
            // `home_page_blocks.is_visible` gives: an answer taken down for the
            // season should not have to be retyped when it comes back.
            $table->boolean('is_published')->default(true);

            $table->timestamps();

            // The read this table exists for: one tenant's published entries,
            // in their order, with the product-specific ones separable. Every
            // guest-facing query is `tenant_id` + `is_published` + a
            // `product_id` that is either null or one value, so the index
            // answers all of them.
            $table->index(['tenant_id', 'is_published', 'product_id', 'sort_order'], 'faqs_render_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faqs');
    }
};
