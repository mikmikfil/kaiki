<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A number that was taken and never used (spec MYD-4.4, ADR-0022).
 *
 * ## What this table is for
 *
 * A number is allocated at the send attempt. If the send then fails **hard** —
 * not a retry, a permanent refusal — the number has left the counter and no
 * document carries it. MYD-4.4 permits that and requires it to be written down:
 * *"Gaps are permitted and MUST be logged."*
 *
 * An unexplained gap in a Greek invoicing series is a question an accountant
 * asks and an operator cannot answer. This table is the answer: the number, the
 * reason, the moment, and the invoice that was being sent. It is shown in the
 * panel in Greek, so the operator has it before their accountant does.
 *
 * ## ⚠ The gap policy is not settled, and this table holds either way
 *
 * ADR-0022 was accepted on engineering grounds. Whether *any* gap is acceptable
 * in a Greek invoicing series is not an engineering question, and spec §16.3
 * flags it as needing an accountant's sign-off before M6 ships.
 *
 * **If gaps are ruled out, only the allocation instant moves** — allocate after
 * AADE returns a MARK rather than before the attempt. This table is then rarely
 * or never written, and it stays: a table with no rows is the correct shape for
 * a thing that must not happen but has to be recorded if it does.
 *
 * ## Rows are written, never updated or deleted
 *
 * The same reasoning as `invoices` (§1.4). A gap that could be tidied away is a
 * gap that will be, on the afternoon it looks embarrassing, which is precisely
 * when the record matters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_number_gaps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('series', 16);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('number');

            // Nullable rather than required: the invoice row is what was being
            // sent, and a gap can outlive nothing but is easier to read with it.
            // `nullOnDelete` never fires today — invoices are never deleted —
            // and is here so this table cannot become the reason a delete fails.
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();

            // Why the number was lost, in a form the panel can translate.
            $table->string('reason_code', 32);
            $table->text('reason_detail')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'series', 'year'], 'invoice_gaps_tenant_series_year_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_number_gaps');
    }
};
