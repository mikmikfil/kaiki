<?php

declare(strict_types=1);

namespace Database\Factories;

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
            'hosted_page_enabled' => true,
            'is_sandbox' => false,
            'turnaround_buffer_minutes' => 60,
            'guest_document_retention_days' => 90,
            'auto_issue_invoice' => false,
            'settings' => [],
        ];
    }

    public function active(): self
    {
        return $this->state(fn (): array => [
            'plan' => Plan::Fleet,
            'status' => TenantStatus::Active,
            'trial_ends_at' => null,
        ]);
    }

    /** A lapsed subscription: the panel opens, nothing can be written (#7). */
    public function readOnly(): self
    {
        return $this->state(fn (): array => ['status' => TenantStatus::ReadOnly]);
    }
}
