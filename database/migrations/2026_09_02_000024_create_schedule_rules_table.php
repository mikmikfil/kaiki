<?php

declare(strict_types=1);

use App\Domain\Availability\Support\WeekdayMask;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What generates departures (`docs/data-model.md` §2.3, spec CAT-14, AVL-52,
 * AVL-55).
 *
 * §6 item 23, after `products` and `vessels`.
 *
 * ## A bitmask rather than a JSON array of days
 *
 * §2.3 states the reason: the generation job filters in PHP anyway, and one
 * small integer column is trivially diffable in the panel — "changed from 62 to
 * 126" is a line in an audit log; two JSON arrays are not.
 *
 * Monday is **bit 0** and Sunday is bit 6, which is ISO-8601's week and the
 * one a Greek operator reads. PHP's own `date('w')` starts on Sunday, so the
 * conversion happens in exactly one place ({@see WeekdayMask})
 * and never at a call site.
 *
 * ## Hard delete, and the departures survive
 *
 * No `deleted_at` (§1.3). Deleting a rule leaves every departure it generated
 * alone, because those may already hold bookings — a cascade here would be a
 * cancelled holiday. §2.3 makes "also cancel future empty departures" a
 * separate, explicit operator action instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();

            // Null inherits `products.vessel_id`. The override exists for the
            // operator who runs the same trip on a second boat on Sundays.
            $table->foreignId('vessel_id')->nullable()->constrained('vessels')->nullOnDelete();

            // Monday = bit 0 … Sunday = bit 6. 127 is daily; 0 is refused by
            // the Action, because a rule that never fires is a rule an operator
            // believes is running.
            $table->unsignedTinyInteger('weekday_mask');

            // Tenant-local, always. The conversion to a UTC instant is #26's
            // single authority (AVL-16) and never happens in this column.
            $table->time('start_time');

            $table->date('valid_from');

            // Inclusive; null is open-ended, and generation still only reaches
            // `generate_days_ahead`.
            $table->date('valid_until')->nullable();

            $table->unsignedSmallInteger('capacity_override')->nullable();
            $table->unsignedSmallInteger('generate_days_ahead')->default(180);

            $table->boolean('is_active')->default(true);

            // The watermark that makes generation incremental rather than a
            // full rescan. #27 owns its semantics; the column ships here so
            // that adding it later is not a table rebuild on SQLite (§6).
            $table->date('last_generated_on')->nullable();

            $table->timestamps();

            // The nightly job's driving query.
            $table->index(['tenant_id', 'is_active', 'valid_from'], 'schedule_rules_tenant_active_idx');

            // Panel listing, and the product deletion guard.
            $table->index(['tenant_id', 'product_id'], 'schedule_rules_tenant_product_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_rules');
    }
};
