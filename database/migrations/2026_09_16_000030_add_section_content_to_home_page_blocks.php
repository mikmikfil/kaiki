<?php

declare(strict_types=1);

use App\Domain\Hosted\Support\BlockItems;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the home page sections of 16 September need that a block could not hold.
 *
 * The numbers under the masthead, the three steps, the reasons to choose the
 * operator, the reviews, the call-to-action band and the hero's trust badges,
 * all editable in both languages from the panel — the small line above every
 * heading, every button's label and destination, and a description for every
 * photograph included.
 *
 * ## Why not the JSON columns the table already has
 *
 * `settings` is documented, in the migration that created it, as holding "only
 * the knobs that are never translated — so a missing translation is impossible
 * to hide in there". Nearly all of this is translated. Putting it there would
 * make that sentence false for every row written from today.
 *
 * `images` was named for the gallery "so a second type cannot quietly start
 * meaning something else by it". A list of reviews is exactly that second
 * meaning.
 *
 * ## Four columns, each named for what it holds
 *
 * - `eyebrow` and `image_alt` are ordinary translatable columns, like `heading`
 *   beside them, and inherit every guarantee those already have.
 * - `items` is a list of entries whose shape is decided per block type, and
 *   `buttons` a list of `{label, target}` — both normalised on the way in and
 *   on the way out by {@see BlockItems}, the same whitelist
 *   discipline `BlockSettings` applies to `settings`. A button names a target
 *   from a closed list; no column here holds an address an operator typed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('home_page_blocks', function (Blueprint $table): void {
            // Translatable, nullable: the short line above a section's heading.
            $table->json('eyebrow')->nullable()->after('heading');

            // Translatable, nullable: the description of `image_path`.
            $table->json('image_alt')->nullable()->after('image_path');

            $table->json('items')->nullable()->after('images');
            $table->json('buttons')->nullable()->after('items');
        });
    }

    public function down(): void
    {
        Schema::table('home_page_blocks', function (Blueprint $table): void {
            $table->dropColumn(['eyebrow', 'image_alt', 'items', 'buttons']);
        });
    }
};
