<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use Filament\Support\Contracts\HasLabel;

/**
 * «Φύλο» on the passenger list for the Λιμεναρχείο (ν. 4926/2022 άρθρο 13,
 * 2026-09-24). The two values the port authority's own form has; the list
 * prints the letter («Α» / «Θ»), the checkout the word.
 */
enum GuestSex: string implements HasLabel
{
    use HasTranslatedLabel;

    case Male = 'm';

    case Female = 'f';

    /** «Α» or «Θ», as the harbour's form writes it. */
    public function letter(): string
    {
        return (string) __('enums.guest_sex.' . $this->value . '.letter');
    }
}
