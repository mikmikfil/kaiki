<?php

declare(strict_types=1);

use App\Models\AgeBand;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Passenger details at checkout (product owner, 2026-09-17).
 *
 * ## «Χωρίς έγγραφο», per age band
 *
 * A baby has no passport number to type, and a checkout that demanded one
 * would stop a family at the last step. So each band says whether its
 * passengers are asked for a document. Null means "decide from the ages":
 * {@see AgeBand::isDocumentFree()} treats a band ending at two or younger as
 * document-free, which is the infant band every operator has. Existing infant
 * bands are written explicitly here so the panel shows the switch on.
 *
 * ## «Άλλο» goes
 *
 * Only a passport or an identity card is accepted on the manifest now. The
 * rows that said `other` become `id_card`, the closer of the two: the number
 * is kept, and a passport would additionally need an expiry date nobody typed.
 *
 * ## Nullable, no foreign keys
 *
 * `docs/data-model.md` §6, as every column added since M1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('age_bands', function (Blueprint $table): void {
            $table->boolean('no_document')->nullable();
        });

        DB::table('age_bands')
            ->whereNotNull('max_age')
            ->where('max_age', '<=', AgeBand::DOCUMENT_FREE_MAX_AGE)
            ->update(['no_document' => true]);

        DB::table('booking_guests')
            ->where('document_type', 'other')
            ->update(['document_type' => 'id_card']);
    }

    public function down(): void
    {
        Schema::table('age_bands', function (Blueprint $table): void {
            $table->dropColumn('no_document');
        });
    }
};
