<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether an operator is walked through the first-time setup guide (product
 * owner, 2026-09-17).
 *
 * Set by the platform on /admin → Edit Merchant, audited like the other
 * switches. Off when the platform set the account up for the operator: no
 * guide on sign-in, no menu item, no checklist on the home page.
 *
 * Null and true are both **on**, so every operator who existed before the
 * column keeps the guide they had. Read it as `!== false`.
 *
 * `docs/data-model.md` §6: a nullable column with no foreign key may be added
 * to an existing table without a rebuild on SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->boolean('setup_guide_enabled')->nullable()->default(true)->after('sms_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('setup_guide_enabled');
        });
    }
};
