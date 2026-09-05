<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a season runs (`docs/data-model.md` §2.3, spec CAT-9, PRC-3).
 *
 * **A table rather than a JSON array on `seasons`, and the document argues the
 * case:** the pricing resolver asks *"which season contains this date, highest
 * priority first"* on **every price quote**, and that query has to be indexed.
 * A JSON array cannot be.
 *
 * ## `date`, not a timestamp
 *
 * A season is a calendar concept — "1 June to 15 September" — and both bounds
 * are **inclusive**, because that is how an operator writes it and how a guest
 * reads it. Storing it as a UTC timestamp would move its edges by three hours
 * and put a 1 June departure in the previous season for anyone booking before
 * 03:00 local.
 *
 * This is the same reasoning as `departures.local_date` and the opposite of
 * `starts_at_utc`: an instant is stored in UTC, a calendar date is not an
 * instant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('season_date_ranges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // Cascades: a range has no meaning without its season, and a price
            // already quoted carries its own snapshot.
            $table->foreignId('season_id')->constrained('seasons')->cascadeOnDelete();

            $table->date('starts_on');
            $table->date('ends_on');

            $table->timestamps();

            // Serves the resolver's `where starts_on <= ? and ends_on >= ?`,
            // with `tenant_id` leading because the global scope adds it to
            // every query (§1.2).
            $table->index(['tenant_id', 'starts_on', 'ends_on'], 'season_ranges_tenant_dates_idx');

            $table->index(['tenant_id', 'season_id'], 'season_ranges_season_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('season_date_ranges');
    }
};
