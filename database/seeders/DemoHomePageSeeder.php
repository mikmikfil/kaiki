<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Hosted\Actions\SaveHomePage;
use App\Enums\HomeBlockType;
use App\Models\HomePageBlock;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * The demo operator's home page, including the "about us" (HOS-3, #102).
 *
 * ## Why this seeder had to exist
 *
 * The demo tenant's home page was built by hand during #102 and lived only in
 * the development database — no seeder wrote it, so a fresh `migrate:fresh
 * --seed` produced the default four-block fallback and the page nobody had
 * seen. That is the failure mode where a feature looks unbuilt because the demo
 * data does not exercise it, which is exactly what happened to the story block:
 * it has had a template, a CSS grid, an `image_side` control and a panel form
 * since #102, and it was absent from every screen anybody actually looked at.
 *
 * The same applies to the sections of 16 September, so `aegean-blue` gets every
 * one of them, written in the words of its own WordPress site: the three steps,
 * the reasons, the numbers, the reviews and the private-charter band. The other demo operators keep the five-section page they always had.
 *
 * ## Through `SaveHomePage`, like the editor
 *
 * The rows are written by the same Action the panel calls, so the settings,
 * the entries and the buttons are normalised exactly as an operator's save
 * would normalise them — a seeder cannot store a shape the editor could not.
 *
 * ## The order is the page an operator writes
 *
 * Masthead, what we sell, who we are, the questions, how to reach us. The story
 * sits **after** the trips: somebody arriving on this page came to look at boat
 * trips, and the paragraph about the family reads better as the reason to book
 * the one they have just been looking at than as an obstacle in front of it.
 *
 * {@see HomeBlockType::defaultLayout()} deliberately does **not** include it,
 * and that stays true: the default is what an operator gets before they have
 * written anything, and an empty "about us" heading on a page nobody has edited
 * is worse than no block. This seeder is a demo operator who *has* written one.
 */
class DemoHomePageSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Tenant::query()->orderBy('id')->get() as $tenant) {
            Tenancy::forTenant($tenant, function () use ($tenant): void {
                // Only for an operator who has never touched the editor.
                // Overwriting a page somebody arranged by hand — including the
                // one in this development database — is not a seeder's job.
                if (HomePageBlock::query()->onPage(HomePageBlock::PAGE_HOME)->exists()) {
                    return;
                }

                app(SaveHomePage::class)($this->blocks($tenant));
            });
        }
    }

    /**
     * The page, in the shape `SaveHomePage` takes.
     *
     * Public so a development database whose page predates a new section can
     * be brought up to date from the same source — inside the tenant's scope.
     *
     * @return list<array<string, mixed>>
     */
    public function blocks(Tenant $tenant): array
    {
        $full = $tenant->slug === 'aegean-blue';

        return array_values(array_filter([
            [
                'type' => HomeBlockType::Hero->value,
                'eyebrow' => $full ? ['el' => 'Εκδρομές με σκάφος από τον Πειραιά', 'en' => 'Boat trips from Piraeus'] : null,
                'heading' => ['el' => 'Το Αιγαίο, μια μέρα τη φορά', 'en' => 'The Aegean, a day at a time'],
                'body' => [
                    'el' => 'Μικρές παρέες, έμπειροι καπετάνιοι και θάλασσα που τη γνωρίζουμε από παιδιά.',
                    'en' => 'Small groups, experienced skippers, and a sea we have known since we were children.',
                ],
                'image_path' => $this->imageOrNull($tenant, 'demo-hero.jpg'),
                // No button on the plain demo pages. The search form is directly
                // beneath the standfirst and is what a visitor actually came to
                // use — a "see our trips" button above it competes with the
                // thing it is standing in front of. The full demo has the two
                // buttons and the badges of the operator's WordPress masthead.
                'settings' => ['cta' => 'none'],
                ...($full ? [
                    'buttons' => [
                        ['label' => ['el' => 'Δείτε τις εκδρομές', 'en' => 'See our trips'], 'target' => 'trips'],
                        ['label' => ['el' => 'Ιδιωτική ναύλωση', 'en' => 'Private charter'], 'target' => 'contact'],
                    ],
                    'items' => [
                        ['icon' => 'star', 'text' => ['el' => '4,9 / 5 από 1.200+ κριτικές', 'en' => '4.9 / 5 from 1,200+ reviews']],
                        ['icon' => 'sun', 'text' => ['el' => 'Εγγύηση καιρού', 'en' => 'Weather guarantee']],
                        ['icon' => 'check', 'text' => ['el' => 'Άμεση επιβεβαίωση', 'en' => 'Instant confirmation']],
                    ],
                ] : []),
            ],
            [
                'type' => HomeBlockType::Trips->value,
                'eyebrow' => $full ? ['el' => 'Εκδρομές', 'en' => 'Trips'] : null,
                'heading' => $full
                    ? ['el' => 'Διαλέξτε τη δική σας μέρα στη θάλασσα', 'en' => 'Choose your own day at sea']
                    : ['el' => 'Οι πιο δημοφιλείς εκδρομές', 'en' => 'Our best sellers'],
                // No limit. A limit of six capped the whole block, so all six
                // went into the featured rail and the "all trips" grid beneath
                // it had nothing left to show — the operator's own catalogue
                // was invisible on their own front page.
                'settings' => ['source' => 'all', 'limit' => 0],
            ],
            $full ? [
                'type' => HomeBlockType::Steps->value,
                'eyebrow' => ['el' => 'Πώς λειτουργεί', 'en' => 'How it works'],
                'heading' => ['el' => 'Από την οθόνη σας στο κατάστρωμα', 'en' => 'From your screen to the deck'],
                'items' => [
                    ['title' => ['el' => 'Διαλέξτε εκδρομή', 'en' => 'Choose a trip'], 'text' => ['el' => 'Δείτε τις ελεύθερες θέσεις κάθε μέρας και την τιμή για την παρέα σας.', 'en' => 'See the free seats for every day and the price for your group.']],
                    ['title' => ['el' => 'Πληρώστε online', 'en' => 'Pay online'], 'text' => ['el' => 'Με κάρτα, με ασφάλεια. Το εισιτήριο έρχεται αμέσως στο email σας.', 'en' => 'By card, securely. Your ticket arrives by email straight away.']],
                    ['title' => ['el' => 'Ελάτε στη Μαρίνα Ζέας', 'en' => 'Meet us at Zea Marina'], 'text' => ['el' => '15 λεπτά πριν. Εμείς φέρνουμε τον καφέ, εσείς το μαγιό.', 'en' => '15 minutes before. We bring the coffee, you bring a swimsuit.']],
                ],
            ] : null,
            [
                // The "about us". Title and prose on the left, photograph on the
                // right — `image_side` is a class on the section, because the
                // hosted CSP has no `unsafe-inline` and a `style` attribute
                // would be dropped by the browser.
                'type' => HomeBlockType::Story->value,
                'eyebrow' => $full ? ['el' => 'Ποιοι είμαστε', 'en' => 'Who we are'] : null,
                'heading' => $full
                    ? ['el' => 'Ένα ξύλινο καΐκι και δύο αδέρφια', 'en' => 'One wooden kaiki and two brothers']
                    : ['el' => 'Ποιοι είμαστε', 'en' => 'Who we are'],
                'body' => [
                    'el' => "Ξεκινήσαμε με ένα ξύλινο καΐκι και δύο αδέρφια που δεν ήθελαν να δουλέψουν σε γραφείο.\n\nΤριάντα χρόνια μετά, ο στόλος μεγάλωσε αλλά η μέρα έμεινε ίδια: φεύγουμε νωρίς, σταματάμε εκεί που το νερό είναι καθαρό, και γυρνάμε πριν πέσει ο ήλιος. Δεν παίρνουμε ποτέ περισσότερους από όσους χωράει άνετα το σκάφος.",
                    'en' => "We started with one wooden kaiki and two brothers who did not want to work in an office.\n\nThirty years on the fleet is bigger, but the day is the same: we leave early, we stop where the water is clear, and we are back before the sun goes down. We never take more people than the boat carries comfortably.",
                ],
                'image_path' => $this->imageOrNull($tenant, 'demo-story.jpg'),
                'image_alt' => ['el' => 'Το καΐκι μας δεμένο στη μαρίνα', 'en' => 'Our kaiki moored in the marina'],
                'settings' => ['image_side' => 'left'],
            ],
            // A second story under the first, photograph on the other side, so
            // the two read as a zig-zag (Mike, 2026-09-24).
            $full ? [
                'type' => HomeBlockType::Story->value,
                'eyebrow' => ['el' => 'Πώς ταξιδεύουμε', 'en' => 'How we sail'],
                'heading' => ['el' => 'Μικρές παρέες, μεγάλη θάλασσα', 'en' => 'Small groups, a big sea'],
                'body' => [
                    'el' => 'Δεν βάζουμε ποτέ πάνω από δώδεκα άτομα στο σκάφος. Έτσι χωράνε όλοι στην πλώρη, ο κυβερνήτης ξέρει τα ονόματά σας, και σταματάμε όπου η παρέα θέλει να κολυμπήσει.

Το πρόγραμμα το κανονίζει ο καιρός, όχι το ρολόι: αν φυσάει βοριάς πάμε στην απάνεμη πλευρά, αν η θάλασσα είναι λάδι μένουμε λίγο παραπάνω.',
                    'en' => 'We never take more than twelve people aboard. Everyone fits on the bow, the captain knows your names, and we stop wherever the group wants to swim.

The weather sets the plan, not the clock: if the north wind blows we go to the sheltered side, and if the sea is like glass we stay a little longer.',
                ],
                'image_path' => $this->productImageOrNull('istioploia-me-pania'),
                'image_alt' => ['el' => 'Τα πανιά ανοιχτά στο ηλιοβασίλεμα', 'en' => 'Sails up at sunset'],
                'settings' => ['image_side' => 'right'],
            ] : null,
            $full ? [
                'type' => HomeBlockType::Features->value,
                'eyebrow' => ['el' => 'Γιατί Aegean Blue', 'en' => 'Why Aegean Blue'],
                'heading' => ['el' => 'Όλα όσα χρειάζεστε για μια ήσυχη μέρα', 'en' => 'Everything you need for an easy day'],
                'settings' => ['dark' => true],
                'items' => [
                    ['icon' => 'users', 'title' => ['el' => 'Μικρές παρέες', 'en' => 'Small groups'], 'text' => ['el' => 'Ποτέ περισσότεροι απ’ όσους χωράει άνετα το σκάφος.', 'en' => 'Never more people than the boat carries comfortably.']],
                    ['icon' => 'anchor', 'title' => ['el' => 'Τριάντα χρόνια εμπειρίας', 'en' => 'Thirty years at sea'], 'text' => ['el' => 'Καπετάνιοι που ξέρουν κάθε όρμο του Σαρωνικού.', 'en' => 'Skippers who know every cove in the Saronic.']],
                    ['icon' => 'shield', 'title' => ['el' => 'Εγγύηση καιρού', 'en' => 'Weather guarantee'], 'text' => ['el' => 'Αν δεν βγούμε λόγω καιρού, άλλη μέρα ή τα χρήματά σας πίσω.', 'en' => 'If the weather keeps us in, another day or your money back.']],
                    ['icon' => 'lock', 'title' => ['el' => 'Ασφαλής πληρωμή', 'en' => 'Secure payment'], 'text' => ['el' => 'Πληρώνετε online και το εισιτήριο έρχεται αμέσως στο email.', 'en' => 'Pay online and your ticket arrives by email straight away.']],
                ],
            ] : null,
            $full ? [
                'type' => HomeBlockType::Testimonials->value,
                'eyebrow' => ['el' => 'Κριτικές', 'en' => 'Reviews'],
                'heading' => ['el' => 'Τι λένε όσοι ταξίδεψαν μαζί μας', 'en' => 'What our guests say'],
                'items' => [
                    [
                        'quote' => ['el' => 'Η καλύτερη μέρα των διακοπών μας. Ο καπετάνιος ήξερε όρμους που δεν θα βρίσκαμε ποτέ μόνοι μας, και τα παιδιά δεν ήθελαν να κατέβουν.', 'en' => 'The best day of our holiday. The skipper knew coves we would never have found on our own, and the children did not want to get off.'],
                        'name' => 'Ελένη Π.',
                        'trip' => ['el' => 'Ολοήμερη στα τρία νησιά', 'en' => 'Three islands in a day'],
                        'rating' => 5,
                    ],
                    [
                        'quote' => ['el' => 'Κλείσαμε το ηλιοβασίλεμα για την επέτειό μας. Κρασί, μεζέδες και η Αίγινα να βάφεται πορτοκαλί. Άψογη οργάνωση από την αρχή ως το τέλος.', 'en' => 'We booked the sunset trip for our anniversary. Wine, meze and Aegina turning orange. Faultless from start to finish.'],
                        'name' => 'Νίκος & Μαρία',
                        'trip' => ['el' => 'Ηλιοβασίλεμα στην Αίγινα', 'en' => 'Sunset at Aegina'],
                        'rating' => 5,
                    ],
                    [
                        'quote' => ['el' => 'We booked in two minutes on our phone and got the ticket straight away. Small group, friendly crew, crystal water. Highly recommended!', 'en' => 'We booked in two minutes on our phone and got the ticket straight away. Small group, friendly crew, crystal water. Highly recommended!'],
                        'name' => 'Sarah K.',
                        'trip' => ['el' => 'Πρωινό κολυμβητικό', 'en' => 'Morning swim'],
                        'rating' => 5,
                    ],
                ],
            ] : null,
            $full ? [
                'type' => HomeBlockType::Cta->value,
                'eyebrow' => ['el' => 'Ιδιωτικές ναυλώσεις', 'en' => 'Private charters'],
                'heading' => ['el' => 'Όλο το σκάφος, μόνο για την παρέα σας', 'en' => 'The whole boat, just for your group'],
                'body' => [
                    'el' => 'Γενέθλια, πρόταση γάμου, εταιρική εκδρομή ή απλώς μια μέρα χωρίς αγνώστους. Πείτε μας ημερομηνία και άτομα, και σας στέλνουμε προσφορά μέσα στη μέρα.',
                    'en' => 'A birthday, a proposal, a company outing or simply a day without strangers. Tell us the date and how many of you, and we send a quote the same day.',
                ],
                // Friends on deck at sunset (Mike, 24/9): the private-trips band's
                // photograph, from the pool the demo images seeder copies.
                'image_path' => $this->publicFileOrNull(sprintf('products/%d/ilioyasilema-me-krasi.jpg', $tenant->getKey()))
                    ?? $this->productImageOrNull('idiotiki-imera-skafos'),
                'image_alt' => ['el' => 'Ιστιοφόρο στη θάλασσα', 'en' => 'A sailing boat at sea'],
                'buttons' => array_values(array_filter([
                    ['label' => ['el' => 'Ζητήστε προσφορά', 'en' => 'Ask for a quote'], 'target' => 'contact'],
                    ($charter = Product::query()->where('slug', 'idiotiki-imera-skafos')->first()) instanceof Product
                        ? ['label' => ['el' => 'Δείτε την ιδιωτική ημέρα', 'en' => 'See the private day'], 'target' => 'trip', 'product_id' => $charter->getKey()]
                        : null,
                ])),
            ] : null,
            [
                'type' => HomeBlockType::Faq->value,
                'heading' => ['el' => 'Συχνές ερωτήσεις', 'en' => 'Common questions'],
            ],
            [
                'type' => HomeBlockType::Contact->value,
                'heading' => ['el' => 'Επικοινωνήστε μαζί μας', 'en' => 'Get in touch'],
                'image_path' => $this->imageOrNull($tenant, 'demo-contact.jpg'),
            ],
        ]));
    }

    /**
     * A missing photograph is a block without one, not a broken image.
     *
     * The files are put on the disk by {@see DemoImageSeeder}, which runs
     * before this one, and for the first operator only. Until #132 that seeder
     * did not exist and this docblock claimed the images were committed when
     * `git ls-files` listed none of them: they lived on one development machine
     * and a fresh `migrate:fresh --seed` produced a page with no photographs.
     *
     * Every block type that takes an image renders fine without one — the story
     * block drops to a single column. The second demo operator used to keep its
     * page in prose on the stated grounds that nothing else exercised that
     * path; `StoryBlockSideTest`'s `story-plain` case always did, so it has
     * photographs of its own now. A tenant with no pictures still renders, and
     * this method is what makes that true.
     */
    private function imageOrNull(Tenant $tenant, ?string $file): ?string
    {
        if ($file === null) {
            return null;
        }

        $path = sprintf('branding/%d/%s', $tenant->getKey(), $file);

        return Storage::disk('public')->exists($path) ? $path : null;
    }

    /** A file already on the public disk, or null. */
    private function publicFileOrNull(string $path): ?string
    {
        return Storage::disk('public')->exists($path) ? $path : null;
    }

    /** One of a trip's own photographs, for the call-to-action band, if it is on the disk. */
    private function productImageOrNull(string $slug): ?string
    {
        $product = Product::query()->where('slug', $slug)->first();
        $path = $product instanceof Product ? ($product->images[0]['path'] ?? null) : null;

        return is_string($path) && Storage::disk('public')->exists($path) ? $path : null;
    }
}
