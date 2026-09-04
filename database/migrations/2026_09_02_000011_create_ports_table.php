<?php

declare(strict_types=1);

use App\Support\Locale\TranslationColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meeting points and home ports (`docs/data-model.md` §2.3, spec CAT-3).
 *
 * **One table serving two roles**, by design: `products.meeting_point_id` and
 * `vessels.home_port_id` both point here, because the data is identical and an
 * operator reuses the same marina for both. §4 of the brief names the concept
 * "Port / MeetingPoint" and forbids renaming it, so there is no
 * `meeting_points` table and there is not going to be one.
 *
 * Item **10** in the §6 migration order, and it comes before `vessels` because
 * `vessels.home_port_id` is a foreign key to it and **SQLite cannot add a
 * foreign key to an existing table**. There is no second chance at this.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ports', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // Translatable (§1.6). Both `el` and `en` are required on write and
            // the observer refuses a set missing either.
            $table->json('name');

            // A single free-text line, because this is what goes into the maps
            // link. Splitting it into street/number/city would mean reassembling
            // it for every link and getting Greek address order wrong somewhere.
            $table->string('address', 255)->nullable();

            // The only `decimal` columns in the whole schema (§2.3). Not money,
            // and they need roughly 1 cm of precision; a float would drift and
            // put the pin in the wrong basin. Both engines store this faithfully.
            // No spatial type, because there is no radius search in MVP — which
            // is fortunate, since SQLite has none.
            $table->decimal('lat', total: 10, places: 7)->nullable();
            $table->decimal('lng', total: 10, places: 7)->nullable();

            // Translatable — "meet at the blue kiosk".
            $table->json('instructions')->nullable();

            // A path, not a media row: ADR-0021 Option A, no polymorphic table.
            $table->string('photo_path', 255)->nullable();

            // An operator override for the "open in maps" link. Some marinas
            // resolve badly from their postal address and the operator knows
            // the pin that actually works.
            $table->string('maps_url', 255)->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            // ADR-0008 companion columns. `name` and `instructions` are JSON and
            // **no query may sort or filter on a JSON path** (CAT-6, ENV-8), so
            // searching and ordering run against these, maintained on save by
            // SearchIndexObserver.
            //
            // Not indexed, and deliberately: MySQL 8 refuses an index on TEXT
            // without a key length, SQLite has no prefix index, and
            // `LIKE '%term%'` cannot use a B-tree either way. The argument is
            // recorded in full on #15.
            $table->text(TranslationColumns::SEARCH)->nullable();

            // Per locale, because ordering *is* a per-language question: the
            // Greek list must read alphabetically in Greek. Generated from the
            // same helper the observer uses, so the two cannot drift.
            foreach (TranslationColumns::sortColumnsFor(['name']) as $column) {
                $table->string($column, 191)->nullable();
            }

            $table->timestamps();
            $table->softDeletes();

            // `tenant_id` leads every composite index (§1.2): the BelongsToTenant
            // global scope appends `where tenant_id = ?` to every query, so an
            // index that does not lead with it will not be used.
            $table->index(['tenant_id', 'is_active', 'sort_order'], 'ports_tenant_active_idx');

            foreach (TranslationColumns::sortColumnsFor(['name']) as $column) {
                $table->index(['tenant_id', $column], "ports_tenant_{$column}_idx");
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ports');
    }
};
