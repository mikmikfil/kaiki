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
 * this seeder copies lives in `database/seeders/assets/images/`, drawn by
 * `assets/generate.mjs` — see that file for why the pictures are generated
 * rather than downloaded.
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
     * The home page's photographs, for the first operator only.
     *
     * The second one keeps a page in prose on purpose: {@see DemoHomePageSeeder}
     * exists partly to prove every block renders without an image, and a demo
     * where both operators have pictures stops testing that.
     */
    private const HOME_IMAGES = ['demo-hero.jpg', 'demo-story.jpg', 'demo-contact.jpg'];

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
        'proino-kolymvitiko' => ['el' => 'Καΐκι αγκυροβολημένο σε ήσυχο όρμο το πρωί', 'en' => 'A caique at anchor in a quiet cove in the morning'],
        'olimeri-tria-nisia' => ['el' => 'Καταμαράν ανοιχτά, με νησιά στον ορίζοντα', 'en' => 'A catamaran offshore, islands on the horizon'],
        'apogevmatino-psarema' => ['el' => 'Μικρό καΐκι στη θάλασσα αργά το απόγευμα', 'en' => 'A small caique on the water in the late afternoon'],
        'romantiko-dilino' => ['el' => 'Σκάφος με αναμμένα φώτα κάτω από το φεγγάρι', 'en' => 'A boat with its lights on under the moon'],
        'idiotiki-imera-skafos' => ['el' => 'Σκάφος αναψυχής ανοιχτά μια ηλιόλουστη μέρα', 'en' => 'A motor yacht offshore on a sunny day'],
        'spilies-kai-ormoi' => ['el' => 'Βραχώδης αψίδα πάνω από τη θάλασσα', 'en' => 'A rock arch over the sea'],
        'istioploia-me-pania' => ['el' => 'Ιστιοφόρα με ανοιγμένα πανιά', 'en' => 'Sailing boats with their sails up'],
        'metafora-sto-nisi' => ['el' => 'Ταχύπλοο εν πλω προς το νησί', 'en' => 'A fast boat under way towards the island'],
        'iliovasilema-aigina' => ['el' => 'Καΐκι στη θάλασσα την ώρα του ηλιοβασιλέματος', 'en' => 'A caique on the water at sunset'],
        'idiotiki-naulosi-imeras' => ['el' => 'Σκάφος αγκυροβολημένο σε όρμο', 'en' => 'A yacht at anchor in a cove'],
        'ilioyasilema-me-krasi' => ['el' => 'Ιστιοφόρο αγκυροβολημένο στο σούρουπο', 'en' => 'A sailing boat at anchor at dusk'],
        'misi-mera-idiotiko' => ['el' => 'Καταμαράν σε ήρεμα νερά', 'en' => 'A catamaran on calm water'],
        'imerisia-krouazera' => ['el' => 'Καταμαράν σε ημερήσια κρουαζιέρα', 'en' => 'A catamaran on a day cruise'],
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
            self::HOME_IMAGES,
        ));
        sort($pool);

        if ($pool === []) {
            $this->command?->warn('No demo images found — run `node database/seeders/assets/generate.mjs`.');

            return;
        }

        $primary = Tenant::query()->orderBy('id')->value('id');

        foreach (Tenant::query()->orderBy('id')->get() as $tenant) {
            $id = $tenant->getKey();

            // The home page reads these off the disk by name and renders the
            // block without one when they are missing, so they are copied here
            // rather than referenced from `database/`.
            if ($id === $primary) {
                foreach (self::HOME_IMAGES as $file) {
                    $this->copy($disk, $source . '/' . $file, "branding/{$id}/{$file}");
                }
            }

            Tenancy::forTenant($tenant, function () use ($tenant, $disk, $source, $pool, $id): void {
                foreach (Product::query()->orderBy('id')->get() as $product) {
                    if (! $this->isReplaceable($product)) {
                        continue;
                    }

                    $file = in_array($product->slug . '.jpg', $pool, true)
                        ? $product->slug . '.jpg'
                        : $pool[$this->pick($product->slug . '@' . $tenant->slug, count($pool))];

                    $path = "products/{$id}/{$product->slug}.jpg";
                    $this->copy($disk, $source . '/' . $file, $path);

                    $product->images = [[
                        'path' => $path,
                        'alt' => self::ALT[basename($file, '.jpg')] ?? ['el' => '', 'en' => ''],
                    ]];
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
