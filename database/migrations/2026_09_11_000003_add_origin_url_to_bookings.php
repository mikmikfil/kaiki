<?php

declare(strict_types=1);

use App\Domain\Hosted\Support\AllowedOrigin;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The page a guest was on when they started the booking.
 *
 * ## Why it is stored at all
 *
 * The product owner's instruction (2026-09-11): the checkout page and the
 * booking page carry a «← Επιστροφή στην ιστοσελίδα» link. The widget lives on
 * the operator's own site and hands the whole browser to `/c/{token}`, so
 * without this the guest's only way back to the site they came from is the
 * browser's Back button — which, after a gateway, is a walk back through Viva.
 *
 * ## Only ever an allowed origin
 *
 * Written by `POST /api/v1/bookings` only when it passes
 * {@see AllowedOrigin} — the key's CORS list, or a page Kaiki serves itself.
 * Anything else is stored as null rather than refused: a link we will not print
 * is not a reason to lose somebody's seats.
 *
 * ## Not `referrer_url`
 *
 * That column (varchar 500) is the attribution field §2.5 has carried since M2,
 * and it answers "where did this booking come from" for a report. This one
 * answers "where do we send the guest back to", is rendered as a link, and is
 * validated as one. 2000, the same bound `return_url` has.
 *
 * ## Nullable with no foreign key, so it can land here
 *
 * `docs/data-model.md` §6: a nullable column with no foreign key may be added
 * to an existing table without a rebuild on SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->string('origin_url', 2000)->nullable()->after('referrer_url');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn('origin_url');
        });
    }
};
