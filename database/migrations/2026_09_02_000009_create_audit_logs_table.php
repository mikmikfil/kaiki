<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The operator's own audit trail (ADR-0025 Option A, spec SEC-16;
 * `docs/data-model.md` §2.8).
 *
 * Item **9** in the §6 order — first in M1, ahead of every table whose resource
 * ships a destructive action. It holds foreign keys only to `tenants` and
 * `users`, both M0, so it could technically sit anywhere; it is first because
 * the ADR's own consequence is that the trail exists *"before any further
 * resource ships a destructive action"*, and a position in the order is how
 * that stops being an intention.
 *
 * ## `subject_type` / `subject_id` carry no foreign key, on purpose
 *
 * The `vessel_blocks.booking_id` precedent. An audit row must **outlive its
 * subject**: the subject may be soft-deleted (which is the common case — half
 * these rows are about deletions), force-deleted by a super-admin, or from a
 * table that does not exist until M2. A foreign key would either refuse the
 * delete the row exists to record, or cascade away the record of it.
 *
 * ## `created_at` and no `updated_at`
 *
 * Append-only is the whole point, and a column that exists invites an update
 * that would be a lie. The model refuses writes as well — a schema that merely
 * lacks the column is a convention, and ADR-0025 asks for the property.
 *
 * ## `user_id` is nullable and `nullOnDelete`
 *
 * Two reasons, and both are in the ADR. A **system action** has no actor —
 * a nightly purge, a webhook, a scheduled cancellation. And a force-deleted
 * user must not take the trail with them: the row keeps its timestamp, its
 * action and its causality, and simply stops identifying a person. That is
 * also how the seven-year retention (§3, ADR-0012's 90 days notwithstanding)
 * survives a GDPR erasure request — the actor was never a name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // A string column backed by `App\Enums\AuditAction`, never a MySQL
            // ENUM (CNV-6): this list grows every time a milestone adds a
            // destructive action, and an ENUM would make each one an ALTER.
            $table->string('action', 48);

            // The morph pair, deliberately without `morphs()`: that helper adds
            // its own index name and we want the composite below to lead with
            // `tenant_id`, which the global scope appends to every query.
            $table->string('subject_type', 96)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            // What the subject was called at the time. Denormalised on purpose:
            // the subject is usually gone by the time anybody reads this, and
            // "Vessel #418" is not an answer to "which boat did we delete".
            $table->string('subject_label', 191)->nullable();

            // SEC-16's "reason where applicable". Free text the operator typed,
            // so it is guest-safe only by the operator's own choice — which is
            // why it is a plain column and not part of `context`, whose shape a
            // test enforces.
            $table->string('reason', 500)->nullable();

            // Small, machine-readable, and **no personal data** (ADR-0025 §3).
            // NOT NULL with no database default: MySQL 8 refuses a literal
            // DEFAULT on JSON and the expression form has no SQLite equivalent
            // (ENV-12), so the default lives on the model.
            $table->json('context');

            // 45 characters: an IPv6 address with an IPv4 tail is 45. Nullable
            // because a console action has no request behind it.
            $table->string('ip_address', 45)->nullable();

            // No `updated_at`. See the class docblock.
            $table->timestamp('created_at')->nullable();

            // `tenant_id` leads every composite (§1.2): the global scope appends
            // `where tenant_id = ?` to every query, so an index that does not
            // lead with it will not be used.
            //
            // The trail as an operator reads it — their tenant, newest first.
            $table->index(['tenant_id', 'created_at'], 'audit_logs_tenant_created_idx');

            // "What did this person do?" — the actor filter on the panel page.
            $table->index(['tenant_id', 'user_id', 'created_at'], 'audit_logs_tenant_actor_idx');

            // "What happened to this vessel?" — the subject filter, and the
            // query an incident actually starts from.
            $table->index(['tenant_id', 'subject_type', 'subject_id'], 'audit_logs_tenant_subject_idx');

            // Three indexes, and deliberately no fourth on `created_at` alone.
            // The only query that wants one is the nightly retention purge,
            // which is platform-wide and therefore not tenant-led — and §6 is
            // explicit that index additions are portable, belong in M8, and
            // should be measured against production data rather than guessed at
            // on an empty table.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
