<?php

declare(strict_types=1);

use App\Support\Locale\TranslationColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The bookable resource (`docs/data-model.md` §2.3, spec CAT-1, CAT-2).
 *
 * Item **11** in the §6 order, immediately after `ports`, because
 * `home_port_id` is a foreign key to it.
 *
 * Everything a vessel will ever need is here on day one. `products`,
 * `schedule_rules`, `departures` and `vessel_blocks` all point at this table,
 * and SQLite cannot add a foreign key — or cheaply alter a column — after the
 * fact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vessels', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // A proper noun, and deliberately **not** translatable (§1.6):
            // "Οδυσσέας" is the boat's name, not a phrase to be rendered into
            // English. It still needs folding to sort and search portably —
            // see `name_sort` below.
            $table->string('name', 120);

            // String plus PHP enum, never a MySQL ENUM (§1.8): SQLite has none,
            // so the two environments would enforce different constraints and a
            // bad value would pass locally and fail on deploy.
            $table->string('type', 32);

            // ΑΛΣ registration. Appears on the manifest and the ναυλοσύμφωνο,
            // and is nullable because an operator may not have it to hand while
            // setting the boat up.
            $table->string('registration_number', 40)->nullable();

            // Centimetres, integer (13.5 m = 1350). Money is not the only thing
            // that must not be a float; a length that renders as 13.499999 on a
            // spec sheet is just as wrong.
            $table->unsignedSmallInteger('length_cm')->nullable();

            // The **legal** ceiling. Every product's `max_pax` and every
            // departure's `capacity` must be at or below it — a constraint the
            // database cannot express, so `GuardVesselCapacity` enforces it on
            // the way down.
            $table->unsignedSmallInteger('capacity_max');
            $table->unsignedTinyInteger('crew_count')->default(1);
            $table->string('captain_name', 120)->nullable();

            // A deleted port must not delete boats: an operator tidying up their
            // marina list would otherwise lose their fleet.
            $table->foreignId('home_port_id')->nullable()->constrained('ports')->nullOnDelete();

            // **Null means inherit `tenants.turnaround_buffer_minutes`** (AVL-7),
            // resolved by Vessel::effectiveTurnaroundBufferMinutes(). Nullable
            // rather than defaulted per vessel on purpose: with a copied default,
            // changing the tenant setting would silently stop changing anything.
            $table->unsignedSmallInteger('turnaround_buffer_minutes')->nullable();

            // Translatable (§1.6).
            $table->json('description')->nullable();

            // §3.9 — free-form but validated against a known key list, so the
            // product page renders a consistent spec sheet.
            $table->json('specs');

            // §3.15 — ordered array of `{path, alt: {el, en}, sort}`. Paths, not
            // a media table (ADR-0021 Option A).
            $table->json('images');

            $table->string('status', 16)->default('active');
            $table->unsignedSmallInteger('sort_order')->default(0);

            // ADR-0008 companions. Here the haystack is fed by both a plain
            // column (`name`) and a translatable one (`description`) — an
            // operator searching for a boat should not have to know which.
            $table->text(TranslationColumns::SEARCH)->nullable();

            // One column, not one per locale: `name` is not translatable, so a
            // `name_sort_el`/`name_sort_en` pair would always hold identical
            // bytes. It exists because ordering a raw Greek varchar is not
            // portable — MySQL's utf8mb4_unicode_ci folds tonos, SQLite's
            // BINARY does not, and the two produce different orders.
            $table->string(TranslationColumns::foldedSort('name'), 191)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status', 'sort_order'], 'vessels_tenant_status_idx');
            $table->index(['tenant_id', 'home_port_id'], 'vessels_tenant_home_port_idx');
            $table->index(['tenant_id', TranslationColumns::foldedSort('name')], 'vessels_tenant_name_sort_idx');

            // Spec TEN-6 names `vessels.name` among the values whose uniqueness
            // is per-tenant and enforced by a composite unique index. §2.3's
            // index table omitted it; TEN-6 is the more specific statement, so
            // the index is here and §2.3 is amended in the same PR.
            //
            // **`deleted_at` is deliberately not part of the key.** Adding it
            // looks like it would let a retired boat's name be reused, but it
            // does the opposite: NULL is distinct from NULL in a unique index
            // on both MySQL and SQLite, so every live row — all of which have a
            // null `deleted_at` — would stop colliding too, and the constraint
            // would silently enforce nothing at all.
            //
            // So a soft-deleted vessel keeps its name reserved, exactly as
            // `products_tenant_slug_unique` reserves a soft-deleted product's
            // slug (§7.6). That is also the safer behaviour: a soft-deleted
            // vessel can be restored, and restoring it into a name collision
            // would be a worse failure than refusing the duplicate up front.
            // Freeing the name is a force-delete, which is the owner's call.
            //
            // 8 + (120 × 4) = 488 bytes, well inside MySQL's 3072 (§7.6).
            $table->unique(['tenant_id', 'name'], 'vessels_tenant_name_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vessels');
    }
};
