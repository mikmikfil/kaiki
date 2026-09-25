<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The credentials an outside system uses to call *us* (ADR-0034, spec EXT-1).
 *
 * ## Why this is a new pair of columns and not a field in `credentials`
 *
 * Every other secret on this table is something the operator pastes and we
 * send. This is the opposite direction: GetYourGuide's servers call Kaiki's
 * endpoints with HTTP Basic, and the request carries nothing else identifying —
 * no supplier id, no signature, no origin. The username **is** how we know
 * whose availability is being asked about.
 *
 * That makes it a lookup key, and `credentials` is `encrypted:array`: you
 * cannot index into it, and you cannot query it. The same argument that put
 * `webhook_token` in its own indexed column rather than inside the blob.
 *
 * ## The password is hashed, not encrypted
 *
 * Also unlike everything else here. An encrypted secret is one we have to read
 * back because we send it somewhere; this one is only ever *compared*. ADR-0013
 * settled the same question for API keys — store a hash, show the value once at
 * generation, and rotate by replacing rather than by revealing. A password we
 * cannot read is a password that cannot leak from a database dump, a log line
 * or a support ticket, and there is no feature that needs it back.
 *
 * The consequence is deliberate: an operator who loses it regenerates the pair
 * and pastes the new one into GetYourGuide. There is no "show me it again".
 *
 * `docs/data-model.md` §6: nullable columns with no foreign key may be added to
 * an existing table without a rebuild on SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_credentials', function (Blueprint $table): void {
            // Unguessable rather than derived from the tenant: a username that
            // encoded the operator would let anybody enumerate who sells here.
            $table->string('inbound_username', 64)->nullable()->after('webhook_token');

            // sha256, like `api_keys.secret_hash`. Never the password.
            $table->string('inbound_secret_hash', 64)->nullable()->after('inbound_username');

            // What the operator sees on the screen afterwards, so they can tell
            // which pair is in GetYourGuide's dashboard without it being the
            // pair. Four characters, as `IntegrationCredential::hint()` shows.
            $table->string('inbound_last_four', 4)->nullable()->after('inbound_secret_hash');

            $table->timestamp('inbound_rotated_at')->nullable()->after('inbound_last_four');

            // Globally unique, because the lookup runs before any tenant is
            // known — it is what *resolves* the tenant, so it cannot be scoped
            // to one. The same reasoning as `integr_creds_webhook_token_uq`.
            $table->unique('inbound_username', 'integr_creds_inbound_username_uq');
        });
    }

    public function down(): void
    {
        Schema::table('integration_credentials', function (Blueprint $table): void {
            $table->dropUnique('integr_creds_inbound_username_uq');
            $table->dropColumn(['inbound_username', 'inbound_secret_hash', 'inbound_last_four', 'inbound_rotated_at']);
        });
    }
};
