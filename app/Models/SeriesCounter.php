<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Compliance\Actions\AllocateInvoiceNumber;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\SeriesCounterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The last number handed out in one invoice series, for one year
 * (spec MYD-4, ADR-0022 Option A).
 *
 * Not interesting on its own. It exists to be **locked**, which
 * {@see AllocateInvoiceNumber} does, and its
 * whole value is that a row can be locked and `MAX(number) + 1` cannot.
 *
 * @property int $last_number
 * @property Carbon|null $last_allocated_at
 */
class SeriesCounter extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<SeriesCounterFactory> */
    use HasFactory;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'last_number' => 'integer',
            'last_allocated_at' => 'datetime',
        ];
    }
}
