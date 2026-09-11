<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The platform's announcement banner (spec SAA-1).
 *
 * The platform owner writes one short message — «Συντήρηση την Κυριακή 02:00–03:00»
 * — and it shows at the top of every page of every operator's panel until it
 * ends, is switched off, or the person reading it closes it.
 *
 * ## Two new tables, and nothing added to an existing one
 *
 * Dismissal is per person, so it needed somewhere to live. A column on `users`
 * would have been a second migration against an existing table, and a foreign
 * key there is exactly what data-model §6 forbids on SQLite. A table of its own
 * costs nothing and lets both foreign keys exist from the first migration.
 *
 * ## Platform-owned, like `vat_rates`
 *
 * No `tenant_id`: one announcement is for every operator at once. Named in
 * `config/tenancy.php`'s `platform_owned_models`, because TEN-5 allows no third
 * state between tenant-owned and explicitly platform-owned.
 *
 * `severity` is a string backed by `AnnouncementSeverity`, never a MySQL `ENUM`
 * (CLAUDE.md). `message` is a translatable JSON column (ADR-0008) with no
 * search or sort companion: nobody searches a list of a handful of notices.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_announcements', function (Blueprint $table): void {
            $table->id();
            $table->json('message');
            $table->string('severity', 16)->default('info');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // The one query that runs on every operator page: active, started,
            // newest first.
            $table->index(['is_active', 'starts_at'], 'platform_announcements_current_idx');
        });

        Schema::create('platform_announcement_dismissals', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('platform_announcement_id');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('dismissed_at');

            // Named by hand: Laravel's generated name for this key is 67
            // characters, and MySQL refuses identifiers longer than 64.
            $table->foreign('platform_announcement_id', 'pa_dismissals_announcement_fk')
                ->references('id')
                ->on('platform_announcements')
                ->cascadeOnDelete();

            // One row per person per announcement, so a double click is a no-op
            // rather than a second row.
            $table->unique(['platform_announcement_id', 'user_id'], 'pa_dismissals_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_announcement_dismissals');
        Schema::dropIfExists('platform_announcements');
    }
};
