<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ImportSource;
use App\Enums\ImportStatus;
use App\Models\ImportJob;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ImportJob> */
class ImportJobFactory extends Factory
{
    protected $model = ImportJob::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'source' => ImportSource::Wxr,
            'status' => ImportStatus::Pending,
            'is_dry_run' => true,
            'connection' => null,
            'mapping' => [],
            'stats' => [],
        ];
    }
}
