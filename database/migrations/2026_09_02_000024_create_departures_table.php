<?php

declare(strict_types=1);

use App\Domain\Availability\LocalDateTimeResolver;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The `per_seat` sellable instance — the hottest table in the system
 * (`docs/data-model.md` §2.4, spec AVL-13 to AVL-21, CNV-2, CNV-3).
 *
 * §6 item 24, after `products`, `vessels` and `schedule_rules`.
 *
 * ## Three columns for one moment, and why that is not redundancy
 *
 * `local_date`, `local_time` and `starts_at_utc` all describe the same
 * departure. CNV-3 requires them to always agree, and
 * {@see LocalDateTimeResolver} is the only thing
 * allowed to make them. The UTC column is what every interval comparison uses
 * (AVL-13); the local pair is what an operator filters by and what "every
 * Tuesday at 10:00" means to them — a query that had to convert would be
 * unindexable across a DST boundary.
 *
 * ## The unique index is on the UTC instant, deliberately
 *
 * `departures_tenant_prod_start_uq` is `(tenant_id, product_id, starts_at_utc)`
 * and it is what makes generation idempotent — re-running the nightly job can
 * never duplicate a departure. It uses `starts_at_utc` rather than
 * `local_date + local_time` **so the October DST repeat cannot collide**: on the
 * fall-back date the same local time happens twice, and a unique index on the
 * local pair would refuse the second one as a duplicate of the first.
 *
 * ## `seats_held` is disjoint from `seats_sold`, and additive
 *
 * §2.4: `available = capacity − seats_sold − seats_held` (AVL-22.3, AVL-24).
 * Keeping holds out of `seats_sold` is what stops an unpaid draft flipping a
 * departure to `guaranteed` and emailing every guest that the trip is
 * confirmed, and what keeps it visible on the at-risk dashboard.
 *
 * No soft deletes: cancellation is a status, and bookings must keep resolving
 * their `departure_id` to render a guest's history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departures', function (Blueprint $table): void {
            $table->id();

            // The widget books against this, so it is public and not the id.
            $table->uuid()->unique('departures_uuid_unique');

            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // `restrictOnDelete` on both: a product or a boat with open
            // departures is not something to delete by accident, and the
            // failure would be a guest holding a ticket to nothing.
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();

            // Snapshotted at generation, so reassigning a product's boat does
            // not silently move departures people have already booked.
            $table->foreignId('vessel_id')->constrained('vessels')->restrictOnDelete();

            // Null = a manual one-off departure (#28).
            $table->foreignId('schedule_rule_id')->nullable()->constrained('schedule_rules')->nullOnDelete();

            $table->date('local_date');
            $table->time('local_time');
            $table->timestamp('starts_at_utc');
            $table->timestamp('ends_at_utc');

            // True when this local time happened twice (ADR-0016). The panel
            // badges it, because "03:30" on that date is genuinely two moments
            // and the operator should know which one was chosen.
            $table->boolean('dst_ambiguous')->default(false);

            $table->unsignedSmallInteger('capacity');
            $table->unsignedSmallInteger('min_pax')->default(0);

            // Both derived (§1.9) and both mutated only under a row lock in M2.
            $table->unsignedSmallInteger('seats_sold')->default(0);
            $table->unsignedSmallInteger('seats_held')->default(0);

            $table->string('status', 16)->default('scheduled');
            $table->string('cancel_reason', 32)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancellation_note', 500)->nullable();

            // A cache of a range query over `vessel_blocks`, not a source of
            // truth — #29 owns its recomputation, and the write path re-checks.
            $table->boolean('is_blocked')->default(false);

            $table->string('notes', 1000)->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            // Idempotent generation. On the UTC instant — see the class doc.
            $table->unique(['tenant_id', 'product_id', 'starts_at_utc'], 'departures_tenant_prod_start_uq');

            // The availability query (§7.1): product X between dates A and B.
            $table->index(['tenant_id', 'product_id', 'local_date', 'status'], 'departures_avail_idx');

            // Vessel-window conflict detection (§7.2): is this boat busy?
            $table->index(['tenant_id', 'vessel_id', 'starts_at_utc', 'ends_at_utc'], 'departures_vessel_window_idx');

            // The at-risk dashboard (§7.3). `seats_sold < min_pax` is filtered
            // on the small result set: an inequality between two columns is not
            // sargable, and generated columns are not portable (ENV-12).
            $table->index(['tenant_id', 'status', 'starts_at_utc'], 'departures_at_risk_idx');

            // "Today / tomorrow" and the crew check-in screen, across products.
            $table->index(['tenant_id', 'local_date', 'status'], 'departures_tenant_date_idx');

            // Rule edits, and "cancel future empty departures".
            $table->index(['tenant_id', 'schedule_rule_id'], 'departures_schedule_rule_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departures');
    }
};
