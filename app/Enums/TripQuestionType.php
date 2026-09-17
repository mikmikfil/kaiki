<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * What kind of answer a trip question takes (product owner, 2026-09-17).
 *
 * Three, because those are the questions operators actually ask: «Χρειάζεστε
 * μεταφορά;» is yes/no, «Μέγεθος στολής» is a choice, «Αλλεργίες» is a line of
 * text. Anything longer belongs in «Κάτι που πρέπει να ξέρουμε».
 */
enum TripQuestionType: string
{
    use HasTranslatedLabel;

    case YesNo = 'yes_no';
    case Choice = 'choice';
    case Text = 'text';
}
