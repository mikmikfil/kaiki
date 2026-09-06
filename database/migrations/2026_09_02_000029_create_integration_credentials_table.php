<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every per-operator secret the platform stores (`docs/data-model.md` §2.7).
 *
 * **Item 29, and first in M2**, because `payments` (35) and the gateway
 * contract both read from it and §6's rule is absolute: no migration ever adds
 * a foreign key to an existing table.
 *
 * ## One table, not four
 *
 * The brief scatters credentials across §3 (gateways), §10 (myDATA) and §2
 * (SMS/email). They share one shape — per tenant, per provider, encrypted,
 * live or test, verifiable — and the alternative is four column groups on
 * `tenants`, which on SQLite is four table rebuilds (§0).
 *
 * ## `payment_gateway_accounts` is this table under its old name
 *
 * ADR-0004 Option A named it `payment_gateway_accounts` with a `gateway`
 * discriminator and a `mode` of `live` | `sandbox`. Its *decision* — encrypted
 * cast columns, no external secret store, no per-tenant key separation, one row
 * per tenant per provider per environment — is honoured here completely; only
 * the illustrative name and vocabulary are reconciled, and `docs/spec.md` PAY-4
 * was rewritten in the same commit with the reason recorded in `CHANGELOG.md`
 * (`docs/api.md` §10 item 5). `sandbox` became `test` so that this column and
 * `api_keys.environment` are one vocabulary; PAY-11 and SAA-9 still say
 * "sandbox mode" in prose, which is a mode and not a column value.
 *
 * ## Why `external_account_id` is a plain column and not a JSON key
 *
 * `gateway_webhook_events.tenant_id` is nullable because a webhook arrives
 * before the tenant is resolved (§2.7) — and this table is what resolves it.
 * That lookup is by provider plus the provider's own account identifier, with
 * no tenant in context, which makes it a filter; ENV-8 forbids filtering on a
 * JSON path, so it cannot live inside `public_config`. Same reasoning as
 * `ical_sources.url_hash`: a value you must look rows up by is a plain indexed
 * column, whatever else it travels with.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_credentials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // PHP enums, never a MySQL ENUM: `IntegrationProvider` covers the
            // gateways, myDATA, the three SMS vendors and Postmark;
            // `CredentialEnvironment` is `live` | `test`.
            $table->string('provider', 24);
            $table->string('environment', 8)->default('live');

            // PAY-3: `encrypted:array`. `text`, not `json` — ciphertext is not
            // JSON, and a `json` column would make MySQL reject the write.
            $table->text('credentials');

            // The non-secret half, deliberately queryable: a Viva source code,
            // an SMS sender name, a from-address. Nothing here is a credential,
            // and `NoCredentialLeakTest` is what keeps that true.
            $table->json('public_config');

            // The provider's own account identifier where it issues one — a
            // Viva merchant id, a Stripe `acct_…`. Not a secret; it appears in
            // the webhook body, which is the whole reason it is stored.
            $table->string('external_account_id', 190)->nullable();

            // Exactly one `true` per tenant per environment among the payment
            // providers, enforced by the application (§2.7). A partial unique
            // index would be the database's job to do it, and partial indexes
            // are not portable to MySQL 8.
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);

            $table->timestamp('verified_at')->nullable();
            // Truncated on write. A gateway that returns a wall of HTML must not
            // be able to decide how wide this column has to be.
            $table->string('last_error', 500)->nullable();

            // Encrypted for the same reason as `credentials`: an inbound
            // signature secret is what proves a webhook is genuine, so a leaked
            // one lets anyone mark a booking paid.
            $table->text('webhook_secret')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'provider', 'environment'], 'integr_creds_tenant_prov_env_uq');
            $table->index(['tenant_id', 'is_active', 'is_default'], 'integr_creds_tenant_active_idx');
            // **Not tenant-first**, on purpose — this is the index the webhook
            // resolver reads, and it has no tenant yet.
            $table->index(['provider', 'external_account_id'], 'integr_creds_provider_account_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_credentials');
    }
};
