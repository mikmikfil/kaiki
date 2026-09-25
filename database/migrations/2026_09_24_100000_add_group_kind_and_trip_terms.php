<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prices by group and period (product owner, 2026-09-24, approved from
 * `docs/mockups/pricing-flow.html`).
 *
 * ## A group is by age or by status
 *
 * «ΑμεΑ» and «Φοιτητής» are not ages. `kind = status` says the group is chosen
 * for what the passenger *is*: no age is checked at checkout, and when
 * `requires_proof` is on the passenger list and the ticket tell the crew to see
 * the card at boarding. Every existing band is `age`, as it always was.
 *
 * ## One set of terms for the trip, and a period may differ
 *
 * Deposit and booking deadlines lived on each «τιμοκατάλογος», so an operator
 * with four periods set them four times. They are now set once, on the trip's
 * year-round plan, and copied to every period that `follows_trip_terms`. A
 * period whose terms already differ from the year-round plan's is marked as
 * not following, so nothing an operator set before today changes.
 *
 * Nullable-free with defaults, no foreign keys — `docs/data-model.md` §6.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('age_bands', function (Blueprint $table): void {
            $table->string('kind', 8)->default('age');
            $table->boolean('requires_proof')->default(false);
        });

        Schema::table('rate_plans', function (Blueprint $table): void {
            $table->boolean('follows_trip_terms')->default(true);
        });

        $terms = ['deposit_type', 'deposit_percent', 'deposit_fixed_cents', 'balance_due_days_before_departure', 'min_lead_time_hours', 'max_advance_days'];

        $defaults = DB::table('rate_plans')
            ->whereNull('season_id')
            ->whereNull('deleted_at')
            ->get(array_merge(['product_id'], $terms))
            ->keyBy('product_id');

        DB::table('rate_plans')
            ->whereNotNull('season_id')
            ->orderBy('id')
            ->get(array_merge(['id', 'product_id'], $terms))
            ->each(static function (object $plan) use ($defaults, $terms): void {
                $default = $defaults->get($plan->product_id);

                $same = $default !== null && collect($terms)->every(
                    static fn (string $column): bool => (string) $plan->{$column} === (string) $default->{$column},
                );

                if (! $same) {
                    DB::table('rate_plans')->where('id', $plan->id)->update(['follows_trip_terms' => false]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('rate_plans', function (Blueprint $table): void {
            $table->dropColumn('follows_trip_terms');
        });

        Schema::table('age_bands', function (Blueprint $table): void {
            $table->dropColumn(['kind', 'requires_proof']);
        });
    }
};
