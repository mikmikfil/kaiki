<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which Kaiki trip is which product on the far side (spec EXT-1, ADR-0034).
 *
 * EXT-1 named "map external product ids" as one of the four things a channel
 * has to do. This is where the answer lives, and it is a table rather than a
 * column on `products` for one reason: **a trip can be sold on more than one
 * channel**, and a column would make the second one a migration.
 *
 * ## Both directions are unique, and both constraints earn their place
 *
 * `(tenant_id, channel, external_product_id)` stops two Kaiki trips claiming
 * the same GetYourGuide product — which would make an incoming booking
 * ambiguous at the worst possible moment, with a guest already paid.
 *
 * `(tenant_id, channel, product_id)` stops one Kaiki trip being mapped to two
 * products on the same channel. That would quietly halve nothing and double
 * everything: both products would report the same seats, and selling one would
 * empty the other without anybody being told.
 *
 * ## Scoped to the tenant, though the lookup is not
 *
 * GetYourGuide product ids are unique on their side, not ours, and two
 * operators could in principle be handed the same id by different contracts.
 * The tenant is already known by the time this is read — the Basic username
 * resolved it before the request reached a controller — so the narrow index is
 * the right one and the cross-tenant case cannot arise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_product_map', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // The `ChannelKey` value. A string rather than an enum column for
            // the reason every other enum here is: adding a channel must not be
            // a migration on a table with rows in it.
            $table->string('channel', 24);

            // Cascade: a deleted trip has no mapping to keep. The trip is soft
            // deleted, so this only fires on a real purge.
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();

            // 190 for the same index-width reason as `external_account_id`.
            $table->string('external_product_id', 190);

            $table->timestamps();

            $table->unique(['tenant_id', 'channel', 'external_product_id'], 'channel_map_external_uq');
            $table->unique(['tenant_id', 'channel', 'product_id'], 'channel_map_product_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_product_map');
    }
};
