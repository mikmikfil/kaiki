<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per invoice series per year, and the row a number is taken from
 * (spec MYD-4, ADR-0022 Option A, `docs/data-model.md` §4.6 notes).
 *
 * ## Why a counter table rather than `MAX(number) + 1`
 *
 * `MAX(number) + 1` reads the `invoices` table, and two requests reading it at
 * the same instant both see the same maximum. The window is small and it is a
 * **legal sequence**: two documents sharing a number is not a bug an operator
 * reports, it is a bug their accountant finds in an audit.
 *
 * A counter row can be locked. **[LOCK]** — `lockForUpdate()` on this row inside
 * the allocation transaction, the same portable pattern as ADR-0006's seat
 * allocation. On SQLite that lock is a no-op (§0), which is exactly why
 * `invoices_tenant_series_num_uq` exists and why the allocator retries on
 * violation rather than trusting the lock to have worked.
 *
 * ## The scope is the triple, and the year resets
 *
 * Per (`tenant_id`, `series`, `year`), resetting each calendar year, because
 * that is how a Greek invoicing series is numbered. A missing row means the
 * series has issued nothing this year and the first allocation creates it at 1 —
 * so nothing has to be seeded when the year turns, and an operator who starts
 * mid-year does not begin at a number their books cannot explain.
 *
 * `last_allocated_at` is not used by the allocator. It is here for the question
 * an accountant asks — *when did this series last move* — which is otherwise
 * only answerable by scanning invoices, including the ones that never sent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('series_counters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('series', 16);
            $table->unsignedSmallInteger('year');

            // The last number handed out. The next allocation takes this plus
            // one; a fresh row starts at zero so the first document is 1.
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamp('last_allocated_at')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'series', 'year'], 'series_counters_tenant_series_year_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('series_counters');
    }
};
