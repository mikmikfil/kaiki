<?php

declare(strict_types=1);

use App\Policies\PolicyTemplatePolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The cancellation policies offered during setup (Mike, 2026-09-23:
 * *«να μπορώ να τις επεξεργαστώ ως admin τις επιλογές που τους δίνω»*).
 *
 * Until now the three ladders a new operator chooses between lived in the
 * code — the numbers in `Setup::presetLadder()`, the names and the printed
 * ladder in `lang/{el,en}/setup.php`, the list itself in a `const`. Adding a
 * fourth, or moving a refund percentage, meant editing three files and
 * deploying. Worse, the numbers and the words were in different files with
 * nothing keeping them in step: the ladder a new operator *read* and the ladder
 * that was actually *written* could drift apart silently.
 *
 * ## Platform-owned, like `vat_rates`
 *
 * No `tenant_id`. These are the platform's menu, not an operator's data, and
 * {@see PolicyTemplatePolicy} keeps operators out of them
 * entirely — an operator never reads this table directly, they read the three
 * cards the setup guide draws from it.
 *
 * ## Choosing one copies it; it does not link to it
 *
 * The setup guide still writes a real `cancellation_policies` row owned by the
 * operator, with real `cancellation_policy_tiers`. That is deliberate and
 * unchanged: cancellation terms have already been shown to guests and put in
 * their confirmation emails, so editing a template must never reach backwards
 * into an operator who took it last season. A template is a starting point.
 *
 * ## The ladder is JSON, not a child table
 *
 * A template's ladder is read whole, every time, and never queried by
 * threshold — nothing asks "which templates refund 50% at 7 days". The
 * operator's *copy* gets proper rows, because that one is queried on every
 * cancellation. A child table here would buy a join and cost a second resource
 * to maintain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_templates', function (Blueprint $table): void {
            $table->id();

            // Stable across renames: `Setup` records which template an operator
            // took, and the seeder finds its own rows again by this.
            $table->string('code', 32)->unique();

            $table->json('name');
            $table->json('summary')->nullable();

            // Null means the ladder alone decides — there is no "free until"
            // window. Mirrors `cancellation_policies.free_cancellation_hours`.
            $table->unsignedSmallInteger('free_cancellation_hours')->nullable();

            // list<{days_before: int, refund_percent: int}>, largest threshold
            // first. Empty is valid: «free until 24h, nothing after» is a whole
            // policy on its own.
            $table->json('tiers');

            $table->unsignedSmallInteger('sort_order')->default(0);

            // Retired rather than deleted, the same way a VAT rate is: an
            // operator who took this template keeps their copy, and the card
            // simply stops being offered.
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['is_active', 'sort_order'], 'policy_templates_offered_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_templates');
    }
};
