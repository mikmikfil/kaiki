<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The refund ladder (`docs/data-model.md` §2.3, spec CXL-3).
 *
 * **A table rather than a JSON column, and the document argues the case.** The
 * brief wrote `tiers[]` as JSON. Tiers are edited row by row in a repeater,
 * sorted, validated for duplicates — and snapshotted onto the booking as JSON
 * anyway. Keeping the live version relational buys ordering, per-tier
 * validation and a sane form; the immutable copy on the booking is JSON. Both,
 * for the price of one small table.
 *
 * ## `days_before` is a threshold, not a bucket
 *
 * A tier says "cancel at least N days before departure and get this
 * percentage". Evaluation takes the tier with the **largest `days_before` less
 * than or equal to** the days actually remaining, and refunds 0% when none
 * qualifies. Two tiers at the same threshold would make that ambiguous, which
 * is what the unique index refuses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cancellation_policy_tiers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // Cascades: a policy's ladder has no meaning without the policy, and
            // the snapshot on any affected booking already holds its own copy.
            $table->foreignId('cancellation_policy_id')
                ->constrained('cancellation_policies')
                ->cascadeOnDelete();

            $table->unsignedSmallInteger('days_before');
            $table->unsignedTinyInteger('refund_percent');

            $table->timestamps();

            // One rule per threshold. `tenant_id` leads both indexes because
            // every query is already scoped by it (§1.2).
            $table->unique(
                ['tenant_id', 'cancellation_policy_id', 'days_before'],
                'cxl_tiers_policy_days_uq',
            );

            // Evaluation order. The DESC is advisory on SQLite, which ignores
            // the direction, and real on MySQL — the ladder is read largest
            // threshold first either way.
            $table->index(
                ['tenant_id', 'cancellation_policy_id', 'days_before'],
                'cxl_tiers_policy_days_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cancellation_policy_tiers');
    }
};
