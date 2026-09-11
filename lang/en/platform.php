<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The platform panel at /admin (SAA-1, SAA-17)
|--------------------------------------------------------------------------
|
| Read by the platform owner when something is wrong: every line says what a
| number means and what to do next. The announcement banner is read by every
| operator.
|
*/

return [

    'none' => '—',

    'health' => [
        'nav' => 'Platform health',
        'title' => 'Platform health',
        'subheading' => 'What is waiting, what failed, and for which operator. Refreshes every minute.',
        'none' => 'None',

        'queue' => [
            'heading' => 'Job queue',
            'depth' => 'Waiting',
            'reserved' => 'Running now',
            'oldest' => 'Oldest has waited',
            'driver' => 'Queue driver',
            'worker_hint' => 'If the waiting count grows and the oldest job has waited more than a few minutes, the queue worker is probably not running — and without it no email, ticket or invitation is sent.',
        ],

        'failed' => [
            'heading' => 'Failed jobs',
            'total' => ':count failed job in total|:count failed jobs in total',
            'none' => 'No failed jobs.',
            'by_class' => 'By kind of job',
            'recent' => 'Most recent',
            'class' => 'Job',
            'count' => 'Count',
            'when' => 'When',
            'error' => 'Error',
            'uuid' => 'Id',
            'sample' => 'The split by kind counts the last :count.',
            'retry_hint' => 'Retrying and forgetting are done on the server: php artisan queue:retry <id> or php artisan queue:forget <id>. There is no button here because a platform action like that has to be recorded, and the audit trail belongs to each operator — not to a job that has none.',
        ],

        'tenants' => [
            'heading' => 'By operator',
            'help' => 'Invoices AADE rejected, payment notifications that could not be processed, and iCal calendars unreadable for :threshold attempts. Counts what is still open, not what was fixed.',
            'none' => 'No operator has an open failure.',
            'operator' => 'Operator',
            'mydata' => 'myDATA',
            'gateway' => 'Payment notifications',
            'ical' => 'iCal calendars',
            'orphans' => ':count payment notification matches no operator — somebody was charged and the money has no booking.|:count payment notifications match no operator — somebody was charged and the money has no booking.',
        ],
    ],

    'announcements' => [
        'nav' => 'Announcements',
        'model' => [
            'singular' => 'Announcement',
            'plural' => 'Announcements',
        ],
        'sections' => [
            'message' => 'The message',
            'message_help' => 'Shown at the top of every page of every operator’s panel, until it ends or each person closes it.',
            'when' => 'When',
        ],
        'form' => [
            'message' => 'Message',
            'message_help' => 'Short, plain text. No HTML — anything that looks like a tag is removed.',
            'severity' => 'Kind',
            'starts_at' => 'From',
            'starts_at_help' => 'Empty means from the moment it is saved.',
            'ends_at' => 'Until',
            'ends_at_help' => 'Empty means until you switch it off.',
            'ends_before_start' => 'The end cannot be before the start.',
            'is_active' => 'Active',
            'is_active_help' => 'Off, it is shown nowhere, whatever the dates say.',
        ],
        'table' => [
            'message' => 'Message',
            'severity' => 'Kind',
            'starts_at' => 'From',
            'ends_at' => 'Until',
            'is_active' => 'Active',
            'created_at' => 'Written',
            'from_now' => 'Immediately',
            'open_ended' => 'No end',
        ],
        'empty' => [
            'heading' => 'No announcements',
            'description' => 'An announcement is shown to every operator — a maintenance window, a change, something everybody needs to know.',
        ],
    ],

    'severity' => [
        'info' => ['label' => 'Information'],
        'warning' => ['label' => 'Warning'],
    ],

    'banner' => [
        'label' => 'Platform announcement',
        'dismiss' => 'Dismiss',
    ],

];
