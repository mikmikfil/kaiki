<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The token-authenticated iCal export URL, one per vessel
 * (`docs/data-model.md` §2.7, spec OPS-13).
 *
 * §6 item 25 — **pulled forward from M5** so that `vessel_blocks.ical_source_id`
 * has something to point at. The sync code itself stays in M5; only the tables
 * move, because §6 forbids adding a foreign key to an existing table and SQLite
 * cannot do it at all.
 *
 * ## The URL is the credential
 *
 * An operator pastes this into Google Calendar, which will not send a header.
 * So the token *is* the authentication, and two consequences follow into the
 * schema: it is globally unique rather than unique per tenant, and
 * `include_guest_names` defaults to **false** — an unauthenticated URL must not
 * leak a passenger list unless the operator has decided it should.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ical_feeds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('vessel_id')->constrained('vessels')->cascadeOnDelete();

            // Globally unique, not per tenant: the URL carries no other
            // identifier, so a collision across operators would hand one
            // tenant's calendar to another.
            $table->char('token', 40)->unique('ical_feeds_token_unique');

            $table->boolean('include_departures')->default(true);
            $table->boolean('include_blocks')->default(true);

            // Off by default. See the class docblock.
            $table->boolean('include_guest_names')->default(false);

            $table->boolean('is_active')->default(true);

            // Throttled write (the same shape as `api_keys.last_used_at`), so
            // an aggressive calendar client cannot turn every read into a write.
            $table->timestamp('last_accessed_at')->nullable();
            $table->unsignedInteger('access_count')->default(0);

            $table->timestamps();

            // One feed per vessel; rotating the token replaces it in place
            // rather than leaving the old URL working.
            $table->unique(['tenant_id', 'vessel_id'], 'ical_feeds_tenant_vessel_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ical_feeds');
    }
};
