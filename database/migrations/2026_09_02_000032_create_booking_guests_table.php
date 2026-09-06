<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The manifest row (`docs/data-model.md` §2.5, §6 item 32).
 *
 * **Where the personal data lives.** One row per person aboard — capacity-
 * counting and not, because an infant still needs a manifest line — created at
 * confirmation with `full_name` null and filled in by the guest-details flow.
 *
 * ## `document_number` is encrypted, never indexed, and purged
 *
 * ADR-0012 and `CLAUDE.md`: a passport number is not logged, not exported
 * without an explicit operator action, and nulled after the retention window.
 * Encrypted means it cannot be searched, which is the point — a column you can
 * search is a column somebody builds a search on.
 *
 * `bguests_purge_idx` is the retention sweeper's index and, like the hold
 * sweeper's, deliberately **not** tenant-first: the purge is a platform
 * obligation running across every operator.
 *
 * ## `ticket_code` is globally unique because a QR scan has no tenant
 *
 * Crew scan a code on a pier with no session and no subdomain. Resolving it has
 * to be one indexed read with nothing else known, exactly like
 * `bookings.manage_token`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_guests', function (Blueprint $table): void {
            $table->id();
            // Encoded in the QR ticket alongside `ticket_code`.
            $table->char('uuid', 36)->unique('booking_guests_uuid_unique');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();

            $table->foreignId('age_band_id')->nullable()->constrained('age_bands')->nullOnDelete();
            // Snapshot of the band code, so a manifest still renders a year
            // later after the band has been renamed or deleted.
            $table->string('age_band_code', 24);

            // 1-based ordinal within the booking. Part of the unique index
            // below, which is what makes "create N guest rows" idempotent.
            $table->unsignedTinyInteger('position')->default(1);

            // Null until the guest-details form is submitted. Every one of these
            // being non-null is what `guest_details_status = complete` means.
            $table->string('full_name', 180)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->char('nationality', 2)->nullable();
            $table->string('document_type', 16)->nullable();
            // **Encrypted** (§1.7, ADR-0012). `text`, not `string`: ciphertext is
            // several times the length of what it encrypts.
            $table->text('document_number')->nullable();
            $table->date('document_expires_on')->nullable();
            // Stamped by the retention job when `document_number` is nulled, so
            // "purged" and "never supplied" stay distinguishable.
            $table->timestamp('document_purged_at')->nullable();

            $table->char('ticket_code', 24)->unique('booking_guests_ticket_code_unique');

            $table->timestamp('checked_in_at')->nullable();
            $table->foreignId('checked_in_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Mirrors the booking's lead guest, so a manifest can be read
            // without joining back for the one name that matters most.
            $table->boolean('is_lead')->default(false);
            $table->string('notes', 255)->nullable();

            // Hard delete, cascading with the booking. No soft deletes: a
            // manifest row outliving its booking is personal data with no owner.
            $table->timestamps();

            $table->unique(['tenant_id', 'booking_id', 'position'], 'bguests_tenant_booking_pos_uq');
            $table->index(['tenant_id', 'booking_id'], 'bguests_tenant_booking_idx');
            // **Not tenant-first** — the retention sweeper is platform-wide.
            $table->index(['document_purged_at', 'created_at'], 'bguests_purge_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_guests');
    }
};
