<?php

declare(strict_types=1);

use App\Support\Locale\TranslationColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The operator's cancellation terms (`docs/data-model.md` §2.3, spec CAT-13, CXL-3).
 *
 * Item **13** in the §6 order, and it has to precede `products` (17): a product
 * holds `cancellation_policy_id`, and §6's rule is that no migration ever adds
 * a foreign key to an existing table. Landing this after `products` would cost
 * a table rebuild on SQLite, permanently.
 *
 * ## What this table is *not* used for
 *
 * **Refunds are never computed from these rows.** CXL-1 is fixed: the refund
 * comes from `bookings.policy_snapshot`, frozen when the guest paid, so that
 * editing a policy cannot change money owed on a booking already taken. These
 * rows feed the snapshot at booking time and the policy text a guest reads
 * while browsing — nothing else.
 *
 * That is also why `weather_refund_percent` and `force_majeure_voucher_months`
 * live here rather than on the tenant: they travel into the snapshot with the
 * rest of the policy, so a cancellation eighteen months later still knows the
 * voucher term it promised.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cancellation_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // Translatable (§1.6). The guest reads both before paying, so
            // neither is an operator-only label.
            $table->json('name');
            $table->json('summary')->nullable();

            // Nullable means "no free-cancellation window at all", which is a
            // real policy — not the same as zero hours, which would mean free
            // cancellation right up to departure.
            $table->unsignedSmallInteger('free_cancellation_hours')->nullable();

            // Percentages as whole numbers 0-100. tinyint holds 0-255, so the
            // range is enforced in validation; a column that cannot express a
            // rejected value turns a typo into a truncation.
            $table->unsignedTinyInteger('weather_refund_percent')->default(100);
            $table->unsignedTinyInteger('force_majeure_voucher_months')->default(18);
            $table->unsignedTinyInteger('no_show_refund_percent')->default(0);

            // Exactly one per tenant, enforced by the application rather than
            // by a partial unique index — SQLite and MySQL disagree about those,
            // and ENV-12 forbids branching on the driver. `SaveCancellationPolicy`
            // demotes the previous default in the same transaction.
            $table->boolean('is_default')->default(false);

            // The ADR-0008 companion columns, written by `SearchIndexObserver`.
            // A tenant has a handful of policies, so this is not about scale —
            // it is that `name` is Greek text an operator sorts and searches by,
            // and MySQL folds tonos while SQLite does not. Without these the two
            // engines return different orders for the same list.
            //
            // Taking the trait also brings §1.6's both-locales rule with it,
            // which is the point: a policy named in Greek and blank in English
            // is a booking page with a blank where the terms should be, and the
            // importer has no form to stop it.
            $table->text(TranslationColumns::SEARCH)->nullable();

            foreach (TranslationColumns::sortColumnsFor(['name']) as $column) {
                $table->string($column, 191)->nullable();
            }

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'is_default'], 'cxl_policies_tenant_default_idx');

            foreach (TranslationColumns::sortColumnsFor(['name']) as $column) {
                $table->index(['tenant_id', $column], "cxl_policies_tenant_{$column}_idx");
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cancellation_policies');
    }
};
