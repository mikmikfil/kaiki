<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether an operator takes a deposit and collects the rest later.
 *
 * ## The rate plan already knew how much; nobody could say whether
 *
 * `rate_plans.deposit_type` and its two amount columns have existed since #21,
 * so a plan could ask for 30% and there was no way for an operator to say "not
 * on my boat". That is a decision about how a business is run, not about how one
 * price list is written: an operator either takes deposits or does not, and
 * setting `none` on every rate plan one at a time is a setting expressed as a
 * chore.
 *
 * ## Off, for everyone, on purpose
 *
 * The product owner's instruction, and it is also the safer default. Partial
 * payment means a balance falls due later (PRC-27), a reminder has to be sent,
 * a guest has to come back and pay it, and an operator has to chase whoever
 * does not — a chain nobody should be opted into by a column default. An
 * operator who wants it turns it on and knows why.
 *
 * ## Nullable with a constant default, so it can land here
 *
 * `docs/data-model.md` §6: a nullable column with no foreign key and a constant
 * default is portable and may be added to an existing table. A `NOT NULL` one
 * would be a table rebuild on SQLite, which is the whole reason that rule
 * exists. Readers treat null as false, and {@see Tenant} casts it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->boolean('deposits_enabled')->nullable()->default(false)->after('auto_issue_invoice');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('deposits_enabled');
        });
    }
};
