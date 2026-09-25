<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use Filament\Support\Contracts\HasLabel;

/**
 * Where an operator collects the balance left after a deposit (Mike, 2026-09-25).
 *
 * - `Online` — the guest pays it by card before the trip: a due date, the
 *   reminders and the overdue notice (PRC-27), as before.
 * - `OnBoard` — paid on the day, on the boat: no due date, no reminder, and not
 *   overdue until the departure has sailed with the balance still open.
 *
 * `tenants.balance_collection`; read through `Tenant::collectsBalanceOnBoard()`.
 */
enum BalanceCollection: string implements HasLabel
{
    use HasTranslatedLabel;

    case Online = 'online';

    case OnBoard = 'on_board';
}
