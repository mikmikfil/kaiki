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
 * Masthead, who we are, what we sell, the questions, how to reach us. The story
 * sits second on purpose: a guest who has just read the headline is deciding
 * whether these are people they want to spend a day at sea with, and the answer
 * to that is the paragraph about the family and the boat — not the price list.
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
                'settings' => ['cta' => 'trips'],
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
                'settings' => ['image_side' => 'right'],
            ],
            [
                'type' => HomeBlockType::Trips,
                'heading' => ['el' => 'Οι εκδρομές μας', 'en' => 'Our trips'],
                'settings' => ['source' => BlockSettings::SOURCE_ALL, 'limit' => 6],
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
     * The demo images are committed for tenant 1 only. Every block type that
     * takes an image renders fine without it — the story block drops to a
     * single column — so a second demo operator gets the same page in prose.
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
