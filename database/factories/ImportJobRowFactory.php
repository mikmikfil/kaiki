<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ImportRowStatus;
use App\Enums\ImportRowType;
use App\Models\ImportJob;
use App\Models\ImportJobRow;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ImportJobRow> */
class ImportJobRowFactory extends Factory
{
    protected $model = ImportJobRow::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'import_job_id' => ImportJob::factory(),
            'source_type' => ImportRowType::Product,
            'source_id' => (string) $this->faker->unique()->numberBetween(1, 999999),
            'source_payload' => ['title' => 'Κρουαζιέρα'],
            'status' => ImportRowStatus::Pending,
            'messages' => [],
        ];
    }
}
