<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custom hostnames pointed at the platform by CNAME (ADR-0010 Option A).
 *
 * `hostname` is globally unique and stored lowercased and punycode-normalised,
 * because "one hostname resolves to at most one tenant" is the entire security
 * property here. Two rows differing only by case or by Unicode form would be
 * two answers to the same question.
 *
 * #13 adds the Caddy `/internal/tls-ask` endpoint that reads this table before
 * issuing a certificate. This issue only needs the table and the lookup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_domains', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // 190 keeps the unique index at 760 bytes under utf8mb4, and no
            // real hostname approaches it.
            $table->string('hostname', 190)->unique('tenant_domains_hostname_unique');
            $table->string('status', 16)->default('pending');

            $table->string('verification_token', 64)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();

            $table->timestamps();

            // The resolution lookup filters on status, so it belongs in the index.
            $table->index(['status', 'hostname'], 'tenant_domains_status_hostname_idx');
            $table->index(['tenant_id', 'status'], 'tenant_domains_tenant_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_domains');
    }
};
