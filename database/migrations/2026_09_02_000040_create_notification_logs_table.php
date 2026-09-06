<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every email and SMS we attempted (`docs/data-model.md` §2.7, §6 item 40).
 *
 * ## The dedupe index is the reason this table exists at all
 *
 * BKG-16 requires every reminder to be *"idempotent per booking per reminder
 * type"*, and §2.7 says how: `notif_logs_tenant_tmpl_idx` answers *"have we
 * already sent the 48h reminder for this booking?"* in one indexed read. A
 * scheduler that ran twice without it would double-mail every guest on the
 * fleet, and the second copy is the one that makes an operator's phone ring.
 *
 * It is an **index rather than a unique constraint**, deliberately. A retry
 * after a genuine failure is a second row for the same (booking, template) pair
 * and must be allowed — the log is a record of attempts, not of outcomes, and a
 * unique index would make a retry impossible to record.
 *
 * ## `notif_logs_provider_ref_idx` does not lead with `tenant_id`
 *
 * The third index in the schema that does not, after `bookings_hold_expiry_idx`
 * and `bookings_weather_choice_idx`, and for the same kind of reason: NTF-8's
 * bounce and complaint webhooks arrive from Postmark **with no tenant context**
 * and a message id. The lookup is what resolves the tenant, so it cannot be
 * scoped by one.
 *
 * ## Rows are pruned, never deleted by hand
 *
 * §2.7: twelve months, by a scheduled `model:prune`. The trail is the point —
 * an operator asking *"did the guest ever get the confirmation"* six months
 * later has to be able to find out, and "we sent it, Postmark bounced it, here
 * is the message id" is the only answer worth having.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // Null for tenant-level mail — dunning, platform alerts — which has
            // no booking to hang off.
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->cascadeOnDelete();
            // Weather-cancellation batches, where the interesting unit is the
            // sailing rather than any one booking on it.
            $table->foreignId('departure_id')->nullable()->constrained('departures')->cascadeOnDelete();

            // For non-booking recipients: a user, an enquiry. A morph pair with
            // no foreign key, like `audit_logs`, because the subject may be from
            // a table that does not exist yet.
            $table->string('notifiable_type', 64)->nullable();
            $table->unsignedBigInteger('notifiable_id')->nullable();

            /* `mail` | `sms` | `webhook` — PHP enum `NotificationChannel`. */
            $table->string('channel', 16);

            /* `booking_confirmed`, `guest_details_reminder_48h`, … */
            $table->string('template', 64);

            // NTF-4: proves we sent in the **guest's** language rather than in
            // whatever the operator's panel happened to be set to.
            $table->char('locale', 2);

            // Personal data, and included in the GDPR export and erase paths.
            $table->string('to', 190);
            $table->string('subject', 255)->nullable();

            /* `queued` | `sent` | `delivered` | `bounced` | `failed`. */
            $table->string('status', 16)->default('queued');

            $table->string('provider', 24)->nullable();
            // The message id a delivery webhook arrives quoting (NTF-8).
            $table->string('provider_ref', 190)->nullable();
            $table->string('error_message', 500)->nullable();

            // SMS costs money and an operator wants to know how much. Nullable
            // because email does not, and because a provider that does not
            // report it should record nothing rather than zero.
            $table->unsignedInteger('cost_cents')->nullable();

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();

            $table->timestamps();

            // The booking timeline, in the order an operator reads it.
            $table->index(['tenant_id', 'booking_id', 'created_at'], 'notif_logs_tenant_booking_idx');
            // **Not tenant-first** — see the class docblock.
            $table->index(['provider', 'provider_ref'], 'notif_logs_provider_ref_idx');
            // The failure feed (BKG-14).
            $table->index(['tenant_id', 'status', 'created_at'], 'notif_logs_tenant_status_idx');
            // The dedupe read BKG-16 rests on.
            $table->index(['tenant_id', 'template', 'booking_id'], 'notif_logs_tenant_tmpl_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};
