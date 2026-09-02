<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\VesselAmenity;
use App\Enums\VesselStatus;
use App\Enums\VesselType;
use App\Models\Port;
use App\Models\Tenant;
use App\Models\Vessel;
use App\Support\Tenancy;
use App\Support\Text\GreekText;
use Illuminate\Database\Seeder;

/**
 * Ports and vessels for the two demo operators (ENV-13).
 *
 * **Deterministic** — fixed names, fixed capacities, no faker. Later catalogue
 * tests assert against this data, and a seeder that produced something different
 * on each run would make them flaky for reasons unrelated to what they test.
 * `updateOrCreate` keyed on the name means re-seeding is idempotent.
 *
 * The two fleets have deliberately different shapes, matching the tenants
 * {@see DemoTenantSeeder} builds: Aegean Blue is a Greek-first fleet operator
 * with several boats and a shared home port; Ionian Sunset is an English-first
 * single-boat trial account. Anything that only works for a fleet breaks
 * visibly on the second one.
 *
 * Every translatable value carries **both** locales, because §1.6 requires it
 * and the observer refuses the save otherwise — the seeder is the first place
 * that rule is exercised end to end.
 *
 * One vessel per fleet deliberately **overrides** `turnaround_buffer_minutes`
 * and the rest inherit, so a developer opening `/app` sees both states of AVL-7
 * without having to construct one.
 */
class DemoCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $aegean = Tenant::query()->where('slug', 'aegean-blue')->first();
        $ionian = Tenant::query()->where('slug', 'ionian-sunset')->first();

        if ($aegean !== null) {
            $this->seedAegean($aegean);
        }

        if ($ionian !== null) {
            $this->seedIonian($ionian);
        }
    }

    private function seedAegean(Tenant $tenant): void
    {
        // Written inside the tenant's context rather than by passing tenant_id
        // by hand, so the seeder takes the same path application code does and
        // the global scope is exercised rather than bypassed.
        Tenancy::forTenant($tenant, function (): void {
            $zea = $this->port([
                'name' => ['el' => 'Μαρίνα Ζέας', 'en' => 'Zea Marina'],
                'address' => 'Ακτή Θεμιστοκλέους, Πειραιάς 185 39',
                'lat' => '37.9339000',
                'lng' => '23.6469000',
                'instructions' => [
                    'el' => 'Συνάντηση στο μπλε περίπτερο, δίπλα στον προβλήτα Δ. Ελάτε 15 λεπτά νωρίτερα.',
                    'en' => 'Meet at the blue kiosk beside pier D. Please arrive 15 minutes early.',
                ],
                'sort_order' => 0,
            ]);

            $aegina = $this->port([
                'name' => ['el' => 'Παλιό Λιμάνι Αίγινας', 'en' => 'Aegina Old Harbour'],
                'address' => 'Λεωφόρος Δημοκρατίας, Αίγινα 180 10',
                'lat' => '37.7470000',
                'lng' => '23.4280000',
                'instructions' => [
                    'el' => 'Αποβίβαση στον κεντρικό μώλο, απέναντι από τα ταξί.',
                    'en' => 'We land at the main mole, opposite the taxi rank.',
                ],
                'sort_order' => 1,
            ]);

            // An inactive one, so the "active" filter and the TernaryFilter have
            // something to hide the first time anyone opens the screen.
            $this->port([
                'name' => ['el' => 'Μαρίνα Αλίμου', 'en' => 'Alimos Marina'],
                'address' => 'Λεωφόρος Ποσειδώνος, Άλιμος 174 55',
                'lat' => '37.9105000',
                'lng' => '23.7000000',
                'instructions' => [
                    'el' => 'Δεν χρησιμοποιείται αυτή τη σεζόν.',
                    'en' => 'Not in use this season.',
                ],
                'is_active' => false,
                'sort_order' => 2,
            ]);

            $this->vessel([
                'name' => 'Οδυσσέας',
                'type' => VesselType::TraditionalKaiki,
                'registration_number' => 'NP 4412',
                'length_cm' => 1350,
                'capacity_max' => 42,
                'crew_count' => 3,
                'captain_name' => 'Γιώργος Δημητρίου',
                'home_port_id' => $zea->getKey(),
                // Inherits the tenant's 60 (AVL-7). The common case.
                'turnaround_buffer_minutes' => null,
                'description' => [
                    'el' => 'Παραδοσιακό ξύλινο καΐκι του 1978, με σκιά σε όλο το κατάστρωμα και ψυγείο.',
                    'en' => 'A traditional wooden kaiki from 1978, with shade over the whole deck and a fridge.',
                ],
                'specs' => [
                    'beam_m' => 4.2,
                    'year_built' => 1978,
                    'engine' => '2 × 180 hp',
                    'cruising_speed_kn' => 9,
                    'amenities' => [
                        VesselAmenity::ShadeCanopy->value,
                        VesselAmenity::Fridge->value,
                        VesselAmenity::Wc->value,
                        VesselAmenity::SnorkellingGear->value,
                    ],
                ],
                'sort_order' => 0,
            ]);

            $this->vessel([
                'name' => 'Ποσειδών',
                'type' => VesselType::Catamaran,
                'registration_number' => 'NP 5108',
                'length_cm' => 1620,
                'capacity_max' => 60,
                'crew_count' => 4,
                'home_port_id' => $zea->getKey(),
                // The override, so both branches of AVL-7 are visible on the
                // fleet list without anyone constructing one.
                'turnaround_buffer_minutes' => 90,
                'description' => [
                    'el' => 'Καταμαράν με δύο καταστρώματα, ηχοσύστημα και ηλιακό κατάστρωμα.',
                    'en' => 'A two-deck catamaran with a sound system and a sun deck.',
                ],
                'specs' => [
                    'beam_m' => 7.4,
                    'year_built' => 2016,
                    'cruising_speed_kn' => 14,
                    'amenities' => [
                        VesselAmenity::ShadeCanopy->value,
                        VesselAmenity::SoundSystem->value,
                        VesselAmenity::SunDeck->value,
                        VesselAmenity::Wc->value,
                    ],
                ],
                'sort_order' => 1,
            ]);

            $this->vessel([
                'name' => 'Γαλήνη',
                'type' => VesselType::Rib,
                'registration_number' => 'NP 2277',
                'length_cm' => 780,
                'capacity_max' => 10,
                'crew_count' => 1,
                'home_port_id' => $aegina->getKey(),
                'turnaround_buffer_minutes' => null,
                // In maintenance, so the fleet list shows a non-sellable boat.
                'status' => VesselStatus::Maintenance,
                'description' => [
                    'el' => 'Ταχύπλοο φουσκωτό για μικρές παρέες και ιδιωτικές μεταφορές.',
                    'en' => 'A fast RIB for small groups and private transfers.',
                ],
                'specs' => [
                    'beam_m' => 2.6,
                    'year_built' => 2021,
                    'cruising_speed_kn' => 28,
                    'amenities' => [VesselAmenity::ShadeCanopy->value],
                ],
                'sort_order' => 2,
            ]);
        });
    }

    private function seedIonian(Tenant $tenant): void
    {
        Tenancy::forTenant($tenant, function (): void {
            $corfu = $this->port([
                'name' => ['el' => 'Λιμάνι Κέρκυρας', 'en' => 'Corfu Port'],
                'address' => 'Neo Limani, Corfu 491 00',
                'lat' => '39.6280000',
                'lng' => '19.9130000',
                'instructions' => [
                    'el' => 'Συνάντηση στο γραφείο του λιμανιού, 20 λεπτά πριν την αναχώρηση.',
                    'en' => 'Meet at the harbour office, 20 minutes before departure.',
                ],
                'sort_order' => 0,
            ]);

            // One boat, no home-port variety, English-first: a trial account.
            $this->vessel([
                'name' => 'Ionian Star',
                'type' => VesselType::SailingYacht,
                'registration_number' => 'NK 0914',
                'length_cm' => 1480,
                'capacity_max' => 12,
                'crew_count' => 2,
                'captain_name' => 'Andreas Kollias',
                'home_port_id' => $corfu->getKey(),
                'turnaround_buffer_minutes' => null,
                'description' => [
                    'el' => 'Ιστιοπλοϊκό σκάφος για ολοήμερες εκδρομές στα Διαπόντια νησιά.',
                    'en' => 'A sailing yacht for full-day trips to the Diapontia islands.',
                ],
                'specs' => [
                    'beam_m' => 4.5,
                    'year_built' => 2009,
                    'cruising_speed_kn' => 7,
                    'cabins' => 3,
                    'wc' => 2,
                    'amenities' => [
                        VesselAmenity::Fridge->value,
                        VesselAmenity::Wc->value,
                        VesselAmenity::SnorkellingGear->value,
                    ],
                ],
                'sort_order' => 0,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function port(array $attributes): Port
    {
        /** @var array{el: string, en: string} $name */
        $name = $attributes['name'];

        // Keyed on the Greek name rather than on `name` itself: the column is
        // JSON and matching a whole JSON document is both engine-dependent and
        // exactly the JSON-path query ENV-8 forbids. The sort companion is a
        // plain column and holds the folded Greek, which is stable.
        $existing = Port::query()->where('name_sort_el', $this->sortKey($name['el']))->first();

        if ($existing !== null) {
            $existing->fill($attributes)->save();

            return $existing;
        }

        return Port::query()->create($attributes + ['is_active' => true]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function vessel(array $attributes): Vessel
    {
        /** @var string $name */
        $name = $attributes['name'];

        return Vessel::query()->updateOrCreate(
            ['name' => $name],
            $attributes + [
                'status' => VesselStatus::Active,
                'images' => [],
            ],
        );
    }

    /** The same folding the observer applies, so the lookup matches what it wrote. */
    private function sortKey(string $value): string
    {
        return GreekText::fold($value);
    }
}
