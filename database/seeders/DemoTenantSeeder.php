<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Plan;
use App\Enums\Role;
use App\Enums\TenantStatus;
use App\Models\RoleAssignment;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Two demo operators with deliberately different shapes (ENV-13).
 *
 * Deterministic — fixed slugs, emails and ids — because the isolation suite in
 * #8 and every later catalog test asserts against this data. Nothing here is
 * random; a seeder that produced different data on each run would make those
 * tests flaky for reasons unrelated to what they test.
 *
 * Two tenants, not one: isolation cannot be demonstrated with a single tenant,
 * and the second exists so a leak has somewhere to leak *from*.
 */
class DemoTenantSeeder extends Seeder
{
    public function run(): void
    {
        $aegean = $this->createTenant([
            'name' => 'Aegean Blue Cruises',
            'slug' => 'aegean-blue',
            'legal_name' => 'AEGEAN BLUE CRUISES ΙΚΕ',
            'vat_number' => '801234567',
            'tax_office' => 'ΔΟΥ Πειραιά',
            'city' => 'Πειραιάς',
            'postcode' => '18538',
            'email' => 'info@aegean-blue.example',
            'default_locale' => 'el',
            'plan' => Plan::Fleet,
            'status' => TenantStatus::Active,
            'trial_ends_at' => null,
        ]);

        $this->createStaff($aegean, 'aegean-blue.example', [
            ['Μαρία Παπαδοπούλου', 'maria', Role::Owner],
            ['Γιώργος Δημητρίου', 'giorgos', Role::Manager],
            ['Νίκος Βασιλείου', 'nikos', Role::Crew],
        ]);

        // A different shape on purpose: single vessel, still on trial, English
        // first. Anything that only works for the Greek fleet operator breaks
        // visibly here.
        $ionian = $this->createTenant([
            'name' => 'Ionian Sunset Sailing',
            'slug' => 'ionian-sunset',
            'legal_name' => 'IONIAN SUNSET SAILING MON. IKE',
            'vat_number' => '809876543',
            'tax_office' => 'ΔΟΥ Κέρκυρας',
            'city' => 'Κέρκυρα',
            'postcode' => '49100',
            'email' => 'hello@ionian-sunset.example',
            'default_locale' => 'en',
            'plan' => Plan::Solo,
            'status' => TenantStatus::Trialing,
            'trial_ends_at' => now()->addDays(14),
        ]);

        $this->createStaff($ionian, 'ionian-sunset.example', [
            ['Elena Rossi', 'elena', Role::Owner],
            ['Andreas Kollias', 'andreas', Role::Crew],
        ]);

        // Platform staff: no tenant, `/admin` only.
        User::query()->updateOrCreate(
            ['email' => 'admin@kaiki.example'],
            [
                'name' => 'Kaiki Platform Admin',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'locale' => 'en',
                'tenant_id' => null,
                'is_super_admin' => true,
            ],
        );
    }

    /** @param array<string, mixed> $attributes */
    private function createTenant(array $attributes): Tenant
    {
        return Tenant::query()->updateOrCreate(
            ['slug' => $attributes['slug']],
            $attributes + [
                'country' => 'GR',
                'timezone' => 'Europe/Athens',
                'supported_locales' => ['el', 'en'],
                'currency' => 'EUR',
                'hosted_page_enabled' => true,
                'is_sandbox' => false,
                'turnaround_buffer_minutes' => 60,
                'guest_document_retention_days' => 90,
                'auto_issue_invoice' => false,
                'settings' => [],
            ],
        );
    }

    /** @param list<array{0: string, 1: string, 2: Role}> $staff */
    private function createStaff(Tenant $tenant, string $domain, array $staff): void
    {
        // Role assignments are tenant-owned, so they are written inside the
        // tenant's context rather than by passing tenant_id by hand — the same
        // path application code takes.
        Tenancy::forTenant($tenant, function () use ($tenant, $domain, $staff): void {
            foreach ($staff as [$name, $handle, $role]) {
                $user = User::query()->updateOrCreate(
                    ['email' => "{$handle}@{$domain}"],
                    [
                        'tenant_id' => $tenant->getKey(),
                        'name' => $name,
                        'password' => Hash::make('password'),
                        'email_verified_at' => now(),
                        'locale' => $tenant->default_locale,
                        'is_super_admin' => false,
                    ],
                );

                RoleAssignment::query()->firstOrCreate([
                    'user_id' => $user->getKey(),
                    'role' => $role,
                ]);
            }
        });
    }
}
