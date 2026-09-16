<?php

declare(strict_types=1);

use App\Models\Product;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A short label on a trip's photograph: «Δημοφιλές», «Για δύο», "Best seller".
 *
 * ## Why it is a column and not the category
 *
 * The category is what the trip *is* — a half-day shared cruise, a private
 * charter — and it drives search filters and structured data. The label is what
 * the operator wants to *say* about it on a card, and the two disagree all the
 * time: two sunset trips, one of which is the one everybody books. Falling back
 * to the category where there is no label would print «Ηλιοβασίλεμα» over a
 * photograph of a sunset, which is a caption rather than a recommendation, so an
 * empty label renders nothing.
 *
 * The WordPress plugin kept these in its own post meta until 2026-09-16, which
 * meant the hosted cards could not show them and a label typed on one site was
 * missing on the other. It lives here now and the API carries it as `badge`.
 *
 * ## Translatable JSON, nullable, like every other piece of product prose
 *
 * `{"el": "…", "en": "…"}` (§1.6), read through {@see Product::$translatable}.
 * Nullable because most trips have none, and not in `$requiredTranslations`
 * for the same reason `summary` is not. The length (24) is enforced by the
 * panel form, where the operator can see which language is too long; a JSON
 * column cannot hold a per-locale limit anyway.
 *
 * `docs/data-model.md` §6: a nullable column with no foreign key and no default
 * may be added to an existing table without a rebuild on SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->json('badge')->nullable()->after('summary');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('badge');
        });
    }
};
