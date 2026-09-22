<?php

declare(strict_types=1);

use App\Domain\Tenancy\Support\SetupChecklist;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two ways out of the setup guide, now that it holds the panel back.
 *
 * Product owner, 2026-09-22: *"the first time configurator should open
 * fullscreen and not have access to panel before setting this up"*, then —
 * immediately, and it is the half that makes the first half safe — *"but there
 * should be a button to totally skip it if operator wants to"*, and *"από κάπου
 * να ανοίγει συνέχεια ρύθμισης, αλλά να υπάρχει και τελείως skip"*.
 *
 * A gate with no way round it locks out the operator whose accountant has not
 * answered the VAT question yet — the exact case SAA-10 was written for. So the
 * gate ships with two exits, and they are different promises:
 *
 * `onboarding_deferred_at` — **«Θα το κάνω αργότερα».** The panel opens; the
 * guide stays in the menu with its badge and is picked up where it was left.
 * Set once, it never blocks again: the gate is an introduction, not a nag.
 *
 * `onboarding_dismissed_at` — **«Δεν το χρειάζομαι».** The operator is done with
 * the guide as a thing that appears: no gate, no menu item, no badge. It is
 * still reachable from Ρυθμίσεις, because "I do not want to be shown this" and
 * "I never want to see it again" are not the same sentence, and an operator who
 * changes their mind must not need support to find it.
 *
 * Neither replaces `onboarding_completed_at`, which means *finished* — a
 * different fact, and the one the checklist and the badge read.
 *
 * Nullable, no default, no foreign key (§6): a `NOT NULL` column here would
 * rebuild `tenants` on SQLite. Null is the right answer for every tenant that
 * existed before today — nobody has deferred or dismissed a gate that did not
 * exist. {@see SetupChecklist} reads both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->timestamp('onboarding_deferred_at')->nullable()->after('onboarding_skipped_steps');
            $table->timestamp('onboarding_dismissed_at')->nullable()->after('onboarding_deferred_at');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn(['onboarding_deferred_at', 'onboarding_dismissed_at']);
        });
    }
};
