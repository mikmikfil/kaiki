<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anything that occupies a boat and is not a departure
 * (`docs/data-model.md` §2.4, spec AVL-3, AVL-4, AVL-35).
 *
 * §6 item 27, after `ical_sources` — which is why those two tables were pulled
 * forward from M5.
 *
 * ## `booking_id` has no foreign key, on purpose
 *
 * §6's rule is absolute: no migration ever adds a foreign key to an existing
 * table, because SQLite cannot. `vessel_blocks` ships in M1 and `bookings` not
 * until M2, so a real FK is impossible in either direction without rebuilding a
 * table later. It is a plain indexed `unsignedBigInteger`, integrity is
 * application-enforced — cancelling a booking deletes its block explicitly —
 * and the nightly reconciler flags orphans.
 *
 * That is a documented weakening, not an oversight, and it is written here so
 * the next reader does not "fix" it into a constraint that cannot be added.
 *
 * ## An all-day block still stores a window
 *
 * §2.4: *"the window is still stored as 00:00 → 23:59:59 local so overlap maths
 * never special-cases."* The alternative — a null window plus an `is_all_day`
 * flag — puts a branch inside every overlap test in the product, and the branch
 * that gets forgotten is the one on the 25-hour October day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vessel_blocks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // Cascade: a force-deleted vessel's blocks are meaningless.
            $table->foreignId('vessel_id')->constrained('vessels')->cascadeOnDelete();

            // Authoritative for overlap maths (AVL-13). The local pair below is
            // for display and for "which day is this on" filtering.
            $table->timestamp('starts_at_utc');
            $table->timestamp('ends_at_utc');

            $table->date('local_date');
            $table->date('local_end_date');

            $table->boolean('is_all_day')->default(false);

            $table->string('reason', 32);

            // No FK. See the class docblock.
            $table->unsignedBigInteger('booking_id')->nullable();

            $table->foreignId('ical_source_id')->nullable()->constrained('ical_sources')->cascadeOnDelete();

            // The iCal `UID`. What makes re-polling a feed idempotent.
            $table->string('external_uid', 190)->nullable();

            $table->string('title', 190)->nullable();
            $table->string('notes', 500)->nullable();

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // The conflict query (§7.2). Every availability check in both modes
            // hits this one.
            $table->index(['tenant_id', 'vessel_id', 'starts_at_utc', 'ends_at_utc'], 'vblocks_vessel_window_idx');

            // Idempotent iCal sync: re-polling updates rather than duplicates.
            // 190 chars = 760 bytes, plus two bigints = 776, inside the 3072
            // byte key limit on utf8mb4.
            $table->unique(['tenant_id', 'ical_source_id', 'external_uid'], 'vblocks_source_uid_uq');

            // "Show me the block this charter created", and cleanup on
            // cancellation — the query that stands in for the missing FK.
            $table->index(['tenant_id', 'booking_id'], 'vblocks_booking_idx');

            $table->index(['tenant_id', 'local_date'], 'vblocks_tenant_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vessel_blocks');
    }
};
