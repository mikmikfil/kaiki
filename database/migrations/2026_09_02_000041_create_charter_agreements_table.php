<?php

declare(strict_types=1);

use App\Models\CharterAgreement;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ναυλοσύμφωνο (`docs/data-model.md` §2.6, §6 item 45).
 *
 * ## An M6 table landing in M2, on purpose
 *
 * The **document** belongs to M6 and nothing here generates one. The **table**
 * has to exist now, because §0 and §6 say SQLite cannot add a foreign key to an
 * existing table — so a table with three FKs is created once with all three or
 * it is rebuilt later, and a rebuild of a table holding legal evidence is not a
 * thing anybody should have to do.
 *
 * This is the same move #29 made for the iCal tables and #47 made for
 * `vat_rates`: the columns land early so the key resolves, and the feature
 * lands in its own milestone. Say so here, or the next reader wonders why a
 * compliance table appeared in the booking milestone.
 *
 * ## The acceptance evidence is the legally interesting part
 *
 * §2.6 names it: timestamp, IP, user agent and the typed name. Those four
 * columns are why the table is versioned rather than mutable — regenerating an
 * agreement the guest has already accepted would overwrite the only record that
 * they accepted it, which is precisely the record a dispute is about.
 *
 * The uniqueness key is therefore **(tenant, booking, template_version)** and
 * not (tenant, booking): a new template version is a **new row**, the old
 * evidence survives beside it, and {@see CharterAgreement} refuses
 * the write that would have overwritten it.
 *
 * ## What is deliberately *not* here
 *
 * No ΚΥΑ-prescribed field columns. The form is prescribed by ΚΥΑ Α.Π.
 * 3133.1/47821 and whether a freely designed PDF is a valid ναυλοσύμφωνο at all
 * is a legal question that has not been answered — so the rendered content goes
 * into `fields_snapshot` as an encrypted blob, and the shape of that blob stays
 * M6's problem. Guessing at columns now would be guessing at a form nobody has
 * confirmed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charter_agreements', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // `restrictOnDelete`, like `invoices`. An accepted agreement is
            // evidence about a real charter; a booking that can take it with it
            // is a booking that can erase the evidence.
            $table->foreignId('booking_id')->constrained('bookings')->restrictOnDelete();

            $table->string('template_key', 48)->default('default');
            $table->string('template_version', 16);

            // §3.8 — encrypted:array. Everything merged into the template,
            // which for a charter agreement is both parties' names, the
            // skipper, the vessel's registration and the itinerary.
            $table->text('fields_snapshot');

            $table->string('pdf_path', 255)->nullable();
            // SHA-256 of the file. Proves the PDF an operator produces in a
            // dispute is the PDF the guest accepted, rather than one generated
            // afterwards from the same data.
            $table->char('pdf_hash', 64)->nullable();

            $table->timestamp('generated_at')->nullable();
            $table->timestamp('sent_at')->nullable();

            // The four evidence columns. Never overwritten — see the class
            // docblock, and the guard on the model.
            $table->timestamp('guest_accepted_at')->nullable();
            $table->string('guest_accepted_ip', 45)->nullable();
            $table->string('guest_accepted_user_agent', 500)->nullable();
            $table->string('guest_accepted_name', 180)->nullable();

            $table->timestamp('operator_signed_at')->nullable();
            $table->foreignId('operator_signed_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('status', 16)->default('draft');

            $table->timestamps();

            // One agreement per booking **per template version**, so a new
            // version is a new row rather than an overwrite. This index is the
            // database half of the "evidence is never overwritten" rule; the
            // model carries the application half, because a unique key stops a
            // duplicate and not an update.
            $table->unique(['tenant_id', 'booking_id', 'template_version'], 'charter_agr_tenant_booking_uq');
            $table->index(['tenant_id', 'status'], 'charter_agr_tenant_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('charter_agreements');
    }
};
