<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The three invoicing settings the tenants table was missing
 * (spec MYD-3.2, MYD-4.5, ADR-0003, ADR-0022 Option C).
 *
 * ## `invoicing_mode` — the operator who already has an invoicing system
 *
 * MYD-4.5 gives every operator an escape hatch. Plenty of Greek boat operators
 * already issue through a bookkeeper's software and have no intention of moving;
 * for them Kaiki issuing a second document against the same sale is not a
 * feature, it is a duplicate in their register.
 *
 * `external` disables issuance entirely and the panel offers a "record external
 * invoice" form instead, storing the operator's own number and MARK so
 * reconciliation still works. **Nothing about this is a downgrade** — a booking
 * engine that refuses to work alongside an accountant's software is a booking
 * engine an accountant tells them not to buy.
 *
 * ## `invoice_auto_issue` — on, and delayed
 *
 * ADR-0003 puts the default **on**, with the job running
 * `invoice_auto_issue_delay_minutes` after `BookingConfirmed` rather than
 * immediately. The delay exists so a guest has a window to say "I need a company
 * invoice" on the confirmation page: issue instantly and every one of those
 * becomes a credit note plus a re-issue (MYD-3.7), which is two extra documents
 * in the series for a question that was answered ninety seconds late.
 *
 * Fifteen minutes is ADR-0003's number.
 *
 * ## The column table said `auto_issue_invoice` and the requirement says otherwise
 *
 * `tenants.auto_issue_invoice` exists already, defaulting to **false**, while
 * MYD-3.2 and ADR-0003 both say auto-issue defaults **on**. One of the two is
 * wrong and it is not obvious which was intended, so this migration does not
 * quietly flip the existing column: it adds the pair the requirement names, and
 * `docs/data-model.md` is amended in the same commit to record that
 * `auto_issue_invoice` is superseded. Changing a default that already sits in
 * every seeded tenant, inside a migration that is meant to be additive, is how
 * a test that has passed for months starts failing for a reason nobody can find.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('invoicing_mode', 16)->default('kaiki')->after('auto_issue_invoice');
            $table->boolean('invoice_auto_issue')->default(true)->after('invoicing_mode');
            $table->unsignedSmallInteger('invoice_auto_issue_delay_minutes')->default(15)->after('invoice_auto_issue');

            // The operator's own series letter. Greek series are usually a
            // single letter and «Α» is the ordinary first one, but it is the
            // operator's choice and their accountant's, not ours — so it is a
            // column with a sensible default rather than a constant.
            $table->string('invoice_series', 16)->default('A')->after('invoice_auto_issue_delay_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn([
                'invoicing_mode',
                'invoice_auto_issue',
                'invoice_auto_issue_delay_minutes',
                'invoice_series',
            ]);
        });
    }
};
