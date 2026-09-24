<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Hosted\Actions\SaveHomePage;
use App\Domain\Tenancy\Actions\InviteStaffMember;
use App\Enums\CrewSpecialty;
use App\Enums\HomeBlockType;
use App\Enums\Role;
use App\Enums\VesselLicence;
use App\Models\BrandProfile;
use App\Models\HomePageBlock;
use App\Models\Port;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vessel;
use App\Support\Tenancy;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * «Σχετικά με εμάς» for the demo operator, Aegean Blue Cruises (2026-09-24).
 *
 * The page Mike approved in docs/mockups/about-page.html, with the demo's own
 * boats, people and port behind it. Only what is missing is filled in: a boat
 * that already has photographs keeps them, a person who already has a
 * specialty keeps it, and an about page somebody saved by hand is left alone.
 *
 * Photographs come from `database/seeders/assets/images/`, like every other
 * demo picture ({@see DemoImageSeeder}).
 */
class DemoAboutPageSeeder extends Seeder
{
    /** Boat name → [photograph, length in cm, licence]. */
    private const BOATS = [
        'Γαλήνη' => ['misi-mera-idiotiko.jpg', 1020, VesselLicence::DayCruise],
        'Οδυσσέας' => ['idiotiki-imera-skafos.jpg', 1350, VesselLicence::DayCruise],
        'Ποσειδών' => ['olimeri-tria-nisia.jpg', 1620, VesselLicence::ProfessionalPleasure],
    ];

    public function run(): void
    {
        $tenant = Tenant::query()->where('slug', 'aegean-blue')->first();

        if (! $tenant instanceof Tenant) {
            return;
        }

        Tenancy::forTenant($tenant, function () use ($tenant): void {
            $disk = Storage::disk((string) config('kaiki.catalog.uploads.disk', 'public'));
            $dir = "branding/{$tenant->getKey()}/about";

            $photo = function (string $file, string $as) use ($disk, $dir): ?string {
                $from = database_path('seeders/assets/images/' . $file);

                return is_file($from) ? $this->copy($disk, $from, "{$dir}/{$as}") : null;
            };

            // The button colour Mike picked on 24/9, from the home mockup.
            BrandProfile::query()->first()?->forceFill(['color_accent' => '#1B5FD1'])->save();

            $boats = $this->boats($photo);
            $this->crew($tenant, $photo);
            $this->port($photo);

            if (HomePageBlock::query()->onPage(HomePageBlock::PAGE_ABOUT)->exists()) {
                return;
            }

            app(SaveHomePage::class)($this->blocks($photo, $boats), HomePageBlock::PAGE_ABOUT);
        });
    }

    /**
     * @param  callable(string, string): ?string  $photo
     * @return list<int> the boats the fleet section shows
     */
    private function boats(callable $photo): array
    {
        $ids = [];

        foreach (self::BOATS as $name => [$file, $length, $licence]) {
            $vessel = Vessel::query()->where('name', $name)->first();

            if (! $vessel instanceof Vessel) {
                continue;
            }

            $ids[] = $vessel->getKey();

            $path = $photo($file, 'boat-' . $vessel->getKey() . '.jpg');

            $vessel->forceFill([
                'images' => $vessel->images === [] || $vessel->images === null
                    ? ($path === null ? [] : [['path' => $path, 'alt' => ['el' => "Το σκάφος {$name}", 'en' => "The boat {$name}"]]])
                    : $vessel->images,
                'length_cm' => $vessel->length_cm ?: $length,
                'licence_type' => $vessel->licence_type ?? $licence,
            ])->save();
        }

        return $ids;
    }

    /** @param  callable(string, string): ?string  $photo */
    private function crew(Tenant $tenant, callable $photo): void
    {
        $people = [
            'giorgos@aegean-blue.example' => [CrewSpecialty::Captain, 'demo-crew-giorgos.jpg', [
                'el' => 'Στη θάλασσα από παιδί. Ξέρει κάθε σπηλιά της Αίγινας.',
                'en' => 'At sea since he was a boy. Knows every cave on Aegina.',
            ]],
            'nikos@aegean-blue.example' => [CrewSpecialty::Deckhand, null, [
                'el' => 'Σας βοηθάει να ανεβείτε και φτιάχνει τον καλύτερο φραπέ του Σαρωνικού.',
                'en' => 'Helps you aboard and makes the best frappé in the Saronic.',
            ]],
        ];

        foreach ($people as $email => [$specialty, $file, $bio]) {
            $user = User::query()->where('tenant_id', $tenant->getKey())->where('email', $email)->first();

            if (! $user instanceof User) {
                continue;
            }

            $user->forceFill([
                'specialty' => $user->specialty ?? $specialty,
                'photo_path' => $user->photo_path ?? ($file === null ? null : $photo($file, 'person-' . $user->getKey() . '.jpg')),
                'bio' => $user->bio ?? $bio,
            ])->save();
        }

        // A captain who never signs in — «Χωρίς σύνδεση» — so the page shows
        // somebody from the crew who is not an account holder.
        $owner = User::query()->where('tenant_id', $tenant->getKey())->where('email', 'maria@aegean-blue.example')->first();
        $exists = User::query()->where('tenant_id', $tenant->getKey())->where('name', 'Ελένη Μαρκάκη')->exists();

        if ($owner instanceof User && ! $exists) {
            $eleni = app(InviteStaffMember::class)(name: 'Ελένη Μαρκάκη', email: null, roles: [Role::Crew], invitedBy: $owner, locale: 'el');

            $eleni->forceFill([
                'specialty' => CrewSpecialty::Captain,
                'photo_path' => $photo('demo-crew-eleni.jpg', 'person-' . $eleni->getKey() . '.jpg'),
                'bio' => [
                    'el' => 'Κάνει τα ηλιοβασιλέματα και μιλάει αγγλικά, ιταλικά και γαλλικά.',
                    'en' => 'Sails the sunset trips and speaks English, Italian and French.',
                ],
            ])->save();
        }
    }

