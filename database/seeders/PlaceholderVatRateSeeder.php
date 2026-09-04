<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\VatRate;
use Illuminate\Database\Seeder;

/**
 * One VAT rate for local work, and it is **not a real rate** (spec CAT-11b, MYD-6a).
 *
 * ## Why anything at all is seeded
 *
 * `products.vat_rate_id` and `extras.vat_rate_id` are foreign keys into an
 * otherwise empty table. A developer opening `/app` to build or review a
 * product has nothing to select, and the demo catalogue cannot be completed by
 * clicking — which is the state #18 and #22 are built in.
 *
 * ## Why it cannot be mistaken for a real one
 *
 * CAT-11b and MYD-6a forbid any seeder presenting a percentage as
 * authoritative. Brief §10 says passenger transport is *typically* 13% and
 * other tourist services *typically* 24% — and explicitly refuses to fix them,
 * because they are an accountant's decision and they differ by island regime.
 *
 * So this row is **1 basis point — 0.01%**. No tax authority charges it, no
 * invoice would survive it, and nobody reading a test that asserts on it could
 * believe the project had decided anything. Its `vat_category` is `8`, the AADE
 * id for records without VAT, which is the honest category for a rate that is
 * not a rate. Both descriptions say so, in capitals, in the operator's own
 * language — the description is rendered in the product form and on the
 * invoice, so the warning appears exactly where using it would be a mistake.
 *
 * `VatRateTest` asserts no seeder writes a Greek statutory percentage, which is
 * what keeps this file honest after the next person edits it.
 *
 * **Not registered in `DatabaseSeeder` for production.** It is demo data, run
 * alongside the demo tenants and catalogue.
 */
class PlaceholderVatRateSeeder extends Seeder
{
    /** 0.01%. Deliberately absurd — see the class docblock. */
    private const PLACEHOLDER_BASIS_POINTS = 1;

    public function run(): void
    {
        VatRate::query()->updateOrCreate(
            ['code' => 'placeholder_not_a_real_rate', 'valid_from' => '2020-01-01'],
            [
                'rate_bp' => self::PLACEHOLDER_BASIS_POINTS,
                'vat_category' => '8',
                'description' => [
                    'el' => 'ΠΡΟΣΩΡΙΝΟΣ — δεν είναι πραγματικός συντελεστής. Ζητήστε τους σωστούς από τον λογιστή σας πριν εκδώσετε παραστατικό.',
                    'en' => 'PLACEHOLDER — not a real rate. Ask your accountant for the correct ones before issuing anything.',
                ],
                'valid_to' => null,
                'is_selectable' => true,
            ],
        );
    }
}
