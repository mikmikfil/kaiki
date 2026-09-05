<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * External calendars polled for blocks (`docs/data-model.md` §2.7, spec OPS-15).
 *
 * §6 item 26 — pulled forward from M5 with `ical_feeds`, for the same reason:
 * `vessel_blocks.ical_source_id` needs its target to exist.
 *
 * ## `url_hash` is the canonical §1.7 example
 *
 * The URL is **encrypted** — it frequently contains a private Airbnb or Google
 * token, and a leaked one exposes an operator's whole calendar. An encrypted
 * column cannot be indexed or compared, so uniqueness needs a deterministic
 * hash beside it. Without that, an operator can add the same feed twice and
 * every event arrives as two blocks that the boat has to be free for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ical_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('vessel_id')->constrained('vessels')->cascadeOnDelete();

            $table->string('name', 80);

            // Encrypted at rest (§1.7). `text` rather than `string`, because
            // ciphertext is substantially longer than the URL it came from.
            $table->text('url');

            // SHA-256 of the plaintext URL. See the class docblock.
            $table->char('url_hash', 64);

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sync_interval_minutes')->default(15);

            // `last_synced_at` moves on every attempt and `last_success_at`
            // only on success: an operator needs to see a feed that is being
            // polled and failing, which one column cannot say.
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->string('last_error', 500)->nullable();

            // Auto-deactivates at ten, with a notification. A feed that has
            // been 404ing for a week is one an operator has forgotten, and
            // polling it forever is noise in the logs and load on somebody
            // else's server.
            $table->unsignedTinyInteger('consecutive_failures')->default(0);

            $table->string('etag', 190)->nullable();
            $table->unsignedInteger('events_imported')->default(0);

            $table->timestamps();

            $table->index(['tenant_id', 'vessel_id'], 'ical_sources_tenant_vessel_idx');
            $table->unique(['tenant_id', 'url_hash'], 'ical_sources_url_hash_uq');

            // **Cross-tenant**, deliberately: the poller walks every operator's
            // due feeds in one pass, so the leading column is `is_active`
            // rather than `tenant_id`.
            $table->index(['is_active', 'last_synced_at'], 'ical_sources_sync_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ical_sources');
    }
};
