<?php

declare(strict_types=1);

namespace App\Filament\App\Support;

use Illuminate\Contracts\Support\Htmlable;

/**
 * An edit page titled with the record's own name — «Μελτέμι», not
 * «Επεξεργασία: Σκάφος» (rule Ε of the form mockup, 2026-09-24).
 *
 * With several boats or ports open in tabs, the model's name said nothing about
 * which one this was. The name comes from the resource's
 * `$recordTitleAttribute`; a record with an empty one keeps Filament's title.
 */
trait TitledByRecord
{
    public function getTitle(): string|Htmlable
    {
        $title = trim((string) static::getResource()::getRecordTitle($this->getRecord()));

        return $title !== '' && $title !== static::getResource()::getModelLabel()
            ? $title
            : parent::getTitle();
    }
}
