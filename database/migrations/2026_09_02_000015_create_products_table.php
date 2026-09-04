<?php

declare(strict_types=1);

use App\Support\Locale\TranslationColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The catalogue item (`docs/data-model.md` §2.3, spec CAT-4, CAT-5).
 *
 * Item **18** in the §6 order, and it has to come after all five tables it
 * points at: `vessels`, `ports`, `cancellation_policies`, `vat_rates` and
 * `tenants`. §6's rule is absolute — no migration ever adds a foreign key to an
 * existing table, because SQLite cannot — so every one of those had to land
 * first. `vat_rates` needed #47 to exist at all and `cancellation_policies`
 * needed #23; both were built ahead of this issue for exactly that reason.
 *
 * (The filename suffix is `000015` rather than `000018`: ADR-0025 took §6
 * position 9 for `audit_logs`, and the already-committed M1 files keep their
 * old suffixes until #53 renames the set. §6 records the lag.)
 *
 * ## Everything this table will ever need is here
 *
 * §6 lists adding a nullable column with a constant default as the *only*
 * portable later change. A `NOT NULL` column, a unique column, a foreign key or
 * any change to an existing column is a table rebuild on SQLite and a locking
 * `ALTER` on MySQL. `products` is the most-referenced table in the catalogue,
 * so the cost of getting it wrong compounds through `age_bands`, `rate_plans`,
 * `schedule_rules`, `departures` and `bookings`.
 *
 * ## `mode` is immutable once a booking exists
 *
 * Not a database constraint — the database cannot see `bookings` from here, and
 * that table does not exist until M2. It is an application guard, and §2.3
 * explains the stake: a `per_seat` product that became `per_vessel` would
 * invalidate every departure and silently change what every existing price
 * snapshot meant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();

            // The public identifier — this is the id in
            // `[kaiki_booking product="uuid"]`, so it is in operators' page
            // source and cannot be reissued.
            $table->uuid()->unique('products_uuid_unique');

            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // Nullable only for `quote` products with no fixed boat (§2.3).
            // `restrictOnDelete` because a vessel with products pointing at it
            // must not vanish — vessels are soft-deleted anyway, so this bites
            // only on a force delete, which is exactly when it should.
            $table->foreignId('vessel_id')->nullable()->constrained('vessels')->restrictOnDelete();

            // 120 chars = 480 bytes as utf8mb4, comfortably inside the index
            // limit with `tenant_id`. Hosted-page and WordPress permalink.
            $table->string('slug', 120);

            // String columns backed by PHP enums, never a MySQL ENUM (CNV-6):
            // adding a case to a MySQL ENUM is an ALTER on a large table, and
            // SQLite has no ENUM at all.
            $table->string('category', 32);
            $table->string('mode', 16);

            // Translatable (§1.6). `description` is rich text and is sanitised
            // on the way in; the others are plain.
            $table->json('title');
            $table->json('summary')->nullable();
            $table->json('description')->nullable();

            // ≤ 1440. Multi-day trips are out of scope, and a smallint that can
            // hold 65535 would let one be entered silently — the ceiling is in
            // validation, where it can explain itself.
            $table->unsignedSmallInteger('duration_minutes');

            // Tenant-local wall time, not UTC: a 09:00 departure is 09:00 in
            // the operator's own timezone all year, and the UTC instant moves
            // with DST. #26 owns the conversion.
            $table->time('default_start_time')->nullable();

            // `per_vessel` only (CAT-5) — the guest proposes the window.
            $table->boolean('flexible_start')->default(false);
            $table->time('earliest_start_time')->nullable();
            $table->time('latest_start_time')->nullable();

            $table->unsignedSmallInteger('check_in_offset_minutes')->default(30);

            // `nullOnDelete`: a deleted meeting point should not delete the
            // trips that met there, and a null renders as the vessel's home
            // port.
            $table->foreignId('meeting_point_id')->nullable()->constrained('ports')->nullOnDelete();

            // Translatable arrays (§3.5) and the translatable array-of-objects
            // with its `_geo` sidecar (§3.6). Null means "not configured" and
            // hides the section; an empty array means "configured as empty",
            // which is a different statement to a guest reading the page.
            $table->json('includes')->nullable();
            $table->json('excludes')->nullable();
            $table->json('what_to_bring')->nullable();
            $table->json('itinerary_stops')->nullable();

            $table->string('route_map_image_path', 255)->nullable();

            // Ordered `{path, alt:{el,en}}` (§3.15). NOT NULL with no database
            // default — MySQL 8 refuses a literal DEFAULT on JSON and the
            // expression form has no SQLite equivalent (ENV-12), so the default
            // lives on the model, as it does for `vessels.images`.
            $table->json('images');

            // `per_seat` only (CAT-5): the guaranteed-departure threshold.
            // 0 means always guaranteed.
            $table->unsignedSmallInteger('min_pax')->default(0);

            // ≤ `vessels.capacity_max`, validated in the application because a
            // database cannot compare across tables on write.
            $table->unsignedSmallInteger('max_pax');

            $table->unsignedSmallInteger('min_booking_pax')->default(1);

            // `nullOnDelete`, and null means the tenant default (§2.3) — which
            // is safer than deleting products, and is why #23 guarantees a
            // tenant always has exactly one default.
            $table->foreignId('cancellation_policy_id')
                ->nullable()
                ->constrained('cancellation_policies')
                ->nullOnDelete();

            $table->boolean('guest_details_required')->default(false);
            $table->unsignedSmallInteger('guest_details_deadline_hours')->default(48);

            // ADR-0002 Option A: a foreign key, not a percentage. Nullable in
            // M1 so onboarding can proceed (CAT-11b); myDATA activation is
            // separately gated on every sellable product having one (MYD-16).
            // `restrictOnDelete` — a rate in use can never be deleted, which is
            // why superseding one means closing its `valid_to` and adding a row.
            $table->foreignId('vat_rate_id')->nullable()->constrained('vat_rates')->restrictOnDelete();

            $table->string('mydata_income_class', 16)->nullable();

            // Derived (§1.9) for the `list` mount's "from €X". Owned by #33,
            // which resolves rate plans; nullable because it is meaningless
            // until a rate plan exists.
            $table->unsignedInteger('price_from_cents')->nullable();

            $table->string('status', 16)->default('draft');
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->json('meta_title')->nullable();
            $table->json('meta_description')->nullable();
            $table->string('og_image_path', 255)->nullable();

            $table->boolean('is_featured')->default(false);

            // ADR-0008 companion columns, written by SearchIndexObserver. The
            // search haystack is one text column for all locales; sorting is
            // per locale, because ordering *is* a per-language question.
            $table->text(TranslationColumns::SEARCH)->nullable();

            foreach (TranslationColumns::sortColumnsFor(['title']) as $column) {
                $table->string($column, 191)->nullable();
            }

            $table->timestamps();
            $table->softDeletes();

            // `tenant_id` leads every composite index (§1.2): the global scope
            // appends `where tenant_id = ?` to every query, so an index that
            // does not lead with it will not be used.
            //
            // Trashed rows are **included** in the slug unique, matching the
            // vessels decision in #16: `NULL` is distinct from `NULL` in a
            // unique index on both engines, so adding `deleted_at` would stop
            // every live row colliding too and the constraint would enforce
            // nothing. A soft-deleted product keeps its slug reserved, which is
            // right — the slug is in someone's permalink.
            $table->unique(['tenant_id', 'slug'], 'products_tenant_slug_unique');

            // The hottest catalogue read: the `list` mount and GET /products.
            $table->index(['tenant_id', 'status', 'sort_order'], 'products_tenant_status_sort_idx');

            // The vessel calendar, and "which products use this boat" when one
            // is being deleted.
            $table->index(['tenant_id', 'vessel_id'], 'products_tenant_vessel_idx');

            // The `data-category` filtered list mount.
            $table->index(['tenant_id', 'category', 'status'], 'products_tenant_cat_status_idx');

            // Departure generation scans `per_seat` products only.
            $table->index(['tenant_id', 'mode'], 'products_tenant_mode_idx');

            foreach (TranslationColumns::sortColumnsFor(['title']) as $column) {
                $table->index(['tenant_id', $column], "products_tenant_{$column}_idx");
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
