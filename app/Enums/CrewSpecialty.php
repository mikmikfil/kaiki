<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use Filament\Support\Contracts\HasLabel;

/**
 * What a person does on the boat (Mike, 2026-09-24): separate from their role,
 * which is what they may see in Kaiki. A departure's «Κυβερνήτης» list offers
 * the captains; the crew list offers everyone.
 */
enum CrewSpecialty: string implements HasLabel
{
    use HasTranslatedLabel;

    case Captain = 'captain';

    case Deckhand = 'deckhand';

    case Other = 'other';
}
