<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `tenants`, `users` and the framework session/password tables.
 *
 * These live in one migration, and `tenants` is created first, because
 * `users.tenant_id` is a foreign key to it and **SQLite cannot add a foreign
 * key to an existing table** (data-model §0). Every column each table will ever
 * need has to be present the day it is created — including the Cashier columns
 * on `tenants`, which are not used until M7.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36)->unique();

            $table->string('name', 120);
            $table->string('slug', 64)->unique();

            // Legal identity — invoices and the ναυλοσύμφωνο.
            $table->string('legal_name', 180)->nullable();
            $table->string('vat_number', 20)->nullable();   // ΑΦΜ — public company data, not encrypted
            $table->string('tax_office', 60)->nullable();   // ΔΟΥ
            $table->string('gemi_number', 30)->nullable();  // ΓΕΜΗ

            $table->string('address_line1', 180)->nullable();
            $table->string('address_line2', 180)->nullable();
            $table->string('city', 80)->nullable();
            $table->string('postcode', 16)->nullable();
            $table->char('country', 2)->default('GR');
            $table->string('phone', 32)->nullable();
            $table->string('email', 190);

            $table->string('timezone', 64)->default('Europe/Athens');
            $table->char('default_locale', 2)->default('el');
            $table->json('supported_locales');
            $table->char('currency', 3)->default('EUR');

            $table->string('plan', 32)->default('trial');
            $table->string('status', 32)->default('trialing');
            $table->timestamp('trial_ends_at')->nullable();

            $table->string('custom_domain', 190)->nullable()->unique();
            $table->timestamp('custom_domain_verified_at')->nullable();
            $table->boolean('hosted_page_enabled')->default(true);
            $table->boolean('is_sandbox')->default(false);

            // Read on every availability conflict check, so it lives here rather
            // than behind a join (data-model §2.1). A vessel may override it.
            $table->unsignedSmallInteger('turnaround_buffer_minutes')->default(60);
            $table->unsignedSmallInteger('guest_document_retention_days')->default(90);
            $table->boolean('auto_issue_invoice')->default(false);

            $table->json('settings');

            // Cashier (M7). Present now because SQLite cannot add them later.
            $table->string('stripe_id')->nullable()->index();
            $table->string('pm_type')->nullable();
            $table->char('pm_last_four', 4)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('status', 'tenants_status_index');
            $table->index('vat_number', 'tenants_vat_number_index');
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36)->unique();

            // Nullable: null = platform super-admin, non-null = operator staff.
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();

            $table->string('name', 120);
            // 190 keeps the unique index at 760 bytes under utf8mb4.
            $table->string('email', 190)->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('phone', 32)->nullable();
            $table->char('locale', 2)->default('el');
            $table->boolean('is_super_admin')->default(false);
            $table->timestamp('last_login_at')->nullable();

            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();

            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'is_super_admin'], 'users_tenant_role_idx');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
        Schema::dropIfExists('tenants');
    }
};
