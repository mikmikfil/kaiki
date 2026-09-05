<?php

declare(strict_types=1);

use App\Support\Locale\TranslationColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The calendar concept pricing resolves against (`docs/data-model.md` §2.3,
 * spec CAT-9, PRC-3, PRC-4).
 *
 * §6 item 16. Rate plans (#21) hold `season_id`, so this precedes them.
 *
 * ## Higher priority wins, and ties are prevented rather than resolved
 *
 * Ranges may overlap **across** seasons — that is what `priority` is for, and
 * it is how "August" sits inside "Summer". PRC-4 is explicit that two seasons
 * with the same priority and overlapping ranges are **prevented by
 * validation**, with the deterministic ordering kept only as defence in depth.
 *
 * That combination is deliberate: a tie is a configuration mistake an operator
 * can fix, and silently picking one at read time means their prices are decided
 * by a row id they never see.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seasons', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $table->json('name');

            // Operator shorthand (`HIGH26`). Nullable — most operators name a
            // season and never think about a code.
            $table->string('code', 32)->nullable();

            // Higher wins. Unsigned smallint: a priority is an ordering, and a
            // negative one would only mean "below the default", which 0 already
            // covers.
            $table->unsignedSmallInteger('priority')->default(0);

            $table->boolean('is_active')->default(true);

            // ADR-0008 companions. A season name is Greek text an operator
            // sorts and searches by, and the two engines fold tonos
            // differently.
            $table->text(TranslationColumns::SEARCH)->nullable();

            foreach (TranslationColumns::sortColumnsFor(['name']) as $column) {
                $table->string($column, 191)->nullable();
            }

            $table->timestamps();
            $table->softDeletes();

            // Nullable unique: two seasons may both have no code — NULL is
            // distinct from NULL in a unique index on both engines, which is
            // the one place that behaviour is useful rather than a trap.
            $table->unique(['tenant_id', 'code'], 'seasons_tenant_code_uq');

            $table->index(['tenant_id', 'priority', 'is_active'], 'seasons_tenant_priority_idx');

            foreach (TranslationColumns::sortColumnsFor(['name']) as $column) {
                $table->index(['tenant_id', $column], "seasons_tenant_{$column}_idx");
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seasons');
    }
};
