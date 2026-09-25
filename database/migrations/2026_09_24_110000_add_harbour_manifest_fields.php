<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the passenger list for the Λιμεναρχείο still lacked (ν. 4926/2022,
 * άρθρο 13, as read in the competitor study of 2026-09-23 — the reading is to
 * be confirmed by the lawyer; see docs/erotiseis).
 *
 * - `booking_guests.sex` — «Φύλο», asked at checkout beside nationality.
 *   One letter, `m` or `f`, the two the Λιμεναρχείο's own form has.
 * - `vessels.licence_type` — ημερόπλοιο or επαγγελματικό πλοίο αναψυχής. It is
 *   printed on the list, and a pleasure boat sold per seat is flagged: the
 *   reading is that it may only be chartered whole.
 * - `products.landing_port_id` — where the passengers get off. Null means the
 *   same port they boarded at, which is every round trip.
 *
 * Nullable, like every column added since M1 (`docs/data-model.md` §6): the
 * guests already booked have no sex on record and must not be invented one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_guests', function (Blueprint $table): void {
            $table->string('sex', 1)->nullable();
        });

        Schema::table('vessels', function (Blueprint $table): void {
            $table->string('licence_type', 24)->nullable();
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->foreignId('landing_port_id')->nullable()->constrained('ports')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('landing_port_id');
        });

        Schema::table('vessels', function (Blueprint $table): void {
            $table->dropColumn('licence_type');
        });

        Schema::table('booking_guests', function (Blueprint $table): void {
            $table->dropColumn('sex');
        });
    }
};
