<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Product;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * A picture on every trip (#132).
 *
 * ## Why the demo had none
 *
 * `products.images` was empty for all twenty-one demo trips, so the hosted page
 * rendered twenty-one copies of `.trip-image.is-empty` — the tinted panel a new
 * operator sees before they have uploaded anything. That state is correct and
 * deliberate for a real operator on their first afternoon, and it is the wrong
 * thing for a demo to be made of: a row of text cards is a list, a row of
 * pictures is a shop, and the demo exists to show somebody the shop.
 *
 * Two files did exist on one development machine and in no repository, which is
 * the same reason {@see DemoHomePageSeeder} shipped with a docblock promising
 * committed images that were not committed. Both are fixed here: everything
 * this seeder copies lives in `database/seeders/assets/images/`, committed.
 *
 * ## They were drawn, and now they are photographs
 *
 * #132 generated them — five times of day, five boat silhouettes, printed to
 * JPEG by Chromium — because the alternative was sixteen stock photographs with
 * no licence attached sitting in `database/`, which is a liability rather than
 * a decoration.
 *
 * The product owner has since supplied photographs of his own and asked for
 * them to be used («fill all banners and trips with images»), which answers the
 * licence question by moving it off this repository's plate: they are his to
 * supply. `assets/generate.mjs` is gone with the drawings it made — a generator
 * still sitting beside assets it no longer produces is how the next person
 * spends an afternoon regenerating the wrong thing.
 *
 * Each is cover-cropped to 1800x1000 and re-encoded at quality 72. Photographs
 * do not compress the way flat illustration does: the drawn set was 944 KB for
 * sixteen files, this one is 3.8 MB for twenty-two.
 *
 * ## Matching a picture to a trip
 *
 * By slug, so a scene drawn for the sunset trip lands on the sunset trip. A
 * product with no picture of its own — {@see DemoFleetSeeder} tops the
 * catalogue up with `trip-<random>` slugs — gets one from the same pool, chosen
 * by hashing its slug rather than at random, because re-running the seeder must
 * not reshuffle the demo.
 *
 * Two operators selling a trip of the same name do get the same picture, and
 * that is fine: their pages are two different websites and nobody sees them
 * side by side. Each still gets its own copy on disk, under its own tenant
 * directory, because an operator deleting a file must not blank a stranger's
 * catalogue.
 *
 * ## What it will not overwrite
 *
 * An operator's own uploads. `images` is only written when it is empty or still
 * holds one of the two hand-placed `branding/…/demo-trip-*.jpg` placeholders
 * this replaces — flat teal gradients that were images in name only.
 */
class DemoImageSeeder extends Seeder
{
    /**
     * The home page's photographs, one set per operator in tenant order.
     *
     * Both operators have pictures now, asked for directly: «fill all banners
     * and trips with images».
     *
     * The second one used to keep its page in prose, and the docblock here said
     * that was the only thing exercising the image-less path. That was not
     * true — `StoryBlockSideTest`'s `story-plain` case has asserted it all
     * along — so nothing was lost by giving it photographs.
     *
     * A third operator, or a tenant seeded by a factory, still gets no set and
     * no pictures, which is correct: the page renders without them.
     */
    private const HOME_IMAGES = [
        ['demo-hero.jpg', 'demo-story.jpg', 'demo-contact.jpg'],
        ['demo-hero-2.jpg', 'demo-story-2.jpg', 'demo-contact-2.jpg'],
    ];

    /**
     * Where a set lands, whichever operator it belongs to.
     *
     * The source files differ per operator and the destinations do not, so
     * {@see DemoHomePageSeeder} names one filename and gets the right picture
     * for the tenant it is seeding.
     */
    private const HOME_TARGETS = ['demo-hero.jpg', 'demo-story.jpg', 'demo-contact.jpg'];

    /**
     * Photographs per trip: one lead and nine for the gallery.
     *
     * Asked for directly — «βάλε 10 ανά εκδρομή», repeats welcome. A real
     * operator uploads what they have; a demo has to look like somebody who
     * already did.
     */
    private const GALLERY = 10;

    /** The placeholders this seeder is allowed to replace. */
    private const PLACEHOLDERS = ['demo-trip-1.jpg', 'demo-trip-2.jpg'];

    /**
     * Alt text, per scene. Descriptive rather than promotional: it is read
     * aloud in place of the picture, and «Κρατήστε τη θέση σας» is not what the
     * picture shows.
     *
     * @var array<string, array{el: string, en: string}>
     */
    private const ALT = [
        'proino-kolymvitiko' => ['el' => 'Ξύλινο σκάφος δεμένο στην προβλήτα, με μπλε μπαλόνια στο πλάι', 'en' => 'A wooden boat moored at the quay, blue fenders along its side'],
        'olimeri-tria-nisia' => ['el' => 'Καταμαράν ανοιχτά, με βουνά στον ορίζοντα', 'en' => 'A catamaran offshore, mountains on the horizon'],
        'apogevmatino-psarema' => ['el' => 'Η πλώρη ιστιοφόρου δεμένου στη μαρίνα', 'en' => 'The foredeck of a sailing boat at its berth'],
        'romantiko-dilino' => ['el' => 'Δύο άνθρωποι με ποτήρια κρασί στο κατάστρωμα, την ώρα του ηλιοβασιλέματος', 'en' => 'Two people with glasses of wine on deck at sunset'],
        'idiotiki-imera-skafos' => ['el' => 'Λευκό σκάφος αναψυχής ανοιχτά, μέρα με ήλιο', 'en' => 'A white motor yacht offshore on a bright day'],
        'spilies-kai-ormoi' => ['el' => 'Καταμαράν αγκυροβολημένο κοντά σε καταπράσινη ακτή', 'en' => 'A catamaran at anchor off a green shore'],
        'istioploia-me-pania' => ['el' => 'Ανοιγμένα πανιά από το κατάστρωμα, στο φως του απογεύματος', 'en' => 'Sails set, seen from the deck in the late afternoon light'],
        'metafora-sto-nisi' => ['el' => 'Η πλώρη σκάφους που βγαίνει από το λιμάνι', 'en' => 'The bow of a boat leaving harbour'],
        'iliovasilema-aigina' => ['el' => 'Δύο φίλες με ποτήρια κρασί στο σκάφος, την ώρα του ηλιοβασιλέματος', 'en' => 'Two friends with glasses of wine on board at sunset'],
        'idiotiki-naulosi-imeras' => ['el' => 'Λευκό σκάφος αναψυχής στη θάλασσα', 'en' => 'A white yacht on the open sea'],
        'ilioyasilema-me-krasi' => ['el' => 'Δύο φίλες γελούν με ποτήρια κρασί στο κατάστρωμα, στο ηλιοβασίλεμα', 'en' => 'Two friends laughing over glasses of wine on deck at sunset'],
        'misi-mera-idiotiko' => ['el' => 'Ιστιοφόρο σε ήρεμα νερά, με καταπράσινους λόφους πίσω του', 'en' => 'A sailing boat on calm water below green hills'],
        'imerisia-krouazera' => ['el' => 'Γυναίκα κάνει γιόγκα στο κατάστρωμα ιστιοφόρου', 'en' => 'A woman doing yoga on the deck of a sailing yacht'],

        // No trip of their own. They widen the pool the generated trips draw
        // from, so ten trips are not three photographs.
        'pool-deck-boarding' => ['el' => 'Επιβάτισσα περπατά στο πλάι του καταστρώματος, μέσα στη μαρίνα', 'en' => 'A passenger walking along the side deck in a marina'],
        'pool-marina-yachts' => ['el' => 'Μεγάλα σκάφη δεμένα στη μαρίνα', 'en' => 'Large yachts moored in a marina'],
        'pool-harbour-sailboat' => ['el' => 'Ιστιοφόρο δεμένο στο λιμάνι', 'en' => 'A sailing boat moored in the harbour'],
        'pool-open-sea' => ['el' => 'Ανοιχτή θάλασσα με μια χαμηλή νησίδα στον ορίζοντα', 'en' => 'Open water with a low island on the horizon'],
    ];

    public function run(): void
    {
        $source = database_path('seeders/assets/images');
        $disk = Storage::disk((string) config('kaiki.catalog.uploads.disk', 'public'));

        // The pool a product with no picture of its own draws from — the trip
        // scenes, sorted, so the choice below depends on the slug and not on
        // whatever order the filesystem happened to list the directory in.
        $pool = array_values(array_diff(
            array_map('basename', glob($source . '/*.jpg') ?: []),
            array_merge(...self::HOME_IMAGES),
        ));
        sort($pool);

        if ($pool === []) {
            $this->command?->warn('No demo images found in database/seeders/assets/images.');

            return;
        }

        foreach (Tenant::query()->orderBy('id')->get() as $ordinal => $tenant) {
            $id = $tenant->getKey();

            // The home page reads these off the disk by name and renders the
            // block without one when they are missing, so they are copied here
            // rather than referenced from `database/`.
            foreach (self::HOME_IMAGES[$ordinal] ?? [] as $i => $file) {
                $this->copy($disk, $source . '/' . $file, "branding/{$id}/" . self::HOME_TARGETS[$i]);
            }

            Tenancy::forTenant($tenant, function () use ($tenant, $disk, $source, $pool, $id): void {
                foreach (Product::query()->orderBy('id')->get() as $product) {
                    if (! $this->isReplaceable($product)) {
                        continue;
                    }

                    // The lead: the scene drawn for this trip if one exists,
                    // otherwise one from the pool chosen by hashing the slug —
                    // deterministic, so re-running the seeder does not reshuffle
                    // the demo.
                    $lead = in_array($product->slug . '.jpg', $pool, true)
                        ? $product->slug . '.jpg'
                        : $pool[$this->pick($product->slug . '@' . $tenant->slug, count($pool))];

                    // Then nine more for the gallery, walking the pool from the
                    // lead's position so two trips do not get the same nine in
                    // the same order. There are fewer than ten distinct
                    // photographs for some tenants and pictures repeat between
                    // trips; that is fine for a demo and was asked for.
                    $files = [$lead];
                    $start = (int) array_search($lead, $pool, true);

                    for ($i = 1; $i < self::GALLERY; $i++) {
                        $files[] = $pool[($start + $i) % count($pool)];
                    }

                    $images = [];

                    foreach ($files as $i => $file) {
                        // Each keeps its own copy under the tenant's directory,
                        // because an operator deleting a file must not blank a
                        // stranger's catalogue. The lead keeps the bare slug so
                        // anything already pointing at it still resolves.
                        $path = $i === 0
                            ? "products/{$id}/{$product->slug}.jpg"
                            : "products/{$id}/{$product->slug}-{$i}.jpg";

                        $this->copy($disk, $source . '/' . $file, $path);

                        $images[] = [
                            'path' => $path,
                            'alt' => self::ALT[basename($file, '.jpg')] ?? ['el' => '', 'en' => ''],
                        ];
                    }

                    $product->images = $images;
                    $product->save();
                }
            });
        }
    }

    /**
     * Empty, or still holding one of the two placeholders. Anything else is
     * something a person chose.
     */
    private function isReplaceable(Product $product): bool
    {
        $images = $product->images ?? [];

        if ($images === []) {
            return true;
        }

        foreach ($images as $image) {
            $path = is_array($image) ? ($image['path'] ?? null) : null;

            if (! is_string($path) || ! in_array(basename($path), self::PLACEHOLDERS, true)) {
                return false;
            }
        }

        return true;
    }

    /** FNV-1a over the slug, so the same trip always draws the same picture. */
    private function pick(string $key, int $count): int
    {
        $h = 0x811C9DC5;

        foreach (str_split($key) as $char) {
            $h = (($h ^ ord($char)) * 0x01000193) & 0xFFFFFFFF;
        }

        return $h % $count;
    }

    private function copy(Filesystem $disk, string $from, string $to): void
    {
        if (! is_file($from)) {
            return;
        }

        $disk->put($to, (string) file_get_contents($from));
    }
}
