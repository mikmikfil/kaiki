<?php

declare(strict_types=1);

use App\Domain\Tenancy\Support\SetupChecklist;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two things about the setup wizard that cannot be derived (#51, SAA-9, SAA-10).
 *
 * ## Almost nothing is stored, on purpose
 *
 * {@see SetupChecklist} answers each step from the data it is about — a boat
 * exists or it does not, an ΑΦΜ is filled in or it is not. That is what makes
 * SAA-10's *"resumable"* free and, more importantly, correct: an operator who
 * adds their first boat from the Σκάφη screen without ever opening the wizard
 * has done that step, and a stored pointer would still be telling them to do
 * it. A checkbox somebody has to remember to tick is a second version of the
 * truth.
 *
 * Two things genuinely cannot be read back from the data:
 *
 * `onboarding_skipped_steps` — SAA-10 makes skipping a **requirement**, not a
 * convenience: an operator whose accountant has not answered the VAT question
 * must still reach the panel and add their boats. A skipped step and an
 * untouched step look identical in the data and must not look identical on the
 * screen, or the checklist nags for ever about something already declined.
 *
 * `onboarding_completed_at` — the end. The last step of SAA-9 hands over the
 * embed snippet and the hosted-page link; there is no row anywhere that says
 * whether anyone read it. It is also what stops the checklist: SAA-10 says an
 * operator who has not finished sees a persistent checklist, and the corollary
 * is that one who has finished stops seeing it.
 *
 * ## Nullable, no foreign key, constant defaults
 *
 * §6, the same rule the deposits column followed. A `NOT NULL` column here
 * would be a `tenants` rebuild on SQLite, which is what that rule exists to
 * prevent. Null reads as "not finished" and as "nothing skipped", which are the
 * right answers for every tenant that existed before this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->timestamp('onboarding_completed_at')->nullable()->after('default_vat_rate_id');
            $table->json('onboarding_skipped_steps')->nullable()->after('onboarding_completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn(['onboarding_completed_at', 'onboarding_skipped_steps']);
        });
    }
};
