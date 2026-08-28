<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public API credentials (data-model §2.1).
 *
 * The raw key is never stored. `prefix` is the plaintext lookup handle,
 * `secret_hash` is a SHA-256 of the whole key, and `last_four` exists only so
 * the panel can show an operator which key a row refers to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $table->string('name', 80);
            $table->string('type', 16);
            $table->string('environment', 8)->default('live');

            // The lookup handle: plaintext, e.g. `pk_live_a1b2c3`. 20 chars = 80
            // bytes, comfortably inside the utf8mb4 index limit.
            $table->string('prefix', 20)->unique('api_keys_prefix_unique');
            // SHA-256 hex of the full key. Unique as defence in depth: two keys
            // hashing the same would mean the generator is broken.
            $table->char('secret_hash', 64)->unique('api_keys_secret_hash_unique');
            $table->char('last_four', 4);

            $table->json('scopes');
            $table->json('allowed_origins');

            // Throttled to at most one write per key per minute, so a busy
            // widget does not turn every read into a write.
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            // Revocation is a column, never a delete: a revoked key must stay
            // auditable and its prefix must never be reissued.
            $table->timestamp('revoked_at')->nullable();

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'type', 'revoked_at'], 'api_keys_tenant_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
