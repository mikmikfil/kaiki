<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a person wants to be greeted: «Μαρία», «Κυρία Ελένη», «Captain Nikos».
 *
 * The home page says «Καλημέρα, …» (product owner, 2026-09-17). A full name
 * read out as a greeting is stiff, and a first word cut from it is often wrong
 * — a compound first name, a title, a nickname everyone uses. So the person
 * writes it themselves on «Το προφίλ μου», optionally, and the full name is
 * used as it is when they have not.
 *
 * `docs/data-model.md` §6: a nullable column with no foreign key and no default
 * may be added to an existing table without a rebuild on SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('salutation', 60)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('salutation');
        });
    }
};
