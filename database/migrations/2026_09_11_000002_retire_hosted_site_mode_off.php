<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The hosted site has two modes, not three (ADR-0029 as amended 2026-09-11).
 *
 * ## Why *off* went
 *
 * An operator on *off* still sent every guest to Kaiki's checkout, which asks
 * them to accept terms that were on a legal page the same mode 404'd. The
 * product owner retired it on the model WebHotelier and FareHarbor both use:
 * the booking pages always exist, and the choice is only whether Kaiki also
 * publishes a home page.
 *
 * ## Where an *off* operator lands
 *
 * **Bookings only** — the nearest state that still does not publish a home page
 * under their name. No schema change: the column is a `string(16)` and both
 * remaining values fit it.
 *
 * ## No way back
 *
 * `down()` cannot know which operators were *off*, and the enum no longer has
 * the case to cast them to, so it does nothing rather than guess.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('tenants')
            ->where('hosted_site_mode', 'off')
            ->update(['hosted_site_mode' => 'bookings_only']);
    }

    public function down(): void
    {
        //
    }
};
