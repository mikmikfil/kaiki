<?php

declare(strict_types=1);

use App\Models\Product;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Τι θα ζήσετε»: a few short lines about what makes the day (2026-09-16).
 *
 * «Τρεις στάσεις για μπάνιο σε όρμους με κρυστάλλινα νερά», «Φεύγουμε νωρίς,
 * πριν από τον κόσμο και τη ζέστη». Not the description, which is the page's
 * prose, and not `includes`, which is what the ticket pays for: these are the
 * reasons to book, and they sit at the top of the trip page and in the
 * WordPress plugin's highlights field.
 *
 * ## The same shape as `includes`, on purpose
 *
 * A **translatable array of strings** (`docs/data-model.md` §3.5):
 * `{"el": ["…"], "en": ["…"]}`, read through {@see Product::$translatable}.
 * Nullable, because "not configured" hides the section and most trips will not
 * have them on day one; not a required translation, because every piece of
 * trip-page content is optional. Anything that already handles `includes` —
 * the detail resource, the sync feed, the plugin's list fields — handles this
 * with no new code path.
 *
 * `docs/data-model.md` §6: a nullable column with no foreign key and no default
 * may be added to an existing table without a rebuild on SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->json('highlights')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('highlights');
        });
    }
};