    /** @param  callable(string, string): ?string  $photo */
    private function port(callable $photo): void
    {
        $port = Port::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->first();

        if (! $port instanceof Port) {
            return;
        }

        $port->forceFill(['photo_path' => $port->photo_path ?? $photo('demo-contact.jpg', 'port-' . $port->getKey() . '.jpg')]);

        if (blank($port->getTranslation('instructions', 'el', false))) {
            $port->setTranslations('instructions', [
                'el' => 'Ελάτε 20 λεπτά πριν την αναχώρηση, στην προβλήτα Β.',
                'en' => 'Please be here 20 minutes before departure, at pier B.',
            ]);
        }

        $port->save();
    }

    /**
     * The sections, in the mockup's order.
     *
     * @param  callable(string, string): ?string  $photo
     * @param  list<int>  $boats
     * @return list<array<string, mixed>>
     */
    private function blocks(callable $photo, array $boats): array
    {
        $both = static fn (string $el, string $en): array => ['el' => $el, 'en' => $en];

        return [
            [
                'type' => HomeBlockType::Hero->value,
                'eyebrow' => $both('Σχετικά με εμάς', 'About us'),
                'heading' => $both('Τρεις γενιές στη θάλασσα του Σαρωνικού', 'Three generations on the Saronic Gulf'),
                'body' => $both(
                    'Οικογενειακή επιχείρηση από τον Πειραιά. Βγάζουμε μικρές παρέες για κολύμπι, ψάρεμα και ηλιοβασιλέματα, με δικά μας σκάφη και δικούς μας κυβερνήτες.',
                    'A family business from Piraeus. We take small groups swimming, fishing and out for the sunset, on our own boats with our own captains.',
                ),
                'image_path' => $photo('istioploia-me-pania.jpg', 'hero.jpg'),
                'buttons' => [],
            ],
            [
                'type' => HomeBlockType::Stats->value,
                'items' => [
                    ['value' => $both('1968', '1968'), 'label' => $both('η πρώτη βάρκα της οικογένειας', 'the family\'s first boat')],
                    ['value' => $both('3', '3'), 'label' => $both('σκάφη, όλα δικά μας', 'boats, all our own')],
                    ['value' => $both('12', '12'), 'label' => $both('άτομα το πολύ σε κάθε εκδρομή', 'people at most on a trip')],
                    ['value' => $both('4,9', '4.9'), 'label' => $both('στην Google, από 380 κριτικές', 'on Google, from 380 reviews')],
                ],
            ],
            [
                'type' => HomeBlockType::Story->value,
                'eyebrow' => $both('Η ιστορία μας', 'Our story'),
                'heading' => $both('Ξεκινήσαμε με μια ψαρόβαρκα στη Σαλαμίνα', 'We started with a fishing boat on Salamina'),
                'body' => $both(
                    "Ο παππούς μας ψάρευε ανάμεσα στη Σαλαμίνα και την Αίγινα από το 1968. Εκεί μάθαμε κάθε κάβο, κάθε σπηλιά και ποιος όρμος έχει νερά σαν πισίνα όταν φυσάει μελτέμι.\n\nΤο 2009 φτιάξαμε το πρώτο σκάφος για επισκέπτες. Σήμερα έχουμε τρία και βγαίνουμε κάθε μέρα από τον Απρίλιο ως τον Οκτώβριο. Οι παρέες μένουν μικρές, για να χωράνε όλοι στην πλώρη.",
                    "Our grandfather fished between Salamina and Aegina from 1968. That is where we learnt every cape, every cave, and which cove stays calm as a pool when the meltemi blows.\n\nIn 2009 we fitted out our first boat for visitors. Today we have three and sail every day from April to October. The groups stay small, so everyone fits on the bow.",
                ),
                'image_path' => $photo('demo-story.jpg', 'story.jpg'),
                'image_alt' => $both('Ο Γιώργος στην πλώρη', 'Giorgos on the bow'),
                'settings' => ['image_side' => 'left'],
            ],
            [
                'type' => HomeBlockType::Timeline->value,
                'eyebrow' => $both('Χρονολόγιο', 'Timeline'),
                'heading' => $both('Πώς φτάσαμε ως εδώ', 'How we got here'),
                'items' => [
                    ['year' => '1968', 'title' => $both('Η «Αγία Ειρήνη»', 'The «Agia Eirini»'), 'text' => $both('Η ψαρόβαρκα του παππού, στο λιμανάκι της Σαλαμίνας.', 'Grandfather\'s fishing boat, in the little harbour on Salamina.')],
                    ['year' => '1994', 'title' => $both('Δίπλωμα κυβερνήτη', 'A captain\'s licence'), 'text' => $both('Ο Γιώργος παίρνει το δίπλωμα και το πρώτο καΐκι.', 'Giorgos gets his licence and his first caique.')],
                    ['year' => '2009', 'title' => $both('Το πρώτο σκάφος', 'The first boat'), 'text' => $both('Το πρώτο μας σκάφος για επισκέπτες, 12 θέσεις.', 'Our first boat for visitors, with 12 seats.')],
                    ['year' => '2021', 'title' => $both('Μαρίνα Ζέας', 'Zea Marina'), 'text' => $both('Μεταφερόμαστε στον Πειραιά και ο στόλος γίνεται τρία σκάφη.', 'We move to Piraeus and the fleet grows to three.')],
                    ['year' => '2026', 'title' => $both('Σήμερα', 'Today'), 'text' => $both('Τρία σκάφη, πέντε άνθρωποι, 40 χιλιάδες επιβάτες ως τώρα.', 'Three boats, five people, 40,000 passengers so far.')],
                ],
            ],
            [
                'type' => HomeBlockType::Fleet->value,
                'eyebrow' => $both('Ο στόλος', 'The fleet'),
                'heading' => $both('Τα σκάφη μας', 'Our boats'),
                'settings' => ['vessel_ids' => $boats],
            ],
            [
                'type' => HomeBlockType::Crew->value,
                'eyebrow' => $both('Στο τιμόνι', 'At the helm'),
                'heading' => $both('Οι άνθρωποί μας', 'Our people'),
                'settings' => ['photos' => true],
            ],
            [
                'type' => HomeBlockType::Testimonials->value,
                'eyebrow' => $both('Κριτικές', 'Reviews'),
                'heading' => $both('Τι λένε όσοι ήρθαν', 'What our guests say'),
                'items' => [
                    [
                        'quote' => $both('Ο Γιώργος μάς πήγε σε έναν όρμο που δεν θα βρίσκαμε ποτέ μόνοι μας. Τα παιδιά ακόμα μιλάνε για τα ψάρια.', 'Giorgos took us to a cove we would never have found on our own. The children still talk about the fish.'),
                        'name' => 'Katrin M.',
                        'trip' => $both('Οικογενειακή εκδρομή, Αύγουστος 2026', 'Family trip, August 2026'),
                        'rating' => 5,
                    ],
                    [
                        'quote' => $both('Μικρή παρέα, καθαρό σκάφος, και ένα ηλιοβασίλεμα που δεν ξεχνιέται.', 'A small group, a spotless boat and a sunset we will not forget.'),
                        'name' => 'Νίκος Π.',
                        'trip' => $both('Ηλιοβασίλεμα στην Αίγινα', 'Sunset at Aegina'),
                        'rating' => 5,
                    ],
                ],
            ],
            [
                'type' => HomeBlockType::Credentials->value,
                'eyebrow' => $both('Άδειες και ασφάλεια', 'Licences and insurance'),
                'heading' => $both('Όλα στα χαρτιά τους', 'All in order'),
                'body' => $both(
                    'Κάθε σκάφος έχει άδεια από το Λιμεναρχείο Πειραιά και κάθε επιβάτης είναι ασφαλισμένος σε κάθε εκδρομή.',
                    'Every boat is licensed by the Piraeus Port Authority, and every passenger is insured on every trip.',
                ),
            ],
            [
                'type' => HomeBlockType::MeetingPoint->value,
                'eyebrow' => $both('Σημείο συνάντησης', 'Meeting point'),
                'heading' => $both('Μαρίνα Ζέας, προβλήτα Β', 'Zea Marina, pier B'),
            ],
            [
                'type' => HomeBlockType::Cta->value,
                'eyebrow' => $both('Κάντε κράτηση', 'Book'),
                'heading' => $both('Ελάτε μαζί μας', 'Come sailing with us'),
                'body' => $both('Διαλέξτε εκδρομή και κλείστε θέση σε ένα λεπτό.', 'Choose a trip and book your seat in a minute.'),
                'image_path' => $photo('romantiko-dilino.jpg', 'cta.jpg'),
                'buttons' => [
                    ['label' => $both('Δείτε τις εκδρομές', 'See our trips'), 'target' => 'search'],
                ],
            ],
        ];
    }

    private function copy(Filesystem $disk, string $from, string $to): string
    {
        if (! $disk->exists($to)) {
            $disk->put($to, (string) file_get_contents($from));
        }

        return $to;
    }
}
