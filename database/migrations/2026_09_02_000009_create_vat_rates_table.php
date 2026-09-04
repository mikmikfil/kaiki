<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The platform-owned VAT reference table (`docs/data-model.md` §2.3,
 * spec CAT-11, ADR-0002 Option A).
 *
 * Item **9** in the §6 order, and it has to be: `products` (16) and `extras`
 * (20) both hold a foreign key to it, and §6's own rule is that no migration
 * ever adds a foreign key to an existing table. Landing this after either of
 * them is a table rebuild on SQLite, permanently.
 *
 * The three M1 migrations already committed were renumbered 10, 11 and 12 to
 * take this in front of them, so the filename suffix keeps meaning the §6 item
 * number. Nothing is deployed; the cost is one `migrate:fresh` locally.
 *
 * ## Not tenant-owned, and that is the whole point
 *
 * **No `tenant_id`, no `BelongsToTenant`.** VAT rates are set by Greek tax law,
 * not by operators: a super-admin maintains the rows and an operator only
 * *selects* one per product or extra. TEN-5 allows no implicit exemption from
 * the tenant scope, so `VatRate` is named in `platform_owned_models` in
 * `config/tenancy.php` — one list a reviewer can read, rather than a property
 * of whichever trait somebody forgot to add.
 *
 * ## A statutory change is a new row, never an edit
 *
 * `valid_from` is part of the unique key with `code`, and `valid_to` is
 * nullable meaning "currently in force". Editing `rate_bp` in place would
 * rewrite what every past product resolved to — which the price snapshot
 * protects against downstream, but the reference table should not be lying
 * either. `is_selectable` retires a superseded row from the product form
 * without breaking the rows that already point at it.
 *
 * ## No default on `rate_bp`, deliberately
 *
 * Brief §10 says passenger transport is *typically* 13% and other tourist
 * services *typically* 24%, and explicitly refuses to fix them. A column
 * default here would be this project deciding a number that is an accountant's
 * to decide, and it would be the number every row inherited by accident.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vat_rates', function (Blueprint $table): void {
            $table->id();

            // Stable machine key, e.g. `gr_reduced_transport`. Referenced in
            // support tickets and in the accountant's own notes, so it is a
            // readable slug rather than an id.
            $table->string('code', 32);

            // **Basis points**, so 1300 is 13.00%. An integer for the same
            // reason money is: a rate that renders as 12.999999 on an invoice
            // is a rate that will be argued about with the tax authority.
            // No default — see the class docblock.
            $table->unsignedSmallInteger('rate_bp');

            // The AADE myDATA `vatCategory` id. It lives **here, next to the
            // percent**, so the percent-to-category mapping is data rather than
            // a `match` in PHP that a statutory change would strand (CAT-11a).
            $table->string('vat_category', 16);

            // Translatable (§1.6). Shown in the product form and on the
            // invoice, so it is read by an operator and by their customer.
            $table->json('description');

            $table->date('valid_from');

            // Null means currently in force. A closed row is history, kept
            // because invoices issued under it still resolve through it.
            $table->date('valid_to')->nullable();

            // False hides a superseded rate from selection lists without
            // breaking existing references. Not a soft delete: the row is still
            // readable, still joinable, and still the truth about what was
            // charged.
            $table->boolean('is_selectable')->default(true);

            $table->timestamps();

            // A statutory change is a new row with a later `valid_from`, so the
            // code alone cannot be unique.
            $table->unique(['code', 'valid_from'], 'vat_rates_code_from_unique');

            // The lookup the pricing engine makes: which rate was in force on
            // this date.
            $table->index(['valid_from', 'valid_to'], 'vat_rates_validity_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vat_rates');
    }
};
