<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The crew as people, not only as logins (Mike, 2026-09-24).
 *
 * - `specialty` — what the person does on the boat: Κυβερνήτης, Ναύτης, Άλλο.
 *   Separate from the role, which is what they may see in Kaiki: an owner can
 *   be the captain without having to stop being the owner.
 * - `email` becomes optional: «not everyone uses email». A person with none is
 *   «Χωρίς σύνδεση» — named on the passenger list, never signing in, never
 *   mailed. The unique index stays; it allows any number of nulls.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('email', 190)->nullable()->change();
            $table->string('specialty', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('specialty');
        });
    }
};
