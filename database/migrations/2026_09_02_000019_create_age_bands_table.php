<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\SaveAgeBands;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Passenger categories, per product (`docs/data-model.md` §2.3, spec CAT-7, CAT-8).
 *
 * §6 item 19, after `products` because a band is meaningless without one.
 *
 * ## `counts_toward_capacity` is the consequential flag
 *
 * An infant on a parent's lap does not consume a seat, so it does not reduce
 * what the availability engine can sell (AVL-23) — but it **is still a person
 * on the boat** for the legal capacity check, and it is still priced. Those
 * three facts pull in different directions, and this one boolean is where the
 * difference is recorded. Getting it backwards oversells a departure or
 * undersells a boat, and neither is discovered until someone is standing on
 * the quay.
 *
 * ## Ages are inclusive at both ends, and `max_age` is nullable
 *
 * "0–2" and "3–11" are how an operator writes it and how a parent reads it, so
 * both bounds are inclusive and the resolver treats them that way. A null
 * `max_age` means no upper bound, which is what the adult band almost always
 * is — the alternative is picking an arbitrary 120 that eventually excludes
 * somebody.
 *
 * ## The invariants are not here
 *
 * CAT-8's rules — no overlap, exactly one base band, at least one counted band
 * — are **set-level**: none of them can be judged from a single row, so a
 * column constraint cannot express any of them. They live in
 * {@see SaveAgeBands}, which validates a product's
 * whole set atomically.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('age_bands', function (Blueprint $table): void {
            $table->id();

            // The widget posts age-band uuids in its `pax` payload, so this is
            // a public identifier and cannot be reissued.
            $table->uuid()->unique('age_bands_uuid_unique');

            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // Cascades: bands are meaningless without their product, and a
            // historical booking holds the band **snapshot** rather than a live
            // join, so deleting one cannot rewrite what a guest was charged.
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();

            // A stable machine key (`adult`, `child`, `infant`) that appears in
            // `pax_breakdown` snapshots — so a booking from two years ago is
            // still readable without joining a table whose labels have changed.
            $table->string('code', 24);

            $table->json('label');

            // tinyint: 0-255 covers every human age with room to spare, and a
            // smallint here would only invite a typo to pass validation.
            $table->unsignedTinyInteger('min_age')->default(0);
            $table->unsignedTinyInteger('max_age')->nullable();

            $table->boolean('counts_toward_capacity')->default(true);

            $table->string('pricing_mode', 16)->default('multiplier');

            // Basis points of the base band's price: 5000 is exactly half.
            // Nullable because a `fixed` band does not use it; required by
            // validation when the mode is `multiplier`.
            $table->unsignedSmallInteger('price_multiplier_bp')->nullable();

            // Exactly one per product, application-enforced — a partial unique
            // index would express it, and SQLite and MySQL disagree about those
            // (ENV-12 forbids branching on the driver).
            $table->boolean('is_base')->default(false);

            $table->boolean('requires_adult')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();

            // One `adult` band per product, which is also what makes an
            // importer run idempotent: re-importing the same product updates
            // its bands rather than duplicating them.
            $table->unique(['tenant_id', 'product_id', 'code'], 'age_bands_tenant_product_code_uq');

            $table->index(['tenant_id', 'product_id', 'sort_order'], 'age_bands_tenant_product_sort_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('age_bands');
    }
};
