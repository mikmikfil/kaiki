<?php

declare(strict_types=1);

use App\Domain\Compliance\Actions\GenerateCharterAgreement;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `template_version` was three characters too short for its own constant.
 *
 * {@see GenerateCharterAgreement::TEMPLATE_VERSION} is
 * `provisional-2026-09` — nineteen characters — and the column was declared
 * `varchar(16)`. On MySQL that is `SQLSTATE[22001]: Data too long`, and every
 * charter agreement fails to save. On SQLite it is nothing at all: SQLite does
 * not enforce a declared length, so six tests passed locally and the same six
 * failed in CI, which is exactly the split the MySQL job exists to find.
 *
 * ## Widened rather than shortened
 *
 * The prefix is load-bearing. `provisional-` is what keeps every document
 * produced under a template no lawyer has read permanently distinguishable from
 * one produced under the real text, and the date is what makes two provisional
 * templates tell themselves apart. Thirty-two characters leaves room for the
 * version that replaces it without another migration.
 *
 * The column carries a unique index with `tenant_id` and `booking_id`; widening
 * keeps it, on both engines.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charter_agreements', function (Blueprint $table): void {
            $table->string('template_version', 32)->change();
        });
    }

    public function down(): void
    {
        Schema::table('charter_agreements', function (Blueprint $table): void {
            $table->string('template_version', 16)->change();
        });
    }
};
