<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Faq;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Database\Seeder;

/**
 * The questions the two demo operators are tired of answering (ENV-13, #103).
 *
 * **Deterministic**, like every other demo seeder: fixed questions, fixed order,
 * no faker, and an `updateOrCreate` keyed on the slot an entry occupies, so
 * re-seeding is idempotent. See {@see self::entry()} for why the key is not the
 * question itself.
 *
 * The point of seeding these at all is that the FAQ is invisible until somebody
 * writes one — the block renders nothing when there is nothing published — so a
 * developer opening the hosted page would see the feature as an absence and
 * conclude it was not built. Aegean Blue gets both shapes: three answers about
 * the business and one about a single trip, which is the distinction the whole
 * table exists for.
 */
class DemoFaqSeeder extends Seeder
{
    public function run(): void
    {
        $aegean = Tenant::query()->where('slug', 'aegean-blue')->first();
        $ionian = Tenant::query()->where('slug', 'ionian-sunset')->first();

        if ($aegean instanceof Tenant) {
            $this->seedAegean($aegean);
        }

        if ($ionian instanceof Tenant) {
            $this->seedIonian($ionian);
        }
    }

    private function seedAegean(Tenant $tenant): void
    {
        // Inside the tenant's context rather than passing `tenant_id` by hand,
        // so the seeder takes the path application code takes.
        Tenancy::forTenant($tenant, function (): void {
            $this->entry([
                'question' => ['el' => 'Πού συναντιόμαστε;', 'en' => 'Where do we meet?'],
                'answer' => [
                    'el' => "Στο μπλε περίπτερο της Μαρίνας Ζέας, δίπλα στον προβλήτα Δ.\n\nΕλάτε 15 λεπτά νωρίτερα για να προλάβουμε την επιβίβαση με την ησυχία μας.",
                    'en' => "At the blue kiosk in Zea Marina, beside pier D.\n\nCome 15 minutes early so we can board without rushing.",
                ],
                'sort_order' => 0,
            ]);

            $this->entry([
                'question' => ['el' => 'Τι γίνεται αν έχει κακοκαιρία;', 'en' => 'What happens if the weather is bad?'],
                'answer' => [
                    'el' => 'Αν το λιμεναρχείο απαγορεύσει τον απόπλου, σας ειδοποιούμε το πρωί και επιλέγετε: άλλη ημερομηνία ή πλήρης επιστροφή χρημάτων.',
                    'en' => 'If the port authority stops sailings we tell you that morning, and you choose: another date, or a full refund.',
                ],
                'sort_order' => 1,
            ]);

            $this->entry([
                'question' => ['el' => 'Χρειάζεται να ξέρω κολύμπι;', 'en' => 'Do I need to know how to swim?'],
                'answer' => [
                    'el' => 'Όχι. Δίνουμε σωσίβια σε όλους και η στάση για μπάνιο είναι προαιρετική.',
                    'en' => 'No. We hand out life jackets to everyone and the swimming stop is optional.',
                ],
                'sort_order' => 2,
            ]);

            // The exception the nullable `product_id` exists for: true of the
            // sunset cruise and false of the private charter beside it.
            $sunset = Product::query()->orderBy('id')->first();

            if ($sunset instanceof Product) {
                $this->entry([
                    'product_id' => $sunset->getKey(),
                    'question' => ['el' => 'Σερβίρετε φαγητό στο ηλιοβασίλεμα;', 'en' => 'Is food served on the sunset cruise?'],
                    'answer' => [
                        'el' => 'Περιλαμβάνεται ένα ποτήρι κρασί και μεζεδάκια. Για πλήρες γεύμα πείτε μας δύο ημέρες πριν.',
                        'en' => 'A glass of wine and small plates are included. For a full meal, tell us two days ahead.',
                    ],
                    'sort_order' => 0,
                ]);
            }
        });
    }

    private function seedIonian(Tenant $tenant): void
    {
        // The English-first trial account: one entry, because a single-boat
        // operator who has just signed up has written one.
        Tenancy::forTenant($tenant, function (): void {
            $this->entry([
                'question' => ['el' => 'Πόσα άτομα χωράει το σκάφος;', 'en' => 'How many people fit on the boat?'],
                'answer' => [
                    'el' => 'Έως οκτώ επιβάτες, με τον καπετάνιο πάντα μαζί σας.',
                    'en' => 'Up to eight passengers, with the skipper always aboard.',
                ],
                'sort_order' => 0,
            ]);
        });
    }

    /**
     * One entry, keyed on the slot it occupies so re-seeding does not duplicate.
     *
     * Keyed on `product_id` and `sort_order` rather than on the question, which
     * is the natural key and is a **translatable JSON column** — matching on it
     * would mean `where('question->el', …)`, the exact JSON-path query ENV-8
     * forbids and `NoJsonPathQueryTest` scans `database/` for. Two plain integer
     * columns identify the row this seeder owns just as well.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function entry(array $attributes): Faq
    {
        return Faq::query()->updateOrCreate(
            [
                'product_id' => $attributes['product_id'] ?? null,
                'sort_order' => $attributes['sort_order'],
            ],
            $attributes,
        );
    }
}
