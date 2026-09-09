<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Hosted\Support\BlockSettings;
use App\Enums\HomeBlockType;
use App\Models\HomePageBlock;
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
                if (HomePageBlock::query()->exists()) {
                    return;
                }

                foreach ($this->blocks($tenant) as $order => $block) {
                    HomePageBlock::create([
                        'type' => $block['type'],
                        'sort_order' => $order,
                        'is_visible' => true,
                        'heading' => $block['heading'],
                        'body' => $block['body'] ?? null,
                        'image_path' => $this->imageOrNull($tenant, $block['image'] ?? null),
                        'settings' => BlockSettings::normalise($block['type'], $block['settings'] ?? []),
                    ]);
                }
            });
        }
    }

    /**
     * @return list<array{type: HomeBlockType, heading: array<string, string>, body?: array<string, string>, image?: string, settings?: array<string, mixed>}>
     */
    private function blocks(Tenant $tenant): array
    {
        return [
            [
                'type' => HomeBlockType::Hero,
                'heading' => ['el' => 'Το Αιγαίο, μια μέρα τη φορά', 'en' => 'The Aegean, a day at a time'],
                'body' => [
                    'el' => 'Μικρές παρέες, έμπειροι καπετάνιοι και θάλασσα που τη γνωρίζουμε από παιδιά.',
                    'en' => 'Small groups, experienced skippers, and a sea we have known since we were children.',
                ],
                'image' => 'demo-hero.jpg',
                // No button. The search form is directly beneath the
                // standfirst and is what a visitor actually came to use —
                // a "see our trips" button above it competes with the thing
                // it is standing in front of.
                'settings' => ['cta' => 'none'],
            ],
            [
                'type' => HomeBlockType::Trips,
                'heading' => ['el' => 'Οι πιο δημοφιλείς εκδρομές', 'en' => 'Our best sellers'],
                // No limit. A limit of six capped the whole block, so all six
                // went into the featured rail and the "all trips" grid beneath
                // it had nothing left to show — the operator's own catalogue
                // was invisible on their own front page.
                'settings' => ['source' => BlockSettings::SOURCE_ALL, 'limit' => 0],
            ],
            [
                // The "about us". Title and prose on the left, photograph on the
                // right — `image_side` is a class on the section, because the
                // hosted CSP has no `unsafe-inline` and a `style` attribute
                // would be dropped by the browser.
                'type' => HomeBlockType::Story,
                'heading' => ['el' => 'Ποιοι είμαστε', 'en' => 'Who we are'],
                'body' => [
                    'el' => "Ξεκινήσαμε με ένα ξύλινο καΐκι και δύο αδέρφια που δεν ήθελαν να δουλέψουν σε γραφείο.\n\nΤριάντα χρόνια μετά, ο στόλος μεγάλωσε αλλά η μέρα έμεινε ίδια: φεύγουμε νωρίς, σταματάμε εκεί που το νερό είναι καθαρό, και γυρνάμε πριν πέσει ο ήλιος. Δεν παίρνουμε ποτέ περισσότερους από όσους χωράει άνετα το σκάφος.",
                    'en' => "We started with one wooden kaiki and two brothers who did not want to work in an office.\n\nThirty years on the fleet is bigger, but the day is the same: we leave early, we stop where the water is clear, and we are back before the sun goes down. We never take more people than the boat carries comfortably.",
                ],
                'image' => 'demo-story.jpg',
                'settings' => ['image_side' => 'left'],
            ],
            [
                'type' => HomeBlockType::Faq,
                'heading' => ['el' => 'Συχνές ερωτήσεις', 'en' => 'Common questions'],
            ],
            [
                'type' => HomeBlockType::Contact,
                'heading' => ['el' => 'Πού θα μας βρείτε', 'en' => 'Where to find us'],
                'image' => 'demo-contact.jpg',
            ],
        ];
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
}
