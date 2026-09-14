<?php

declare(strict_types=1);

use App\Domain\Analytics\Support\AnalyticsMetric;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Visits and funnel steps, counted per day (ADR-0032).
 *
 * ## A rollup, and never a log
 *
 * There is no visitor id in this table, no session id, no IP address, no user
 * agent and no row per person. One row is *"this tenant, on this local day, saw
 * this many of this thing"* — and that is the whole of it.
 *
 * Three things follow from that, and they are the reason the shape was chosen
 * before anything was built:
 *
 * - **GDR-12 holds.** Nothing is written to a visitor's browser, so there is
 *   nothing to ask consent for and no banner on an operator's page.
 * - **There is nothing to purge.** GDR-3 and GDR-10 are about personal data
 *   retention; an aggregate has no subject, so it can be kept for ever without
 *   a sweeper nobody would remember to write.
 * - **Growth is bounded by days, not by traffic.** A busy August adds the same
 *   number of rows as a quiet one.
 *
 * ## Why `dimension_value` is `''` rather than null
 *
 * It is in the unique index, and MySQL treats every null in a unique index as
 * distinct — so a nullable column would let the same metric be inserted a
 * second time for the same day rather than incrementing, and the count would
 * silently split in two. An empty string is a value, and a value compares.
 *
 * {@see AnalyticsMetric} is the list of what may be counted, and the server
 * refuses anything else: this table is written by a **public** endpoint, and a
 * public endpoint that accepts an arbitrary string as a column value is an
 * invitation to fill somebody else's disk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_daily', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // The tenant's own local day, the same calendar every other figure
            // on the statistics page is bucketed by.
            $table->date('date');

            // `App\Domain\Analytics\Support\AnalyticsMetric`, a string column
            // backed by a PHP enum — never a database enum (ADR-0015).
            $table->string('metric', 32);

            // What the count is broken down by, if anything: a product's uuid,
            // an error code. Empty when the metric is counted whole.
            $table->string('dimension', 32)->default('');
            $table->string('dimension_value', 64)->default('');

            $table->unsignedInteger('count')->default(0);

            // For a metric that carries money — a confirmed booking's value —
            // so the funnel can be read in euros as well as in people.
            $table->unsignedBigInteger('value_cents')->default(0);

            $table->timestamps();

            // The upsert target. Everything about this table's correctness
            // rests on it: without it two workers counting the same second
            // write two rows instead of one count.
            $table->unique(['tenant_id', 'date', 'metric', 'dimension', 'dimension_value'], 'analytics_daily_point_uq');

            // The read: one tenant's days in order, for a range.
            $table->index(['tenant_id', 'date'], 'analytics_daily_range_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_daily');
    }
};
