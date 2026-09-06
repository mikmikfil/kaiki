<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AgreementStatus;
use App\Models\Booking;
use App\Models\CharterAgreement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CharterAgreement>
 *
 * A generated, unaccepted agreement by default — the state an M6 regeneration
 * is allowed to overwrite. A test about the evidence rule has to call
 * {@see self::accepted()} explicitly and cannot arrive there by accident.
 */
class CharterAgreementFactory extends Factory
{
    protected $model = CharterAgreement::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'booking_id' => Booking::factory(),
            'template_key' => 'default',
            'template_version' => '2026.1',
            'fields_snapshot' => [
                'charterer' => 'Μαρία Παπαδοπούλου',
                'vessel' => 'Αγία Ειρήνη',
            ],
            'pdf_path' => 'tenants/1/agreements/example.pdf',
            'pdf_hash' => hash('sha256', 'example'),
            'generated_at' => now(),
            'status' => AgreementStatus::Generated,
        ];
    }

    /** The four evidence columns §2.6 calls "the legally interesting part". */
    public function accepted(): self
    {
        return $this->state(fn (): array => [
            'status' => AgreementStatus::Accepted,
            'guest_accepted_at' => now(),
            'guest_accepted_ip' => '203.0.113.7',
            'guest_accepted_user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X)',
            'guest_accepted_name' => 'Μαρία Παπαδοπούλου',
        ]);
    }
}
