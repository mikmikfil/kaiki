<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The e-ticket's path, and per-guest no-show (spec BKG-13.1, BKG-23; #88).
 *
 * ## Why a path column and not a media table
 *
 * ADR-0021, which settled this for every file the platform stores: *"plain path
 * columns plus a `StoreUploadedImage` Action; **no polymorphic media table**"*.
 * `invoices.pdf_path` and `charter_agreements.pdf_path` already follow it, so
 * the ticket does too — one column, one hash, one timestamp.
 *
 * The **hash** is here for the same reason it is on the agreement: a ticket is
 * shown at a quay by somebody holding a phone, and being able to say "this file
 * is the file we generated" costs one `char(64)`.
 *
 * ## `booking_guests.no_show` — the half BKG-23 has and §2.5 did not
 *
 * > **BKG-23** No-show marking is available **per guest and per booking**, is
 * > reversible, and does not itself trigger any refund logic.
 *
 * `bookings.no_show` has existed since #80. The per-guest half had nowhere to
 * live, and the two are not the same fact: a family of four where one person
 * missed the boat is three people who sailed, and marking the *booking* as a
 * no-show would be wrong in a way that reaches the manifest, the operator's
 * numbers and — the moment M6 lands — the invoice.
 *
 * Reversibility needs no column. `no_show` is a boolean an operator sets back
 * to false, and BKG-23's own sentence is explicit that nothing else follows
 * from it: no refund, no status change, no seat released.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->string('eticket_path', 255)->nullable()->after('manage_token');
            $table->char('eticket_hash', 64)->nullable()->after('eticket_path');
            $table->timestamp('eticket_generated_at')->nullable()->after('eticket_hash');
        });

        Schema::table('booking_guests', function (Blueprint $table): void {
            $table->boolean('no_show')->default(false)->after('checked_in_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn(['eticket_path', 'eticket_hash', 'eticket_generated_at']);
        });

        Schema::table('booking_guests', function (Blueprint $table): void {
            $table->dropColumn('no_show');
        });
    }
};
