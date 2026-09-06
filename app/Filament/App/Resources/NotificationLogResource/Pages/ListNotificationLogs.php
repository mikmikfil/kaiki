<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\NotificationLogResource\Pages;

use App\Filament\App\Resources\NotificationLogResource;
use Filament\Resources\Pages\ListRecords;

/**
 * No create action, and no edit page.
 *
 * A notification log row is a record of an attempt this application made. There
 * is nothing for an operator to author here and nothing to correct — the retry
 * button rebuilds the message from the booking, which is the only edit that
 * makes sense.
 */
class ListNotificationLogs extends ListRecords
{
    protected static string $resource = NotificationLogResource::class;
}
