<?php

declare(strict_types=1);

use App\Enums\BalanceCollection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where an operator collects the balance after a deposit (Mike, 2026-09-25):
 * `online` — the guest pays it by card before the trip, with a due date and
 * reminders, as it has always worked — or `on_board`, paid on the day, with
 * no due date, no reminder and nothing overdue before departure.
 *
 * {@see BalanceCollection}. Not null with a constant default: SQLite
 * adds such a column without a table rebuild, and every existing operator
 * keeps today's behaviour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('balance_collection', 16)->default('online');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('balance_collection');
        });
    }
};
