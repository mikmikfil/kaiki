<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\PolicyTemplate;
use Illuminate\Database\Seeder;

/**
 * The three ladders the setup guide has always offered, moved out of the code.
 *
 * These are the exact numbers and names that were in `Setup::presetLadder()`
 * and `lang/{el,en}/setup.php` before 2026-09-23, carried over unchanged so
 * that nothing a new operator sees moves on the day the table arrives. What
 * changes is only where they live: from now on they are rows a super-admin can
 * edit, reorder, retire or add to without a deploy.
 *
 * **Idempotent by `code`.** It runs on every `db:seed`, and an admin who has
 * since renamed «Κανονική» or moved a percentage must not have that overwritten
 * — so an existing row is left exactly as it is, and only a missing one is
 * created. The codes are the same three the old `const PRESETS` used, which is
 * also what lets an operator's recorded choice still make sense.
 */
class PolicyTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach (self::templates() as $template) {
            PolicyTemplate::query()->firstOrCreate(
                ['code' => $template['code']],
                $template,
            );
        }
    }

    /** @return list<array<string, mixed>> */
    private static function templates(): array
    {
        return [
            [
                'code' => 'flexible',
                'name' => ['el' => 'Ευέλικτη', 'en' => 'Flexible'],
                'summary' => ['el' => 'Πλήρης επιστροφή έως 24 ώρες πριν', 'en' => 'Full refund up to 24 hours before'],
                'free_cancellation_hours' => 24,
                'tiers' => [],
                'sort_order' => 10,
                'is_active' => true,
            ],
            [
                'code' => 'standard',
                'name' => ['el' => 'Κανονική', 'en' => 'Standard'],
                'summary' => ['el' => 'Πλήρης έως 7 ημέρες, μισή έως 2 ημέρες', 'en' => 'Full to 7 days, half to 2 days'],
                'free_cancellation_hours' => null,
                'tiers' => [
                    ['days_before' => 7, 'refund_percent' => 100],
                    ['days_before' => 2, 'refund_percent' => 50],
                ],
                'sort_order' => 20,
                'is_active' => true,
            ],
            [
                'code' => 'strict',
                'name' => ['el' => 'Αυστηρή', 'en' => 'Strict'],
                'summary' => ['el' => 'Μισή επιστροφή έως 14 ημέρες πριν', 'en' => 'Half refund up to 14 days before'],
                'free_cancellation_hours' => null,
                'tiers' => [
                    ['days_before' => 14, 'refund_percent' => 50],
                ],
                'sort_order' => 30,
                'is_active' => true,
            ],
        ];
    }
}
