<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * Assigns the public identifier on `creating` (data-model §1.1).
 *
 * `id` is internal and never appears in a URL, an API payload or the widget;
 * `uuid` is what the outside world sees.
 */
trait HasUuid
{
    public static function bootHasUuid(): void
    {
        static::creating(static function (self $model): void {
            if (blank($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
