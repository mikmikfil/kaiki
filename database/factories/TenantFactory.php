<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\HostedSiteMode;
use App\Enums\Plan;
use App\Enums\TenantStatus;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Tenant> */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = $this->faker->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name) . '-' . $this->faker->unique()->numberBetween(1, 99999),
            'legal_name' => $name . ' ΙΚΕ',
            'vat_number' => (string) $this->faker->numberBetween(100000000, 999999999),
            'tax_office' => 'ΔΟΥ Πειραιά',
            'country' => 'GR',
            'email' => $this->faker->unique()->companyEmail(),
            'timezone' => 'Europe/Athens',
            'default_locale' => 'el',
            'supported_locales' => ['el', 'en'],
            'currency' => 'EUR',
            'plan' => Plan::Trial,
            'status' => TenantStatus::Trialing,
            'trial_ends_at' => now()->addDays(14),
            'hosted_site_mode' => HostedSiteMode::Full,
            'is_sandbox' => false,
            'turnaround_buffer_minutes' => 60,
            'guest_document_retention_days' => 90,
            'auto_issue_invoice' => false,
            // Off, like the column default and like every real operator until
            // they say otherwise. A test that wants an instalment says so.
            'deposits_enabled' => false,
            // On, like the column default: every operator scanned tickets
            // before the switch existed.
            'qr_check_in_enabled' => true,
            // An operator who has been through the setup guide, like the legal
            // name and the ΑΦΜ two lines above: this factory has always built
            // somebody already trading, not somebody on their first afternoon.
            //
            // It also keeps `OfferSetupOnce` out of the way of every other test
            // that signs an owner in and asks for the dashboard — that redirect
            // fires exactly once per account and is the subject of its own
            // tests. `unconfigured()` is how a test asks for the other state.
            'onboarding_completed_at' => now(),
            'onboarding_skipped_steps' => null,
            'settings' => [],
        ];
    }

    /**
     * An account as it is the minute it is created (#51).
     *
     * Nothing answered, nothing skipped, nothing finished — the state the setup
     * guide exists for, and the one `OnboardOperator` actually produces.
     */
    public function unconfigured(): static
    {
        return $this->state(fn (): array => [
            'legal_name' => null,
            'vat_number' => null,
            'tax_office' => null,
            'default_vat_rate_id' => null,
            'onboarding_completed_at' => null,
            'onboarding_skipped_steps' => null,
        ]);
    }

    public function active(): self
    {
        return $this->state(fn (): array => [
            'plan' => Plan::Fleet,
            'status' => TenantStatus::Active,
            'trial_ends_at' => null,
        ]);
    }

    /** An operator who takes a deposit now and the balance later (PRC-23). */
    public function takingDeposits(): self
    {
        return $this->state(fn (): array => ['deposits_enabled' => true]);
    }

    /** A small operator who boards from the passenger list, with no QR anywhere. */
    public function withoutQrCheckIn(): self
    {
        return $this->state(fn (): array => ['qr_check_in_enabled' => false]);
    }

    /** A lapsed subscription: the panel opens, nothing can be written (#7). */
    public function readOnly(): self
    {
        return $this->state(fn (): array => ['status' => TenantStatus::ReadOnly]);
    }
}
